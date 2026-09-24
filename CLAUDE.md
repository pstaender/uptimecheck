# Uptimecheck

Uptimecheck is a very simple php script for shared hosting sites.

`index.php` shows the interface (login required).

`cronjob.php` runs the cronjob, only executable on cli / cronjob.

`schema.php` contains the database schema.

`config.php` has the config (gitignored, see `config.example.php` for all options). A config per hostname is possible: `config.<hostname>.php` (e.g. `config.zeitpulse.com.php`, `config.www.zeitpulse.com.php`) is used when it exists, `config.php` otherwise. The web interface takes the hostname from the request, the cronjob from `--host=<hostname>`. A login is only valid for the config it was made with.

`lib.php` contains shared helpers (config, db, migrations, site state) for `index.php` and `cronjob.php`.


## Frontend

The frontend is using minimax as css foundation (very minimal).

The interface is very clean, businesslike and simple. It is not overloaded with features, but it has all the necessary functions to see the past uptime checks. Use colors very frugal (only for indicating warning / greenlight etc). It's not for configuring the app, this is be done via the config.php.

A login is required to see the inerface

## Cronjob

The cronjob php is build, to get executed in intervals from external service (shared hoster often offer cronjobs). So this script is the executing the whole check http services batch job. When another job is still running, the script aborts. That means, if the check takes more than the interval time, the current interval is skipped.

## Database schema

The following tables exists:

* jobs
* checks
* notifications
* migrations

The jobs table keeps track of the running job.

The check contains the check of every website. The table keeps track of failed and successful checks.

## Check in cronjob.php

First the script migrates the database. The the script (in case the script exists/crashed later in the process) looks for the last checks and find sites whose failed checks in a row reached the value of tolerated_failures_in_a_row (1 = notify on the first failed check). When the threshold is reached, the website(s) are marked as down and the notification is sent (send a consolidated notification, not one message per website). The script will not send notifications for every failed check, only when the threshold is reached.
When something in up and down sites has changed since the last notification, send a new notification. When everything is up to normal, also send a notification that everything is back to normal. Use the `email_template.php`. Use inline css to style the html. Also send a plain text email (see `email_plain_text_template.php`).

## Programming Style

* simple is better than clever
* use built-in functions wherever possible
* don't comment code, the code should be self-explanatory
* only comment / describe business logic, not the code itself

## Technology

* use modern CSS
* use modern vanilla (module) JS (if you need to use module, load the directly in the js file, no NodeJS)
* use modern PHP 8.3+
* use only postgres for database (pdo_pgsql)
* use php built-in functions, no composer packages at runtime (dev dependencies like pest are fine)
* use php curl for http requests

## Development

* `composer serve` starts the web interface on http://localhost:8000
* `composer cron` runs the checks once (verbose), `composer test-mail` sends a test mail (both accept `-- --host=<hostname>`)
* `composer hash-password -- 'your-password'` prints the hash for `auth.password`
* `composer build` creates `dist/uptimecheck-<version>.zip` with the files for the web hosting (list in `build.php`, version from `composer.json`)
* `composer test` runs the pest tests in `tests/`. They need a local postgres (database `uptime_test` is created if missing, see `tests/Support/Env.php` for the `UPTIMECHECK_TEST_DB_*` env vars). The tests run `cronjob.php` and the web interface as real processes against a fixture http server (`tests/Support/router.php`) and a fake smtp server (`tests/Support/smtp_server.php`).

