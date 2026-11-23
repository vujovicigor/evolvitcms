<?php
require_once(dirname(__FILE__).'/security.php');
evolvit_session_start();
evolvit_require_post();
evolvit_require_same_origin();
include("engine.php");
header('Content-Type: application/json');

if ( !isset($_SESSION['_session_google_user_email']) ) {
  http_response_code(403);
  exit(0);
};
$text = isset($_POST['textComment']) ? trim((string)$_POST['textComment']) : '';
if ($text === '' || mb_strlen($text) > 5000) evolvit_json_error('Invalid comment');

$f = fetch('
insert into `Comment` ( `PostId`, `Text`, `google_user_email`, `google_user_family_name`, `google_user_given_name`, `google_user_picture`, `google_user_sub`)
values ( :PostId, :textComment, :_session_google_user_email, :_session_google_user_family_name, :_session_google_user_given_name, :_session_google_user_picture, :_session_google_user_sub)
;SELECT * from Comment where id = last_insert_rowid();', array(
  'PostId' => isset($_POST['PostId']) ? (string)$_POST['PostId'] : '',
  'textComment' => $text,
));
if (!isset($f[0])) evolvit_json_error('Could not save comment', 500);
// the client renders this via innerHTML
foreach ($f[0] as $k => $v) if (is_string($v)) $f[0][$k] = htmlspecialchars($v, ENT_QUOTES);
echo json_encode($f[0]);
