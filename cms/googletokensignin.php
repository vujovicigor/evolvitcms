<?php
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
evolvit_require_post();
evolvit_require_same_origin();
header('Content-Type: application/json');

$idtoken = isset($_POST['idtoken']) ? (string)$_POST['idtoken'] : '';
if ($idtoken === '' || !preg_match('/^[A-Za-z0-9_\-.]+$/', $idtoken)) evolvit_json_error('Invalid token');

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($idtoken),
]);
$resp = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$userdata = json_decode((string)$resp, TRUE);
// The token must have been issued for *our* client id, by Google, for a verified e-mail.
// Without the audience check any Google token minted for any other app would be accepted.
if ($status !== 200 || !is_array($userdata)
    || !in_array(isset($userdata['aud']) ? $userdata['aud'] : '', evolvit_google_client_ids(), true)
    || !in_array(isset($userdata['iss']) ? $userdata['iss'] : '', array('accounts.google.com', 'https://accounts.google.com'), true)
    || !isset($userdata['exp']) || (int)$userdata['exp'] < time()
    || (isset($userdata['email_verified']) ? (string)$userdata['email_verified'] : '') !== 'true') {
  evolvit_json_error('Invalid token', 401);
}

session_regenerate_id(true);
foreach (array('sub', 'email', 'picture', 'given_name', 'family_name', 'locale') as $k)
  $_SESSION['_session_google_user_'.$k] = isset($userdata[$k]) ? (string)$userdata[$k] : '';
echo json_encode(array(
  'email' => $_SESSION['_session_google_user_email'],
  'given_name' => $_SESSION['_session_google_user_given_name'],
  'family_name' => $_SESSION['_session_google_user_family_name'],
  'picture' => $_SESSION['_session_google_user_picture'],
));
