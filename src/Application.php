<?php

declare(strict_types=1);

namespace SitePreview;

use Throwable;

final readonly class Application
{
    public function __construct(private Config $config, private PreviewStore $store, private Proxy $proxy)
    {
    }

    public function run(array $server, string $body): void
    {
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $requestHost = strtolower(rtrim((string) (preg_replace('/:\d+$/', '', $server['HTTP_HOST'] ?? '') ?? ''), '.'));
        if ($path === '/healthz') {
            http_response_code(204);
            return;
        }
        if ($requestHost === $this->config->baseHost()) {
            $session = null;
            if (in_array($path, ['/', '/index.html', '/manage', '/help', '/api/config', '/api/previews'], true)) {
                $session = (new Access($this->config, $this->store))->session();
                session_write_close();
                if (!$session['authenticated'] && $path !== '/manage') {
                    if (str_starts_with($path, '/api/')) {
                        $this->json(401, ['error' => 'Sign in to create previews.']);
                    } else {
                        header('Cache-Control: no-store');
                        header('Location: /manage?create=1', true, 303);
                    }
                    return;
                }
                if (
                    ($server['REQUEST_METHOD'] ?? 'GET') !== 'GET'
                    && !hash_equals($session['csrf'], (string) ($server['HTTP_X_CSRF_TOKEN'] ?? ''))
                ) {
                    $this->json(403, ['error' => 'Refresh the page and try again.']);
                    return;
                }
            }
            if ($path === '/api/config') {
                $this->json(200, [
                    'name' => $this->config->name,
                    'logo_light' => $this->config->logoLight,
                    'logo_dark' => $this->config->logoDark,
                    'preview_ttl' => $this->config->previewTtl,
                    'timezone' => $this->config->timezone,
                    'default_expires_local' => (new Expiration($this->config->timezone))
                        ->format(time() + $this->config->previewTtl),
                    'csrf' => $session['csrf'],
                ]);
                return;
            }
            if (str_starts_with($path, '/api/manage/')) {
                if (!$this->sameOrigin($server)) {
                    $this->json(403, ['error' => 'Origin not allowed']);
                    return;
                }
                [$status, $result] = (new Management($this->config, $this->store))->run($path, $server, $body);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->json($status, $result);
                return;
            }
            if ($path === '/api/previews' && ($server['REQUEST_METHOD'] ?? '') === 'POST') {
                $this->create($server, $body);
                return;
            }
            if ($this->asset($path, $session)) {
                return;
            }
            if (!str_starts_with($path, '/api/')) {
                $this->notFound();
                return;
            }
            $this->notFound();
            return;
        }
        if (!str_ends_with($requestHost, '.' . $this->config->baseHost())) {
            $this->notFound();
            return;
        }
        $label = substr($requestHost, 0, -strlen('.' . $this->config->baseHost()));
        if ($label === '' || str_contains($label, '.')) {
            $this->notFound();
            return;
        }
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        if ($path === '/robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "User-agent: *\nDisallow: /\n";
            return;
        }
        $preview = $this->store->findPreview($label);
        if ($preview === null || (int) $preview['expires_at'] <= time()) {
            $this->notFound(410);
            return;
        }
        if (!(new Access($this->config, $this->store))->preview($preview, $requestHost, $server, $body)) {
            return;
        }
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            http_response_code(405);
            return;
        }
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        if (!str_starts_with($uri, '/') || str_contains($uri, "\r") || str_contains($uri, "\n")) {
            http_response_code(400);
            return;
        }
        $this->proxy->serve(
            $method,
            $uri,
            $server,
            (string) $preview['hostname'],
            (string) $preview['ip'],
            $requestHost,
            $preview['upstream_scheme'] ?? null,
            isset($preview['upstream_port']) ? (int) $preview['upstream_port'] : null,
        );
    }

    private function create(array $server, string $body): void
    {
        $originAllowed = !isset($server['HTTP_ORIGIN']) || UrlRewriter::sameOrigin(
            $server['HTTP_ORIGIN'],
            $this->config->baseScheme(),
            $this->config->baseHost(),
            $this->config->basePort(),
        );
        if (!$originAllowed) {
            $this->json(403, ['error' => 'origin not allowed']);
            return;
        }
        if (!str_starts_with(strtolower($server['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $this->json(415, ['error' => 'expected application/json']);
            return;
        }
        $input = strlen($body) <= 4096 ? json_decode($body, true) : null;
        if (!is_array($input)) {
            $this->json(400, ['error' => 'invalid request']);
            return;
        }
        $target = Target::url((string) ($input['url'] ?? $input['hostname'] ?? ''), $this->config->upstreamScheme);
        $hostname = $target['hostname'] ?? null;
        $ip = Target::publicIp((string) ($input['ip'] ?? ''));
        if ($hostname === null) {
            $this->json(400, ['error' => 'Enter a public hostname or HTTP(S) URL.']);
            return;
        }
        if ($ip === null) {
            $this->json(400, ['error' => 'enter a public IP address']);
            return;
        }
        $label = Target::label($hostname, $ip, $this->config->hashSecret);
        if (isset($input['expires_local'])) {
            try {
                $input['expires_at'] = (new Expiration($this->config->timezone))
                    ->parse((string) $input['expires_local']);
            } catch (\InvalidArgumentException $error) {
                $this->json(400, ['error' => $error->getMessage()]);
                return;
            }
        }
        $expiresAt = filter_var(
            $input['expires_at'] ?? time() + $this->config->previewTtl,
            FILTER_VALIDATE_INT,
        );
        $password = (string) ($input['password'] ?? '');
        if ($expiresAt === false || $expiresAt <= time() || strlen($password) > 72) {
            $this->json(400, ['error' => 'Choose a future expiry and a password of at most 72 bytes.']);
            return;
        }
        $hash = $password === '' ? '' : password_hash($password, PASSWORD_DEFAULT);
        try {
            if (
                !$this->store->createPreview(
                    $label,
                    $hostname,
                    $ip,
                    $expiresAt,
                    $hash,
                    $target['original_url'],
                    $target['scheme'],
                    $target['port'],
                )
            ) {
                $label = Target::label($hostname, $ip, $this->config->hashSecret . bin2hex(random_bytes(16)));
                if (
                    !$this->store->createPreview(
                        $label,
                        $hostname,
                        $ip,
                        $expiresAt,
                        $hash,
                        $target['original_url'],
                        $target['scheme'],
                        $target['port'],
                    )
                ) {
                    throw new \RuntimeException('Preview label collision');
                }
            }
        } catch (Throwable $error) {
            error_log('Preview creation failed: ' . $error::class);
            $this->json(503, ['error' => 'Could not create preview.']);
            return;
        }
        $port = parse_url($this->config->baseUrl, PHP_URL_PORT);
        $url = $this->config->baseScheme() . '://' . $label . '.' . $this->config->baseHost()
            . ($port ? ':' . $port : '') . $target['uri'];
        $this->json(201, ['url' => $url, 'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt)]);
    }

    private function asset(string $path, ?array $session = null): bool
    {
        $types = [
            '/ui.js' => 'application/javascript; charset=utf-8',
            '/manage.js' => 'application/javascript; charset=utf-8',
            '/app.js' => 'application/javascript; charset=utf-8',
            '/styles.css' => 'text/css; charset=utf-8',
        ];
        if (in_array($path, ['/', '/index.html', '/manage', '/help'], true)) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
            $file = match ($path) {
                '/manage' => '/manage.html',
                '/help' => '/help.html',
                default => '/index.html',
            };
            echo $this->page($file, $session);
            return true;
        }
        if (isset($types[$path]) && is_file($this->config->documentRoot . $path)) {
            header('Content-Type: ' . $types[$path]);
            header('Cache-Control: public, max-age=3600');
            readfile($this->config->documentRoot . $path);
            return true;
        }
        $path = rawurldecode($path);
        if (
            !str_starts_with($path, '/assets/')
            || preg_match('/[\x00-\x1f\x7f]/', $path)
            || preg_match('~\.(svg|png|jpe?g|webp|ico)$~i', $path) !== 1
        ) {
            return false;
        }
        $file = realpath($this->config->documentRoot . $path);
        $assets = realpath($this->config->documentRoot . '/assets');
        if (
            $file === false || $assets === false
            || !str_starts_with($file, $assets . DIRECTORY_SEPARATOR)
            || !is_file($file)
        ) {
            return false;
        }
        $mime = [
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        header('Content-Type: ' . $mime[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
        header('Cache-Control: public, max-age=3600');
        readfile($file);
        return true;
    }

    private function page(string $file, ?array $session = null): string
    {
        return (new Page($this->config))->render($file, $session);
    }

    private function sameOrigin(array $server): bool
    {
        return !isset($server['HTTP_ORIGIN']) || UrlRewriter::sameOrigin(
            $server['HTTP_ORIGIN'],
            $this->config->baseScheme(),
            $this->config->baseHost(),
            $this->config->basePort(),
        );
    }

    private function notFound(int $status = 404): void
    {
        header('Cache-Control: no-store');
        if ($this->config->notFoundLocation() !== '') {
            header('Location: ' . $this->config->notFoundLocation(), true, 302);
            return;
        }
        http_response_code($status);
        echo 'Preview expired or not found';
    }

    private function json(int $status, array $body): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
