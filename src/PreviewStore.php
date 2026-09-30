<?php

declare(strict_types=1);

namespace SitePreview;

use PDO;

final class PreviewStore
{
    private PDO $pdo;

    public function __construct(array $database)
    {
        $port = (int) ($database['port'] ?? 3306);
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database['host'], $port, $database['name']),
            $database['user'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }

    public function createPreview(
        string $label,
        string $hostname,
        string $ip,
        int $expiresAt,
        string $passwordHash = '',
        string $originalUrl = '',
        ?string $upstreamScheme = null,
        ?int $upstreamPort = null,
    ): bool {
        $statement = $this->pdo->prepare(
            'INSERT INTO previews(label, hostname, ip, expires_at, password_hash, original_url, '
            . 'upstream_scheme, upstream_port) '
            . 'VALUES(?, ?, ?, ?, ?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'label = IF(expires_at <= ?, VALUES(label), label), '
            . 'hostname = IF(expires_at <= ?, VALUES(hostname), hostname), '
            . 'ip = IF(expires_at <= ?, VALUES(ip), ip), '
            . 'password_hash = IF(expires_at <= ?, VALUES(password_hash), password_hash), '
            . 'original_url = IF(expires_at <= ?, VALUES(original_url), original_url), '
            . 'upstream_scheme = IF(expires_at <= ?, VALUES(upstream_scheme), upstream_scheme), '
            . 'upstream_port = IF(expires_at <= ?, VALUES(upstream_port), upstream_port), '
            . 'expires_at = IF(expires_at <= ?, VALUES(expires_at), expires_at)',
        );
        $now = time();
        $statement->execute([
            $label,
            $hostname,
            $ip,
            $expiresAt,
            $passwordHash,
            $originalUrl === '' ? 'https://' . $hostname . '/' : $originalUrl,
            $upstreamScheme,
            $upstreamPort,
            $now,
            $now,
            $now,
            $now,
            $now,
            $now,
            $now,
            $now,
        ]);
        $created = $statement->rowCount() > 0;
        $statement->closeCursor();
        return $created;
    }

    public function findPreview(string $label): ?array
    {
        return $this->one(
            'SELECT label, hostname, ip, expires_at, password_hash, original_url, upstream_scheme, upstream_port '
                . 'FROM previews WHERE label = ?',
            [$label],
        );
    }

    public function listPreviews(
        string $search,
        string $sort,
        string $direction,
        int $page,
        int $perPage = 25,
    ): array {
        $columns = [
            'label' => 'label',
            'hostname' => 'hostname',
            'original_url' => 'original_url',
            'ip' => 'ip',
            'expires_at' => 'expires_at',
            'password_protected' => '(password_hash <> \'\')',
        ];
        $orderBy = $columns[$sort] ?? 'expires_at';
        $orderDirection = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = " WHERE label LIKE ? ESCAPE '=' OR hostname LIKE ? ESCAPE '=' "
                . "OR original_url LIKE ? ESCAPE '=' OR ip LIKE ? ESCAPE '='";
            $needle = '%' . strtr($search, ['=' => '==', '%' => '=%', '_' => '=_']) . '%';
            $params = [$needle, $needle, $needle, $needle];
        }

        $total = (int) $this->scalar('SELECT COUNT(*) FROM previews' . $where, $params);
        $rows = $this->all(
            'SELECT label, hostname, ip, original_url, upstream_scheme, upstream_port, expires_at, '
            . '(password_hash <> \'\') AS password_protected '
            . 'FROM previews' . $where . ' ORDER BY ' . $orderBy . ' ' . $orderDirection . ', label ASC LIMIT '
            . $perPage . ' OFFSET ' . $offset,
            $params,
        );
        return [
            'items' => $rows, 'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'expiredCount' => (int) $this->scalar('SELECT COUNT(*) FROM previews WHERE expires_at <= ?', [time()]),
        ];
    }

    public function updatePreview(
        string $label,
        string $hostname,
        string $ip,
        int $expiresAt,
        ?string $passwordHash,
        string $originalUrl = '',
        ?string $upstreamScheme = null,
        ?int $upstreamPort = null,
    ): bool {
        $sql = 'UPDATE previews SET hostname = ?, ip = ?, expires_at = ?, original_url = ?, '
            . 'upstream_scheme = ?, upstream_port = ?';
        $params = [
            $hostname,
            $ip,
            $expiresAt,
            $originalUrl === '' ? 'https://' . $hostname . '/' : $originalUrl,
            $upstreamScheme,
            $upstreamPort,
        ];
        if ($passwordHash !== null) {
            $sql .= ', password_hash = ?';
            $params[] = $passwordHash;
        }
        $sql .= ' WHERE label = ?';
        $params[] = $label;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $updated = $statement->rowCount() > 0;
        $statement->closeCursor();
        return $updated || $this->findPreview($label) !== null;
    }

    public function deleteExpired(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM previews WHERE expires_at <= ?');
        $statement->execute([time()]);
        $deleted = $statement->rowCount();
        $statement->closeCursor();
        return $deleted;
    }

    public function deletePreview(string $label): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM previews WHERE label = ?');
        $statement->execute([$label]);
        $deleted = $statement->rowCount() > 0;
        $statement->closeCursor();
        return $deleted;
    }

    public function allowLoginAttempt(string $scope, string $remoteIp, int $limit, int $windowSeconds): bool
    {
        if ($limit <= 0) {
            return true;
        }
        if ($windowSeconds <= 0) {
            throw new \InvalidArgumentException('Login rate limit window must be positive.');
        }

        $this->execute('DELETE FROM login_attempts WHERE window_started < ? LIMIT 100', [
            time() - max(86400, $windowSeconds),
        ]);
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $this->pdo->beginTransaction();
        try {
            $this->execute(
                'INSERT INTO login_attempts(scope, remote_ip, window_started, attempt_count) VALUES(?, ?, ?, 0) '
                . 'ON DUPLICATE KEY UPDATE scope = VALUES(scope)',
                [$scope, $remoteIp, $window],
            );
            $row = $this->one(
                'SELECT window_started, attempt_count FROM login_attempts '
                . 'WHERE scope = ? AND remote_ip = ? FOR UPDATE',
                [$scope, $remoteIp],
            );
            if ((int) $row['window_started'] !== $window) {
                $this->execute(
                    'UPDATE login_attempts SET window_started = ?, attempt_count = 0 '
                    . 'WHERE scope = ? AND remote_ip = ?',
                    [$window, $scope, $remoteIp],
                );
                $count = 0;
            } else {
                $count = (int) $row['attempt_count'];
            }
            if ($count >= $limit) {
                $this->pdo->commit();
                return false;
            }
            $this->execute(
                'UPDATE login_attempts SET attempt_count = attempt_count + 1 '
                . 'WHERE scope = ? AND remote_ip = ?',
                [$scope, $remoteIp],
            );
            $this->pdo->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function execute(string $sql, array $params = []): void
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $statement->closeCursor();
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $row === false ? null : $row;
    }

    private function all(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $rows;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        $statement->closeCursor();
        return $value;
    }
}
