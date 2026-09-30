<?php

declare(strict_types=1);

namespace SitePreview;

final readonly class Access
{
    public function __construct(private Config $config, private PreviewStore $store)
    {
    }

    public function session(): array
    {
        session_name($this->config->baseScheme() === 'https' ? '__Host-preview_manage' : 'preview_manage');
        session_start([
            'use_strict_mode' => true,
            'cookie_secure' => $this->config->baseScheme() === 'https',
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'cookie_path' => '/',
            'cookie_domain' => '',
        ]);
        $idleTimeout = (int) ini_get('session.gc_maxlifetime');
        if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] >= $idleTimeout) {
            $_SESSION = [];
        }
        $fingerprint = hash('sha256', $this->config->managePassword);
        $authenticated = $this->config->managePassword !== ''
            && hash_equals($fingerprint, (string) ($_SESSION['manager'] ?? ''));
        if ($authenticated) {
            $_SESSION['last_activity'] = time();
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        return ['authenticated' => $authenticated, 'csrf' => $_SESSION['csrf'], 'idle_timeout' => $idleTimeout];
    }

    public function attempt(string $scope, array $server): bool
    {
        return $this->store->allowLoginAttempt(
            $scope,
            (string) ($server['REMOTE_ADDR'] ?? ''),
            $this->config->loginAttempts,
            $this->config->loginWindow,
        );
    }

    public function preview(array $preview, string $host, array $server, string $body): bool
    {
        if ($preview['password_hash'] === '') {
            return true;
        }
        $cookieName = $this->config->baseScheme() === 'https' ? '__Host-preview_access' : 'preview_access';
        $signature = $this->signature($preview, $host);
        if (hash_equals($signature, (string) ($_COOKIE[$cookieName] ?? ''))) {
            return true;
        }
        $message = '';
        if (($server['REQUEST_METHOD'] ?? '') === 'POST' && ($server['REQUEST_URI'] ?? '') === '/__preview/unlock') {
            parse_str($body, $input);
            if (!$this->attempt('preview:' . substr(hash('sha256', $host), 0, 32), $server)) {
                http_response_code(429);
                header('Retry-After: ' . $this->config->loginWindow);
                $message = 'Too many attempts. Try again later.';
            } elseif (password_verify((string) ($input['password'] ?? ''), $preview['password_hash'])) {
                setcookie($cookieName, $signature, [
                    'expires' => (int) $preview['expires_at'],
                    'path' => '/',
                    'secure' => $this->config->baseScheme() === 'https',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                header('Location: /', true, 303);
                return false;
            } else {
                http_response_code(401);
                $message = 'Incorrect password.';
            }
        } else {
            http_response_code(401);
        }
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        $origin = $this->config->origin();
        header("Content-Security-Policy: default-src 'none'; style-src {$origin}; img-src {$origin}; "
            . "form-action 'self'; base-uri 'none'");
        echo (new Page($this->config))->render('/protected.html', assetOrigin: $origin, message: $message);
        return false;
    }
    private function signature(array $preview, string $host): string
    {
        return hash_hmac(
            'sha256',
            implode("\n", [$host, $preview['hostname'], $preview['ip'],
                $preview['password_hash'], $preview['expires_at'],
                $preview['upstream_scheme'] ?? '', $preview['upstream_port'] ?? '']),
            $this->config->hashSecret,
        );
    }
}
