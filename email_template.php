<?php
if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

/**
 * HTML notification mail, rendered by cronjob.php.
 *
 * @var array $downSites   list of ['site', 'down_since', 'failures', 'status_code', 'error']
 * @var array $newlyDown   sites that went down since the last notification
 * @var array $recovered   sites that are up again since the last notification
 * @var array $removed     sites that were down, but got removed from the config
 * @var int $siteCount
 * @var string $generatedAt
 */
$e = fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$font = "font-family: -apple-system, 'Helvetica Neue', Helvetica, Arial, sans-serif;";
$cell = 'padding: 8px 12px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top;';
$red = '#c62828';
$green = '#2e7d32';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin: 0; padding: 24px; background: #ffffff; color: #111111; <?= $font ?> font-size: 15px; line-height: 1.5;">
  <div style="max-width: 640px; margin: 0 auto;">
<?php if ($downSites): ?>
    <h1 style="margin: 0 0 8px; font-size: 20px; color: <?= $red ?>;">
      <?= count($downSites) ?> of <?= $siteCount ?> <?= $siteCount === 1 ? 'site' : 'sites' ?> down
    </h1>
<?php else: ?>
    <h1 style="margin: 0 0 8px; font-size: 20px; color: <?= $green ?>;">All sites are back to normal</h1>
<?php endif ?>
    <p style="margin: 0 0 24px; color: #666666; font-size: 13px;"><?= $e($generatedAt) ?></p>

<?php if ($downSites): ?>
    <table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px;">
      <thead>
        <tr>
          <th style="<?= $cell ?> border-bottom: 2px solid #111;">Site</th>
          <th style="<?= $cell ?> border-bottom: 2px solid #111;">Down since</th>
          <th style="<?= $cell ?> border-bottom: 2px solid #111;">Reason</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($downSites as $site): ?>
        <tr>
          <td style="<?= $cell ?>">
            <a href="<?= $e($site['site']) ?>" style="color: #111111;"><?= $e($site['site']) ?></a>
<?php if (in_array($site['site'], $newlyDown, true)): ?>
            <span style="color: <?= $red ?>; font-size: 12px; font-weight: 600;">NEW</span>
<?php endif ?>
          </td>
          <td style="<?= $cell ?> white-space: nowrap;"><?= $e($site['down_since'] ? gmdate('Y-m-d H:i', strtotime($site['down_since'])) . ' UTC' : '–') ?></td>
          <td style="<?= $cell ?>">
            <?= $e($site['error'] ?? '') ?>
            <div style="color: #666666; font-size: 12px;"><?= (int) $site['failures'] ?> failed <?= $site['failures'] === 1 ? 'check' : 'checks' ?> in a row</div>
          </td>
        </tr>
<?php endforeach ?>
      </tbody>
    </table>
<?php endif ?>

<?php if ($recovered): ?>
    <h2 style="margin: 0 0 8px; font-size: 16px; color: <?= $green ?>;">Back up</h2>
    <ul style="margin: 0 0 24px; padding-left: 20px;">
<?php foreach ($recovered as $site): ?>
      <li><a href="<?= $e($site) ?>" style="color: #111111;"><?= $e($site) ?></a></li>
<?php endforeach ?>
    </ul>
<?php endif ?>

<?php if ($removed): ?>
    <h2 style="margin: 0 0 8px; font-size: 16px;">Removed from monitoring</h2>
    <ul style="margin: 0 0 24px; padding-left: 20px;">
<?php foreach ($removed as $site): ?>
      <li><?= $e($site) ?></li>
<?php endforeach ?>
    </ul>
<?php endif ?>

    <p style="margin: 0; padding-top: 12px; border-top: 1px solid #ddd; color: #666666; font-size: 12px;">
      Sent by uptimecheck. You receive a new mail as soon as the state of any site changes.
    </p>
  </div>
</body>
</html>
