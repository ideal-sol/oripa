<?php

// Laravel's supported application router override. Preserve routing and request
// bytes; only its diagnostic URI is redacted before artisan serve captures it.
require_once __DIR__.'/app/Logging/PaidyLogRedactor.php';

$publicPath = getcwd();
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($uri !== '/' && file_exists($publicPath.$uri)) {
    return false;
}
$formattedDateTime = date('D M j H:i:s Y');
$requestMethod = $_SERVER['REQUEST_METHOD'];
$remoteAddress = $_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'];
$safeUri = \App\Logging\PaidyLogRedactor::redact($uri, null, $_SERVER['REQUEST_URI']);
file_put_contents('php://stdout', "[$formattedDateTime] $remoteAddress [$requestMethod] URI: $safeUri\n");
require_once $publicPath.'/index.php';
