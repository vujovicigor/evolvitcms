<?php
include("engine.php");
$imgcache = dirname(dirname(__FILE__)).'/imgcache/';
$img_slug = isset($_GET['id']) && is_string($_GET['id']) ? $_GET['id'] : '';

// slugs look like "my-image_2.jpg" (or a numeric id): no paths, no hidden files
if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,200}$/', $img_slug) || strpos($img_slug, '..') !== false) {
  http_response_code(404);
  return;
}
$img = get_image_blob($img_slug);
if (!$img) {
  http_response_code(404);
  return;
}

// only raster images may be written into the publicly served cache directory
$ext = strtolower(pathinfo($img_slug, PATHINFO_EXTENSION));
$cacheTypes = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp');
$mime = strtolower((string)$img['mime_type']);
$cacheable = isset($cacheTypes[$ext]) && in_array($mime, array('image/jpeg', 'image/pjpeg', 'image/png', 'image/gif', 'image/webp'), true);

header('Pragma: public');
header('Cache-Control: max-age=86400');
header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));

function write_cache($path, $data) {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
  if (@file_put_contents($tmp, $data) !== false) @rename($tmp, $path);
}

if (isset($_GET['w'])) {
  $w = (string)$_GET['w'];
  if (!ctype_digit($w) || (int)$w < 1 || (int)$w > 4000 || !$cacheable) {
    http_response_code(400);
    return;
  }
  $w = (int)$w;
  $src = @imagecreatefromstring($img['blob']);
  if (!$src) { http_response_code(415); return; }
  $scaled_img = imagescale($src, $w, -1);
  ob_start();
  switch ($cacheTypes[$ext]) {
    case 'image/png':  imagepng($scaled_img); break;
    case 'image/gif':  imagegif($scaled_img); break;
    case 'image/webp': imagewebp($scaled_img); break;
    default:           imagejpeg($scaled_img, null, 85);
  }
  $data = ob_get_clean();
  header('Content-Type: '.$cacheTypes[$ext]);
  header('X-Content-Type-Options: nosniff');
  echo $data;
  write_cache($imgcache.$w.'/'.basename($img_slug), $data);
}
else {
  evolvit_send_file_headers($img['mime_type']);
  echo($img['blob']);
  if ($cacheable) write_cache($imgcache.basename($img_slug), $img['blob']);
}
