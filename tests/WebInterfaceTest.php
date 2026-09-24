<?php

use Tests\Support\Browser;
use Tests\Support\Env;

describe('authentication', function () {
    it('shows only the login form when not logged in', function () {
        Env::config(['sites' => [Env::site('ok') => []]]);

        $response = (new Browser())->get();

        expect($response['status'])->toBe(200)
            ->and($response['body'])->toContain('name="password"')
            ->and($response['body'])->not->toContain(Env::site('ok'));
    });

    it('logs in with valid credentials', function () {
        $browser = new Browser();

        $response = $browser->login();

        expect($response['status'])->toBe(302)
            ->and($response['location'])->toBe('/index.php')
            ->and($browser->get()['body'])->toContain('Log out')->not->toContain('name="password"');
    });

    it('rejects a wrong password', function () {
        $browser = new Browser();

        $response = $browser->login('admin', 'wrong');

        expect($response['status'])->toBe(200)
            ->and($response['body'])->toContain('Invalid user or password.')
            ->and($browser->get()['body'])->toContain('name="password"');
    });

    it('rejects a wrong user', function () {
        expect((new Browser())->login('root')['body'])->toContain('Invalid user or password.');
    });

    it('rejects requests without a valid csrf token', function () {
        $response = (new Browser())->post(['csrf' => 'invalid', 'action' => 'login', 'user' => 'admin', 'password' => Env::PASSWORD]);

        expect($response['status'])->toBe(400);
    });

    it('refuses login and explains why when the password is not a password_hash() hash', function () {
        Env::config(['auth' => ['user' => 'admin', 'password' => 'sha256:' . hash('sha256', Env::PASSWORD)]]);
        $browser = new Browser();

        expect($browser->get()['body'])->toContain('must be a password_hash() hash')
            ->and($browser->login()['body'])->toContain('Invalid user or password.');
    });

    it('logs out', function () {
        $browser = new Browser();
        $browser->login();

        $response = $browser->post(['csrf' => $browser->csrf(), 'action' => 'logout']);

        expect($response['status'])->toBe(302)
            ->and($browser->get()['body'])->toContain('name="password"');
    });
});

describe('overview', function () {
    it('shows that all sites are up', function () {
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => []]]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get()['body'];

        expect($body)->toContain('All 2 sites are up')
            ->toContain('<title>uptime</title>')
            ->toContain(Env::site('a'))
            ->toContain(Env::site('b'))
            ->toContain('Last run')
            ->and(substr_count($body, 'badge success">up'))->toBe(2);
    });

    it('shows down sites with their error', function () {
        Env::setSite('b', ['status' => 503]);
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => []]]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get()['body'];

        expect($body)->toContain('1 of 2 sites down')
            ->toContain('<title>(1 down) uptime</title>')
            ->toContain('badge danger">down')
            ->toContain('Unexpected status code 503');
    });

    it('shows slow sites as warning', function () {
        Env::setSite('slow', ['delay' => 0.3]);
        Env::config([
            'max_response_time' => 0.1,
            'dont_send_notifications_on_slow_pages' => true,
            'sites' => [Env::site('slow') => []],
        ]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->toContain('badge warning">slow')->toContain('class="slow"');
    });

    it('shows sites without checks as pending', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->toContain('badge">pending')->toContain('The cronjob has not run yet.');
    });

    it('calculates the uptime', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::migrate();
        foreach ([true, true, true, false] as $i => $success) {
            Env::insertCheck(Env::site('a'), $success, gmdate('c', time() - $i * 3600));
        }
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->toContain('75%');
    });

    it('calculates the uptime for the selected period', function (string $period, string $uptime) {
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::migrate();
        Env::insertCheck(Env::site('a'), true, gmdate('c', time() - 3600));
        Env::insertCheck(Env::site('a'), false, gmdate('c', time() - 3 * 86400));
        Env::insertCheck(Env::site('a'), false, gmdate('c', time() - 20 * 86400));
        $browser = new Browser();
        $browser->login();

        expect($browser->get('/index.php', ['period' => $period])['body'])->toContain(">$uptime<")->toContain('selected>Past ' . $period);
    })->with([
        ['day', '100%'],
        ['week', '50%'],
        ['month', '33.33%'],
    ]);

    it('falls back to the past day for an unknown period', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        $browser = new Browser();
        $browser->login();

        expect($browser->get('/index.php', ['period' => 'year'])['body'])->toContain('selected>Past day');
    });

    it('shows a bar per time slot with failures in the failure color', function () {
        Env::setSite('a', ['status' => 500]);
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get()['body'];
        preg_match('#<span class="bars">(.*?)</span>#s', $body, $bars);

        expect(substr_count($bars[1], '<i'))->toBe(60)
            ->and(substr_count($bars[1], 'class="fail"'))->toBe(1);
    });

    it('lists sent notifications', function () {
        Env::setSite('down', ['status' => 500]);
        Env::config(['sites' => [Env::site('down') => []]]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->toContain('[uptime] 1 of 1 site down')->toContain('ops@example.com');
    });

    it('escapes site urls', function () {
        $url = Env::site('ok', 'q="><script>alert(1)</script>');
        Env::config(['sites' => [$url => []]]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->not->toContain('<script>alert(1)</script>')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });

    it('explains that no sites are configured', function () {
        $browser = new Browser();
        $browser->login();

        expect($browser->get()['body'])->toContain('No sites configured.');
    });
});

describe('site details', function () {
    it('shows the checks of a site', function () {
        Env::config(['sites' => [Env::site('a') => [], Env::site('b') => []]]);
        Env::cron();
        Env::setSite('a', ['status' => 500]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get('/index.php', ['site' => Env::site('a')])['body'];

        expect($body)->toContain('2 checks')
            ->toContain('class="danger">Failed')
            ->toContain('class="success">OK')
            ->toContain('Down since')
            ->toContain('expects 200')
            ->not->toContain(Env::site('b'));
    });

    it('shows the response time histogram, stats and the past 7 days', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::cron();
        Env::setSite('a', ['status' => 500]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get('/index.php', ['site' => Env::site('a')])['body'];
        preg_match('#<div class="hours">(.*?)</div>#s', $body, $today);

        expect($body)->toContain('This check has seen <span class="danger">50%</span> uptime within the past day')
            ->toContain('<dt>Checks</dt><dd>2</dd>')
            ->toContain('<dt>Failures</dt><dd>1</dd>')
            ->and(substr_count($body, '<div class="hours">'))->toBe(7)
            ->and(substr_count($body, 'class="fail" title="' . gmdate('D H:00')))->toBe(1);
        preg_match('#<div class="columns">(.*?)</div>#s', $body, $histogram);
        expect(substr_count($histogram[1], '<i'))->toBe(60);
    });

    it('explains when there are no checks in the period', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        $browser = new Browser();
        $browser->login();

        expect($browser->get('/index.php', ['site' => Env::site('a'), 'period' => 'week'])['body'])
            ->toContain('No checks within the past week yet.');
    });

    it('filters failed checks', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::cron();
        Env::setSite('a', ['status' => 500]);
        Env::cron();
        $browser = new Browser();
        $browser->login();

        $body = $browser->get('/index.php', ['site' => Env::site('a'), 'failed' => 1])['body'];

        expect($body)->toContain('1 check')
            ->toContain('class="danger">Failed')
            ->not->toContain('class="success">OK');
    });

    it('paginates the checks', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        Env::migrate();
        Env::db()->exec("INSERT INTO checks (site, success, checked_at) SELECT '" . Env::site('a') . "', true, now() - make_interval(mins => i) FROM generate_series(1, 150) i");
        $browser = new Browser();
        $browser->login();

        $first = $browser->get('/index.php', ['site' => Env::site('a')])['body'];
        $second = $browser->get('/index.php', ['site' => Env::site('a'), 'page' => 2])['body'];

        expect($first)->toContain('Page 1 of 2')->toContain('Older')
            ->and(substr_count($first, 'class="success">OK'))->toBe(100)
            ->and($second)->toContain('Page 2 of 2')->toContain('Newer')
            ->and(substr_count($second, 'class="success">OK'))->toBe(50);
    });

    it('shows the overview for unknown sites', function () {
        Env::config(['sites' => [Env::site('a') => []]]);
        $browser = new Browser();
        $browser->login();

        expect($browser->get('/index.php', ['site' => 'https://evil.example.com'])['body'])
            ->toContain('All 1 site is up')
            ->not->toContain('evil.example.com');
    });
});

describe('protected files', function () {
    it('refuses to run the cronjob via http', function () {
        expect((new Browser())->get('/cronjob.php')['body'])->toBe('This script can only be run from the command line.');
    });

    it('does not output the config or the email templates via http', function (string $file) {
        expect((new Browser())->get($file)['body'])->not->toContain('password');
    })->with(['/config.example.php', '/email_template.php', '/email_plain_text_template.php', '/lib.php', '/schema.php']);
});
