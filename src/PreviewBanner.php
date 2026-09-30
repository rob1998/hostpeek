<?php

declare(strict_types=1);

namespace SitePreview;

final class PreviewBanner
{
    public static function inject(string $html, string $hostname, string $ip, array &$headers): string
    {
        // HTML fragments and downloads must retain their original representation.
        if (!preg_match('/<body\b[^>]*>/i', $html) || isset($headers['content-disposition'])) {
            return $html;
        }
        $nonce = base64_encode(random_bytes(18));
        foreach (['content-security-policy', 'content-security-policy-report-only'] as $name) {
            foreach ($headers[$name] ?? [] as $index => $policy) {
                $headers[$name][$index] = self::allowStyle($policy, $nonce);
            }
        }
        $html = preg_replace_callback('/<meta\b[^>]*>/i', static function (array $match) use ($nonce): string {
            if (!preg_match('/http-equiv\s*=\s*(["\'])content-security-policy\1/i', $match[0])) {
                return $match[0];
            }
            $contentPattern = '/\bcontent\s*=\s*(["\'])(.*?)\1/is';
            return preg_replace_callback($contentPattern, static function (array $value) use ($nonce) {
                $policy = html_entity_decode($value[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $policy = htmlspecialchars(self::allowStyle($policy, $nonce), ENT_QUOTES, 'UTF-8');
                return 'content="' . $policy . '"';
            }, $match[0]) ?? $match[0];
        }, $html) ?? $html;
        $hostname = htmlspecialchars($hostname, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ip = htmlspecialchars($ip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $bar = '<aside id="site-preview-notice" aria-label="Website preview">'
            . '<style nonce="' . $nonce . '">'
            . '#site-preview-notice{all:initial!important;box-sizing:border-box!important;display:flex!important;'
            . 'position:sticky!important;top:0!important;z-index:2147483647!important;'
            . 'padding:7px 16px!important;margin:0!important;'
            . 'background:#17212f!important;color:#fff!important;text-align:left!important;'
            . 'font:12px/18px system-ui,sans-serif!important;overflow-wrap:anywhere!important;'
            . 'border-bottom:1px solid #374151!important;color-scheme:dark!important}'
            . '#site-preview-notice{justify-content:space-between!important;align-items:center!important;'
            . 'flex-wrap:wrap!important;gap:4px 24px!important}'
            . '#site-preview-notice>span{all:unset!important;display:block!important;font:inherit!important;'
            . 'color:inherit!important;overflow-wrap:anywhere!important}'
            . '#site-preview-notice>span:last-child{margin-left:auto!important;text-align:right!important}'
            . '@media(max-width:640px){#site-preview-notice>span:last-child{margin-left:0!important;'
            . 'text-align:left!important}}'
            . '</style><span>Preview &middot; ' . $hostname . ' &middot; Destination IP: ' . $ip . '</span>'
            . '<span>Performance might be slower due to preview proxy.</span></aside>';
        return preg_replace_callback('/<body\b[^>]*>/i', static fn(array $match): string => $match[0] . $bar, $html, 1)
            ?? $html;
    }

    private static function allowStyle(string $policy, string $nonce): string
    {
        $directives = array_filter(array_map('trim', explode(';', $policy)));
        $fallback = null;
        $hasStyle = false;
        foreach ($directives as &$directive) {
            [$name] = explode(' ', $directive, 2);
            if ($name === 'default-src') {
                $fallback = substr($directive, strlen('default-src'));
            }
            if (in_array($name, ['style-src', 'style-src-elem'], true)) {
                $hasStyle = $hasStyle || $name === 'style-src';
                if (self::allowsInline($directive)) {
                    continue;
                }
                $directive = str_replace("'none'", '', $directive) . " 'nonce-" . $nonce . "'";
                $hasStyle = $hasStyle || $name === 'style-src';
            }
        }
        unset($directive);
        if (!$hasStyle && $fallback !== null && !self::allowsInline($fallback)) {
            $directives[] = 'style-src' . str_replace("'none'", '', $fallback) . " 'nonce-" . $nonce . "'";
        }
        return implode('; ', $directives);
    }
    private static function allowsInline(string $policy): bool
    {
        return str_contains($policy, "'unsafe-inline'") && !preg_match("/'(?:nonce-|sha(?:256|384|512)-)/", $policy);
    }
}
