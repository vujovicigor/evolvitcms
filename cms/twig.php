<?php
require_once(dirname(__FILE__).'/security.php');
include("engine.php");
require_once dirname(__FILE__).'/vendor/autoload.php';

$currentPath = $_SERVER['PHP_SELF'];
$pathInfo = pathinfo($currentPath);
$BASE = dirname($pathInfo['dirname']);

function getBaseUrl()
{
    $currentPath = $_SERVER['PHP_SELF'];
    $pathInfo = pathinfo($currentPath);
    $hostName = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    if (!preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $hostName)) $hostName = 'localhost';
    $protocol = evolvit_is_https() ? 'https://' : 'http://';
    $dir = '/';
    if ( dirname($pathInfo['dirname']) !='/') $dir = dirname($pathInfo['dirname']).'/';
    return $protocol.$hostName.$dir;
}

/** Twig 2 -> 3: `{% for x in list if cond %}` becomes `{% for x in list|filter(x => cond) %}` */
function evolvit_twig2_compat($src){
    return preg_replace_callback('/\{%(-?)\s*for\s+(\w+)(?:\s*,\s*(\w+))?\s+in\s+((?:(?!%\}).)+?)\s+if\s+((?:(?!%\}).)+?)\s*(-?)%\}/s', function ($m) {
        $args = $m[3] !== '' ? '('.$m[3].', '.$m[2].')' : $m[2];
        $vars = $m[3] !== '' ? $m[2].', '.$m[3] : $m[2];
        return '{%'.$m[1].' for '.$vars.' in ('.$m[4].')|filter('.$args.' => '.$m[5].') '.$m[6].'%}';
    }, $src);
}

$TwigTemplatesDir = dirname( dirname(__FILE__) ).'/twigtemplates';
$dbTemplates = null;
if (EVOLVIT_TWIG_FROM_DB) {
    $dbTemplates = array();
    foreach (fetch('Twigs') as $t) if (isset($t['name'])) $dbTemplates[$t['name']] = evolvit_twig2_compat((string)$t['html']);
    $loader = new \Twig\Loader\ArrayLoader($dbTemplates);
} else {
    $loader = new \Twig\Loader\FilesystemLoader( $TwigTemplatesDir );
}
$twig = new \Twig\Environment($loader, array(
    'cache' => dirname(__FILE__).'/twigcache',
    'auto_reload' => true,
    'autoescape' => 'html',
));

$twig->addFunction(new \Twig\TwigFunction('dump', function ($mixed) {
  ob_start();
  var_dump($mixed);
  $content = ob_get_contents();
  ob_end_clean();
  return $content;
}));

$twig->addFunction(new \Twig\TwigFunction('fetch', function ($name, $params=array()) {
  return fetch($name, $params);
}));

if (!EVOLVIT_TWIG_SANDBOX) {
  $twig->addFunction(new \Twig\TwigFunction('header', function ($header, $replace = TRUE) {
    if (preg_match('/[\r\n]/', $header)) return;
    return header ( $header, $replace );
  }));
}

if (EVOLVIT_TWIG_SANDBOX) {
  // Untrusted templates: no PHP callables (map('system')), no constant(), no object methods/properties.
  $tags = array('apply', 'autoescape', 'block', 'do', 'embed', 'extends', 'for', 'from', 'if', 'import', 'include',
                'macro', 'set', 'verbatim', 'with', 'guard', 'types');
  $policy = new \Twig\Sandbox\SecurityPolicy($tags, array(), array(), array(), array());
  $twig->addExtension(new \Twig\Extension\SandboxExtension($policy, true));
}

/* transform /filename/param1/param2/... to ['filename', 'param1', 'param2'] and assign it to $_GET */
$p = parse_url($_SERVER['REQUEST_URI']);
if (!is_array($p)) $p = array();
if (!isset($p['query'])) $p['query']='';
if (!isset($p['path']))  $p['path']='';

if ($BASE !== '/' && substr($p['path'], 0, strlen($BASE)) == $BASE) {
    $p['path'] = substr($p['path'], strlen($BASE));
}

$pathArr = array_values(array_filter(explode('/', $p['path']), 'strlen'));
parse_str($p['query'], $queryArr);

$index = 0;
foreach($pathArr as $key) {
    $queryArr[$index] = urldecode($key);
    $index++;
}

$_GET = array_merge($_GET, $queryArr);
$_GET['twig_file_name'] = isset($_GET[0]) && is_string($_GET[0]) ? $_GET[0] : 'index';

// only expose non-sensitive server variables to templates
$safeServer = array();
foreach (array('HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'REQUEST_URI', 'REQUEST_METHOD', 'QUERY_STRING', 'SCRIPT_NAME',
               'PHP_SELF', 'HTTPS', 'REQUEST_SCHEME', 'REMOTE_ADDR', 'HTTP_USER_AGENT', 'HTTP_REFERER', 'HTTP_ACCEPT_LANGUAGE', 'REQUEST_TIME') as $k)
    if (isset($_SERVER[$k])) $safeServer[$k] = $_SERVER[$k];

$twig->addGlobal('_GET', $_GET);
$twig->addGlobal('_POST', $_POST);
$twig->addGlobal('_BASE', getBaseUrl());
$twig->addGlobal('_SERVER', $safeServer);

$name = $_GET['twig_file_name'];
// template names: no path separators, no traversal, no hidden files
if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]{0,100}$/', $name) || strpos($name, '..') !== false) {
    http_response_code(404);
    return;
}
$templatename = $name.'.twig';
if ( $dbTemplates !== null ? !isset($dbTemplates[$templatename]) : !is_file($TwigTemplatesDir.'/'.$templatename) ) {
    http_response_code(404);
    return;
}

try {
    if (EVOLVIT_TWIG_SANDBOX) {
        // allow every registered filter (callables must be arrow functions in sandbox mode) and every
        // function except those that reach PHP constants/enums or compile templates from strings
        $policy->setAllowedFilters(array_keys($twig->getFilters()));
        $policy->setAllowedFunctions(array_values(array_diff(array_keys($twig->getFunctions()), array('constant', 'enum', 'enum_cases', 'template_from_string'))));
    }
    echo $twig->render($templatename);
} catch (\Twig\Error\Error $e) {
    error_log('EvolvitCMS twig error: '.$e->getMessage());
    http_response_code(500);
    echo EVOLVIT_DEBUG ? htmlspecialchars($e->getMessage()) : 'Template error';
}
