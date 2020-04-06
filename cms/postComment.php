<?php
session_start();
print_r( $_POST );
//print_r( $_SESSION );
include("engine.php");
//$userdata = json_decode($resp, TRUE);
//header('Access-Control-Allow-Origin: *');
//header('Content-Type: application/json');

//$_SESSION['_session_post_count'] = 1;
$f = fetch('
insert into `Comment` ( `PostId`, `Text`, `google_user_email`, `google_user_family_name`, `google_user_given_name`, `google_user_picture`, `google_user_sub`) 
values ( :PostId, :textComment, :_session_google_user_email, :_session_google_user_family_name, :_session_google_user_given_name, :_session_google_user_picture, :_session_google_user_sub)
', $_POST);
print_r( $f );