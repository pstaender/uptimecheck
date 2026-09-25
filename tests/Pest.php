<?php

use Tests\Support\Env;

/*
 * Every test starts with an empty database, no mails and default site states.
 * See tests/Support/Env.php for the test environment.
 */
pest()->beforeEach(fn() => Env::reset())->in(__DIR__);

/** Selects all checks, oldest first. */
function checks(): array
{
    return Env::db()->query('SELECT * FROM checks ORDER BY id')->fetchAll();
}

/** A link to the web interface as it appears (html escaped) in its pages. */
function h_url(array $params): string
{
    return htmlspecialchars('/index.php?' . http_build_query($params), ENT_QUOTES);
}

/** A site as it is named in the plain text mails: without scheme and trailing slash. */
function plain_name(string $url): string
{
    return rtrim(preg_replace('#^https?://#', '', $url), '/');
}

/** Subjects of all mails sent so far. */
function subjects(): array
{
    return array_column(Env::mails(), 'subject');
}
