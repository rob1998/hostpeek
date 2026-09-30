<?php

declare(strict_types=1);

// Explicit integration check against an installed service. Creates and deletes its own preview.
$config = require getenv('PREVIEW_CONFIG') ?: dirname(__DIR__) . '/config.php';
$base = rtrim($config['base_url'], '/');
$cookie = tempnam(sys_get_temp_dir(), 'preview-check-');
$request = static function (
    string $url,
    string $method = 'GET',
    ?array $data = null,
    string $csrf = '',
) use ($cookie): array {
    $curl = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if ($csrf !== '') {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($data !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    if ($raw === false) {
        throw new RuntimeException('HTTP request failed');
    }
    return [$code, substr($raw, $size), substr($raw, 0, $size)];
};
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
try {
    [$code] = $request($base . '/');
    $check($code === 303, 'Create page requires sign-in');
    [$code] = $request($base . '/help');
    $check($code === 303, 'Help requires sign-in');
    [$code] = $request($base . '/api/config');
    $check($code === 401, 'Config requires sign-in');
    [$code] = $request($base . '/api/previews', 'POST', []);
    $check($code === 401, 'Creation API requires sign-in');
    [$code] = $request($base . '/api/manage/previews');
    $check($code === 401, 'Management must require login');
    [$code, $body] = $request($base . '/api/manage/login', 'POST', ['password' => $config['manage_password']]);
    $check($code === 200, 'Manager login');
    $csrf = json_decode($body, true)['csrf'];
    [$code, $body] = $request($base . '/help');
    $check($code === 200 && str_contains($body, 'Where do I change the settings?'), 'Authenticated help');
    [$code] = $request($base . '/api/previews', 'POST', []);
    $check($code === 403, 'Creation requires CSRF token');
    [$code, $html] = $request($base . '/manage');
    $check($code === 200 && !str_contains($html, 'class="signed-out"'), 'Authenticated layout rendered by server');
    $check(str_contains($html, 'id="login-form" class="login-form" hidden'), 'No sign-in flash');
    [$code, $body] = $request($base . '/api/config');
    $publicConfig = json_decode($body, true);
    $check($code === 200 && $publicConfig['timezone'] === $config['timezone'], 'Configured timezone');
    $localExpiry = (new DateTimeImmutable('@' . (time() + 7200)))
        ->setTimezone(new DateTimeZone($config['timezone']))->format('Y-m-d\TH:i');
    $original = 'http://check-' . bin2hex(random_bytes(4)) . '.example.com/path?a=1#section';
    [$code, $body] = $request($base . '/api/previews', 'POST', [
        'url' => $original,
        'ip' => '8.8.8.8', 'expires_local' => $localExpiry, 'password' => 'integration-test-password',
    ], $csrf);
    $check($code === 201, 'Protected preview creation');
    $created = json_decode($body, true);
    $url = $created['url'];
    $label = explode('.', parse_url($url, PHP_URL_HOST))[0];
    $check(abs(strtotime($created['expiresAt']) - time() - 7200) < 61, 'Custom expiry');
    [$code, $body, $headers] = $request('https://' . parse_url($url, PHP_URL_HOST) . '/robots.txt');
    $check($code === 200 && str_contains($body, 'Disallow: /'), 'Robots disallow');
    $check(str_contains(strtolower($headers), 'x-robots-tag: noindex'), 'No-index header');
    [$code] = $request($url);
    $check($code === 401, 'Protected preview requires password');
    [$code, $body] = $request($base . '/api/manage/previews?search=' . urlencode($url));
    $result = json_decode($body, true);
    $check($code === 200 && $result['total'] === 1, 'Full URL search');
    $item = $result['items'][0];
    $check(
        $item['original_url'] === $original && str_ends_with($url, '/path?a=1#section'),
        'Original URL and preview path',
    );
    $check($item['upstream_scheme'] === 'http', 'Explicit HTTP preserved');
    $check($item['password_protected'] && !isset($item['password_hash']), 'No password hash exposed');
    [$code] = $request($base . '/api/manage/previews/' . $label, 'PATCH', [
        'hostname' => $item['hostname'], 'ip' => $item['ip'], 'expires_at' => $item['expires_at'],
    ]);
    $check($code === 403, 'Mutation requires CSRF token');
    [$code] = $request($base . '/api/manage/previews/' . $label, 'PATCH', [
        'hostname' => $item['hostname'], 'ip' => '1.1.1.1', 'expires_at' => time() + 3600, 'password' => '',
    ], $csrf);
    $check($code === 200, 'Edit target and remove password');
    [$code] = $request($base . '/api/manage/previews/' . $label, 'DELETE', null, $csrf);
    $check($code === 200, 'Delete preview');
    [$code] = $request($url);
    $check($code === (empty($config['not_found_redirect']) ? 410 : 302), 'Deleted preview unavailable');
    $request($base . '/api/manage/logout', 'POST', null, $csrf);
    echo "Live management and protected-preview checks passed.\n";
} finally {
    unlink($cookie);
}
