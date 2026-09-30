<?php

declare(strict_types=1);

use SitePreview\Config;
use SitePreview\Setup;

require dirname(__DIR__) . '/vendor/autoload.php';

$configPath = getenv('PREVIEW_CONFIG') ?: dirname(__DIR__) . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing private config file: {$configPath}\n");
    exit(1);
}

try {
    $values = require $configPath;
    $config = Config::fromArray($values, dirname(__DIR__) . '/public');
    Setup::installDatabase($config->database);
    fwrite(STDOUT, "Preview database schema installed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Database setup failed: ' . $error->getMessage() . "\n");
    exit(1);
}
