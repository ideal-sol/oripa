<?php

// Isolated source test: no application ENV, database, provider or host port used.
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Process\Process;

$source = dirname(__DIR__);
$root = sys_get_temp_dir().'/paidy-serve-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
$token = 'synthetic_'.bin2hex(random_bytes(24));
$wrong = 'rejected_'.bin2hex(random_bytes(24));
$copy = function ($from, $to) use (&$copy): void {
    if (is_dir($from)) {
        mkdir($to, 0700, true);
        foreach (new DirectoryIterator($from) as $entry) {
            if (! $entry->isDot()) $copy($entry->getPathname(), $to.'/'.$entry->getFilename());
        }
    } else copy($from, $to);
};
$remove = function ($path) use (&$remove): void {
    if (is_dir($path) && ! is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (! $entry->isDot()) $remove($entry->getPathname());
        }
        rmdir($path);
    } else unlink($path);
};
$server = null;
try {
    foreach (['app', 'bootstrap', 'config', 'routes', 'public', 'artisan', 'server.php', 'composer.json'] as $name) $copy($source.'/'.$name, $root.'/'.$name);
    foreach (glob($root.'/bootstrap/cache/*.php') as $cache) unlink($cache);
    foreach (['storage/logs', 'storage/framework/views', 'storage/framework/cache', 'storage/framework/sessions'] as $directory) mkdir($root.'/'.$directory, 0700, true);
    symlink($source.'/vendor', $root.'/vendor');
    file_put_contents($root.'/routes/webhook.php', <<<'PHP'

// Only in this disposable test application; never part of application routes.
Route::post('/v2/paidy/{token}/log-probe', function (\Illuminate\Http\Request $request, string $token) {
    \Illuminate\Support\Facades\Log::shareContext(['nested' => ['token' => $token]]);
    logger()->error('probe '.$token, ['exception' => new \RuntimeException($token)]);
    \Illuminate\Support\Facades\Log::channel('missing_probe')->error('fallback '.$token);
    if ($request->query('throw')) throw new \RuntimeException('unhandled '.$token);
    return response()->json(['body' => hash('sha256', $request->getContent()), 'token_matches' => hash_equals(config('v2_paidy.webhook_path_token'), $token)]);
});
PHP, FILE_APPEND);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $environment = ['PATH' => getenv('PATH'), 'HOME' => $root, 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)), 'LOG_CHANNEL' => 'stack', 'LOG_STACK' => 'single,stderr',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync', 'V2_PAIDY_WEBHOOK_PATH_TOKEN' => $token, 'V2_PAIDY_WORKER_ENABLED' => 'false'];
    // Explicitly remove inherited ENV, including any provider or database settings.
    foreach (array_keys(getenv()) as $key) if (! array_key_exists($key, $environment)) $environment[$key] = false;
    $server = new Process([PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port='.$port, '--tries=1', '--no-reload'], $root, $environment);
    $server->start();
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $connection = @fsockopen('127.0.0.1', $port);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (! $ready) throw new RuntimeException('isolated server did not start');
    $body = '{"payment_id":"pay_synthetic","nested":{"preserve":"bytes"}}';
    $request = function ($path, $method, $expected) use ($port, $body, $server): string {
        $context = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: application/json\r\nAccept: application/json\r\n", 'content' => $method === 'POST' ? $body : '', 'ignore_errors' => true, 'timeout' => 10]]);
        $result = file_get_contents('http://127.0.0.1:'.$port.$path, false, $context);
        if (! str_contains($http_response_header[0], ' '.$expected.' ')) throw new RuntimeException('unexpected isolated response: '.$expected);
        $server->getOutput();
        $server->getErrorOutput();
        return $result;
    };
    $result = json_decode($request('/webhooks/v2/paidy/'.$token.'/log-probe', 'POST', 200), true);
    if ($result !== ['body' => hash('sha256', $body), 'token_matches' => true]) throw new RuntimeException('request bytes changed');
    $request('/webhooks/v2/paidy/'.$token.'/log-probe?throw=1', 'POST', 500);
    $request('/webhooks/v2/paidy/'.$wrong, 'POST', 404);
    $request('/webhooks/v2/paidy/'.$token, 'GET', 405);
    // Real receiver: no database exists, therefore receipt storage must fail 503.
    $request('/webhooks/v2/paidy/'.$token, 'POST', 503);
    $request('/webhooks/v2/paidy/%'.bin2hex($token[0]).substr($token, 1), 'GET', 405);
    $request('/ordinary-log-marker', 'GET', 404);
    for ($i = 0; $i < 100; $i++) {
        if (str_contains($server->getOutput(), '/ordinary-log-marker')) break;
        $server->getErrorOutput();
        usleep(20000);
    }
    $server->stop(3);
    $streams = ['stdout' => $server->getOutput(), 'stderr' => $server->getErrorOutput(), 'laravel' => file_get_contents($root.'/storage/logs/laravel.log')];
    foreach ($streams as $name => $output) {
        if (str_contains($output, $token) || str_contains($output, $wrong)
            || str_contains($output, substr($token, 0, 15)) || str_contains($output, substr($wrong, 0, 15))) throw new RuntimeException('token leaked in '.$name);
    }
    if (! str_contains($streams['stdout'], '/ordinary-log-marker') || ! str_contains($streams['stdout'], 'REDACTED')
        || ! str_contains($streams['stdout'].$streams['stderr'], 'probe REDACTED') || ! str_contains($streams['laravel'], 'fallback REDACTED')
        || ! str_contains($streams['laravel'], 'unhandled REDACTED')) throw new RuntimeException('missing positive log control');
    // Forward the actual verified streams so an isolated Docker run can verify
    // its json-file log as well, rather than checking only captured PHP output.
    echo $streams['stdout'];
    fwrite(STDERR, $streams['stderr']);
    echo "PAIDY_SERVE_LOG_PROTECTION_PASS statuses=200,500,404,405,503 body=unchanged laravel=redacted\n";
} finally {
    if ($server?->isRunning()) $server->stop(3);
    $remove($root);
}
