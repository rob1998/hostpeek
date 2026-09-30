<?php

declare(strict_types=1);

namespace SitePreview;

final readonly class Page
{
    public function __construct(private Config $config)
    {
    }

    public function render(string $file, ?array $session = null, string $assetOrigin = '', string $message = ''): string
    {
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $assetUrl = static function (string $path) use ($assetOrigin): string {
            $path = '/' . ltrim(trim($path), '/');
            if (!str_starts_with($path, '/assets/')) {
                return '';
            }
            return $assetOrigin . implode('/', array_map('rawurlencode', explode('/', rawurldecode($path))));
        };
        $name = $escape($this->config->name);
        $light = $assetUrl($this->config->logoLight ?: $this->config->logoDark);
        $dark = $assetUrl($this->config->logoDark ?: $this->config->logoLight);
        $brand = $name;
        if ($light !== '') {
            $class = $this->config->logoDark === '' ? ' class="light-logo-fallback"' : '';
            $brand = '<picture' . $class . '><source media="(prefers-color-scheme: dark)" srcset="'
                . $escape($dark) . '"><img src="' . $escape($light) . '" alt="' . $name . '"></picture>';
        }
        return strtr((string) file_get_contents($this->config->documentRoot . $file), [
            '{{asset_origin}}' => $escape($assetOrigin),
            '{{message}}' => $escape($message),
            '{{message_hidden}}' => $message === '' ? 'hidden' : '',
            '{{favicon}}' => $escape($assetUrl($this->config->favicon)),
            '{{name}}' => $name,
            '{{brand}}' => $brand,
            '{{manage_body_class}}' => ($session['authenticated'] ?? false) ? '' : 'signed-out',
            '{{login_hidden}}' => ($session['authenticated'] ?? false) ? 'hidden' : '',
            '{{manager_hidden}}' => ($session['authenticated'] ?? false) ? '' : 'hidden',
            '{{account_hidden}}' => ($session['authenticated'] ?? false) ? '' : 'hidden',
        ]);
    }
}
