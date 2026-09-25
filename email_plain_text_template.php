<?php
if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

/**
 * Plain text notification mail, rendered by cronjob.php.
 *
 * @var array $downSites   list of ['site', 'down_since', 'failures', 'status_code', 'error']
 * @var array $newlyDown   sites that went down since the last notification
 * @var array $recovered   sites that are up again since the last notification
 * @var array $removed     sites that were down, but got removed from the config
 * @var int $siteCount
 * @var string $generatedAt
 * @var ?string $interfaceUrl  url of the web interface (config interface_url), to link the sites to their detail view
 */

$siteName = function (string $site) use ($interfaceUrl): string {
    $name = rtrim(preg_replace('#^https?://#', '', $site), '/');
    return $interfaceUrl ? "[$name](" . detail_url($interfaceUrl, $site) . ')' : $name;
};

if ($downSites) {
    echo count($downSites), ' of ', $siteCount, $siteCount === 1 ? ' site' : ' sites', " down\n";
} else {
    echo "All sites are back to normal\n";
}
echo $generatedAt, "\n\n";

foreach ($downSites as $site) {
    echo '* ', $siteName($site['site']), in_array($site['site'], $newlyDown, true) ? ' [NEW]' : '', "\n";
    if ($site['down_since']) {
        echo '  Down since: ', gmdate('Y-m-d H:i', strtotime($site['down_since'])), " UTC\n";
    }
    echo '  Reason:     ', $site['error'] ?? '–', "\n";
    echo '  Failures:   ', $site['failures'], " in a row\n\n";
}

if ($recovered) {
    echo "Back up:\n";
    foreach ($recovered as $site) {
        echo '* ', $siteName($site), "\n";
    }
    echo "\n";
}

if ($removed) {
    echo "Removed from monitoring:\n";
    foreach ($removed as $site) {
        echo '* ', $site, "\n";
    }
    echo "\n";
}

echo "--\nSent by uptimecheck. You receive a new mail as soon as the state of any site changes.\n";
