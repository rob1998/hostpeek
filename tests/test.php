<?php

declare(strict_types=1);

use SitePreview\Target;
use SitePreview\UrlRewriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$check(Target::hostname(' Example.COM. ') === 'example.com', 'normalizes a public hostname');
$check(Target::hostname('localhost') === null && Target::hostname('a.localhost') === null, 'rejects local hostnames');
$check(Target::publicIp('8.8.8.8') === '8.8.8.8', 'accepts public IPv4');
$check(Target::publicIp('2001:4860:4860::8888') === '2001:4860:4860::8888', 'accepts public IPv6');
$blockedIps = [
    '127.0.0.1', '10.0.0.1', '169.254.1.1', '100.64.0.1', '192.0.2.1', '::1',
    'fc00::1', 'fe80::1', '64:ff9b::c000:201', '::ffff:192.168.1.1', '::2', '2001:db8::1',
];
foreach ($blockedIps as $ip) {
    $check(Target::publicIp($ip) === null, "blocks non-public {$ip}");
}
$secret = str_repeat('s', 40);
$first = Target::label('example.com', '8.8.8.8', $secret);
$check($first === Target::label('example.com', '8.8.8.8', $secret), 'label is stable');
$check(preg_match('/^example-[a-f0-9]{16}$/', $first) === 1, 'label format includes hostname and keyed hash');
$wwwLabel = Target::label('www.example.com', '8.8.8.8', $secret);
$previewOrigin = 'https://example-hash.preview.example.org';
$check(str_starts_with($wwwLabel, 'example-'), 'omits www from readable label');
$check(Target::label('example.com', '8.8.4.4', $secret) !== $first, 'different IP gets another origin');
$check(
    UrlRewriter::text('https://example.com/a and https://notexample.com/', 'example.com', $previewOrigin)
        === $previewOrigin . '/a and https://notexample.com/',
    'rewrites only the exact hostname',
);
$check(
    UrlRewriter::estimatedTextLength('https://a.co/', 'a.co', $previewOrigin) > strlen('https://a.co/'),
    'estimates the memory needed for hostname expansion',
);
$check(
    UrlRewriter::text('https:\\/\\/example.com\\/a', 'example.com', $previewOrigin)
        === str_replace('/', '\\/', $previewOrigin) . '\\/a',
    'rewrites escaped JSON URLs',
);
$check(
    UrlRewriter::location('https://example.com/login?q=1', 'example.com', 'https://example-hash.preview.example.org')
        === 'https://example-hash.preview.example.org/login?q=1',
    'rewrites redirects to target',
);
$check(
    UrlRewriter::location('https://other.nl/login', 'example.com', 'https://preview.example.org')
        === 'https://other.nl/login',
    'preserves external redirects',
);
$check(
    UrlRewriter::cookie('sid=abc; Domain=.example.com; Path=/', 'example.com') === 'sid=abc; Path=/',
    'scopes target cookie to preview origin',
);
$check(UrlRewriter::cookie('sid=abc; Domain=other.nl', 'example.com') === null, 'drops unrelated domain cookies');
$check(
    UrlRewriter::sameOrigin('https://preview.example.org', 'https', 'preview.example.org', 443),
    'accepts configured origin',
);
$check(
    !UrlRewriter::sameOrigin('https://preview.example.org.evil', 'https', 'preview.example.org', 443),
    'rejects lookalike origin',
);
$check(
    !UrlRewriter::sameOrigin('https://preview.example.org:8443', 'https', 'preview.example.org', 443),
    'rejects foreign origin port',
);
$directory = sys_get_temp_dir() . '/preview-assets-' . bin2hex(random_bytes(6));
mkdir($directory . '/assets', 0700, true);
file_put_contents($directory . '/assets/Logo café + #1.svg', '<svg/>');
file_put_contents($directory . '/private.svg', 'private');
symlink($directory . '/private.svg', $directory . '/assets/link.svg');
$values = require dirname(__DIR__) . '/config.example.php';
$values['hash_secret'] = $secret;
$values['favicon'] = '/assets/Logo café + #1.svg';
$values['logo_light'] = '/assets/Logo café + #1.svg';
$values['name'] = 'Preview <test>';
$config = \SitePreview\Config::fromArray($values, $directory);
$store = (new ReflectionClass(\SitePreview\PreviewStore::class))->newInstanceWithoutConstructor();
$app = new \SitePreview\Application($config, $store, new \SitePreview\Proxy($config));
$asset = new ReflectionMethod($app, 'asset');
ob_start();
$check($asset->invoke($app, '/assets/Logo%20caf%C3%A9%20%2B%20%231.svg'), 'serves encoded logo filename');
$check(ob_get_clean() === '<svg/>', 'returns correct logo');
foreach (['/assets/../private.svg', '/assets/%2e%2e/private.svg', '/assets/link.svg', '/assets/%00.svg'] as $path) {
    $check(!$asset->invoke($app, $path), 'confines logo files to assets');
}
file_put_contents(
    $directory . '/manage.html',
    '<title>{{name}}</title>{{brand}}<link rel="icon" href="{{favicon}}">',
);
ob_start();
$check($asset->invoke($app, '/manage'), 'renders signed-out branding');
$html = ob_get_clean();
$check(str_contains($html, '<title>Preview &lt;test&gt;</title>'), 'escapes service name in page title');
$check(str_contains($html, '/assets/Logo%20caf%C3%A9%20%2B%20%231.svg'), 'renders encoded logo before login');
$check(
    str_contains($html, 'rel="icon" href="/assets/Logo%20caf%C3%A9%20%2B%20%231.svg"'),
    'renders configured favicon',
);
copy(dirname(__DIR__) . '/public/protected.html', $directory . '/protected.html');
ob_start();
$locked = (new \SitePreview\Access($config, $store))->preview(
    ['hostname' => 'example.com', 'ip' => '8.8.8.8', 'password_hash' => 'hash', 'expires_at' => time() + 3600],
    'example.preview.example.org',
    ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
    '',
);
$protectedHtml = ob_get_clean();
$check(!$locked && http_response_code() === 401, 'branded page still requires preview authentication');
$check(str_contains($protectedHtml, 'Protected preview · Preview &lt;test&gt;'), 'protected page uses escaped name');
$check(
    str_contains($protectedHtml, $config->origin() . '/assets/Logo%20caf%C3%A9%20%2B%20%231.svg'),
    'protected branding assets load from the service origin before unlock',
);
$check(str_contains($protectedHtml, $config->origin() . '/styles.css?v=14'), 'protected page shares theme styles');
$check(!str_contains($protectedHtml, '{{'), 'protected page has no unresolved placeholders');
unlink($directory . '/protected.html');
unlink($directory . '/manage.html');
unlink($directory . '/assets/link.svg');
unlink($directory . '/assets/Logo café + #1.svg');
unlink($directory . '/private.svg');
rmdir($directory . '/assets');
rmdir($directory);
$access = new \SitePreview\Access($config, $store);
$signature = new ReflectionMethod($access, 'signature');
$record = ['hostname' => 'example.com', 'ip' => '8.8.8.8', 'password_hash' => 'hash', 'expires_at' => 1234];
$token = $signature->invoke($access, $record, 'example.preview.example.org');
$record['ip'] = '1.1.1.1';
$check($token !== $signature->invoke($access, $record, 'example.preview.example.org'), 'Target edit revokes unlock');
$reservedCookie = new ReflectionMethod(\SitePreview\Proxy::class, 'reservedCookie');
$check($reservedCookie->invoke(null, '__Host-preview_access=secret'), 'App cookie is withheld');
$check(!$reservedCookie->invoke(null, 'PHPSESSID=site-session'), 'Target PHP session survives');
$headers = ['content-security-policy' => ["default-src 'none'; script-src 'self'; style-src 'self'"]];
$html = \SitePreview\PreviewBanner::inject(
    '<html><body><h1>Site</h1></body></html>',
    'example.com',
    '1.1.1.1',
    $headers,
);
$check(str_contains($html, 'Destination IP: 1.1.1.1'), 'Banner identifies destination');
$check(substr_count($html, 'id="site-preview-notice"') === 1, 'Single banner injected');
$check(str_contains($headers['content-security-policy'][0], "script-src 'self'"), 'Script policy preserved');
$check(str_contains($headers['content-security-policy'][0], "'nonce-"), 'Banner style explicitly authorized');
$fragment = '<div>Fragment</div>';
$check(
    \SitePreview\PreviewBanner::inject($fragment, 'example.com', '1.1.1.1', $headers) === $fragment,
    'Fragments unchanged',
);
$inlineHeaders = ['content-security-policy' => ["default-src 'self'; style-src 'unsafe-inline'"]];
\SitePreview\PreviewBanner::inject('<body>Site</body>', 'example.com', '1.1.1.1', $inlineHeaders);
$check(!str_contains($inlineHeaders['content-security-policy'][0], 'nonce-'), 'Existing inline styles stay allowed');
$expiration = new \SitePreview\Expiration('Europe/Amsterdam');
$check($expiration->parse('2026-07-01T12:00') === strtotime('2026-07-01T10:00:00Z'), 'Summer timezone offset');
$check($expiration->parse('2026-12-01T12:00') === strtotime('2026-12-01T11:00:00Z'), 'Winter timezone offset');
$check($expiration->format(strtotime('2026-07-01T10:00:00Z')) === '2026-07-01T12:00', 'Server-local display');
try {
    $expiration->parse('2026-03-29T02:30');
    $check(false, 'Nonexistent DST time rejected');
} catch (InvalidArgumentException) {
    // Expected: this local time is skipped by the DST transition.
}
$values['not_found_redirect'] = '/';
$check(
    \SitePreview\Config::fromArray($values, $directory)->notFoundLocation() === 'https://preview.example.org/',
    'Relative missing-page redirect uses base origin',
);
$values['not_found_redirect'] = "https://example.com/\r\nX-Test: bad";
try {
    \SitePreview\Config::fromArray($values, $directory);
    $check(false, 'Redirect header injection rejected');
} catch (InvalidArgumentException) {
    // Invalid configuration must never reach a Location header.
}
echo "PHP preview checks passed\n";
