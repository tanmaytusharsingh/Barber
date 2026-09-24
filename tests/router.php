<?php
// PHP development-server adapter for isolated HTTP/browser tests, not deployment.
if (PHP_SAPI!=='cli-server') { http_response_code(404); exit; }
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
if (!str_starts_with($path,'/Barber/')) { http_response_code(404); exit; }
$relative=substr($path,8) ?: 'index.php';
if (preg_match('~(^|/)(\.|config|includes|database|tests|scripts)|\.(md|sql|log|bak|env|ini|toml|lock|zip|gz)$~i',$relative)) { http_response_code(403); exit; }
$root=realpath(__DIR__.'/..'); $file=realpath($root.'/'.$relative);
if (!$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file)) { http_response_code(404); exit; }
if (pathinfo($file,PATHINFO_EXTENSION)==='php') { require $file; return; }
$types=['css'=>'text/css','svg'=>'image/svg+xml']; header('Content-Type: '.($types[pathinfo($file,PATHINFO_EXTENSION)]??'application/octet-stream')); readfile($file);
