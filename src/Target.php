<?php

declare(strict_types=1);

namespace SitePreview;

final class Target
{
    /** @return array{hostname:string,scheme:string,port:?int,original_url:string,uri:string}|null */
    public static function url(string $raw, string $defaultScheme = 'https'): ?array
    {
        if (!in_array($defaultScheme, ['http', 'https'], true) || preg_match('/[\x00-\x1f\x7f\\\\]/', $raw)) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $input = preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $raw) === 1
            ? $raw
            : $defaultScheme . '://' . $raw;
        $parts = parse_url($input);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !isset($parts['host'])) {
            return null;
        }
        $hostname = self::hostname((string) $parts['host']);
        $port = $parts['port'] ?? null;
        if ($hostname === null || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $uri = str_replace(' ', '%20', $path);
        if (isset($parts['query'])) {
            $uri .= '?' . str_replace(' ', '%20', (string) $parts['query']);
        }
        if (isset($parts['fragment'])) {
            $uri .= '#' . str_replace(' ', '%20', (string) $parts['fragment']);
        }
        $authority = $hostname . ($port === null ? '' : ':' . $port);
        $originalUrl = $scheme . '://' . $authority . $uri;
        return [
            'hostname' => $hostname,
            'scheme' => $scheme,
            'port' => $port === null ? null : (int) $port,
            'original_url' => $originalUrl,
            'uri' => $uri,
        ];
    }

    public static function hostname(string $raw): ?string
    {
        $host = strtolower(rtrim(trim($raw), '.'));
        if (
            strlen($host) > 253
            || preg_match(
                '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                $host,
            ) !== 1
            || filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return null;
        }
        foreach (['.localhost', '.local', '.internal', '.test', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return null;
            }
        }
        return $host;
    }

    public static function publicIp(string $raw): ?string
    {
        $ip = trim($raw);
        if (str_contains($ip, '%') || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (str_contains($ip, ':') && self::inCidr($ip, '::ffff:0:0/96')) {
            $ip = inet_ntop(substr((string) inet_pton($ip), 12));
        }
        if (str_contains($ip, ':') && !self::inCidr($ip, '2000::/3')) {
            return null;
        }
        $blocked4 = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
            '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
            '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        ];
        $blocked6 = [
            '::/96', '::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32',
            '2001::/23', '2002::/16', '64:ff9b::/96', '64:ff9b:1::/48',
        ];
        foreach (str_contains($ip, ':') ? $blocked6 : $blocked4 as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return null;
            }
        }
        return inet_ntop((string) inet_pton($ip)) ?: null;
    }

    public static function label(string $hostname, string $ip, string $secret): string
    {
        $labels = explode('.', $hostname);
        $prefix = $labels[0] === 'www' && isset($labels[1]) ? $labels[1] : $labels[0];
        $prefix = trim(substr(preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?? '', 0, 27), '-');
        $hash = substr(hash_hmac('sha256', $hostname . "\n" . $ip, $secret), 0, 16);
        return ($prefix === '' ? 'site' : $prefix) . '-' . $hash;
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $address = inet_pton($ip);
        $net = inet_pton($network);
        if ($address === false || $net === false || strlen($address) !== strlen($net)) {
            return false;
        }
        $bitCount = (int) $bits;
        $bytes = intdiv($bitCount, 8);
        $remainder = $bitCount % 8;
        return substr($address, 0, $bytes) === substr($net, 0, $bytes)
            && ($remainder === 0 || (ord($address[$bytes]) & (0xff << (8 - $remainder)))
                === (ord($net[$bytes]) & (0xff << (8 - $remainder))));
    }
}
