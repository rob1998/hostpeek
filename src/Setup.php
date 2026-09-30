<?php

declare(strict_types=1);

namespace SitePreview;

use PDO;
use Throwable;

final class Setup
{
    public function __construct(private string $documentRoot, private string $configPath)
    {
    }

    public function run(array $server, string $body): void
    {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (in_array($path, ['/styles.css', '/setup.js'], true) && ($server['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $file = $this->documentRoot . $path;
            if (is_file($file)) {
                header('Content-Type: ' . ($path === '/setup.js'
                    ? 'application/javascript; charset=utf-8' : 'text/css; charset=utf-8'));
                readfile($file);
                return;
            }
        }
        if ($path !== '/') {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        if (is_file($this->configPath)) {
            header('Location: /manage', true, 303);
            return;
        }

        $csrf = $this->csrf($server);
        $error = '';
        $submitted = [];
        if (($server['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            parse_str($body, $posted);
            $submitted = $posted;
            if (strlen($body) > 16384) {
                $error = 'The setup form was too large. Reload the page and try again.';
            } elseif (!hash_equals($csrf, self::scalar($posted['csrf'] ?? null))) {
                $error = 'This setup page expired. Reload it and try again.';
            } else {
                try {
                    $values = self::configValues($posted);
                    $this->install($values);
                    header('Location: /manage', true, 303);
                    return;
                } catch (\InvalidArgumentException $exception) {
                    $error = $exception->getMessage();
                } catch (Throwable $exception) {
                    error_log('Setup failed: ' . $exception::class);
                    $error = 'Setup could not finish. Check the database details and permissions, then try again.';
                }
            }
        } elseif (($server['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            header('Allow: GET, POST');
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $this->render($error, $csrf, $server, $submitted);
    }

    public static function configValues(array $input): array
    {
        $string = static fn(string $key, string $default = ''): string => trim(self::scalar($input[$key] ?? $default));
        $name = $string('name', 'Hostpeek');
        $baseUrl = rtrim($string('base_url'), '/');
        $timezone = $string('timezone', 'Europe/Amsterdam');
        $managePassword = self::scalar($input['manage_password'] ?? null);
        $database = [
            'host' => $string('database_host', 'localhost'),
            'port' => filter_var($input['database_port'] ?? '3306', FILTER_VALIDATE_INT),
            'name' => $string('database_name'),
            'user' => $string('database_user'),
            'password' => self::scalar($input['database_password'] ?? null),
        ];
        $ttl = filter_var($input['preview_ttl'] ?? '3600', FILTER_VALIDATE_INT);
        $scheme = $string('upstream_scheme', 'https');
        $values = [
            'name' => $name,
            'favicon' => $string('favicon', '/assets/favicon.svg'),
            'logo_light' => $string('logo_light'),
            'logo_dark' => $string('logo_dark'),
            'base_url' => $baseUrl,
            'hash_secret' => bin2hex(random_bytes(32)),
            'allow_self_signed' => filter_var($input['allow_self_signed'] ?? false, FILTER_VALIDATE_BOOL),
            'upstream_scheme' => $scheme,
            'preview_ttl' => $ttl,
            'timezone' => $timezone,
            'not_found_redirect' => '/',
            'manage_password' => $managePassword,
            'login_attempts' => 5,
            'login_window' => 300,
            'page_size' => 25,
            'database' => $database,
        ];

        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException('Enter a service name of 1 to 120 characters.');
        }
        if ($managePassword === '') {
            throw new \InvalidArgumentException('Choose a management password.');
        }
        if ($database['port'] === false || $database['port'] < 1 || $database['port'] > 65535) {
            throw new \InvalidArgumentException('Enter a database port between 1 and 65535.');
        }
        foreach (['host', 'name', 'user'] as $key) {
            if ($database[$key] === '') {
                throw new \InvalidArgumentException('Enter the database ' . $key . '.');
            }
        }
        if ($database['password'] === '') {
            throw new \InvalidArgumentException('Enter the database password.');
        }
        if ($ttl === false || $ttl < 1) {
            throw new \InvalidArgumentException('Preview lifetime must be a positive number of seconds.');
        }
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Choose HTTP or HTTPS for connections to target websites.');
        }
        try {
            new \DateTimeZone($timezone);
            Config::fromArray($values, dirname(__DIR__) . '/public');
        } catch (\Throwable) {
            throw new \InvalidArgumentException(
                'Check the public URL and timezone. Use a domain origin such as https://preview.example.org.',
            );
        }
        foreach (['logo_light', 'logo_dark', 'favicon'] as $key) {
            if ($values[$key] !== '' && !self::assetPath($values[$key])) {
                throw new \InvalidArgumentException('Logo and favicon paths must point to a file under /assets/.');
            }
        }
        return $values;
    }

    private function install(array $values): void
    {
        $directory = dirname($this->configPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new \RuntimeException('The application directory must be writable during setup.');
        }
        $lockPath = $this->configPath . '.setup.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Could not lock setup.');
        }
        try {
            chmod($lockPath, 0600);
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Could not lock setup.');
            }
            if (file_exists($this->configPath)) {
                throw new \RuntimeException('Setup has already been completed.');
            }
            self::installDatabase($values['database']);

            self::publishConfig($this->configPath, $this->configSource($values));
            @unlink($this->configPath . '.setup.lock');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function installSchema(PDO $pdo, string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new \RuntimeException('Could not read database schema.');
        }
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            // The schema also contains cleanup for obsolete tables; setup must never remove user data.
            if (preg_match('/^DROP\s+TABLE\b/i', $statement)) {
                continue;
            }
            $pdo->exec($statement);
        }
    }

    public static function installDatabase(array $database): void
    {
        $missing = array_values(array_filter(
            ['pdo_mysql', 'curl', 'zlib'],
            static fn(string $extension): bool => !extension_loaded($extension),
        ));
        if ($missing !== []) {
            throw new \RuntimeException('Enable these PHP extensions in cPanel: ' . implode(', ', $missing) . '.');
        }
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $database['host'],
                (int) ($database['port'] ?? 3306),
                $database['name'],
            ),
            $database['user'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        self::installSchema($pdo, dirname(__DIR__) . '/database/schema.sql');
    }

    private function configSource(array $values): string
    {
        $example = file_get_contents(dirname(__DIR__) . '/config.example.php');
        if ($example === false) {
            throw new \RuntimeException('Could not read example configuration.');
        }
        $insideDatabase = false;
        $lines = explode("\n", $example);
        foreach ($lines as &$line) {
            if (preg_match("/^\\s*'database' => \\[\\s*$/", $line) === 1) {
                $insideDatabase = true;
                continue;
            }
            if ($insideDatabase && preg_match('/^\s*\],?\s*$/', $line) === 1) {
                $insideDatabase = false;
                continue;
            }
            if (preg_match("/^(\\s*)'([^']+)' => .*(,\\s*(?:\/\/.*)?)$/", $line, $match) !== 1) {
                continue;
            }
            $key = $match[2];
            $source = $insideDatabase ? ($values['database'][$key] ?? null) : ($values[$key] ?? null);
            if ($source === null || (!array_key_exists($key, $values) && !$insideDatabase)) {
                continue;
            }
            $line = $match[1] . "'" . $key . "' => " . var_export($source, true)
                . $match[3];
        }
        unset($line);
        return implode("\n", $lines);
    }

    public static function publishConfig(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporary = tempnam($directory, '.preview-config-');
        if ($temporary === false) {
            throw new \RuntimeException('Could not prepare config file.');
        }
        try {
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new \RuntimeException('Could not write config file.');
            }
            if (!@link($temporary, $path)) {
                throw new \RuntimeException(
                    'Could not publish config file. Check directory permissions or whether setup already completed.',
                );
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function render(string $error, string $csrf, array $server, array $submitted): void
    {
        $templatePath = $this->documentRoot . '/setup.html';
        if (!is_file($templatePath)) {
            http_response_code(503);
            echo 'Setup page is missing. Upload the complete application files and reload.';
            return;
        }
        $template = file_get_contents($templatePath);
        if ($template === false) {
            http_response_code(503);
            echo 'Could not read the setup page.';
            return;
        }
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? '');
        $host = preg_match('/^[a-z0-9.-]+(?::\d{1,5})?$/i', $host) === 1 ? $host : '';
        $scheme = (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') ? 'https' : 'http';
        $defaults = [
            'name' => 'Hostpeek', 'base_url' => $host === '' ? '' : $scheme . '://' . $host,
            'timezone' => 'Europe/Amsterdam', 'manage_password' => '', 'database_host' => 'localhost',
            'database_port' => '3306', 'database_name' => '', 'database_user' => '',
            'database_password' => '', 'logo_light' => '', 'logo_dark' => '',
            'favicon' => '/assets/favicon.svg', 'preview_ttl' => '3600',
        ];
        $phpSupport = PHP_VERSION_ID >= 80200 ? ' (supported)' : ' (PHP 8.2 or newer required)';
        $checks = '<ul class="setup-checks"><li>PHP ' . PHP_VERSION . $phpSupport
            . '</li><li>PDO MySQL: ' . (extension_loaded('pdo_mysql') ? 'available' : 'missing') . '</li><li>cURL: '
            . (extension_loaded('curl') ? 'available' : 'missing') . '</li><li>zlib: '
            . (extension_loaded('zlib') ? 'available' : 'missing') . '</li><li>Config directory: '
            . (is_writable(dirname($this->configPath)) ? 'writable' : 'not writable') . '</li></ul>';
        $healthy = PHP_VERSION_ID >= 80200 && extension_loaded('pdo_mysql')
            && extension_loaded('curl') && extension_loaded('zlib')
            && is_writable(dirname($this->configPath));
        $checks = '<details class="setup-requirements"' . ($healthy ? '' : ' open') . '><summary>'
            . ($healthy ? 'All system checks passed' : 'System checks need attention')
            . '</summary>' . $checks . '</details>';
        $replace = [
            '{{setup_error}}' => $error === '' ? '' : '<p class="setup-error" role="alert">' . $escape($error) . '</p>',
            '{{setup_token}}' => $escape($csrf),
            '{{setup_base_url}}' => $escape($defaults['base_url']),
            '{{setup_checks}}' => $checks,
        ];
        foreach ($defaults as $key => $value) {
            $postedValue = self::scalar($submitted[$key] ?? $value);
            if (in_array($key, ['manage_password', 'database_password'], true)) {
                $postedValue = '';
            }
            $replace['{{' . $key . '}}'] = $escape($postedValue);
        }
        $replace['{{allow_self_signed_checked}}'] = isset($submitted['allow_self_signed']) ? 'checked' : '';
        $scheme = self::scalar($submitted['upstream_scheme'] ?? 'https');
        $replace['{{upstream_scheme_http_checked}}'] = $scheme === 'http' ? 'checked' : '';
        $replace['{{upstream_scheme_https_checked}}'] = $scheme === 'https' ? 'checked' : '';
        echo strtr($template, $replace);
    }

    private function csrf(array $server): string
    {
        session_name('preview_setup');
        session_start([
            'use_strict_mode' => true,
            'cookie_secure' => !empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_path' => '/',
        ]);
        $_SESSION['setup_csrf'] ??= bin2hex(random_bytes(32));
        $token = (string) $_SESSION['setup_csrf'];
        session_write_close();
        return $token;
    }

    private static function assetPath(string $path): bool
    {
        return str_starts_with($path, '/assets/')
            && !str_contains($path, '..')
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1
            && preg_match('~\.(svg|png|jpe?g|webp|ico)$~i', $path) === 1;
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }
}
