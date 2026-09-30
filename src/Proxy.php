<?php

declare(strict_types=1);

namespace SitePreview;

final readonly class Proxy
{
    public function __construct(private Config $config)
    {
    }

    public function serve(
        string $method,
        string $uri,
        array $server,
        string $targetHost,
        string $targetIp,
        string $requestHost,
        ?string $targetScheme = null,
        ?int $targetPort = null,
        int $redirectDepth = 0,
        array $redirectCookies = [],
    ): void {
        $scheme = $targetScheme ?? $this->config->upstreamScheme;
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->error(400, 'Invalid target scheme');
            return;
        }
        $port = $targetPort ?? ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            $this->error(400, 'Invalid target port');
            return;
        }
        $authority = $targetHost . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port);
        $target = $scheme . '://' . $targetHost . ':' . $port . $uri;
        $previewOrigin = $this->config->baseScheme() . '://' . $requestHost;
        $curl = curl_init($target);
        $headers = [];
        $received = 0;
        $timeout = max(0, (int) ini_get('default_socket_timeout'));
        $body = null;
        $streaming = false;
        $responseSent = false;
        $discardBody = false;
        $followTarget = null;
        if ($curl === false) {
            $this->error(502, 'Target server could not be reached');
            return;
        }
        $forwardHeaders = [];
        $forwardable = [
            'HTTP_ACCEPT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_USER_AGENT', 'HTTP_COOKIE',
            'HTTP_RANGE', 'HTTP_IF_NONE_MATCH', 'HTTP_IF_MODIFIED_SINCE',
        ];
        foreach ($forwardable as $key) {
            if (isset($server[$key])) {
                if ($key === 'HTTP_COOKIE') {
                    $server[$key] = implode('; ', array_filter(
                        explode(';', $server[$key]),
                        static fn(string $cookie): bool => !self::reservedCookie($cookie),
                    ));
                }
                $forwardHeaders[] = str_replace('_', '-', substr($key, 5)) . ': ' . $server[$key];
            }
        }
        $forwardHeaders[] = 'Accept-Encoding: gzip';
        if (isset($server['HTTP_ORIGIN']) && UrlRewriter::originHost($server['HTTP_ORIGIN']) === $requestHost) {
            $forwardHeaders[] = 'Origin: ' . $scheme . '://' . $authority;
        }
        if (isset($server['HTTP_REFERER']) && UrlRewriter::originHost($server['HTTP_REFERER']) === $requestHost) {
            $path = parse_url($server['HTTP_REFERER'], PHP_URL_PATH) ?: '/';
            $query = parse_url($server['HTTP_REFERER'], PHP_URL_QUERY);
            $forwardHeaders[] = 'Referer: ' . $scheme . '://' . $authority . $path . ($query ? '?' . $query : '');
        }
        foreach ($redirectCookies as $cookieValue) {
            $cookie = UrlRewriter::cookie($cookieValue, $targetHost);
            if ($cookie !== null && !self::reservedCookie($cookie)) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        $allowSelfSigned = $this->config->allowSelfSigned;
        $options = [
            CURLOPT_RESOLVE => [
                $targetHost . ':' . $port . ':' . (str_contains($targetIp, ':') ? '[' . $targetIp . ']' : $targetIp),
            ],
            CURLOPT_PROXY => '',
            CURLOPT_HTTPHEADER => $forwardHeaders,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => !$allowSelfSigned,
            CURLOPT_SSL_VERIFYHOST => $allowSelfSigned ? 0 : 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $line, $matches)) {
                    $headers = [':status' => (int) $matches[1]];
                } elseif (trim($line) !== '' && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))][] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function (
                $handle,
                string $chunk,
            ) use (
                &$received,
                &$headers,
                &$body,
                &$streaming,
                &$responseSent,
                &$discardBody,
                &$followTarget,
                $method,
                $targetHost,
                $scheme,
                $port,
                $redirectDepth,
                $uri,
                $previewOrigin,
            ): int {
                $length = strlen($chunk);
                $received += $length;
                if ($discardBody) {
                    return $length;
                }
                if ($method === 'HEAD') {
                    return $length;
                }
                if (!$streaming && $body === null) {
                    $status = (int) ($headers[':status'] ?? 502);
                    if (isset($headers['location'][0])) {
                        $followTarget = self::schemeRedirect(
                            $headers['location'][0],
                            $targetHost,
                            $scheme,
                            $port,
                            $uri,
                            $status,
                        );
                        if ($followTarget !== null) {
                            $discardBody = true;
                            return $length;
                        }
                    }
                    if (in_array($status, [404, 410], true) && $this->config->notFoundLocation() !== '') {
                        header('Cache-Control: private, no-store');
                        header('Location: ' . $this->config->notFoundLocation(), true, 302);
                        $responseSent = true;
                        $discardBody = true;
                        return $length;
                    }
                    $type = strtolower(explode(';', $headers['content-type'][0] ?? '')[0]);
                    $streaming = !in_array($type, [
                        'text/html', 'text/css', 'application/javascript', 'text/javascript',
                        'application/json', 'application/manifest+json',
                    ], true);
                    if ($streaming) {
                        self::sendHeaders($headers, $targetHost, $previewOrigin, false, true);
                        $responseSent = true;
                    } else {
                        $body = fopen('php://temp/maxmemory:1048576', 'w+b');
                    }
                }
                if ($streaming) {
                    echo $chunk;
                    flush();
                    return $length;
                }
                return is_resource($body) ? (fwrite($body, $chunk) ?: 0) : 0;
            },
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        if ($method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        curl_close($curl);
        if ($result === false || !isset($headers[':status'])) {
            if (!$responseSent) {
                $this->error(502, 'Target server could not be reached');
            }
            if (is_resource($body)) {
                fclose($body);
            }
            return;
        }
        if ($followTarget === null && isset($headers['location'][0])) {
            $followTarget = self::schemeRedirect(
                $headers['location'][0],
                $targetHost,
                $scheme,
                $port,
                $uri,
                (int) $headers[':status'],
            );
        }
        if ($followTarget !== null) {
            if ($redirectDepth >= 5) {
                $this->error(502, 'Target redirect limit exceeded');
                return;
            }
            $redirectCookies = [...$redirectCookies, ...($headers['set-cookie'] ?? [])];
            $this->serve(
                $method,
                $followTarget['uri'],
                $server,
                $targetHost,
                $targetIp,
                $requestHost,
                $followTarget['scheme'],
                $followTarget['port'],
                $redirectDepth + 1,
                $redirectCookies,
            );
            return;
        }
        if (
            !$responseSent
            && in_array($headers[':status'], [404, 410], true)
            && $this->config->notFoundLocation() !== ''
        ) {
            header('Cache-Control: private, no-store');
            header('Location: ' . $this->config->notFoundLocation(), true, 302);
            return;
        }
        $head = $method === 'HEAD';
        $type = strtolower(explode(';', $headers['content-type'][0] ?? '')[0]);
        $textTypes = [
            'text/html', 'text/css', 'application/javascript', 'text/javascript',
            'application/json', 'application/manifest+json',
        ];
        $isText = in_array($type, $textTypes, true);
        $rewrittenBody = null;
        if (!$head && !$streaming && is_resource($body)) {
            rewind($body);
            $contents = stream_get_contents($body);
            if ($contents === false) {
                $this->error(502, 'Could not read target response');
                fclose($body);
                return;
            }
            $contents = UrlRewriter::text($contents, $targetHost, $previewOrigin);
            if ($type === 'text/html' && ($headers[':status'] ?? 200) !== 206) {
                $contents = PreviewBanner::inject($contents, $targetHost, $targetIp, $headers);
            }
            fclose($body);
            $body = null;
            $rewrittenBody = $contents;
        }
        if (!$responseSent) {
            self::sendHeaders($headers, $targetHost, $previewOrigin, $head, !$isText);
        }
        if ($head && isset($headers['content-length'][0])) {
            header('Content-Length: ' . $headers['content-length'][0]);
        } elseif (!$head && is_string($rewrittenBody)) {
            header('Content-Length: ' . strlen($rewrittenBody));
            echo $rewrittenBody;
        } elseif (!$head && !$streaming && is_resource($body)) {
            header('Content-Length: ' . $received);
            rewind($body);
            fpassthru($body);
        }
        if (is_resource($body)) {
            fclose($body);
        }
    }

    private static function sendHeaders(
        array $headers,
        string $targetHost,
        string $origin,
        bool $head,
        bool $revalidate = false,
    ): void {
        $skip = [
            'connection', 'proxy-connection', 'keep-alive', 'transfer-encoding', 'upgrade',
            'location', 'set-cookie', 'content-security-policy', 'content-security-policy-report-only',
            'link', 'refresh', 'x-robots-tag',
        ];
        foreach ($headers as $name => $values) {
            $dropBodyHeaders = !$head && in_array($name, ['content-length', 'content-encoding'], true);
            if (
                $name[0] === ':'
                || in_array($name, $skip, true)
                || ($name === 'etag' && !$revalidate)
                || $dropBodyHeaders
            ) {
                continue;
            }
            foreach ($values as $value) {
                header($name . ': ' . $value, false);
            }
        }
        header($revalidate ? 'Cache-Control: private, no-cache' : 'Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        foreach ($headers['location'] ?? [] as $value) {
            header('Location: ' . UrlRewriter::location($value, $targetHost, $origin), false);
        }
        foreach ($headers['set-cookie'] ?? [] as $value) {
            $cookie = UrlRewriter::cookie($value, $targetHost);
            if ($cookie !== null && !self::reservedCookie($cookie)) {
                header('Set-Cookie: ' . $cookie, false);
            }
        }
        foreach (['content-security-policy', 'content-security-policy-report-only', 'link', 'refresh'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                header($name . ': ' . UrlRewriter::text($value, $targetHost, $origin), false);
            }
        }
        http_response_code($headers[':status'] ?? 502);
    }

    private static function reservedCookie(string $cookie): bool
    {
        return in_array(trim(explode('=', $cookie, 2)[0]), [
            '__Host-preview_access', 'preview_access', '__Host-preview_manage', 'preview_manage',
        ], true);
    }

    private static function schemeRedirect(
        string $location,
        string $targetHost,
        string $scheme,
        int $port,
        string $requestedUri,
        int $status,
    ): ?array {
        $parts = parse_url($location);
        if (
            !is_array($parts)
            || !in_array($status, [301, 302, 303, 307, 308], true)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || strtolower(rtrim($parts['host'], '.')) !== $targetHost
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }
        $nextScheme = strtolower($parts['scheme']);
        $nextPort = (int) ($parts['port'] ?? ($nextScheme === 'https' ? 443 : 80));
        $path = $parts['path'] ?? '/';
        $uri = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (
            ($nextScheme === $scheme && $nextPort === $port)
            || $uri !== $requestedUri
            || $nextPort < 1
            || $nextPort > 65535
            || !str_starts_with($path, '/')
            || preg_match('/[\r\n\x00]/', $uri)
        ) {
            return null;
        }
        return ['scheme' => $nextScheme, 'port' => $nextPort, 'uri' => $uri];
    }

    private function error(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $message;
    }
}
