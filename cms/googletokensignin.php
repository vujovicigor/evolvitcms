<?php
$idtoken = $_POST['idtoken'];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_URL => 'https://oauth2.googleapis.com/tokeninfo?id_token='.$idtoken,
]);
// Send the request & save response to $resp
$resp = curl_exec($curl);
echo $resp;
// Close request to clear up some resources
curl_close($curl);

$userdata = json_decode($resp, TRUE);
session_start();
//header('Access-Control-Allow-Origin: *');
//header('Content-Type: application/json');
$_SESSION['_session_google_user_sub'] = $userdata['sub'];
$_SESSION['_session_google_user_email'] = $userdata['email'];
$_SESSION['_session_google_user_picture'] = $userdata['picture'];
$_SESSION['_session_google_user_given_name'] = $userdata['given_name'];
$_SESSION['_session_google_user_family_name'] = $userdata['family_name'];
$_SESSION['_session_google_user_locale'] = $userdata['locale'];