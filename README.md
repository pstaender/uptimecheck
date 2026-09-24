# UptimeCheck

A free, lightweight PHP script that checks whether your websites are up and emails you when one goes down. It has no Composer dependencies and is built for shared hosting, so you can run it as a cronjob.

> **Note:** This project is vibe-coded.

<img width="982" height="1013" alt="Screenshot 2026-09-24 at 22 50 17" src="https://github.com/user-attachments/assets/39c26c2a-69a1-4f21-b69f-d13ff4bf7581" />

## Requirements

- PHP 8.3+ with the `pdo_pgsql` and `curl` extensions
- A PostgreSQL database
- An SMTP server for sending notifications
- Cron (or any scheduler that can run a PHP script)

## Installation

1. Build the release package:

```sh
   php build.php
```

This creates a zip file in the `dist/` folder.

1. Extract the zip and rename `config.example.php` to `config.php`.
2. Create a hashed password for the login (see below) and fill in `config.php` (see [Configuration](#configuration)).
3. Create an empty PostgreSQL database.
4. Upload the files to your web host.
5. Run the check script once manually. This creates the database tables.
6. Set up a cronjob, e.g. every 5 minutes:

```
   */5 * * * * php /path/to/uptimecheck/check.php
```

### Creating a password hash

```sh
composer hash-password mysecretpassword
```

If you don't have Composer, this does the same:

```sh
php -r 'echo password_hash("secret", PASSWORD_DEFAULT), PHP_EOL;'
```

Copy the output (starting with `$2y$`) into the `auth.password` field of your config.

## Configuration

```php
<?php

return [
    "sites" => [
        "https://www.mysite.com/" => [],
        "https://myothersite.com/" => [
            "follow_redirects" => true,
        ],
        "https://myapi.net" => [
            "method" => "POST",
            "status_code" => [200],
            "headers" => [
                "Authorization" => "Bearer mytoken123",
            ],
        ],
    ],

    "tolerated_failures_in_a_row" => 1,
    "timeout" => 5,
    "max_response_time" => 0.5,
    "retention_days" => 90,
    "email" => [
        "from" => "uptime@example.com",
        "to" => ["admin@example.com"],
        "smtp" => [
            "host" => "localhost",
            "port" => 587,
            "username" => "",
            "password" => "",
            "encryption" => "tls",
        ],
    ],

    "db" => [
        "host" => "localhost",
        "username" => "dbuser",
        "password" => "dbpassword",
        "database" => "uptime",
    ],

    "auth" => [
        "user" => "admin",
        "password" => '$2y$10$AnfHxXsCHiJmwa0S/Mt.vu.Zke7nBehC79PLoc8l/sm7nmxpYkczy',
    ],
];
```

<img width="1090" height="909" alt="Screenshot 2026-09-24 at 22 52 16" src="https://github.com/user-attachments/assets/c8b9ce6d-5c5b-4d11-9611-9c19bdb645e6" />

## Security

`config.php` contains your database and SMTP credentials. Make sure it can't be downloaded through the web server. Either place it outside the document root or block access to it, for example with `.htaccess`.
