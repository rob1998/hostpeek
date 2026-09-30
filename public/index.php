<?php

declare(strict_types=1);

use SitePreview\Application;
use SitePreview\Config;
use SitePreview\PreviewStore;
use SitePreview\Proxy;
use SitePreview\Setup;

ini_set('display_errors', '0');

try {
    $configPath = getenv('PREVIEW_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (!is_file($configPath) && PHP_VERSION_ID < 80200) {
        throw new RuntimeException(
            'This application requires PHP 8.2 or newer. Select PHP 8.2+ in cPanel MultiPHP Manager.',
        );
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException(
            'Application dependencies are missing. Upload the complete release, including vendor/.',
        );
    }
    require $autoload;
    if (!is_file($configPath)) {
        $body = file_get_contents('php://input');
        (new Setup(__DIR__, $configPath))->run($_SERVER, $body === false ? '' : $body);
        return;
    }
    $config = Config::fromArray(require $configPath, __DIR__);
    date_default_timezone_set($config->timezone);
    $store = new PreviewStore($config->database);
    $application = new Application($config, $store, new Proxy($config));
    $body = file_get_contents('php://input', false, null, 0, 4097);
    $application->run($_SERVER, $body === false ? '' : $body);
} catch (Throwable $error) {
    error_log('Preview request failed: ' . $error::class);
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        header('Retry-After: 5');
        echo str_contains($error->getMessage(), 'Application dependencies are missing')
            || str_contains($error->getMessage(), 'requires PHP 8.2')
            ? $error->getMessage() : 'Preview service is temporarily unavailable';
    }
}
