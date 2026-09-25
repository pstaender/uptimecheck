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
 * @var ?string $interfaceUrl  url of the web interface (config interface_url), to link the sites to their detail view
 */
$e = fn(?string $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$name = fn(string $url): string => $e(preg_replace('#^https?://#', '', $url));
$nameLink = function (string $site) use ($e, $name, $interfaceUrl): string {
    $style = 'color: #1d1f23; font-weight: 500; text-decoration: none;';
    if (!$interfaceUrl) {
        return "<span style=\"$style\">{$name($site)}</span>";
    }
    return "<a href=\"{$e(detail_url($interfaceUrl, $site))}\" style=\"$style\">{$name($site)}</a>";
};

$text = '#1d1f23';
$muted = '#8a94a6';
$line = '#eceef2';
$red = '#ec6b6b';
$green = '#4ca43f';
$band = '#1f2125';
$bandMuted = '#b0b6bf';

$font = "font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Helvetica Neue', Helvetica, Arial, sans-serif;";
$label = "font-size: 11px; font-weight: 600; letter-spacing: 0.04em; text-transform: uppercase; color: $text;";
$value = "font-size: 16px; font-weight: 300; color: $muted; white-space: nowrap;";
$heading = "margin: 0 0 4px; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; color: $text;";
$row = "padding: 16px 0; border-bottom: 1px solid $line; vertical-align: top;";
$badge = fn(string $color, string $caption): string => "<span style=\"color: $color; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; white-space: nowrap;\">&#9679; $caption</span>";

$downCount = count($downSites);
$upCount = $siteCount - $downCount;
$changes = array_filter([
    $newlyDown ? count($newlyDown) . ' newly down' : null,
    $recovered ? count($recovered) . ' back up' : null,
    $removed ? count($removed) . ' removed from monitoring' : null,
]);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
</head>
<body style="margin: 0; padding: 0; background: #ffffff; color: <?= $text ?>; <?= $font ?> font-size: 15px; line-height: 1.5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #ffffff;">
    <tr>
      <td align="center" style="padding: 32px 16px 0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px;">
          <tr>
            <td style="font-size: 13px; letter-spacing: 0.02em; color: <?= $text ?>;"><b>UPTIME</b>CHECK</td>
          </tr>
          <tr>
            <td align="center" style="padding: 48px 0 40px;">
<?php if ($downSites): ?>
              <h1 style="margin: 0 0 8px; font-size: 34px; font-weight: 300; line-height: 1.2; color: <?= $text ?>;"><?= $downCount ?> of <?= $siteCount ?> <?= $siteCount === 1 ? 'site' : 'sites' ?> down</h1>
              <p style="margin: 0; font-size: 17px; font-weight: 300; color: <?= $muted ?>;"><?= implode(' · ', $changes) ?> since the last notification</p>
<?php else: ?>
              <h1 style="margin: 0 0 8px; font-size: 34px; font-weight: 300; line-height: 1.2; color: <?= $text ?>;">All sites are back to normal</h1>
              <p style="margin: 0; font-size: 17px; font-weight: 300; color: <?= $muted ?>;"><?= $siteCount === 1 ? 'The site is' : "All $siteCount sites are" ?> up again.</p>
<?php endif ?>
            </td>
          </tr>

<?php if ($downSites): ?>
          <tr>
            <td style="padding-bottom: 32px;">
              <h2 style="<?= $heading ?>">Down</h2>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<?php foreach ($downSites as $site): ?>
                <tr>
                  <td style="<?= $row ?>">
                    <?= $nameLink($site['site']) ?>
                    <?= in_array($site['site'], $newlyDown, true) ? $badge($red, 'new') : '' ?><br>
                    <a href="<?= $e($site['site']) ?>" style="font-size: 13px; font-weight: 300; text-decoration: none; color: <?= $muted ?>;"><?= $e($site['site']) ?></a><br>
                    <span style="font-size: 13px; color: <?= $red ?>;"><?= $e($site['error'] ?? '') ?></span>
                  </td>
                  <td width="120" style="<?= $row ?> padding-left: 16px;">
                    <div style="<?= $label ?>">Down since</div>
                    <div style="<?= $value ?>"><?= $e($site['down_since'] ? gmdate('M j, H:i', strtotime($site['down_since'])) : '–') ?></div>
                  </td>
                  <td width="80" style="<?= $row ?> padding-left: 16px;">
                    <div style="<?= $label ?>">Failures</div>
                    <div style="<?= $value ?>"><?= (int) $site['failures'] ?> in a row</div>
                  </td>
                </tr>
<?php endforeach ?>
              </table>
            </td>
          </tr>
<?php endif ?>

<?php if ($recovered): ?>
          <tr>
            <td style="padding-bottom: 32px;">
              <h2 style="<?= $heading ?>">Back up</h2>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<?php foreach ($recovered as $site): ?>
                <tr>
                  <td style="<?= $row ?>">
                    <?= $nameLink($site) ?><br>
                    <a href="<?= $e($site) ?>" style="font-size: 13px; font-weight: 300; text-decoration: none; color: <?= $muted ?>;"><?= $e($site) ?></a>
                  </td>
                  <td width="80" align="right" style="<?= $row ?>"><?= $badge($green, 'up') ?></td>
                </tr>
<?php endforeach ?>
              </table>
            </td>
          </tr>
<?php endif ?>

<?php if ($removed): ?>
          <tr>
            <td style="padding-bottom: 32px;">
              <h2 style="<?= $heading ?>">Removed from monitoring</h2>
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<?php foreach ($removed as $site): ?>
                <tr>
                  <td style="<?= $row ?> color: <?= $muted ?>;"><?= $e($site) ?></td>
                </tr>
<?php endforeach ?>
              </table>
            </td>
          </tr>
<?php endif ?>
        </table>
      </td>
    </tr>
    <tr>
      <td align="center" style="padding: 24px 16px 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px;">
          <tr>
            <td style="font-size: 12px; color: <?= $muted ?>; text-align: center;">
              You receive a new mail as soon as the state of any site changes.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
