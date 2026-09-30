<?php

declare(strict_types=1);

use SitePreview\PreviewStore;

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require getenv('PREVIEW_CONFIG') ?: dirname(__DIR__) . '/config.php';
$store = new PreviewStore($config['database']);
// A connection-local temporary table keeps destructive checks away from real previews.
$pdo = (new ReflectionProperty($store, 'pdo'))->getValue($store);
$schema = explode(';', file_get_contents(dirname(__DIR__) . '/database/schema.sql'))[0];
$pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $schema));
$prefix = 'test-' . bin2hex(random_bytes(6));
$labels = [];
$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
};
try {
    for ($i = 0; $i < 27; $i++) {
        $label = $prefix . '-' . $i;
        $labels[] = $label;
        $check($store->createPreview(
            $label,
            'example.com',
            '8.8.8.8',
            time() + 3600,
            'test-hash',
            'http://example.com/catalog?q=one',
            'http',
            8080,
        ), 'Create');
    }
    $check(!$store->createPreview($labels[0], 'other.com', '1.1.1.1', time() + 7200, ''), 'Active route immutable');
    $row = $store->findPreview($labels[0]);
    $check(
        $row['password_hash'] === 'test-hash'
            && $row['hostname'] === 'example.com'
            && $row['original_url'] === 'http://example.com/catalog?q=one'
            && $row['upstream_scheme'] === 'http'
            && (int) $row['upstream_port'] === 8080,
        'URL target details persist',
    );
    $first = $store->listPreviews($prefix, 'original_url', 'asc', 1);
    $second = $store->listPreviews($prefix, 'original_url', 'asc', 2);
    $check($first['total'] === 27 && count($first['items']) === 25 && count($second['items']) === 2, 'Pagination');
    $check($store->listPreviews($prefix . '%', 'label', 'asc', 1)['total'] === 0, 'Search escapes wildcards');
    $check($store->listPreviews('catalog?q=one', 'label', 'asc', 1)['total'] === 27, 'Search includes original URL');
    $check($store->updatePreview(
        $labels[0],
        'example.com',
        '8.8.8.8',
        (int) $row['expires_at'],
        null,
        'https://example.com/new',
        'https',
        null,
    ), 'Edit URL target');
    $updated = $store->findPreview($labels[0]);
    $check(
        $updated['original_url'] === 'https://example.com/new' && $updated['upstream_scheme'] === 'https',
        'Updated URL target persists',
    );
    $check($store->allowLoginAttempt($prefix, '127.0.0.1', 2, 300), 'First attempt');
    $check($store->allowLoginAttempt($prefix, '127.0.0.1', 2, 300), 'Second attempt');
    $check(!$store->allowLoginAttempt($prefix, '127.0.0.1', 2, 300), 'Login throttled');
    $store->createPreview($prefix . '-expired', 'example.com', '8.8.8.8', time() - 1);
    $store->createPreview($prefix . '-boundary', 'example.com', '8.8.8.8', time());
    $check($store->listPreviews('no-match', 'label', 'asc', 1)['expiredCount'] === 2, 'Expired count ignores search');
    $check($store->deleteExpired() === 2, 'Deletes expired including boundary');
    $check($store->findPreview($labels[0]) !== null, 'Active previews preserved');
    $check($store->deleteExpired() === 0, 'Repeated cleanup is harmless');
    echo "Database CRUD, pagination and login rate-limit checks passed.\n";
} finally {
    foreach ($labels as $label) {
        $store->deletePreview($label);
    }
}
