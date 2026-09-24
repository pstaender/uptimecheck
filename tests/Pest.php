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

/** Subjects of all mails sent so far. */
function subjects(): array
{
    return array_column(Env::mails(), 'subject');
}
