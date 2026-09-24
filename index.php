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

const PERIODS = [
    'day' => ['label' => 'Past day', 'seconds' => 86400, 'tick' => 'time', 'tick_fallback' => 'H:i'],
    'week' => ['label' => 'Past week', 'seconds' => 604800, 'tick' => 'weekday', 'tick_fallback' => 'D j'],
    'month' => ['label' => 'Past month', 'seconds' => 2592000, 'tick' => 'date', 'tick_fallback' => 'M j'],
];
const VIEWS = ['distribution' => 'Distribution', 'time' => 'Over time'];
const BARS = 60;
const TIMELINE_BARS = 120;
const HISTOGRAM_BINS = 60;

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
    // floor instead of round: never show 100% when there were failures
    $percent = floor($value * 10000) / 100;
    $class = $percent >= 99.5 ? '' : ($percent >= 95 ? 'warning' : 'danger');
    return sprintf('<span class="%s">%s%%</span>', $class, rtrim(rtrim(number_format($percent, 2), '0'), '.'));
}

function ms(?float $seconds): string
{
    return $seconds === null ? '–' : number_format($seconds * 1000) . 'ms';
}

function duration(float $seconds): string
{
    return $seconds < 1 ? round($seconds * 1000) . 'ms' : rtrim(rtrim(number_format($seconds, 1), '0'), '.') . 's';
}

function site_name(string $url): string
{
    return rtrim(preg_replace('#^https?://#', '', $url), '/');
}

/**
 * Position of a response time between fast (0) and the site's max_response_time (1),
 * used to color bars from blue to purple.
 */
function speed(float $responseTime, float $scale): float
{
    return round(min(1, $responseTime / $scale), 3);
}

function nice_ceil(float $seconds): float
{
    foreach ([0.1, 0.2, 0.25, 0.5, 1, 2, 2.5, 5, 10, 20, 30, 60] as $step) {
        if ($seconds <= $step) {
            return $step;
        }
    }
    return ceil($seconds / 60) * 60;
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

function check_class(array $row): string
{
    return !$row['success'] ? 'fail' : ($row['slow'] ? 'slow' : 'ok');
}

/**
 * Mean response time, result and number of checks per time slot of $bucketSeconds, keyed by site and slot (1 = oldest).
 */
function response_buckets(PDO $pdo, array $sites, int $from, int $bucketSeconds, int $count): array
{
    if (!$sites) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($sites), '?'));
    $stmt = $pdo->prepare("
        SELECT site,
               width_bucket(extract(epoch FROM checked_at), ?::numeric, ?::numeric, $count) AS bucket,
               avg(response_time) AS response_time,
               bool_and(success) AS success,
               bool_or(slow) AS slow,
               count(*) AS checks
        FROM checks
        WHERE site IN ($placeholders) AND checked_at >= to_timestamp(?)
        GROUP BY site, bucket
    ");
    $stmt->execute([$from, $from + $count * $bucketSeconds, ...$sites, $from]);
    $buckets = [];
    foreach ($stmt as $row) {
        $buckets[$row['site']][$row['bucket']] = $row;
    }
    return $buckets;
}

function render_bars(array $buckets, int $count, int $from, int $bucketSeconds, float $scale, ?float $max = null): string
{
    $max ??= max([0.001, ...array_map(fn($b) => (float) $b['response_time'], $buckets)]);
    $html = '';
    for ($i = 1; $i <= $count; $i++) {
        $bucket = $buckets[$i] ?? null;
        if ($bucket === null) {
            $html .= '<i></i>';
            continue;
        }
        $class = check_class($bucket);
        $responseTime = $bucket['response_time'] !== null ? (float) $bucket['response_time'] : null;
        $title = sprintf(
            '%s UTC · %s · %d %s',
            gmdate('Y-m-d H:i', $from + ($i - 1) * $bucketSeconds),
            $class === 'fail' ? 'failed' : 'mean ' . ms($responseTime),
            $bucket['checks'],
            $bucket['checks'] === 1 ? 'check' : 'checks',
        );
        $html .= sprintf(
            '<i class="%s" style="--h:%.3f;--t:%.3f" title="%s"></i>',
            $class,
            $class === 'fail' || $responseTime === null ? 1 : min(1, max(0.02, $responseTime / $max)),
            $responseTime === null ? 1 : speed($responseTime, $scale),
            h($title),
        );
    }
    return '<span class="bars">' . $html . '</span>';
}

$loggedIn = isset($_SESSION['user']) && ($_SESSION['config'] ?? null) === config_file();
$site = null;

if ($loggedIn) {
    $pdo = db();
    migrate($pdo);
    $sites = sites();
    $states = site_states($pdo);
    $lastJob = $pdo->query('SELECT * FROM jobs ORDER BY started_at DESC LIMIT 1')->fetch() ?: null;

    $period = isset(PERIODS[$_GET['period'] ?? '']) ? $_GET['period'] : 'day';
    $periodSeconds = PERIODS[$period]['seconds'];
    $from = time() + 1 - $periodSeconds;

    $site = isset($_GET['site'], $sites[$_GET['site']]) ? $_GET['site'] : null;
    $view = isset(VIEWS[$_GET['view'] ?? '']) ? $_GET['view'] : 'distribution';
    $query = [
        'site' => $site,
        'period' => $period === 'day' ? null : $period,
        'view' => $view === 'distribution' ? null : $view,
    ];
    $selected = $site !== null ? [$site] : array_keys($sites);
    $placeholders = implode(',', array_fill(0, max(1, count($selected)), '?'));

    $stats = [];
    if ($selected) {
        $stmt = $pdo->prepare("
            SELECT site,
                   count(*) AS checks,
                   avg(success::int) AS uptime,
                   avg(response_time) AS mean,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY response_time) AS p95,
                   max(response_time) AS max,
                   count(*) FILTER (WHERE NOT success) AS failures
            FROM checks
            WHERE site IN ($placeholders) AND checked_at >= to_timestamp(?)
            GROUP BY site
        ");
        $stmt->execute([...$selected, $from]);
        foreach ($stmt as $row) {
            $stats[$row['site']] = $row;
        }
    }

    if ($site === null) {
        $bucketSeconds = intdiv($periodSeconds, BARS);
        $buckets = response_buckets($pdo, $selected, $from, $bucketSeconds, BARS);
        $notifications = $pdo->query('SELECT * FROM notifications ORDER BY sent_at DESC LIMIT 10')->fetchAll();
    } else {
        $options = $sites[$site];
        $state = $states[$site];
        $scale = $options['max_response_time'] ?? 1.0;
        $s = $stats[$site] ?? null;

        if ($view === 'time') {
            $timelineSeconds = intdiv($periodSeconds, TIMELINE_BARS);
            $timeline = response_buckets($pdo, [$site], $from, $timelineSeconds, TIMELINE_BARS)[$site] ?? [];
            $upper = nice_ceil(max([0, ...array_map(fn($b) => (float) $b['response_time'], $timeline)]) ?: 1);
        } else {
            $upper = nice_ceil((float) ($s['max'] ?? 1) ?: 1);
            $histogram = array_fill(1, HISTOGRAM_BINS, 0);
            $stmt = $pdo->prepare('
                SELECT width_bucket(response_time, 0, ?::double precision, ' . HISTOGRAM_BINS . ') AS bin, count(*) AS checks
                FROM checks
                WHERE site = ? AND checked_at >= to_timestamp(?) AND response_time IS NOT NULL
                GROUP BY bin
            ');
            $stmt->execute([$upper, $site, $from]);
            foreach ($stmt as $row) {
                $histogram[min(HISTOGRAM_BINS, max(1, $row['bin']))] += $row['checks'];
            }
            $histogramMax = max(1, ...$histogram);
        }

        $gridFrom = gmmktime(0, 0, 0) - 6 * 86400;
        $hours = [];
        $stmt = $pdo->prepare("
            SELECT extract(epoch FROM date_trunc('hour', checked_at))::bigint AS hour,
                   bool_and(success) AS success,
                   bool_or(slow) AS slow,
                   count(*) AS checks
            FROM checks
            WHERE site = ? AND checked_at >= to_timestamp(?)
            GROUP BY 1
        ");
        $stmt->execute([$site, $gridFrom]);
        foreach ($stmt as $row) {
            $hours[$row['hour']] = $row;
        }

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
    <header class="topbar">
      <a class="brand" href="<?= h(url()) ?>"><b>UPTIME</b>CHECK</a>
<?php if ($loggedIn): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>" />
        <button type="submit" name="action" value="logout" class="link">Log out</button>
      </form>
<?php endif ?>
    </header>

    <main>
<?php if (!$loggedIn): ?>
      <form method="post" class="login">
        <hgroup class="hero">
          <h1>Sign in</h1>
          <p>Uptime of your sites at a glance.</p>
        </hgroup>
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
        <button type="submit">Log in</button>
      </form>
<?php elseif ($site === null): ?>
      <hgroup class="hero">
        <h1>Checks</h1>
<?php if (!$sites): ?>
        <p>No sites configured. Add them to <code>config.php</code>.</p>
<?php elseif ($downCount): ?>
        <p class="danger"><?= $downCount ?> of <?= count($sites) ?> <?= count($sites) === 1 ? 'site' : 'sites' ?> down</p>
<?php else: ?>
        <p>All <?= count($sites) ?> <?= count($sites) === 1 ? 'site is' : 'sites are' ?> up</p>
<?php endif ?>
      </hgroup>

<?php if ($sites): ?>
      <form class="period" method="get">
        <select name="period" aria-label="Period" data-autosubmit>
<?php foreach (PERIODS as $key => $p): ?>
          <option value="<?= $key ?>"<?= $key === $period ? ' selected' : '' ?>><?= $p['label'] ?></option>
<?php endforeach ?>
        </select>
        <noscript><button type="submit">Show</button></noscript>
      </form>

      <div class="checks">
<?php foreach ($sites as $url => $options): $state = $states[$url]; $last = $state['last_check']; $s = $stats[$url] ?? null; ?>
        <article>
          <div class="name">
            <a href="<?= h(url(['site' => $url, 'period' => $query['period']])) ?>"><?= h(site_name($url)) ?></a>
            <span class="muted"><?= h($url) ?></span>
<?php if ($last && (!$last['success'] || $last['slow'])): ?>
            <span class="<?= $last['success'] ? 'warning' : 'danger' ?> small"><?= h($last['error']) ?></span>
<?php endif ?>
          </div>
          <dl class="metrics">
            <div><dt>Uptime</dt><dd><?= percent($s ? (float) $s['uptime'] : null) ?></dd></div>
            <div><dt>Mean</dt><dd><?= ms($s && $s['mean'] !== null ? (float) $s['mean'] : null) ?></dd></div>
            <div><dt>Status</dt><dd><?= status_badge($state) ?></dd></div>
          </dl>
          <?= render_bars($buckets[$url] ?? [], BARS, $from, $bucketSeconds, $options['max_response_time'] ?? 1.0) ?>
        </article>
<?php endforeach ?>
      </div>
<?php endif ?>

      <section class="notifications">
        <h2>Notifications</h2>
<?php if (!$notifications): ?>
        <p class="muted">No notifications sent yet.</p>
<?php else: ?>
        <ul>
<?php foreach ($notifications as $n): ?>
          <li>
            <span class="muted"><?= time_tag($n['sent_at']) ?></span>
            <span><?= h($n['subject']) ?></span>
            <span class="muted"><?= h($n['recipients']) ?></span>
          </li>
<?php endforeach ?>
        </ul>
<?php endif ?>
      </section>
<?php else: ?>
      <nav class="back"><a href="<?= h(url(['period' => $query['period']])) ?>">← All checks</a></nav>

      <hgroup class="hero">
        <h1><?= h(site_name($site)) ?></h1>
<?php if ($s): ?>
        <p>This check has seen <?= percent((float) $s['uptime']) ?> uptime within the <?= strtolower(PERIODS[$period]['label']) ?>, and a mean response time of <?= ms($s['mean'] !== null ? (float) $s['mean'] : null) ?>.</p>
<?php else: ?>
        <p>No checks within the <?= strtolower(PERIODS[$period]['label']) ?> yet.</p>
<?php endif ?>
<?php if ($state['down']): ?>
        <p class="danger">Down since <?= time_tag($state['down_since']) ?> · <?= $state['failures_in_a_row'] ?> failed <?= $state['failures_in_a_row'] === 1 ? 'check' : 'checks' ?> in a row</p>
<?php endif ?>
      </hgroup>

<?php if ($view === 'time'): ?>
      <figure class="chart timeline">
        <div class="y-axis">
<?php foreach (range(4, 0) as $tick): ?>
          <span><?= duration($upper * $tick / 4) ?></span>
<?php endforeach ?>
        </div>
        <?= render_bars($timeline, TIMELINE_BARS, $from, $timelineSeconds, $scale, $upper) ?>
        <div class="axis">
<?php foreach (range(0, 4) as $tick): $ts = $from + intdiv($periodSeconds * $tick, 4); ?>
          <time datetime="<?= gmdate('c', $ts) ?>" data-format="<?= PERIODS[$period]['tick'] ?>"><?= gmdate(PERIODS[$period]['tick_fallback'], $ts) ?></time>
<?php endforeach ?>
        </div>
      </figure>
<?php else: ?>
      <figure class="chart histogram">
        <div class="columns">
<?php foreach ($histogram as $bin => $count): $binFrom = ($bin - 1) * $upper / HISTOGRAM_BINS; $binTo = $bin * $upper / HISTOGRAM_BINS; ?>
          <i style="--h:<?= round($count / $histogramMax, 3) ?>;--t:<?= speed(($binFrom + $binTo) / 2, $scale) ?>" title="<?= h(duration($binFrom) . ' – ' . duration($binTo) . ": $count " . ($count === 1 ? 'check' : 'checks')) ?>"></i>
<?php endforeach ?>
        </div>
        <div class="axis">
<?php foreach (range(0, 4) as $tick): ?>
          <span><?= duration($upper * $tick / 4) ?></span>
<?php endforeach ?>
        </div>
      </figure>
<?php endif ?>
      <p class="legend muted small">
        <span class="scale"></span> 0ms – <?= ms($scale) ?><?= $options['max_response_time'] === null ? '' : ' (max. response)' ?>
        <span class="swatch fail"></span> failed
      </p>

      <div class="toolbar">
        <p class="muted small">
          <?= h($options['method']) ?> ·
          expects <?= h(implode(', ', $options['status_code'])) ?> ·
          <?= $options['follow_redirects'] ? 'follows redirects' : 'no redirects' ?> ·
          timeout <?= h((string) $options['timeout']) ?>s
          <?= $options['max_response_time'] !== null ? '· max. response ' . ms($options['max_response_time']) : '' ?>
        </p>
        <nav class="views">
<?php foreach (VIEWS as $key => $label): ?>
          <a href="<?= h(url([...$query, 'view' => $key === 'distribution' ? null : $key])) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= $label ?></a>
<?php endforeach ?>
        </nav>
        <form class="period" method="get">
          <input type="hidden" name="site" value="<?= h($site) ?>" />
<?php if ($query['view']): ?>
          <input type="hidden" name="view" value="<?= h($query['view']) ?>" />
<?php endif ?>
          <select name="period" aria-label="Period" data-autosubmit>
<?php foreach (PERIODS as $key => $p): ?>
            <option value="<?= $key ?>"<?= $key === $period ? ' selected' : '' ?>><?= $p['label'] ?></option>
<?php endforeach ?>
          </select>
          <noscript><button type="submit">Show</button></noscript>
        </form>
      </div>

      <section class="days">
        <h2>Past 7 days</h2>
        <div class="grid">
<?php foreach (range(0, 6) as $day): $dayStart = $gridFrom + $day * 86400; ?>
          <div class="day">
            <div class="hours">
<?php foreach (range(0, 23) as $hour): $ts = $dayStart + $hour * 3600; $row = $hours[$ts] ?? null; ?>
<?php if ($ts > time()): ?>
              <i class="future"></i>
<?php elseif ($row === null): ?>
              <i title="<?= gmdate('D H:00', $ts) ?> UTC · no checks"></i>
<?php else: ?>
              <i class="<?= check_class($row) ?>" title="<?= h(gmdate('D H:00', $ts) . ' UTC · ' . ($row['success'] ? ($row['slow'] ? 'slow' : 'up') : 'failed') . ' · ' . $row['checks'] . ($row['checks'] === 1 ? ' check' : ' checks')) ?>"></i>
<?php endif ?>
<?php endforeach ?>
            </div>
            <span><?= gmdate('D', $dayStart) ?></span>
          </div>
<?php endforeach ?>
        </div>
      </section>

      <section>
        <nav class="filter">
          <a href="<?= h(url($query)) ?>"<?= $failedOnly ? '' : ' aria-current="page"' ?>>All checks</a>
          <a href="<?= h(url([...$query, 'failed' => 1])) ?>"<?= $failedOnly ? ' aria-current="page"' : '' ?>>Failed only</a>
          <span class="muted"><?= number_format($total) ?> <?= $total === 1 ? 'check' : 'checks' ?></span>
        </nav>

<?php if (!$checks): ?>
        <p class="muted" style="margin-top: var(--space);">No checks yet.</p>
<?php else: ?>
        <table class="list">
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
          <a href="<?= h(url([...$query, 'failed' => $failedOnly ? 1 : null, 'page' => $page - 1])) ?>">← Newer</a>
<?php endif ?>
          <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
<?php if ($page < $pages): ?>
          <a href="<?= h(url([...$query, 'failed' => $failedOnly ? 1 : null, 'page' => $page + 1])) ?>">Older →</a>
<?php endif ?>
        </nav>
<?php endif ?>
<?php endif ?>
      </section>
<?php endif ?>
    </main>

<?php if ($loggedIn): ?>
    <footer class="muted small">
<?php if ($lastJob): ?>
      Last run <?= time_tag($lastJob['started_at']) ?>
<?php if ($lastJob['status'] !== 'finished'): ?>
      · <span class="<?= $lastJob['status'] === 'running' ? '' : 'danger' ?>"><?= h($lastJob['status']) ?></span><?= $lastJob['error'] ? ': ' . h($lastJob['error']) : '' ?>
<?php endif ?>
<?php else: ?>
      The cronjob has not run yet.
<?php endif ?>
      · <b>UPTIME</b>CHECK
    </footer>
<?php endif ?>

<?php if ($loggedIn && $site !== null): ?>
    <dl class="statsbar">
      <div><dt>Uptime</dt><dd><?= percent($s ? (float) $s['uptime'] : null) ?></dd></div>
      <div><dt>Mean response</dt><dd><?= ms($s && $s['mean'] !== null ? (float) $s['mean'] : null) ?></dd></div>
      <div><dt>95th percentile</dt><dd><?= ms($s && $s['p95'] !== null ? (float) $s['p95'] : null) ?></dd></div>
      <div><dt>Checks</dt><dd><?= number_format((int) ($s['checks'] ?? 0)) ?></dd></div>
      <div><dt>Failures</dt><dd><?= number_format((int) ($s['failures'] ?? 0)) ?></dd></div>
      <div><dt>Status</dt><dd><?= status_badge($state) ?></dd></div>
    </dl>
<?php endif ?>
  </body>
</html>
