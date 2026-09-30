<?php

declare(strict_types=1);

return [
    // Service name, browser tab title and text fallback when no logo is configured.
    'name' => 'Hostpeek',
    // Local SVG, PNG or ICO file under public/assets, also shown before sign-in.
    'favicon' => '/assets/favicon.svg',
    // Local files under public/assets. Spaces and Unicode are supported.
    // Empty dark logo reuses the light logo; both empty displays the name.
    'logo_light' => '',
    'logo_dark' => '',
    // Public origin, without a path. A subdomain or dedicated domain both work.
    // DNS, cPanel document roots and TLS must cover this host and *.this-host.
    'base_url' => 'https://preview.example.org',
    // Generate once: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Keep this private and stable: changing it changes generated preview URLs.
    'hash_secret' => '',
    // Skip target HTTPS certificate verification for self-signed, expired or mismatched certificates.
    // false verifies the target certificate; true accepts it. Preview-domain TLS is unaffected.
    'allow_self_signed' => false,
    // Default connection for bare hostnames: https (443) or http (80).
    // A pasted HTTP(S) URL selects the protocol for that preview.
    'upstream_scheme' => 'https',
    // Sets the initial expiration to now plus this many seconds.
    // When signed in, choose a different expiration date/time under Advanced.
    'preview_ttl' => 3600,
    // IANA timezone for PHP and all date/time inputs and displays.
    // Database expiry values are timezone-independent Unix timestamps.
    'timezone' => 'Europe/Amsterdam',
    // Unknown/expired previews and missing pages redirect here (HTTP 302).
    // Relative paths resolve against base_url. Empty string keeps 404/410.
    'not_found_redirect' => '/',
    // Password required to create and manage previews.
    // Empty disables access to these functions until a password is configured.
    'manage_password' => '',
    // Login attempts per client IP and scope (manager or protected preview).
    // These apply only to password submissions, never ordinary page/asset requests.
    'login_attempts' => 5,
    'login_window' => 300, // Window in seconds.
    // Rows per management page. Pagination is hidden when all results fit.
    'page_size' => 25,
    // Dedicated cPanel MariaDB database/user, including the account prefix.
    'database' => [
        'host' => 'localhost', // Usually localhost on cPanel.
        'port' => 3306, // MariaDB TCP port.
        'name' => 'account_preview',
        'user' => 'account_preview',
        'password' => '',
    ],
];
