<?php
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
evolvit_require_post();
evolvit_require_same_origin();
header('Content-Type: application/json');

$claims = evolvit_google_verify_token(isset($_POST['idtoken']) ? $_POST['idtoken'] : '');
if ($claims === null) evolvit_json_error('Invalid token', 401);
evolvit_google_login($claims);
echo json_encode(array(
  'email' => $_SESSION['_session_google_user_email'],
  'given_name' => $_SESSION['_session_google_user_given_name'],
  'family_name' => $_SESSION['_session_google_user_family_name'],
  'picture' => $_SESSION['_session_google_user_picture'],
));
