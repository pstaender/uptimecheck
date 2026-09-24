<?php

use Tests\Support\Browser;
use Tests\Support\Env;

require_once Env::ROOT . '/lib.php';

describe('hostname', function () {
    it('normalizes hostnames', function (?string $host, ?string $expected) {
        expect(normalize_hostname($host))->toBe($expected);
    })->with([
        ['zeitpulse.com', 'zeitpulse.com'],
        ['WWW.Zeitpulse.COM', 'www.zeitpulse.com'],
        ['zeitpulse.com:8080', 'zeitpulse.com'],
        ['zeitpulse.com.', 'zeitpulse.com'],
        ['localhost', 'localhost'],
        ['127.0.0.1', '127.0.0.1'],
        [null, null],
        ['', null],
        ['../config', null],
        ['..', null],
        ['a/b.com', null],
        ['zeitpulse..com', null],
        ['-zeitpulse.com', null],
        ['[::1]:8000', null],
        ["zeitpulse.com\0", null],
        ['example', null],
    ]);

    it('resolves config.<hostname>.php and falls back to config.php', function () {
        $dir = sys_get_temp_dir() . '/uptimecheck-config-' . getmypid();
        @mkdir($dir);
        touch("$dir/config.php");
        touch("$dir/config.zeitpulse.com.php");

        try {
            expect(resolve_config_file($dir, 'zeitpulse.com'))->toBe("$dir/config.zeitpulse.com.php")
                ->and(resolve_config_file($dir, 'www.zeitpulse.com'))->toBe("$dir/config.php")
                ->and(resolve_config_file($dir, null))->toBe("$dir/config.php");
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    });
});

describe('web interface', function () {
    beforeEach(function () {
        Env::config(['sites' => [Env::site('default') => []]]);
        Env::config(['sites' => [Env::site('zeitpulse') => []]], 'zeitpulse.com');
    });

    it('uses the config of the hostname', function () {
        $browser = new Browser('zeitpulse.com');
        $browser->login();

        expect($browser->get()['body'])->toContain(Env::site('zeitpulse'))->not->toContain(Env::site('default'));
    });

    it('ignores the port of the hostname', function () {
        $browser = new Browser('ZEITPULSE.com:8080');
        $browser->login();

        expect($browser->get()['body'])->toContain(Env::site('zeitpulse'));
    });

    it('falls back to config.php when there is no config for the hostname', function () {
        $browser = new Browser('www.zeitpulse.com');
        $browser->login();

        expect($browser->get()['body'])->toContain(Env::site('default'))->not->toContain(Env::site('zeitpulse'));
    });

    it('uses config.www.<hostname>.php for the www subdomain', function () {
        Env::config(['sites' => [Env::site('www') => []]], 'www.zeitpulse.com');
        $browser = new Browser('www.zeitpulse.com');
        $browser->login();

        expect($browser->get()['body'])->toContain(Env::site('www'));
    });

    it('does not accept a login made with the config of another hostname', function () {
        $browser = new Browser('other.example.com');
        $browser->login();
        expect($browser->get()['body'])->toContain('Log out');

        expect($browser->host('zeitpulse.com')->get()['body'])
            ->toContain('name="password"')
            ->not->toContain(Env::site('zeitpulse'));
    });

    it('uses the credentials of the hostname config', function () {
        Env::config([
            'sites' => [Env::site('zeitpulse') => []],
            'auth' => ['user' => 'zeitpulse', 'password' => password_hash('other', PASSWORD_DEFAULT)],
        ], 'zeitpulse.com');

        expect((new Browser('zeitpulse.com'))->login()['body'])->toContain('Invalid user or password.')
            ->and((new Browser('zeitpulse.com'))->login('zeitpulse', 'other')['status'])->toBe(302);
    });
});

describe('cronjob', function () {
    beforeEach(function () {
        Env::config(['sites' => [Env::site('default') => []]]);
        Env::config(['sites' => [Env::site('zeitpulse') => []]], 'zeitpulse.com');
    });

    it('uses config.php without --host', function () {
        $result = Env::cron('--verbose');

        expect($result['out'])->toContain('/config.php')
            ->and(array_column(checks(), 'site'))->toBe([Env::site('default')]);
    });

    it('uses the config of --host', function () {
        $result = Env::cron('--verbose', '--host=zeitpulse.com');

        expect($result['out'])->toContain('/config.zeitpulse.com.php')
            ->and(array_column(checks(), 'site'))->toBe([Env::site('zeitpulse')]);
    });

    it('falls back to config.php for an unknown --host', function () {
        Env::cron('--host=www.zeitpulse.com');

        expect(array_column(checks(), 'site'))->toBe([Env::site('default')]);
    });
});
