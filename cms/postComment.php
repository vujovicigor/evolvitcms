<?php
session_start();
include("engine.php");
//print_r( $_POST );
//print_r( $_SESSION );
//header('Access-Control-Allow-Origin: *');
//header('Content-Type: application/json');

//$_SESSION['_session_post_count'] = 1;
if ( !isset($_SESSION['_session_google_user_email']) ) {
  exit(0);
};

$f = fetch('
insert into `Comment` ( `PostId`, `Text`, `google_user_email`, `google_user_family_name`, `google_user_given_name`, `google_user_picture`, `google_user_sub`) 
values ( :PostId, :textComment, :_session_google_user_email, :_session_google_user_family_name, :_session_google_user_given_name, :_session_google_user_picture, :_session_google_user_sub)
;SELECT * from Comment where id = last_insert_rowid();', $_POST);
//print_r( $f );
$f[0]['Text'] = htmlspecialchars($f[0]['Text']);
echo json_encode($f[0]);
//header('Location: '.$_SERVER['PHP_SELF']);

//$f = fetch(' select * from `Comment` ');
//print_r( $f );