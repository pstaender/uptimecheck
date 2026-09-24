<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

require __DIR__ . '/lib.php';

// arbitrary but fixed key for pg_try_advisory_lock
const LOCK_KEY = 7304219;

$verbose = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);
$testMail = in_array('--test-mail', $argv, true);

function out(string $message): void
{
    global $verbose;
    if ($verbose) {
        echo $message, PHP_EOL;
    }
}

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

try {
    $pdo = db();
} catch (Throwable $e) {
    fail('Database connection failed: ' . $e->getMessage());
}

if ($testMail) {
    try {
        send_mail('[uptime] Test mail', "This is a test mail from uptimecheck.\n", '<p>This is a test mail from uptimecheck.</p>');
        echo 'Test mail sent to ', implode(', ', recipients()), PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fail('Sending test mail failed: ' . $e->getMessage());
    }
}

// The advisory lock is held for the lifetime of the db connection, so it is
// released automatically even if this script crashes.
if (!$pdo->query('SELECT pg_try_advisory_lock(' . LOCK_KEY . ')')->fetchColumn()) {
    out('Another job is still running, skipping this interval.');
    exit(0);
}

$jobId = null;
try {
    foreach (migrate($pdo) as $migration) {
        out("Applied migration $migration");
    }

    // we hold the lock, so any job still marked as running has died
    $pdo->exec("UPDATE jobs SET status = 'aborted', finished_at = now() WHERE status = 'running'");
    $jobId = (int) $pdo->query('INSERT INTO jobs (pid) VALUES (' . getmypid() . ') RETURNING id')->fetchColumn();

    // catch up on notifications a previous (crashed) run may have missed
    notify($pdo);

    $results = run_checks(sites());
    $insert = $pdo->prepare('INSERT INTO checks (job_id, site, success, slow, status_code, response_time, error) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($results as $site => $r) {
        $insert->execute([$jobId, $site, $r['success'] ? 'true' : 'false', $r['slow'] ? 'true' : 'false', $r['status_code'], $r['response_time'], $r['error']]);
        out(sprintf('%-4s %s %s %s', $r['success'] ? ($r['slow'] ? 'SLOW' : 'OK') : 'FAIL', $site, $r['status_code'] ?? '-', $r['error'] ?? ''));
    }

    notify($pdo);

    $retentionDays = (int) (config()['retention_days'] ?? 90);
    if ($retentionDays > 0) {
        $pdo->prepare('DELETE FROM checks WHERE checked_at < now() - make_interval(days => ?)')->execute([$retentionDays]);
        $pdo->prepare('DELETE FROM jobs WHERE started_at < now() - make_interval(days => ?)')->execute([$retentionDays]);
    }

    $pdo->prepare("UPDATE jobs SET status = 'finished', finished_at = now() WHERE id = ?")->execute([$jobId]);
} catch (Throwable $e) {
    if ($jobId !== null) {
        $pdo->prepare("UPDATE jobs SET status = 'failed', finished_at = now(), error = ? WHERE id = ?")
            ->execute([$e->getMessage(), $jobId]);
    }
    fail('Job failed: ' . $e->getMessage());
} finally {
    $pdo->query('SELECT pg_advisory_unlock(' . LOCK_KEY . ')');
}

/**
 * Runs all http checks in parallel.
 *
 * @return array<string, array{success: bool, slow: bool, status_code: ?int, response_time: ?float, error: ?string}>
 */
function run_checks(array $sites): array
{
    // opt-in: slow responses are recorded, but count as up and never trigger a notification
    $slowIsUp = (bool) (config()['dont_send_notifications_on_slow_pages'] ?? false);
    $multi = curl_multi_init();
    $handles = [];
    foreach ($sites as $url => $options) {
        $ch = curl_init($url);
        $headers = [];
        foreach ($options['headers'] as $name => $value) {
            $headers[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $options['method'],
            CURLOPT_NOBODY => $options['method'] === 'HEAD',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => $options['follow_redirects'],
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($options['timeout'] * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($options['timeout'] * 1000),
            CURLOPT_USERAGENT => 'uptimecheck',
            // the body is not needed, discard it instead of buffering it
            CURLOPT_WRITEFUNCTION => fn($ch, string $data): int => strlen($data),
        ]);
        if ($options['body'] !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
        }
        curl_multi_add_handle($multi, $ch);
        $handles[$url] = $ch;
    }

    $codes = [];
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running) {
            curl_multi_select($multi, 1.0);
        }
        while ($info = curl_multi_info_read($multi)) {
            $codes[spl_object_id($info['handle'])] = $info['result'];
        }
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $url => $ch) {
        $options = $sites[$url];
        $code = $codes[spl_object_id($ch)] ?? CURLE_OK;
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
        $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $error = null;
        $slow = false;

        if ($code !== CURLE_OK) {
            $error = curl_error($ch) ?: curl_strerror($code);
        } elseif (!in_array($statusCode, $options['status_code'], true)) {
            $error = "Unexpected status code $statusCode";
        } elseif ($options['max_response_time'] !== null && $time > $options['max_response_time']) {
            $error = sprintf('Response time %.2fs exceeds %.2fs', $time, $options['max_response_time']);
            $slow = true;
        }

        $results[$url] = [
            'success' => $error === null || ($slow && $slowIsUp),
            'slow' => $slow,
            'status_code' => $statusCode,
            'response_time' => $code === CURLE_OK ? $time : null,
            'error' => $error,
        ];
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);
    return $results;
}

/**
 * Sends a consolidated notification when the set of down sites differs
 * from the one of the last notification.
 */
function notify(PDO $pdo): void
{
    $states = site_states($pdo);
    $down = array_keys(array_filter($states, fn($s) => $s['down']));
    sort($down);

    $last = $pdo->query('SELECT down_sites FROM notifications ORDER BY sent_at DESC, id DESC LIMIT 1')->fetchColumn();
    $lastDown = $last ? json_decode($last, true) : [];
    // sites that were down, but got removed from the config in the meantime
    $removed = array_values(array_diff($lastDown, array_keys($states)));
    $lastDown = array_values(array_intersect($lastDown, array_keys($states)));
    sort($lastDown);

    if ($down === $lastDown && !$removed) {
        return;
    }

    $downSites = [];
    foreach ($down as $site) {
        $check = $states[$site]['last_check'];
        $downSites[] = [
            'site' => $site,
            'down_since' => $states[$site]['down_since'],
            'failures' => $states[$site]['failures_in_a_row'],
            'status_code' => $check['status_code'],
            'error' => $check['error'],
        ];
    }
    $data = [
        'downSites' => $downSites,
        'newlyDown' => array_values(array_diff($down, $lastDown)),
        'recovered' => array_values(array_diff($lastDown, $down)),
        'removed' => $removed,
        'siteCount' => count($states),
        'generatedAt' => gmdate('Y-m-d H:i') . ' UTC',
    ];

    $subject = $down
        ? sprintf('[uptime] %d of %d %s down: %s', count($down), count($states), count($states) === 1 ? 'site' : 'sites', implode(', ', array_map(fn($s) => preg_replace('#^https?://#', '', $s), $down)))
        : '[uptime] All sites are back to normal';

    send_mail(
        $subject,
        render(__DIR__ . '/email_plain_text_template.php', $data),
        render(__DIR__ . '/email_template.php', $data),
    );
    $pdo->prepare('INSERT INTO notifications (down_sites, subject, recipients) VALUES (?, ?, ?)')
        ->execute([json_encode($down), $subject, implode(', ', recipients())]);
    out("Notification sent: $subject");
}

function render(string $template, array $data): string
{
    extract($data);
    ob_start();
    try {
        require $template;
        return ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

function recipients(): array
{
    $to = (array) (config()['email']['to'] ?? []);
    if (!$to) {
        throw new RuntimeException('No recipients configured (email.to)');
    }
    return $to;
}

function send_mail(string $subject, string $text, string $html): void
{
    $email = config()['email'] ?? [];
    $from = $email['from'] ?? throw new RuntimeException('No sender configured (email.from)');
    $to = recipients();

    $boundary = 'b' . bin2hex(random_bytes(12));
    $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
    $headers = [
        'Date' => date(DATE_RFC2822),
        'From' => $from,
        'Message-ID' => sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain),
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"$boundary\"",
    ];
    $body = implode("\r\n", [
        "--$boundary",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        quoted_printable_encode(normalize_newlines($text)),
        "--$boundary",
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        '',
        quoted_printable_encode(normalize_newlines($html)),
        "--$boundary--",
        '',
    ]);
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'Q', "\r\n");

    if (empty($email['smtp']['host'])) {
        // no smtp configured: use the hoster's local mailer
        $ok = mail(implode(', ', $to), $encodedSubject, $body, $headers, '-f' . $from);
        if (!$ok) {
            throw new RuntimeException('mail() failed');
        }
        return;
    }

    $message = '';
    foreach (['To' => implode(', ', $to), 'Subject' => $encodedSubject] + $headers as $name => $value) {
        $message .= "$name: $value\r\n";
    }
    smtp_send($email['smtp'], $from, $to, $message . "\r\n" . $body);
}

function normalize_newlines(string $text): string
{
    return preg_replace('/\r\n|\r|\n/', "\r\n", $text);
}

/**
 * Minimal SMTP client. encryption: "tls" (STARTTLS), "ssl" (implicit TLS) or none.
 */
function smtp_send(array $smtp, string $from, array $to, string $message): void
{
    $encryption = strtolower($smtp['encryption'] ?? '');
    $port = (int) ($smtp['port'] ?? ($encryption === 'ssl' ? 465 : 587));
    $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $smtp['host'] . ':' . $port;

    $socket = @stream_socket_client($address, $errno, $errstr, 15);
    if (!$socket) {
        throw new RuntimeException("SMTP connection to $address failed: $errstr");
    }
    stream_set_timeout($socket, 15);

    $command = function (?string $line, int ...$expected) use ($socket): string {
        if ($line !== null) {
            fwrite($socket, $line . "\r\n");
        }
        $response = '';
        while (($row = fgets($socket, 1024)) !== false) {
            $response .= $row;
            if (strlen($row) < 4 || $row[3] === ' ') {
                break;
            }
        }
        if (!in_array((int) substr($response, 0, 3), $expected, true)) {
            $sent = $line === null ? 'connect' : explode(' ', $line)[0];
            throw new RuntimeException("SMTP error after $sent: " . trim($response ?: 'no response'));
        }
        return $response;
    };

    try {
        $hostname = gethostname() ?: 'localhost';
        $command(null, 220);
        $command("EHLO $hostname", 250);
        if ($encryption === 'tls') {
            $command('STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS failed');
            }
            $command("EHLO $hostname", 250);
        }
        if (!empty($smtp['username'])) {
            $command('AUTH LOGIN', 334);
            $command(base64_encode($smtp['username']), 334);
            $command(base64_encode($smtp['password'] ?? ''), 235);
        }
        $command("MAIL FROM:<$from>", 250);
        foreach ($to as $recipient) {
            $command("RCPT TO:<$recipient>", 250, 251);
        }
        $command('DATA', 354);
        // dot-stuffing: lines starting with a dot get an extra dot
        $command(preg_replace('/^\./m', '..', $message) . "\r\n.", 250);
        $command('QUIT', 221);
    } finally {
        fclose($socket);
    }
}
