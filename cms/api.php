<?php
session_start();
//header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
include("engine.php");
$req = array_merge($_GET, $_POST);
if (isset($req['_session_user_name']))      unset($req['_session_user_name']);
if (isset($req['_session_user_role']))           unset($req['_session_user_role']);
if (isset($_SESSION['_session_user_name']))   $req['_session_user_name']  = $_SESSION['_session_user_name'];
if (isset($_SESSION['_session_user_role']))        $req['_session_user_role']  = $_SESSION['_session_user_role'];

if ($req['query'] == '_session_destroy'){
  session_destroy();
  echo json_encode(array('message'=>'Ok'));
  return;
}

if ($req['query'] == '_authCheck'){
  // check is already loged in// TODO: fix, refresh token...
  if ( isset($_SESSION['_session_user_name']) && isset($_SESSION['_session_user_role']) ){
    echo json_encode(array(
      '_session_user_name'=>$_SESSION['_session_user_name'],
      '_session_user_role'=>$_SESSION['_session_user_role']
    ));
  } else echo json_encode(array('message'=>'Wrong credentials'));
  
  return;
}


if ($req['query'] == '_authSA'){
  // check is already loged in// TODO: fix, refresh token...
  if ( isset($_SESSION['_session_user_name']) && isset($_SESSION['_session_user_role']) ){
    echo json_encode(array(
      '_session_user_name'=>$_SESSION['_session_user_name'],
      '_session_user_role'=>$_SESSION['_session_user_role']
    ));
    return;
  }


  if ( !isset($_SESSION['_login_attempts']) ) $_SESSION['_login_attempts'] = 0;
  ++$_SESSION['_login_attempts'];
  if ( !isset($_SESSION['_login_ts']) ) $_SESSION['_login_ts'] = 0;

  $login_timeout_minutes = 10;
  $login_attempts_max = 9;
  $current_time = time();
  // reset counter after 60sec
  if ( ($current_time - $_SESSION['_login_ts']) >= $login_timeout_minutes*60  )
    $_SESSION['_login_attempts'] = 1;

  if ( ($current_time - $_SESSION['_login_ts']) < $login_timeout_minutes*60 && ($_SESSION['_login_attempts']> $login_attempts_max) ){
    sleep(2);
    echo json_encode(array('message'=>"Too many login attempts. Please try again in $login_timeout_minutes min"));
    return;
  }
  $_SESSION['_login_ts'] = $current_time;
  
  $resp = fetch('_authSA', $req);
  if ( $resp == new stdClass() ){
    sleep(1);
    echo json_encode(array('message'=>'Wrong credentials'));
    return;
  }
  echo json_encode($resp);
  $_SESSION['_session_user_name'] = $resp['_session_user_name'];
  $_SESSION['_session_user_role'] = $resp['_session_user_role'];
  return ;
}

//if ( isset($_SESSION['_session_user_role']) ) //&& ($_SESSION['_session_user_role']==2 || $_SESSION['_session_user_role']==4))
  apiJson( $req['query'], $req );
//else echo json_encode(array('_message'=>'No privilegies', '_message_type'=>'error', '_message_action'=>'reload'));
?>