<?php
/*
 * Router for the local php -S test servers (run-servers.sh): behave like the
 * cPanel Apache vhost, where a path with no file behind it is answered by
 * ErrorDocument 404 /error.php?code=404. Without it php -S falls back to
 * index.php with 200, so a removed page would look reachable.
 */
$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
if (str_contains($path, '..')) $path = '/';
$target = $root . $path;
if ($path === '/' || is_dir($target) || is_file($target)) return false; // php -S serves it as usual
$_GET['code'] = '404';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/error.php';
if (is_file($root . '/error.php')) { require $root . '/error.php'; return true; }
http_response_code(404);
echo 'Not found';
return true;
