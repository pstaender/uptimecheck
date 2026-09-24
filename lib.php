<?php

declare(strict_types=1);

/**
 * Shared helpers for index.php and cronjob.php.
 */

function config(): array
{
    static $config = null;
    if ($config === null) {
        $file = getenv('UPTIMECHECK_CONFIG') ?: __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException("Config file not found: $file");
        }
        $config = require $file;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $db = config()['db'];
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $db['host'] ?? 'localhost',
            $db['port'] ?? 5432,
            $db['database'],
        );
        $pdo = new PDO($dsn, $db['username'] ?? null, $db['password'] ?? null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    }
    return $pdo;
}

/**
 * Applies all pending migrations from schema.php.
 * Returns the names of the applied migrations.
 */
function migrate(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (
        name TEXT PRIMARY KEY,
        applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )');
    $applied = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    $migrations = require __DIR__ . '/schema.php';
    $done = [];
    foreach ($migrations as $name => $sql) {
        if (in_array($name, $applied, true)) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            $pdo->prepare('INSERT INTO migrations (name) VALUES (?)')->execute([$name]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException("Migration $name failed: " . $e->getMessage(), 0, $e);
        }
        $done[] = $name;
    }
    return $done;
}

/**
 * Returns all configured sites with their options merged with the defaults.
 *
 * @return array<string, array{method: string, headers: array, body: ?string, status_code: int[], follow_redirects: bool, timeout: float, max_response_time: ?float}>
 */
function sites(): array
{
    $config = config();
    $sites = [];
    foreach ($config['sites'] ?? [] as $url => $options) {
        $sites[$url] = [
            'method' => strtoupper($options['method'] ?? 'GET'),
            'headers' => $options['headers'] ?? [],
            'body' => $options['body'] ?? null,
            'status_code' => array_map('intval', (array) ($options['status_code'] ?? [200])),
            'follow_redirects' => (bool) ($options['follow_redirects'] ?? $config['follow_redirects'] ?? false),
            'timeout' => (float) ($options['timeout'] ?? $config['timeout'] ?? 10),
            'max_response_time' => isset($options['max_response_time']) || isset($config['max_response_time'])
                ? (float) ($options['max_response_time'] ?? $config['max_response_time'])
                : null,
        ];
    }
    return $sites;
}

/**
 * Number of failed checks in a row after which a site is down (at least 1).
 */
function tolerated_failures(): int
{
    return max(1, (int) (config()['tolerated_failures_in_a_row'] ?? 1));
}

/**
 * Determines the current state of every configured site from the latest checks.
 * A site is down when `tolerated_failures_in_a_row` checks in a row failed.
 *
 * @return array<string, array{down: bool, failures_in_a_row: int, last_check: ?array, down_since: ?string}>
 */
function site_states(PDO $pdo): array
{
    $sites = array_keys(sites());
    if (!$sites) {
        return [];
    }
    $limit = tolerated_failures();
    $placeholders = implode(',', array_fill(0, count($sites), '?'));
    $stmt = $pdo->prepare("
        SELECT * FROM (
            SELECT c.*, row_number() OVER (PARTITION BY site ORDER BY checked_at DESC, id DESC) AS rn
            FROM checks c
            WHERE site IN ($placeholders)
        ) t
        WHERE rn <= ?
        ORDER BY site, rn
    ");
    $stmt->execute([...$sites, $limit]);
    $recent = [];
    foreach ($stmt as $row) {
        $recent[$row['site']][] = $row;
    }

    $states = [];
    foreach ($sites as $site) {
        $rows = $recent[$site] ?? [];
        $failures = 0;
        foreach ($rows as $row) {
            if ($row['success']) {
                break;
            }
            $failures++;
        }
        $down = $failures >= $limit;
        $states[$site] = [
            'down' => $down,
            'failures_in_a_row' => $failures,
            'last_check' => $rows[0] ?? null,
            'down_since' => null,
        ];
    }

    // for down sites: find the first failed check after the last success
    $downSites = array_keys(array_filter($states, fn($s) => $s['down']));
    if ($downSites) {
        $stmt = $pdo->prepare("
            SELECT min(checked_at) FROM checks
            WHERE site = :site AND NOT success
              AND checked_at > coalesce((SELECT max(checked_at) FROM checks WHERE site = :site AND success), '-infinity')
        ");
        foreach ($downSites as $site) {
            $stmt->execute(['site' => $site]);
            $states[$site]['down_since'] = $stmt->fetchColumn() ?: null;
        }
    }
    return $states;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Whether the configured password is a password_hash() hash (bcrypt / argon2).
 */
function password_hash_configured(): bool
{
    return password_get_info((string) (config()['auth']['password'] ?? ''))['algo'] !== null;
}
