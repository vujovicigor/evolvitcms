<?php 
include("engine.php");
$imgcache = '../imgcache/';
$img_slug = $_GET['id'];
$img = get_image_blob($img_slug);
if (!$img) {
  http_response_code(404);
  return;
}
header('Pragma: public');
header('Cache-Control: max-age=86400');
header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
header('Content-type:'.$img['mime_type']);

$resize = isset($_GET['w']);
if ($resize) {
  $w = $_GET['w'] + 0;
  if (!is_int($w)) {
    http_response_code(403);
    return;
  }
  //echo $img['blob'];
  $scaled_img = imagescale(imagecreatefromstring($img['blob']), $w, -1, IMG_NEAREST_NEIGHBOUR);
  imagepng($scaled_img);
  mk_dir($imgcache.$w);
  if ($img['mime_type'] == 'png')
    imagepng($scaled_img, $imgcache.$w.'/'.$img_slug); 
  else 
    imagejpeg($scaled_img, $imgcache.$w.'/'.$img_slug);
}
else {
  echo($img['blob']);
  file_put_contents($imgcache.$img_slug, $img['blob']);  
}

function mk_dir($dir){
  if (!file_exists($dir)) {
    mkdir($dir, 0777, false);
  }  
}
?>
