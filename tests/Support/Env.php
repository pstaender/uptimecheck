<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use RuntimeException;

/**
 * Test environment: a postgres test database, a fixture http server simulating
 * the monitored sites, a fake smtp server and the web interface (php -S).
 *
 * The database can be configured with the env vars UPTIMECHECK_TEST_DB_HOST,
 * UPTIMECHECK_TEST_DB_PORT, UPTIMECHECK_TEST_DB_USER, UPTIMECHECK_TEST_DB_PASSWORD
 * and UPTIMECHECK_TEST_DB_NAME (default: uptime_test, created if missing).
 */
final class Env
{
    public const ROOT = __DIR__ . '/../..';
    public const PASSWORD = 'secret';

    private static ?string $dir = null;
    private static array $processes = [];
    private static int $sitesPort;
    private static int $smtpPort;
    private static int $webPort;
    private static ?PDO $pdo = null;
    private static ?string $passwordHash = null;

    /**
     * Resets database, mails and site states and writes a fresh config.
     */
    public static function reset(array $config = []): void
    {
        self::boot();
        self::db()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        foreach (glob(self::$dir . '/{state,mails}/*', GLOB_BRACE) as $file) {
            unlink($file);
        }
        self::config($config);
    }

    /**
     * Writes the config used by cronjob.php and the web interface.
     * Top level keys of $overrides replace the defaults.
     */
    public static function config(array $overrides = []): void
    {
        self::$passwordHash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        $config = array_replace([
            'sites' => [],
            'tolerated_failures_in_a_row' => 1,
            'timeout' => 2,
            'email' => [
                'from' => 'uptime@example.com',
                'to' => ['ops@example.com'],
                'smtp' => ['host' => '127.0.0.1', 'port' => self::$smtpPort, 'encryption' => ''],
            ],
            'db' => self::dbConfig(),
            'auth' => ['user' => 'admin', 'password' => self::$passwordHash],
        ], $overrides);
        file_put_contents(self::configFile(), '<?php return ' . var_export($config, true) . ';');
    }

    /** URL of a simulated site. */
    public static function site(string $name, string $query = ''): string
    {
        return 'http://127.0.0.1:' . self::$sitesPort . "/site/$name" . ($query !== '' ? "?$query" : '');
    }

    /**
     * Sets how a simulated site answers, e.g. ['status' => 503, 'delay' => 0.5, 'location' => '/site/ok'].
     */
    public static function setSite(string $name, array $state): void
    {
        file_put_contents(self::$dir . "/state/$name.json", json_encode($state));
    }

    /** Requests the simulated site received. */
    public static function requests(string $name): array
    {
        $file = self::$dir . "/state/$name.requests";
        if (!is_file($file)) {
            return [];
        }
        return array_map(fn($line) => json_decode($line, true), file($file, FILE_IGNORE_NEW_LINES));
    }

    /**
     * Runs cronjob.php.
     *
     * @return array{exit: int, out: string, err: string}
     */
    public static function cron(string ...$args): array
    {
        $process = proc_open(
            [PHP_BINARY, self::ROOT . '/cronjob.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::ROOT,
            self::env(),
        );
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    /** Base url of the web interface. */
    public static function webUrl(): string
    {
        return 'http://127.0.0.1:' . self::$webPort;
    }

    public static function smtpPort(): int
    {
        return self::$smtpPort;
    }

    /** Makes the fake smtp server reject (or accept again) all recipients. */
    public static function rejectMails(bool $reject = true): void
    {
        $file = self::$dir . '/mails/reject';
        $reject ? touch($file) : @unlink($file);
    }

    /**
     * All mails received by the fake smtp server, oldest first.
     *
     * @return list<array{headers: array<string, string>, subject: string, text: string, html: string}>
     */
    public static function mails(): array
    {
        $files = glob(self::$dir . '/mails/*.eml');
        sort($files);
        return array_map(fn($file) => self::parseMail(file_get_contents($file)), $files);
    }

    public static function db(): PDO
    {
        return self::$pdo ??= self::connect(self::dbConfig()['database']);
    }

    /** Applies the migrations, for tests that insert data before the first cron run. */
    public static function migrate(): void
    {
        require_once self::ROOT . '/lib.php';
        \migrate(self::db());
    }

    /** Inserts a check directly, e.g. to simulate a crashed run or old data. */
    public static function insertCheck(string $site, bool $success, string $checkedAt = 'now', ?string $error = null, ?int $statusCode = null): void
    {
        self::db()->prepare('INSERT INTO checks (site, success, checked_at, error, status_code, response_time) VALUES (?, ?, ?::timestamptz, ?, ?, 0.1)')
            ->execute([$site, $success ? 'true' : 'false', $checkedAt, $error, $statusCode]);
    }

    private static function boot(): void
    {
        if (self::$dir !== null) {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/uptimecheck-tests-' . getmypid();
        @mkdir(self::$dir . '/state', 0777, true);
        @mkdir(self::$dir . '/mails', 0777, true);
        register_shutdown_function(self::shutdown(...));

        self::createDatabase();

        self::$sitesPort = self::freePort();
        self::$smtpPort = self::freePort();
        self::$webPort = self::freePort();
        $env = self::env();
        // several workers, so that slow sites don't delay the others
        $sitesEnv = $env + ['FIXTURE_STATE_DIR' => self::$dir . '/state', 'PHP_CLI_SERVER_WORKERS' => '4'];
        self::start([PHP_BINARY, '-S', '127.0.0.1:' . self::$sitesPort, __DIR__ . '/router.php'], $sitesEnv, self::$sitesPort);
        self::start([PHP_BINARY, __DIR__ . '/smtp_server.php', (string) self::$smtpPort, self::$dir . '/mails'], $env, self::$smtpPort);
        // no opcache: the config file is rewritten by every test
        self::start([PHP_BINARY, '-d', 'opcache.enable=0', '-S', '127.0.0.1:' . self::$webPort, '-t', realpath(self::ROOT)], $env, self::$webPort);
    }

    private static function start(array $command, array $env, int $port): void
    {
        $log = ['file', self::$dir . '/servers.log', 'a'];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $log, 2 => $log], $pipes, self::ROOT, $env);
        self::$processes[] = $process;
        // wait until the server accepts connections (without triggering warnings while it starts)
        set_error_handler(fn() => true);
        try {
            for ($i = 0; $i < 100; $i++) {
                if ($socket = fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
                    fclose($socket);
                    return;
                }
                usleep(50_000);
            }
        } finally {
            restore_error_handler();
        }
        throw new RuntimeException('Could not start ' . implode(' ', $command));
    }

    private static function shutdown(): void
    {
        foreach (self::$processes as $process) {
            // stop the forked workers of php -S first, they would survive their parent
            exec('pkill -TERM -P ' . proc_get_status($process)['pid']);
            proc_terminate($process);
            proc_close($process);
        }
        foreach (glob(self::$dir . '/{,state/,mails/}*', GLOB_BRACE) as $file) {
            is_file($file) && unlink($file);
        }
        @rmdir(self::$dir . '/state');
        @rmdir(self::$dir . '/mails');
        @rmdir(self::$dir);
    }

    private static function env(): array
    {
        return getenv() + ['UPTIMECHECK_CONFIG' => self::configFile()];
    }

    private static function configFile(): string
    {
        return self::$dir . '/config.php';
    }

    private static function dbConfig(): array
    {
        return [
            'host' => getenv('UPTIMECHECK_TEST_DB_HOST') ?: 'localhost',
            'port' => (int) (getenv('UPTIMECHECK_TEST_DB_PORT') ?: 5432),
            'username' => getenv('UPTIMECHECK_TEST_DB_USER') ?: (getenv('USER') ?: 'postgres'),
            'password' => getenv('UPTIMECHECK_TEST_DB_PASSWORD') ?: '',
            'database' => getenv('UPTIMECHECK_TEST_DB_NAME') ?: 'uptime_test',
        ];
    }

    private static function connect(string $database): PDO
    {
        $db = self::dbConfig();
        return new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $db['host'], $db['port'], $database),
            $db['username'],
            $db['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC],
        );
    }

    private static function createDatabase(): void
    {
        $name = self::dbConfig()['database'];
        $pdo = self::connect('postgres');
        $stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
        $stmt->execute([$name]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    private static function parseMail(string $raw): array
    {
        [$head, $body] = explode("\r\n\r\n", $raw, 2);
        $headers = [];
        foreach (explode("\r\n", preg_replace('/\r\n[ \t]+/', ' ', $head)) as $line) {
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower($name)] = trim($value);
        }
        $parts = ['text/plain' => '', 'text/html' => ''];
        if (preg_match('/boundary="([^"]+)"/', $headers['content-type'] ?? '', $m)) {
            foreach (explode('--' . $m[1], $body) as $part) {
                if (preg_match('#^\r\nContent-Type: (text/(?:plain|html))#', $part, $type)) {
                    $parts[$type[1]] = str_replace("\r\n", "\n", quoted_printable_decode(explode("\r\n\r\n", $part, 2)[1]));
                }
            }
        }
        return [
            'headers' => $headers,
            'subject' => mb_decode_mimeheader($headers['subject'] ?? ''),
            'text' => $parts['text/plain'],
            'html' => $parts['text/html'],
        ];
    }
}
