<?php

declare(strict_types=1);

use SitePreview\Config;
use SitePreview\Proxy;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException("Could not reserve test port: {$error}");
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr(strrchr((string) $address, ':'), 1);
$directory = sys_get_temp_dir() . '/preview-proxy-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$router = $directory . '/router.php';
file_put_contents($router, <<<'PHP'
<?php
if ($_SERVER['REQUEST_URI'] === '/html') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<html><body><h1>Site</h1><a href="http://' . $_SERVER['HTTP_HOST'] . '/asset">Asset</a></body></html>';
    return;
}
header('Content-Type: application/octet-stream');
echo $_SERVER['HTTP_HOST'] . ' ' . $_SERVER['REQUEST_URI'];
PHP);
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $directory,
);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start local upstream server');
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);
$ready = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $probe = @fsockopen('127.0.0.1', $port, $probeErrno, $probeError, 0.05);
    if (is_resource($probe)) {
        fclose($probe);
        $ready = true;
        break;
    }
    usleep(20_000);
}
$check($ready, 'starts local upstream fixture');

$values = require dirname(__DIR__) . '/config.example.php';
$values['hash_secret'] = str_repeat('s', 40);
$config = Config::fromArray($values, dirname(__DIR__));
$proxy = new Proxy($config);
ob_start();
$proxy->serve(
    'GET',
    '/asset.bin?version=1',
    [],
    'origin.example.test',
    '127.0.0.1',
    'preview.example.org',
    'http',
    $port,
);
$response = ob_get_clean();
$check(
    $response === 'origin.example.test:' . $port . ' /asset.bin?version=1',
    'streams binary response from an HTTP target on the requested port with original Host header',
);
ob_start();
$proxy->serve('GET', '/html', [], 'origin.example.test', '127.0.0.1', 'preview.example.org', 'http', $port);
$html = ob_get_clean();
$check(
    str_contains($html, '<a href="https://preview.example.org/asset">Asset</a>'),
    'rewrites text URLs and removes upstream ports',
);
$check(str_contains($html, 'id="site-preview-notice"'), 'injects preview banner into HTML');
$schemeRedirect = new ReflectionMethod(Proxy::class, 'schemeRedirect');
$check(
    $schemeRedirect->invoke(
        null,
        'https://origin.example.test/path?q=1',
        'origin.example.test',
        'http',
        80,
        '/path?q=1',
        301,
    ) === ['scheme' => 'https', 'port' => 443, 'uri' => '/path?q=1'],
    'recognizes a same-host HTTP to HTTPS redirect for pinned internal following',
);
$check(
    $schemeRedirect->invoke(
        null,
        'https://elsewhere.example/path',
        'origin.example.test',
        'http',
        80,
        '/path',
        301,
    ) === null,
    'does not follow redirects to external hosts',
);
$check(
    $schemeRedirect->invoke(
        null,
        'https://origin.example.test/other',
        'origin.example.test',
        'http',
        80,
        '/path',
        301,
    ) === null,
    'leaves path-changing redirects for the browser',
);

proc_terminate($process);
foreach ($pipes as $pipe) {
    fclose($pipe);
}
proc_close($process);
unlink($router);
rmdir($directory);
echo "Proxy streaming checks passed\n";
