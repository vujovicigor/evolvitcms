/*
 * EvolvitCMS Google sign-in, built on Google Identity Services (accounts.google.com/gsi/client).
 * Replaces the retired gapi.auth2 / platform.js library.
 *
 *   EvolvitGoogle.init({
 *     button:    element or id where the "Sign in with Google" button is rendered,
 *     signinUrl: endpoint receiving the ID token (default 'cms/googletokensignin.php'),
 *     sessionUrl:'cms/googlesession.php',
 *     onSignIn:  function(user, response) {},   // user = {email, given_name, family_name, picture}
 *     onSignOut: function() {}
 *   })
 *   EvolvitGoogle.signOut()
 *
 * The client id is read from <meta name="google-signin-client_id" content="...">.
 * The login state lives in the server session (the ID token is verified server side).
 */
(function () {
  var opts = {};
  var gsiLoading = null;

  function loadGsi() {
    if (window.google && google.accounts && google.accounts.id) return Promise.resolve();
    if (gsiLoading) return gsiLoading;
    gsiLoading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = 'https://accounts.google.com/gsi/client';
      s.async = true;
      s.onload = function () { resolve(); };
      s.onerror = reject;
      document.head.appendChild(s);
    });
    return gsiLoading;
  }

  function post(url, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(url, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); });
  }

  function onCredential(resp) {
    post(opts.signinUrl, { credential: resp.credential })
      .then(function (data) {
        if (data && data.error) { console.log('Google sign-in rejected:', data.error); return; }
        return post(opts.sessionUrl, {}).then(function (s) {
          if (s && s.isSignedIn) opts.onSignIn(s, data);
        });
      })
      .catch(function (e) { console.log('Google sign-in failed', e); });
  }

  window.EvolvitGoogle = {
    init: function (o) {
      opts = Object.assign({
        signinUrl: 'cms/googletokensignin.php',
        sessionUrl: 'cms/googlesession.php',
        onSignIn: function () {},
        onSignOut: function () {},
        buttonOptions: { theme: 'outline', size: 'large', text: 'signin_with', shape: 'rectangular' }
      }, o || {});
      var meta = document.querySelector('meta[name="google-signin-client_id"]');
      var clientId = opts.clientId || (meta && meta.content);
      // current server-side state first (survives reloads, unlike the GIS button)
      post(opts.sessionUrl, {}).then(function (s) {
        if (s && s.isSignedIn) opts.onSignIn(s, null); else opts.onSignOut();
      }).catch(function () {});
      return loadGsi().then(function () {
        google.accounts.id.initialize({ client_id: clientId, callback: onCredential, auto_select: false, use_fedcm_for_prompt: true });
        var el = typeof opts.button === 'string' ? document.getElementById(opts.button) : opts.button;
        if (el) google.accounts.id.renderButton(el, opts.buttonOptions);
      });
    },
    prompt: function () {
      return loadGsi().then(function () { google.accounts.id.prompt(); });
    },
    signOut: function () {
      if (window.google && google.accounts && google.accounts.id) google.accounts.id.disableAutoSelect();
      return post(opts.sessionUrl, { action: 'logout' }).then(function () { opts.onSignOut(); });
    }
  };
})();
