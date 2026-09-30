<?php

declare(strict_types=1);

namespace SitePreview;

final readonly class Config
{
    public function __construct(
        public string $name,
        public string $logoLight,
        public string $logoDark,
        public string $favicon,
        public string $baseUrl,
        public array $database,
        public string $hashSecret,
        public bool $allowSelfSigned,
        public string $upstreamScheme,
        public int $previewTtl,
        public string $timezone,
        public string $notFoundRedirect,
        public string $managePassword,
        public int $loginAttempts,
        public int $loginWindow,
        public int $pageSize,
        public string $documentRoot,
    ) {
        new \DateTimeZone($timezone);
        if ($notFoundRedirect !== '') {
            $relative = str_starts_with($notFoundRedirect, '/') && !str_starts_with($notFoundRedirect, '//');
            $destination = parse_url($notFoundRedirect);
            $absolute = is_array($destination) && isset($destination['host'])
                && in_array($destination['scheme'] ?? '', ['http', 'https'], true)
                && !isset($destination['user']);
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $notFoundRedirect) || (!$relative && !$absolute)) {
                throw new \InvalidArgumentException('not_found_redirect must be an HTTP(S) URL or root-relative path');
            }
        }
        $base = parse_url($baseUrl);
        $host = strtolower(rtrim((string) ($base['host'] ?? ''), '.'));
        if (
            !is_array($base)
            || !in_array($base['scheme'] ?? '', ['http', 'https'], true)
            || $host === ''
            || isset($base['user'])
            || isset($base['query'])
            || isset($base['fragment'])
            || (isset($base['path']) && !in_array($base['path'], ['', '/'], true))
            || (isset($base['port']) && ($base['port'] < 1 || $base['port'] > 65535))
            || filter_var($host, FILTER_VALIDATE_IP)
            || preg_match(
                '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+'
                    . '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
                $host,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid base_url');
        }
        if (strlen($hashSecret) < 32) {
            throw new \InvalidArgumentException('hash_secret must contain at least 32 characters');
        }
        if (!in_array($upstreamScheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Invalid upstream_scheme');
        }
        foreach (['host', 'name', 'user', 'password'] as $key) {
            if (!isset($database[$key]) || !is_string($database[$key])) {
                throw new \InvalidArgumentException('database.' . $key . ' is required');
            }
        }
        if ($database['host'] === '' || $database['name'] === '' || $database['user'] === '') {
            throw new \InvalidArgumentException('database host, name and user are required');
        }
    }

    public static function fromArray(array $values, string $documentRoot): self
    {
        return new self(
            (string) ($values['name'] ?? 'Hostpeek'),
            (string) ($values['logo_light'] ?? ''),
            (string) ($values['logo_dark'] ?? ''),
            (string) ($values['favicon'] ?? '/assets/favicon.svg'),
            (string) ($values['base_url'] ?? ''),
            is_array($values['database'] ?? null) ? $values['database'] : [],
            (string) ($values['hash_secret'] ?? ''),
            (bool) ($values['allow_self_signed'] ?? false),
            (string) ($values['upstream_scheme'] ?? 'https'),
            max(1, (int) ($values['preview_ttl'] ?? 3600)),
            (string) ($values['timezone'] ?? 'UTC'),
            (string) ($values['not_found_redirect'] ?? '/'),
            (string) ($values['manage_password'] ?? ''),
            max(1, (int) ($values['login_attempts'] ?? 5)),
            max(1, (int) ($values['login_window'] ?? 300)),
            max(1, (int) ($values['page_size'] ?? 25)),
            $documentRoot,
        );
    }

    public function notFoundLocation(): string
    {
        return str_starts_with($this->notFoundRedirect, '/')
            ? $this->origin() . $this->notFoundRedirect : $this->notFoundRedirect;
    }

    public function baseHost(): string
    {
        return strtolower((string) parse_url($this->baseUrl, PHP_URL_HOST));
    }

    public function baseScheme(): string
    {
        return strtolower((string) parse_url($this->baseUrl, PHP_URL_SCHEME));
    }

    public function basePort(): int
    {
        return (int) (parse_url($this->baseUrl, PHP_URL_PORT) ?: ($this->baseScheme() === 'https' ? 443 : 80));
    }

    public function origin(): string
    {
        $port = parse_url($this->baseUrl, PHP_URL_PORT);
        return $this->baseScheme() . '://' . $this->baseHost() . ($port ? ':' . $port : '');
    }
}
