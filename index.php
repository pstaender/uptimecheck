<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
]);
session_name('uptimecheck');
session_start();

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid request, please reload the page.');
    }
    switch ($_POST['action'] ?? '') {
        case 'login':
            $auth = config()['auth'];
            $userOk = hash_equals((string) $auth['user'], (string) ($_POST['user'] ?? ''));
            $passwordOk = password_hash_configured() && password_verify((string) ($_POST['password'] ?? ''), (string) $auth['password']);
            if ($userOk && $passwordOk) {
                session_regenerate_id(true);
                $_SESSION['user'] = $auth['user'];
                // a login is only valid for the config it was made with (see config.<hostname>.php)
                $_SESSION['config'] = config_file();
                header('Location: ' . url());
                exit;
            }
            sleep(1);
            $error = 'Invalid user or password.';
            break;
        case 'logout':
            $_SESSION = [];
            session_destroy();
            header('Location: ' . url());
            exit;
    }
}

function url(array $params = []): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $params = array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== false);
    return $path . ($params ? '?' . http_build_query($params) : '');
}

function time_tag(?string $timestamp): string
{
    if (!$timestamp) {
        return '–';
    }
    $t = strtotime($timestamp);
    return sprintf('<time datetime="%s">%s UTC</time>', gmdate('c', $t), gmdate('Y-m-d H:i', $t));
}

function percent(?float $value): string
{
    if ($value === null) {
        return '–';
    }
    $percent = $value * 100;
    $class = $percent >= 99.5 ? '' : ($percent >= 95 ? 'warning' : 'danger');
    // floor instead of round: never show 100 % when there were failures
    return sprintf('<span class="%s">%s %%</span>', $class, rtrim(rtrim(number_format(floor($percent * 100) / 100, 2), '0'), '.'));
}

function ms(?float $seconds): string
{
    return $seconds === null ? '–' : number_format($seconds * 1000) . ' ms';
}

function status_badge(array $state): string
{
    if ($state['last_check'] === null) {
        return '<span class="badge">pending</span>';
    }
    if ($state['down']) {
        return '<span class="badge danger">down</span>';
    }
    if ($state['failures_in_a_row'] > 0) {
        return '<span class="badge warning">failing</span>';
    }
    if ($state['last_check']['slow']) {
        return '<span class="badge warning">slow</span>';
    }
    return '<span class="badge success">up</span>';
}

function render_history(array $checks): string
{
    $html = '';
    foreach (array_reverse($checks) as $c) {
        $label = sprintf(
            '%s UTC · %s%s',
            gmdate('Y-m-d H:i', strtotime($c['checked_at'])),
            $c['success'] && !$c['slow'] ? 'OK' : ($c['error'] ?? 'failed'),
            $c['response_time'] !== null ? ' · ' . ms((float) $c['response_time']) : '',
        );
        $html .= sprintf('<i class="%s" title="%s"></i>', $c['success'] ? ($c['slow'] ? 'slow' : 'ok') : 'fail', h($label));
    }
    return '<span class="history">' . $html . '</span>';
}

$loggedIn = isset($_SESSION['user']) && ($_SESSION['config'] ?? null) === config_file();
$site = null;

if ($loggedIn) {
    $pdo = db();
    migrate($pdo);
    $sites = sites();
    $states = site_states($pdo);
    $lastJob = $pdo->query('SELECT * FROM jobs ORDER BY started_at DESC LIMIT 1')->fetch() ?: null;

    $site = isset($_GET['site'], $sites[$_GET['site']]) ? $_GET['site'] : null;
    $selected = $site !== null ? [$site] : array_keys($sites);
    $placeholders = implode(',', array_fill(0, max(1, count($selected)), '?'));

    $stats = [];
    if ($selected) {
        $stmt = $pdo->prepare("
            SELECT site,
                   avg(success::int) FILTER (WHERE checked_at > now() - interval '24 hours') AS up_24h,
                   avg(success::int) FILTER (WHERE checked_at > now() - interval '7 days') AS up_7d,
                   avg(success::int) AS up_30d,
                   avg(response_time) FILTER (WHERE checked_at > now() - interval '24 hours') AS avg_response_24h,
                   count(*) FILTER (WHERE NOT success AND checked_at > now() - interval '24 hours') AS failures_24h
            FROM checks
            WHERE site IN ($placeholders) AND checked_at > now() - interval '30 days'
            GROUP BY site
        ");
        $stmt->execute($selected);
        foreach ($stmt as $row) {
            $stats[$row['site']] = $row;
        }
    }

    if ($site === null) {
        // overview: latest checks of every site for the history bar
        $history = [];
        if ($selected) {
            $stmt = $pdo->prepare("
                SELECT * FROM (
                    SELECT site, checked_at, success, slow, response_time, error,
                           row_number() OVER (PARTITION BY site ORDER BY checked_at DESC, id DESC) AS rn
                    FROM checks WHERE site IN ($placeholders)
                ) t WHERE rn <= 48 ORDER BY site, rn
            ");
            $stmt->execute($selected);
            foreach ($stmt as $row) {
                $history[$row['site']][] = $row;
            }
        }
        $notifications = $pdo->query('SELECT * FROM notifications ORDER BY sent_at DESC LIMIT 10')->fetchAll();
    } else {
        // detail view: paginated list of checks
        $perPage = 100;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $failedOnly = !empty($_GET['failed']);
        $where = 'site = ?' . ($failedOnly ? ' AND NOT success' : '');
        $stmt = $pdo->prepare("SELECT count(*) FROM checks WHERE $where");
        $stmt->execute([$site]);
        $total = (int) $stmt->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $stmt = $pdo->prepare("SELECT * FROM checks WHERE $where ORDER BY checked_at DESC, id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
        $stmt->execute([$site]);
        $checks = $stmt->fetchAll();
    }

    $downCount = count(array_filter($states, fn($s) => $s['down']));
}

?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="color-scheme" content="light dark" />
    <title><?= $loggedIn && $downCount ? "($downCount down) " : '' ?>uptime</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/minimaxcss@latest/minimax-starter.css" />
    <link rel="stylesheet" href="layout.css" />
    <script src="app.js" type="module"></script>
  </head>

  <body<?= $loggedIn && $site === null ? ' data-autorefresh="60"' : '' ?>>
    <main>
<?php if (!$loggedIn): ?>
      <form method="post" class="login">
        <h1>uptime</h1>
<?php if (!password_hash_configured()): ?>
        <p class="danger">auth.password in config.php must be a password_hash() hash. Create one with <code>php -r 'echo password_hash("your-password", PASSWORD_DEFAULT);'</code></p>
<?php endif ?>
<?php if ($error): ?>
        <p class="danger"><?= h($error) ?></p>
<?php endif ?>
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>" />
        <input type="hidden" name="action" value="login" />
        <label>User <input type="text" name="user" autocomplete="username" autofocus /></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" /></label>
        <button type="submit" class="call-to-action">Log in</button>
      </form>
<?php else: ?>
      <header>
        <h1><a href="<?= h(url()) ?>">uptime</a></h1>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>" />
          <button type="submit" name="action" value="logout">Log out</button>
        </form>
      </header>

<?php if ($site === null): ?>
      <section class="summary">
<?php if (!$sites): ?>
        <p>No sites configured. Add them to <code>config.php</code>.</p>
<?php elseif ($downCount): ?>
        <p class="headline danger"><?= $downCount ?> of <?= count($sites) ?> <?= count($sites) === 1 ? 'site' : 'sites' ?> down</p>
<?php else: ?>
        <p class="headline success">All <?= count($sites) ?> <?= count($sites) === 1 ? 'site is' : 'sites are' ?> up</p>
<?php endif ?>
        <p class="muted">
<?php if ($lastJob): ?>
          Last run <?= time_tag($lastJob['started_at']) ?>
<?php if ($lastJob['status'] !== 'finished'): ?>
          · <span class="<?= $lastJob['status'] === 'running' ? '' : 'danger' ?>"><?= h($lastJob['status']) ?></span><?= $lastJob['error'] ? ': ' . h($lastJob['error']) : '' ?>
<?php endif ?>
<?php else: ?>
          The cronjob has not run yet.
<?php endif ?>
        </p>
      </section>

<?php if ($sites): ?>
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Site</th>
            <th>Last check</th>
            <th align="right">Response</th>
            <th align="right">24 h</th>
            <th align="right">7 d</th>
            <th align="right">30 d</th>
            <th>History</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($sites as $url => $options): $state = $states[$url]; $last = $state['last_check']; $s = $stats[$url] ?? null; ?>
          <tr>
            <td><?= status_badge($state) ?></td>
            <td>
              <a href="<?= h(url(['site' => $url])) ?>"><?= h($url) ?></a>
<?php if ($last && (!$last['success'] || $last['slow'])): ?>
              <div class="muted small"><?= h($last['error']) ?></div>
<?php endif ?>
            </td>
            <td><?= time_tag($last['checked_at'] ?? null) ?></td>
            <td align="right"><?= ms($last && $last['response_time'] !== null ? (float) $last['response_time'] : null) ?></td>
            <td align="right"><?= percent($s && $s['up_24h'] !== null ? (float) $s['up_24h'] : null) ?></td>
            <td align="right"><?= percent($s && $s['up_7d'] !== null ? (float) $s['up_7d'] : null) ?></td>
            <td align="right"><?= percent($s ? (float) $s['up_30d'] : null) ?></td>
            <td><?= render_history($history[$url] ?? []) ?></td>
          </tr>
<?php endforeach ?>
        </tbody>
      </table>
<?php endif ?>

      <section>
        <h2>Notifications</h2>
<?php if (!$notifications): ?>
        <p class="muted">No notifications sent yet.</p>
<?php else: ?>
        <table>
          <thead>
            <tr><th>Sent</th><th>Subject</th><th>Recipients</th></tr>
          </thead>
          <tbody>
<?php foreach ($notifications as $n): ?>
            <tr>
              <td><?= time_tag($n['sent_at']) ?></td>
              <td><?= h($n['subject']) ?></td>
              <td><?= h($n['recipients']) ?></td>
            </tr>
<?php endforeach ?>
          </tbody>
        </table>
<?php endif ?>
      </section>

<?php else: $state = $states[$site]; $s = $stats[$site] ?? null; $options = $sites[$site]; ?>
      <p><a href="<?= h(url()) ?>">← All sites</a></p>
      <h2><?= status_badge($state) ?> <?= h($site) ?></h2>
<?php if ($state['down']): ?>
      <p class="danger">Down since <?= time_tag($state['down_since']) ?> · <?= $state['failures_in_a_row'] ?> failed checks in a row</p>
<?php endif ?>

      <dl class="stats">
        <div><dt>Uptime 24 h</dt><dd><?= percent($s && $s['up_24h'] !== null ? (float) $s['up_24h'] : null) ?></dd></div>
        <div><dt>Uptime 7 d</dt><dd><?= percent($s && $s['up_7d'] !== null ? (float) $s['up_7d'] : null) ?></dd></div>
        <div><dt>Uptime 30 d</dt><dd><?= percent($s ? (float) $s['up_30d'] : null) ?></dd></div>
        <div><dt>Avg. response 24 h</dt><dd><?= ms($s && $s['avg_response_24h'] !== null ? (float) $s['avg_response_24h'] : null) ?></dd></div>
        <div><dt>Failures 24 h</dt><dd><?= (int) ($s['failures_24h'] ?? 0) ?></dd></div>
      </dl>

      <p class="muted small">
        <?= h($options['method']) ?> ·
        expects <?= h(implode(', ', $options['status_code'])) ?> ·
        <?= $options['follow_redirects'] ? 'follows redirects' : 'no redirects' ?> ·
        timeout <?= h((string) $options['timeout']) ?> s
        <?= $options['max_response_time'] !== null ? '· max. response ' . ms($options['max_response_time']) : '' ?>
      </p>

      <nav class="filter">
        <a href="<?= h(url(['site' => $site])) ?>"<?= $failedOnly ? '' : ' aria-current="page"' ?>>All checks</a>
        <a href="<?= h(url(['site' => $site, 'failed' => 1])) ?>"<?= $failedOnly ? ' aria-current="page"' : '' ?>>Failed only</a>
        <span class="muted"><?= number_format($total) ?> <?= $total === 1 ? 'check' : 'checks' ?></span>
      </nav>

<?php if (!$checks): ?>
      <p class="muted">No checks yet.</p>
<?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Checked</th>
            <th>Result</th>
            <th align="right">Status code</th>
            <th align="right">Response</th>
            <th>Error</th>
          </tr>
        </thead>
        <tbody>
<?php foreach ($checks as $c): ?>
          <tr>
            <td><?= time_tag($c['checked_at']) ?></td>
            <td><?= $c['success'] ? ($c['slow'] ? '<span class="warning">Slow</span>' : '<span class="success">OK</span>') : '<span class="danger">Failed</span>' ?></td>
            <td align="right"><?= h($c['status_code'] !== null ? (string) $c['status_code'] : '–') ?></td>
            <td align="right"><?= ms($c['response_time'] !== null ? (float) $c['response_time'] : null) ?></td>
            <td><?= h($c['error']) ?></td>
          </tr>
<?php endforeach ?>
        </tbody>
      </table>
<?php if ($pages > 1): ?>
      <nav class="pagination">
<?php if ($page > 1): ?>
        <a href="<?= h(url(['site' => $site, 'failed' => $failedOnly ? 1 : null, 'page' => $page - 1])) ?>">← Newer</a>
<?php endif ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
<?php if ($page < $pages): ?>
        <a href="<?= h(url(['site' => $site, 'failed' => $failedOnly ? 1 : null, 'page' => $page + 1])) ?>">Older →</a>
<?php endif ?>
      </nav>
<?php endif ?>
<?php endif ?>
<?php endif ?>
<?php endif ?>
    </main>
  </body>
</html>
