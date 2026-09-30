<?php

declare(strict_types=1);

namespace SitePreview;

final class UrlRewriter
{
    public static function text(string $text, string $host, string $origin): string
    {
        $quoted = preg_quote($host, '~');
        $text = preg_replace(
            '~(?i)(https?://|//)' . $quoted . '(?::[0-9]+)?([^a-z0-9.:-]|$)~',
            $origin . '$2',
            $text,
        ) ?? $text;
        $escapedOrigin = str_replace('/', '\\/', $origin);
        $pattern = '~(?i)(https?:\\\\/\\\\/|\\\\/\\\\/)'
            . $quoted . '(?::[0-9]+)?([^a-z0-9.:-]|$)~';
        return preg_replace($pattern, $escapedOrigin . '$2', $text) ?? $text;
    }

    public static function estimatedTextLength(string $text, string $host, string $origin): int
    {
        $quoted = preg_quote($host, '~');
        $normalCount = preg_match_all('~(?i)(?:https?://|//)' . $quoted . '(?::[0-9]+)?([^a-z0-9.:-]|$)~', $text);
        $escapedCount = preg_match_all('~(?i)(?:https?:\\\\/\\\\/|\\\\/\\\\/)'
            . $quoted . '(?::[0-9]+)?([^a-z0-9.:-]|$)~', $text);
        $normalDelta = max(0, strlen($origin) - strlen($host)) * (int) $normalCount;
        $escapedOrigin = str_replace('/', '\\/', $origin);
        $escapedDelta = max(0, strlen($escapedOrigin) - strlen($host)) * (int) $escapedCount;
        return strlen($text) + $normalDelta + $escapedDelta;
    }

    public static function location(string $raw, string $host, string $origin): string
    {
        $parts = parse_url($raw);
        if (!is_array($parts) || strtolower($parts['host'] ?? '') !== strtolower($host)) {
            return $raw;
        }
        return $origin . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function cookie(string $value, string $targetHost): ?string
    {
        $parts = explode(';', $value);
        foreach (array_slice($parts, 1) as $index => $part) {
            if (preg_match('/^\s*Domain\s*=\s*([^;]+)/i', $part, $matches)) {
                $domain = strtolower(trim($matches[1], " \t\r\n."));
                if ($domain !== $targetHost && !str_ends_with($targetHost, '.' . $domain)) {
                    return null;
                }
                unset($parts[$index + 1]);
            }
        }
        return implode(';', $parts);
    }

    public static function originHost(string $value): string
    {
        return strtolower((string) (parse_url($value, PHP_URL_HOST) ?: ''));
    }

    public static function sameOrigin(string $origin, string $scheme, string $host, int $port): bool
    {
        $parts = parse_url($origin);
        if (
            !is_array($parts) || strtolower($parts['scheme'] ?? '') !== $scheme
            || strtolower(rtrim($parts['host'] ?? '', '.')) !== $host
        ) {
            return false;
        }
        return (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) === $port;
    }
}
