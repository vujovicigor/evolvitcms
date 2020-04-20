<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include("engine.php");
require_once 'vendor/autoload.php';

$currentPath = $_SERVER['PHP_SELF']; 
$pathInfo = pathinfo($currentPath);     
$BASE = dirname($pathInfo['dirname']);

//setlocale(LC_TIME, 'sr_CS');

function getBaseUrl() 
{
    // output: /myproject/index.php
    $currentPath = $_SERVER['PHP_SELF']; 
    // output: Array ( [dirname] => /myproject [basename] => index.php [extension] => php [filename] => index ) 
    $pathInfo = pathinfo($currentPath);     
    // output: localhost
    $hostName = $_SERVER['HTTP_HOST']; 
    // output: http://

    $isSecure = false;
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
        $isSecure = true;
    }
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
        $isSecure = true;
    }
    $protocol = $isSecure ? 'https://' : 'http://';

    // return: http://localhost/myproject/
    //echo $protocol.$hostName;
    //echo dirname($pathInfo['dirname']);
    $dir = '/';
    if ( dirname($pathInfo['dirname']) !='/') $dir = dirname($pathInfo['dirname']).'/';
    return $protocol.$hostName.$dir;
}
//$templates_assoc = fetch_twig_templates();
//print_r($templates_assoc);
// todo: 404 if req name not in templates_assoc
//$loader = new Twig_Loader_Array( $templates_assoc);

$TwigTemplatesDir = dirname( dirname(__FILE__) ).'/twigtemplates';
$loader = new Twig_Loader_Filesystem( $TwigTemplatesDir );
$twig = new Twig_Environment($loader, array(
    'cache' => 'twigcache',
    'auto_reload' => true
));
//$twig->addFilter('var_dump', new Twig_Filter_Function('var_dump'));

$vd = new Twig_SimpleFunction('dump', function ($mixed) {
  ob_start();
  var_dump($mixed);
  $content = ob_get_contents();
  ob_end_clean();
  return $content;
});
$twig->addFunction($vd);


$f = new Twig_SimpleFunction('fetch', function ($name, $params=array()) {
  return fetch($name, $params);
});
$twig->addFunction($f);

$h = new Twig_SimpleFunction('header', function ($header, $replace = TRUE) {
  return header ( $header, $replace ); // add $http_response_code param if needed
});
$twig->addFunction($h);

/* transform /filename/param1/param2/... to ['filename', 'param1', 'param2'] and assign it to $_GET */
//print_r($_SERVER);
$p = parse_url($_SERVER['REQUEST_URI']);
if (!isset($p['query'])) $p['query']='';
if (!isset($p['path']))  $p['path']='';
//list($path, $query) =
//echo $p['path'];

if (substr($p['path'], 0, strlen($BASE)) == $BASE) {
    $p['path'] = substr($p['path'], strlen($BASE));
} 

$pathArr = array_filter(explode('/', $p['path']));
//print_r( $pathArr );
//$queryArr = array_filter(explode('&', $p['query']));
parse_str($p['query'], $queryArr);
//print_r( $queryArr );

$index = 0;
foreach($pathArr as $key) {
    $queryArr[$index] = urldecode($key);
    $index++;
}

$_GET = array_merge($_GET, $queryArr);
$_GET['twig_file_name'] = isset($_GET[0])?$_GET[0]:'index';
//print_r( $_GET );

$twig->addGlobal('_GET', $_GET);
$twig->addGlobal('_POST', $_POST);// todo add SESSION var
$twig->addGlobal('_BASE', getBaseUrl());
$twig->addGlobal('_SERVER', $_SERVER);


$templatename = isset($_GET['twig_file_name'])?$_GET['twig_file_name'].'.twig':'index.twig'; // from fs
if ( !file_exists($TwigTemplatesDir.'/'.$templatename) ) {
    http_response_code(404);
    return;
}

echo $twig->render($templatename);
?>