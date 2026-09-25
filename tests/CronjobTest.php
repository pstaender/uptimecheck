<?php

use Tests\Support\Env;

describe('setup', function () {
    it('migrates the database on the first run', function () {
        $result = Env::cron('--verbose');

        expect($result['exit'])->toBe(0)
            ->and($result['out'])->toContain('Applied migration 001_initial')
            ->and(Env::db()->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN))->toBe(['001_initial']);

        expect(Env::cron('--verbose')['out'])->not->toContain('Applied migration');
    });

    it('records the job', function () {
        Env::cron();

        $job = Env::db()->query('SELECT * FROM jobs')->fetch();
        expect($job['status'])->toBe('finished')
            ->and($job['finished_at'])->not->toBeNull();
    });

    it('skips the run while another job holds the lock', function () {
        Env::config(['sites' => [Env::site('ok') => []]]);
        $other = Env::db();
        $other->query('SELECT pg_advisory_lock(7304219)');

        try {
            $result = Env::cron('--verbose');
        } finally {
            $other->query('SELECT pg_advisory_unlock(7304219)');
        }

        expect($result['exit'])->toBe(0)
            ->and($result['out'])->toContain('Another job is still running')
            ->and(Env::requests('ok'))->toBeEmpty();
    });

    it('marks jobs of crashed runs as aborted', function () {
        Env::migrate();
        Env::db()->exec("INSERT INTO jobs (pid, status) VALUES (1, 'running')");

        Env::cron();

        expect(Env::db()->query('SELECT status FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))
            ->toBe(['aborted', 'finished']);
    });

    it('deletes checks older than retention_days', function () {
        Env::config(['retention_days' => 30]);
        Env::migrate();
        Env::insertCheck(Env::site('ok'), true, '-infinity');
        Env::insertCheck(Env::site('ok'), true, 'now');

        Env::cron();

        expect(checks())->toHaveCount(1);
    });

    it('fails with a message when the database is not reachable', function () {
        Env::config(['db' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '']]);

        $result = Env::cron();

        expect($result['exit'])->toBe(1)
            ->and($result['err'])->toContain('Database connection failed');
    });
});

describe('http checks', function () {
    it('records a successful check', function () {
        Env::config(['sites' => [Env::site('ok') => []]]);

        Env::cron();

        [$check] = checks();
        expect($check['site'])->toBe(Env::site('ok'))
            ->and($check['success'])->toBeTrue()
            ->and($check['slow'])->toBeFalse()
            ->and($check['status_code'])->toBe(200)
            ->and($check['response_time'])->toBeGreaterThan(0)
            ->and($check['error'])->toBeNull();
    });

    it('accepts only status 200 by default', function () {
        Env::setSite('empty', ['status' => 204]);
        Env::config(['sites' => [Env::site('empty') => []]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeFalse()
            ->status_code->toBe(204)
            ->error->toBe('Unexpected status code 204');
    });

    it('accepts the configured status codes', function () {
        Env::setSite('empty', ['status' => 204]);
        Env::config(['sites' => [Env::site('empty') => ['status_code' => [200, 204]]]]);

        Env::cron();

        expect(checks()[0]['success'])->toBeTrue();
    });

    it('does not follow redirects by default', function () {
        Env::setSite('moved', ['status' => 301, 'location' => '/site/ok']);
        Env::config(['sites' => [Env::site('moved') => []]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeFalse()
            ->status_code->toBe(301)
            ->and(Env::requests('ok'))->toBeEmpty();
    });

    it('follows redirects when follow_redirects is enabled for the site', function () {
        Env::setSite('moved', ['status' => 301, 'location' => '/site/ok']);
        Env::config(['sites' => [Env::site('moved') => ['follow_redirects' => true]]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeTrue()
            ->status_code->toBe(200)
            ->and(Env::requests('ok'))->toHaveCount(1);
    });

    it('follows redirects when follow_redirects is enabled globally', function () {
        Env::setSite('moved', ['status' => 302, 'location' => '/site/ok']);
        Env::config(['follow_redirects' => true, 'sites' => [Env::site('moved') => []]]);

        Env::cron();

        expect(checks()[0]['success'])->toBeTrue();
    });

    it('sends the configured method, headers and body', function () {
        Env::config(['sites' => [Env::site('api') => [
            'method' => 'POST',
            'headers' => ['X-Authtoken' => '1234567890'],
            'body' => '{"ping":true}',
        ]]]);

        Env::cron();

        [$request] = Env::requests('api');
        expect($request['method'])->toBe('POST')
            ->and($request['headers']['X-Authtoken'])->toBe('1234567890')
            ->and($request['body'])->toBe('{"ping":true}');
    });

    it('records connection errors', function () {
        Env::config(['sites' => ['http://127.0.0.1:1/' => []]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeFalse()
            ->status_code->toBeNull()
            ->response_time->toBeNull()
            ->error->toContain('127.0.0.1');
    });

    it('fails on timeouts', function () {
        Env::setSite('hanging', ['delay' => 1.5]);
        Env::config(['timeout' => 0.5, 'sites' => [Env::site('hanging') => []]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeFalse()
            ->error->toContain('timed out');
    });

    it('checks all sites in parallel', function () {
        $sites = [];
        foreach (range(1, 3) as $i) {
            Env::setSite("slow$i", ['delay' => 0.5]);
            $sites[Env::site("slow$i")] = [];
        }
        Env::config(['sites' => $sites]);

        $start = microtime(true);
        Env::cron();

        expect(microtime(true) - $start)->toBeLessThan(1.4)
            ->and(checks())->toHaveCount(3);
    });
});

describe('slow responses', function () {
    beforeEach(function () {
        Env::setSite('slow', ['delay' => 0.3]);
    });

    it('counts a slow response as failure by default and notifies', function () {
        Env::config(['max_response_time' => 0.1, 'sites' => [Env::site('slow') => []]]);

        Env::cron();

        expect(checks()[0])
            ->success->toBeFalse()
            ->slow->toBeTrue()
            ->error->toMatch('/^Response time 0\.\d+s exceeds 0\.10s$/')
            ->and(subjects())->toHaveCount(1);
    });

    it('uses the max_response_time of the site over the global one', function () {
        Env::config(['max_response_time' => 0.1, 'sites' => [Env::site('slow') => ['max_response_time' => 5]]]);

        Env::cron();

        expect(checks()[0])->success->toBeTrue()->slow->toBeFalse();
    });

    it('records slow responses as up with dont_send_notifications_on_slow_pages', function () {
        Env::config([
            'max_response_time' => 0.1,
            'dont_send_notifications_on_slow_pages' => true,
            'sites' => [Env::site('slow') => []],
        ]);

        Env::cron();
        Env::cron();

        expect(checks())->each(fn($check) => $check->success->toBeTrue()->slow->toBeTrue())
            ->and(Env::mails())->toBeEmpty();
    });
});

describe('notifications', function () {
    it('notifies on the first failed check when tolerated_failures_in_a_row is 1', function () {
        Env::setSite('down', ['status' => 503]);
        Env::config(['sites' => [Env::site('ok') => [], Env::site('down') => []]]);

        Env::cron();

        expect(subjects())->toBe(['[uptime] 1 of 2 sites down: 127.0.0.1:' . parse_url(Env::site('down'), PHP_URL_PORT) . '/site/down']);
        $mail = Env::mails()[0];
        expect($mail['headers']['to'])->toBe('ops@example.com')
            ->and($mail['headers']['from'])->toBe('uptime@example.com')
            ->and($mail['text'])->toContain(plain_name(Env::site('down')))->toContain('Unexpected status code 503')
            ->and($mail['html'])->toContain(htmlspecialchars(Env::site('down')))->toContain('Unexpected status code 503');
    });

    it('notifies only after tolerated_failures_in_a_row failures in a row', function () {
        Env::setSite('down', ['status' => 500]);
        Env::config(['tolerated_failures_in_a_row' => 3, 'sites' => [Env::site('down') => []]]);

        Env::cron();
        Env::cron();
        expect(Env::mails())->toBeEmpty();

        Env::cron();
        expect(Env::mails())->toHaveCount(1)
            ->and(Env::mails()[0]['text'])->toContain('3 in a row');
    });

    it('uses tolerated_failures_in_a_row of the site over the global one', function () {
        Env::setSite('strict', ['status' => 500]);
        Env::setSite('lenient', ['status' => 500]);
        Env::setSite('default', ['status' => 500]);
        Env::config([
            'tolerated_failures_in_a_row' => 2,
            'sites' => [
                Env::site('strict') => ['tolerated_failures_in_a_row' => 1],
                Env::site('lenient') => ['tolerated_failures_in_a_row' => 3],
                Env::site('default') => [],
            ],
        ]);

        Env::cron();
        Env::cron();
        Env::cron();

        $mails = Env::mails();
        expect($mails)->toHaveCount(3)
            ->and($mails[0]['text'])->toContain(plain_name(Env::site('strict')) . ' [NEW]')->not->toContain(plain_name(Env::site('default')))->not->toContain(plain_name(Env::site('lenient')))
            ->and($mails[1]['text'])->toContain(plain_name(Env::site('default')) . ' [NEW]')->not->toContain(plain_name(Env::site('lenient')))
            ->and($mails[2]['text'])->toContain(plain_name(Env::site('lenient')) . ' [NEW]')
            ->and($mails[2]['subject'])->toStartWith('[uptime] 3 of 3 sites down');
    });

    it('resets the failure counter after a successful check', function () {
        Env::config(['tolerated_failures_in_a_row' => 2, 'sites' => [Env::site('flaky') => []]]);

        Env::setSite('flaky', ['status' => 500]);
        Env::cron();
        Env::setSite('flaky', []);
        Env::cron();
        Env::setSite('flaky', ['status' => 500]);
        Env::cron();

        expect(Env::mails())->toBeEmpty();
    });

    it('sends one consolidated mail for several down sites', function () {
        Env::setSite('a', ['status' => 500]);
        Env::setSite('b', ['status' => 502]);
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => [], Env::site('ok') => []]]);

        Env::cron();

        expect(Env::mails())->toHaveCount(1)
            ->and(subjects()[0])->toStartWith('[uptime] 2 of 3 sites down:')
            ->and(Env::mails()[0]['text'])->toContain(plain_name(Env::site('a')))->toContain(plain_name(Env::site('b')));
    });

    it('links the sites to their detail view when interface_url is configured', function () {
        Env::setSite('a', ['status' => 500]);
        Env::config(['interface_url' => 'https://uptime.example.com/', 'sites' => [Env::site('a') => []]]);
        $detailUrl = 'https://uptime.example.com/?' . http_build_query(['site' => Env::site('a')]);

        Env::cron();
        Env::setSite('a', []);
        Env::cron();

        [$down, $up] = Env::mails();
        expect($down['html'])->toContain('href="' . htmlspecialchars($detailUrl) . '"')
            ->and($down['text'])->toContain('* [' . plain_name(Env::site('a')) . "]($detailUrl) [NEW]")
            ->and($up['html'])->toContain('href="' . htmlspecialchars($detailUrl) . '"')
            ->and($up['text'])->toContain("Back up:\n* [" . plain_name(Env::site('a')) . "]($detailUrl)");
    });

    it('does not link the sites without interface_url', function () {
        Env::setSite('a', ['status' => 500]);
        Env::config(['sites' => [Env::site('a') => []]]);

        Env::cron();

        expect(Env::mails()[0]['html'])->not->toContain('?site=')
            ->and(Env::mails()[0]['text'])->not->toContain('](');
    });

    it('does not notify again while nothing changes', function () {
        Env::setSite('down', ['status' => 500]);
        Env::config(['sites' => [Env::site('down') => []]]);

        Env::cron();
        Env::cron();
        Env::cron();

        expect(Env::mails())->toHaveCount(1)
            ->and(Env::db()->query('SELECT count(*) FROM notifications')->fetchColumn())->toBe(1);
    });

    it('notifies when another site goes down and marks it as new', function () {
        Env::setSite('a', ['status' => 500]);
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => []]]);
        Env::cron();

        Env::setSite('b', ['status' => 500]);
        Env::cron();

        $mails = Env::mails();
        expect($mails)->toHaveCount(2)
            ->and($mails[1]['text'])->toContain(plain_name(Env::site('b')) . ' [NEW]')
            ->and($mails[1]['text'])->not->toContain(plain_name(Env::site('a')) . ' [NEW]');
    });

    it('notifies about recovered sites while others are still down', function () {
        Env::setSite('a', ['status' => 500]);
        Env::setSite('b', ['status' => 500]);
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => []]]);
        Env::cron();

        Env::setSite('a', []);
        Env::cron();

        $mail = Env::mails()[1];
        expect($mail['subject'])->toStartWith('[uptime] 1 of 2 sites down:')
            ->and($mail['text'])->toContain("Back up:\n* " . plain_name(Env::site('a')));
    });

    it('notifies when everything is back to normal', function () {
        Env::setSite('down', ['status' => 500]);
        Env::config(['sites' => [Env::site('down') => []]]);
        Env::cron();

        Env::setSite('down', []);
        Env::cron();
        Env::cron();

        expect(subjects())->toHaveCount(2)
            ->and(subjects()[1])->toBe('[uptime] All sites are back to normal')
            ->and(Env::mails()[1]['html'])->toContain('All sites are back to normal');
    });

    it('does not notify when all sites are up from the start', function () {
        Env::config(['sites' => [Env::site('ok') => []]]);

        Env::cron();

        expect(Env::mails())->toBeEmpty();
    });

    it('mentions sites that were down and got removed from the config once', function () {
        Env::setSite('a', ['status' => 500]);
        Env::config(['sites' => [Env::site('a') => [], Env::site('ok') => []]]);
        Env::cron();

        Env::config(['sites' => [Env::site('ok') => []]]);
        Env::cron();
        Env::cron();

        expect(Env::mails())->toHaveCount(2)
            ->and(subjects()[1])->toBe('[uptime] All sites are back to normal')
            ->and(Env::mails()[1]['text'])->toContain("Removed from monitoring:\n* " . Env::site('a'))
            ->and(Env::mails()[1]['html'])->toContain('Removed from monitoring');
    });

    it('catches up on notifications a crashed run missed', function () {
        Env::config(['sites' => [Env::site('ok') => []]]);
        Env::migrate();
        // failed check of a previous run that crashed before notifying
        Env::insertCheck(Env::site('ok'), false, 'now', 'Unexpected status code 500', 500);

        Env::cron();

        expect(subjects())->toHaveCount(2)
            ->and(subjects()[0])->toStartWith('[uptime] 1 of 1 site down:')
            ->and(subjects()[1])->toBe('[uptime] All sites are back to normal');
    });

    it('retries the notification when sending failed', function () {
        Env::setSite('down', ['status' => 500]);
        Env::config(['sites' => [Env::site('down') => []]]);

        Env::rejectMails();
        $result = Env::cron();
        expect($result['exit'])->toBe(1)
            ->and($result['err'])->toContain('550 recipient rejected')
            ->and(Env::db()->query('SELECT count(*) FROM notifications')->fetchColumn())->toBe(0)
            ->and(Env::db()->query('SELECT status FROM jobs')->fetchColumn())->toBe('failed');

        Env::rejectMails(false);
        expect(Env::cron()['exit'])->toBe(0)
            ->and(Env::mails())->toHaveCount(1);
    });

    it('sends a test mail with --test-mail', function () {
        $result = Env::cron('--test-mail');

        expect($result['exit'])->toBe(0)
            ->and($result['out'])->toContain('Test mail sent to ops@example.com')
            ->and(subjects())->toBe(['[uptime] Test mail']);
    });

    it('sends a test mail of a down notification with --test-mail=down', function () {
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => [], Env::site('c') => []]]);

        $result = Env::cron('--test-mail=down');

        [$mail] = Env::mails();
        expect($result['exit'])->toBe(0)
            ->and($result['out'])->toContain('Test mail (down) sent to ops@example.com')
            ->and($mail['subject'])->toStartWith('[TEST] [uptime] 2 of 3 sites down:')
            ->and($mail['text'])->toContain(plain_name(Env::site('a')) . ' [NEW]')->toContain(plain_name(Env::site('b')) . ' [NEW]')
            ->and($mail['html'])->toContain('Unexpected status code 503')
            ->and(Env::requests('a'))->toBeEmpty();
    });

    it('sends a test mail of a back to normal notification with --test-mail=up', function () {
        Env::config(['sites' => [Env::site('a') => []]]);

        $result = Env::cron('--test-mail=up');

        [$mail] = Env::mails();
        expect($result['exit'])->toBe(0)
            ->and($result['out'])->toContain('Test mail (up) sent to ops@example.com')
            ->and($mail['subject'])->toBe('[TEST] [uptime] All sites are back to normal')
            ->and($mail['text'])->toContain("Back up:\n* " . plain_name(Env::site('a')));
    });

    it('sends test mails without a database connection and does not record them', function () {
        Env::config(['db' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '']]);

        expect(Env::cron('--test-mail=down')['exit'])->toBe(0)
            ->and(Env::mails())->toHaveCount(1)
            ->and(Env::mails()[0]['text'])->toContain('* example.com');
    });

    it('rejects unknown test mail types', function () {
        $result = Env::cron('--test-mail=sideways');

        expect($result['exit'])->toBe(1)
            ->and($result['err'])->toContain('Unknown --test-mail=sideways')
            ->and(Env::mails())->toBeEmpty();
    });

    it('sends to all configured recipients', function () {
        Env::config(['email' => [
            'from' => 'uptime@example.com',
            'to' => ['a@example.com', 'b@example.com'],
            'smtp' => ['host' => '127.0.0.1', 'port' => Env::smtpPort(), 'encryption' => ''],
        ]]);

        Env::cron('--test-mail');

        expect(Env::mails()[0]['headers']['to'])->toBe('a@example.com, b@example.com');
    });
});
