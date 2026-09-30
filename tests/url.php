<?php

declare(strict_types=1);

use SitePreview\Target;

require dirname(__DIR__) . '/vendor/autoload.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$check(
    Target::url('example.com/catalog?q=summer sale#details', 'https') === [
        'hostname' => 'example.com',
        'scheme' => 'https',
        'port' => null,
        'original_url' => 'https://example.com/catalog?q=summer%20sale#details',
        'uri' => '/catalog?q=summer%20sale#details',
    ],
    'Bare domain uses default scheme and preserves encoded path, query, and fragment',
);
$check(
    Target::url('http://www.example.com:8080/a b', 'https') === [
        'hostname' => 'www.example.com',
        'scheme' => 'http',
        'port' => 8080,
        'original_url' => 'http://www.example.com:8080/a%20b',
        'uri' => '/a%20b',
    ],
    'Explicit HTTP and port are retained',
);
$defaultUrl = Target::url('example.com');
$check($defaultUrl !== null && $defaultUrl['scheme'] === 'https', 'Defaults to HTTPS');
$invalidUrls = [
    '', 'ftp://example.com', 'https://user@example.com', 'https://example.com:0',
    'https://example.com:65536', 'https://localhost', '//example.com', "https://example.com/\nheader",
    'https://example.com/a\\b',
];
foreach ($invalidUrls as $invalid) {
    $check(Target::url($invalid) === null, 'Rejects invalid URL: ' . json_encode($invalid));
}
$check(
    \SitePreview\UrlRewriter::text('http://example.com:8080/a', 'example.com', 'https://preview.example.org')
        === 'https://preview.example.org/a',
    'rewrites explicit target port',
);

echo "URL parsing checks passed\n";
