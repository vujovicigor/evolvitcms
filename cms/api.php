<?php
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
// CSRF: the admin UI only ever calls the API from its own origin
evolvit_require_same_origin();
include("engine.php");

$req = array_merge($_GET, $_POST);
// never trust identity/role values coming from the client
foreach (array_keys($req) as $k) if (strpos((string)$k, '_session_') === 0) unset($req[$k]);
if (isset($_SESSION['_session_user_name']))   $req['_session_user_name']  = $_SESSION['_session_user_name'];
if (isset($_SESSION['_session_user_role']))   $req['_session_user_role']  = $_SESSION['_session_user_role'];
$query  = isset($req['query']) && is_string($req['query']) ? $req['query'] : '';
$action = isset($req['_action']) && is_string($req['_action']) ? $req['_action'] : '';

// state changing requests (writes, logins, free-form SQL) must be POST
$isWrite = in_array($action, array('insert', 'update', 'delete'), true)
        || str_word_count($query, 0, '1234567890:@&_') > 1
        || $query === '_authSA' || $query === '_session_destroy';
if ($isWrite && $_SERVER['REQUEST_METHOD'] !== 'POST') evolvit_json_error('POST required', 405);

if ($query == '_session_destroy'){
  $_SESSION = array();
  session_destroy();
  echo json_encode(array('message'=>'Ok'));
  return;
}

if ($query == '_authCheck'){
  if ( isset($_SESSION['_session_user_name']) && isset($_SESSION['_session_user_role']) ){
    echo json_encode(array(
      '_session_user_name'=>$_SESSION['_session_user_name'],
      '_session_user_role'=>$_SESSION['_session_user_role']
    ));
  } else echo json_encode(array('message'=>'Wrong credentials'));
  return;
}

if ($query == '_authSA'){
  if ( isset($_SESSION['_session_user_name']) && isset($_SESSION['_session_user_role']) ){
    echo json_encode(array(
      '_session_user_name'=>$_SESSION['_session_user_name'],
      '_session_user_role'=>$_SESSION['_session_user_role']
    ));
    return;
  }

  $login_timeout_minutes = (int)ceil(EVOLVIT_LOGIN_WINDOW_SECONDS / 60);
  if ( evolvit_login_is_locked() ){
    sleep(2);
    echo json_encode(array('message'=>"Too many login attempts. Please try again in $login_timeout_minutes min"));
    return;
  }

  $resp = fetch('_authSA', array(
    'email'    => isset($req['email']) ? (string)$req['email'] : '',
    'password' => isset($req['password']) ? (string)$req['password'] : '',
  ));
  if ( !is_array($resp) || !isset($resp['_session_user_name']) ){
    evolvit_login_failed();
    sleep(1);
    echo json_encode(array('message'=>'Wrong credentials'));
    return;
  }
  evolvit_login_succeeded();
  session_regenerate_id(true); // prevent session fixation
  $_SESSION['_session_user_name'] = $resp['_session_user_name'];
  $_SESSION['_session_user_role'] = (int)$resp['_session_user_role'];
  echo json_encode($resp);
  return ;
}

apiJson( $query, $req );
