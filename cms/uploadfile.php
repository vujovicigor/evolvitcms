<?php
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
evolvit_require_post();
evolvit_require_same_origin();
include("engine.php");
if ( !isset($_SESSION['_session_user_name']) || !isset($_SESSION['_session_user_role']) || (int)$_SESSION['_session_user_role'] <= 1 ){
    http_response_code(403);
    return;
}

if(!empty($_FILES['file']))
{
    upload2sqlite( $_FILES['file'], isset($_POST['selectedfolder']) ? $_POST['selectedfolder'] : null );
}
