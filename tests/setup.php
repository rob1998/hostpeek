<?php

declare(strict_types=1);

use SitePreview\Setup;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$input = [
    'name' => 'Example <Preview>',
    'base_url' => 'https://preview.example.org',
    'timezone' => 'Europe/Amsterdam',
    'manage_password' => "manager's \\secret\nline",
    'database_host' => 'localhost',
    'database_port' => '3306',
    'database_name' => 'account_preview',
    'database_user' => 'account_preview',
    'database_password' => "database's \\secret\nline",
    'preview_ttl' => '1',
    'upstream_scheme' => 'https',
];
$values = Setup::configValues($input);
$check($values['name'] === 'Example <Preview>', 'accepts and returns a service name');
$check(strlen($values['hash_secret']) === 64, 'generates a fresh hash secret');
$check($values['preview_ttl'] === 1, 'does not impose an unrequested minimum lifetime');
$check($values['timezone'] === 'Europe/Amsterdam', 'accepts the configured timezone');
try {
    Setup::configValues(array_merge($input, ['base_url' => 'https://example.org/path']));
    $check(false, 'rejects a base URL containing a path');
} catch (InvalidArgumentException) {
    // Invalid public origins are rejected before database access.
}

$directory = sys_get_temp_dir() . '/preview-setup-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$setup = new Setup($directory, $directory . '/config.php');
$source = new ReflectionMethod($setup, 'configSource');
$config = $source->invoke($setup, $values);
$check(str_contains($config, '// Service name, browser tab title'), 'preserves explanatory config comments');
$check(str_contains($config, "'name' => 'Example <Preview>',"), 'exports values as PHP config');
$generatedConfig = $directory . '/generated.php';
file_put_contents($generatedConfig, $config);
$loadedConfig = require $generatedConfig;
$check($loadedConfig === $values, 'generated config reloads with every value preserved');

file_put_contents($directory . '/setup.html', '<title>{{name}}</title>{{setup_checks}}{{setup_error}}'
    . '<input value="{{manage_password}}"><input value="{{database_password}}">');
$render = new ReflectionMethod($setup, 'render');
ob_start();
$render->invoke($setup, 'Bad <input>', str_repeat('c', 64), ['HTTP_HOST' => 'preview.example.org', 'HTTPS' => 'on'], [
    'name' => 'Brand <script>', 'manage_password' => 'must not be echoed', 'database_password' => 'secret',
]);
$html = ob_get_clean();
$check(str_contains($html, '<title>Brand &lt;script&gt;</title>'), 'escapes submitted form values');
$check(str_contains($html, 'class="setup-error" role="alert">Bad &lt;input&gt;'), 'escapes actionable error text');
$check(str_contains($html, 'class="setup-checks"'), 'renders setup checks for the form');
$healthy = PHP_VERSION_ID >= 80200 && extension_loaded('pdo_mysql')
    && extension_loaded('curl') && extension_loaded('zlib');
$check(
    str_contains($html, '<details class="setup-requirements"' . ($healthy ? '>' : ' open>')),
    'collapses successful checks and expands failed checks'
);
$unwritableSetup = new Setup($directory, $directory . '/missing/config.php');
ob_start();
$render->invoke($unwritableSetup, '', str_repeat('c', 64), ['HTTP_HOST' => 'preview.example.org'], []);
$unhealthyHtml = ob_get_clean();
$check(str_contains($unhealthyHtml, '<details class="setup-requirements" open>'), 'expands unwritable config checks');
$passwordsCleared = !str_contains($html, 'must not be echoed') && !str_contains($html, 'value="secret"');
$check($passwordsCleared, 'clears passwords after errors');

$configPath = $directory . '/created.php';
Setup::publishConfig($configPath, $config);
$check(file_get_contents($configPath) === $config, 'writes config atomically');
$check((fileperms($configPath) & 0777) === 0600, 'writes config with private permissions');
try {
    Setup::publishConfig($configPath, '<?php return [];');
    $check(false, 'does not overwrite an existing config');
} catch (RuntimeException) {
    $check(file_get_contents($configPath) === $config, 'preserves existing config after collision');
}

unlink($directory . '/setup.html');
unlink($generatedConfig);
unlink($configPath);
rmdir($directory);
echo "PHP setup checks passed\n";
