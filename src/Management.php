<?php

declare(strict_types=1);

namespace SitePreview;

final readonly class Management
{
    public function __construct(private Config $config, private PreviewStore $store)
    {
    }

    public function run(string $path, array $server, string $body): array
    {
        $access = new Access($this->config, $this->store);
        $session = $access->session();
        $method = $server['REQUEST_METHOD'] ?? 'GET';
        $input = json_decode($body, true) ?: [];
        if ($path === '/api/manage/session' && $method === 'GET') {
            return [200, $session + ['timezone' => $this->config->timezone]];
        }
        if ($path === '/api/manage/login' && $method === 'POST') {
            if ($this->config->managePassword === '') {
                return [503, ['error' => 'Set manage_password in config.php to enable management.']];
            }
            if (!$access->attempt('manager', $server)) {
                header('Retry-After: ' . $this->config->loginWindow);
                return [429, ['error' => 'Too many attempts. Try again later.']];
            }
            if (!hash_equals($this->config->managePassword, (string) ($input['password'] ?? ''))) {
                return [401, ['error' => 'Incorrect password.']];
            }
            session_regenerate_id(true);
            $_SESSION['last_activity'] = time();
            $_SESSION['manager'] = hash('sha256', $this->config->managePassword);
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            return [200, ['csrf' => $_SESSION['csrf'], 'timezone' => $this->config->timezone]];
        }
        if (!$session['authenticated']) {
            return [401, ['error' => 'Sign in to manage previews.']];
        }
        if ($method !== 'GET' && !hash_equals($session['csrf'], (string) ($server['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            return [403, ['error' => 'Refresh the page and try again.']];
        }
        if ($path === '/api/manage/expired' && $method === 'DELETE') {
            return [200, ['deleted' => $this->store->deleteExpired()]];
        }
        if ($path === '/api/manage/logout' && $method === 'POST') {
            $_SESSION = [];
            session_destroy();
            return [200, ['ok' => true]];
        }
        if ($path === '/api/manage/previews' && $method === 'GET') {
            parse_str((string) parse_url($server['REQUEST_URI'], PHP_URL_QUERY), $query);
            $search = (string) ($query['search'] ?? '');
            if (preg_match('~^(?:https?://)?([^/]+)~i', $search, $searchMatch)) {
                $searchHost = strtolower((string) parse_url('https://' . $searchMatch[1], PHP_URL_HOST));
                if (str_ends_with($searchHost, '.' . $this->config->baseHost())) {
                    $search = substr($searchHost, 0, -strlen('.' . $this->config->baseHost()));
                }
            }
            $result = $this->store->listPreviews(
                $search,
                (string) ($query['sort'] ?? 'expires_at'),
                (string) ($query['direction'] ?? 'desc'),
                max(1, (int) ($query['page'] ?? 1)),
                $this->config->pageSize,
            );
            foreach ($result['items'] as &$item) {
                $item['expires_local'] = (new Expiration($this->config->timezone))->format((int) $item['expires_at']);
                $target = Target::url($item['original_url'] ?: $item['hostname'], $this->config->upstreamScheme);
                $item['original_url'] = $target['original_url'];
                $item['url'] = rtrim($this->url($item['label']), '/') . $target['uri'];
                $item['password_protected'] = (bool) $item['password_protected'];
            }
            return [200, $result];
        }
        if (!preg_match('~^/api/manage/previews/([a-z0-9-]+)$~D', $path, $match)) {
            return [404, ['error' => 'Not found']];
        }
        $label = $match[1];
        if ($method === 'DELETE') {
            return [$this->store->deletePreview($label) ? 200 : 404, ['ok' => true]];
        }
        if ($method !== 'PATCH') {
            return [405, ['error' => 'Method not allowed']];
        }
        $target = Target::url((string) ($input['url'] ?? $input['hostname'] ?? ''), $this->config->upstreamScheme);
        $hostname = $target['hostname'] ?? null;
        $ip = Target::publicIp((string) ($input['ip'] ?? ''));
        if (isset($input['expires_local'])) {
            try {
                $input['expires_at'] = (new Expiration($this->config->timezone))
                    ->parse((string) $input['expires_local']);
            } catch (\InvalidArgumentException $error) {
                return [400, ['error' => $error->getMessage()]];
            }
        }
        $expires = filter_var($input['expires_at'] ?? null, FILTER_VALIDATE_INT);
        if ($hostname === null || $ip === null || $expires === false || $expires < 1) {
            return [400, ['error' => 'Enter a public hostname or HTTP(S) URL, public IP and valid expiry.']];
        }
        $password = isset($input['password']) ? (string) $input['password'] : null;
        if ($password !== null && strlen($password) > 72) {
            return [400, ['error' => 'Password must be at most 72 bytes.']];
        }
        $hash = $password === null ? null : ($password === '' ? '' : password_hash($password, PASSWORD_DEFAULT));
        $updated = $this->store->updatePreview(
            $label,
            $hostname,
            $ip,
            $expires,
            $hash,
            $target['original_url'],
            $target['scheme'],
            $target['port'],
        );
        return [$updated ? 200 : 404, ['ok' => $updated]];
    }

    private function url(string $label): string
    {
        $port = parse_url($this->config->baseUrl, PHP_URL_PORT);
        return $this->config->baseScheme() . '://' . $label . '.' . $this->config->baseHost()
            . ($port ? ':' . $port : '') . '/';
    }
}
