<?php
// Google sign-in state of the current session (POST), or sign out (POST action=logout).
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
evolvit_require_post();
evolvit_require_same_origin();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (isset($_POST['action']) && $_POST['action'] === 'logout') {
    foreach (array_keys($_SESSION) as $k) if (strpos($k, '_session_google_user_') === 0) unset($_SESSION[$k]);
    session_regenerate_id(true);
    echo json_encode(array('isSignedIn' => false));
    return;
}
if (empty($_SESSION['_session_google_user_email'])) {
    echo json_encode(array('isSignedIn' => false));
    return;
}
echo json_encode(array(
    'isSignedIn'  => true,
    'email'       => $_SESSION['_session_google_user_email'],
    'given_name'  => $_SESSION['_session_google_user_given_name'],
    'family_name' => $_SESSION['_session_google_user_family_name'],
    'picture'     => $_SESSION['_session_google_user_picture'],
));
