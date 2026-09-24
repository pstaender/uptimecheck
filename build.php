<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This script can only be run from the command line.');
}

const FILES = [
    '.htaccess',
    'app.js',
    'config.example.php',
    'cronjob.php',
    'email_plain_text_template.php',
    'email_template.php',
    'index.php',
    'layout.css',
    'lib.php',
    'schema.php',
];

$version = json_decode(file_get_contents(__DIR__ . '/composer.json'), true)['version'] ?? 'dev';
$archive = __DIR__ . "/dist/uptimecheck-$version.zip";

if (!is_dir(dirname($archive))) {
    mkdir(dirname($archive));
}
if (is_file($archive)) {
    unlink($archive);
}

$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Could not create $archive\n");
    exit(1);
}
foreach (FILES as $file) {
    if (!is_file(__DIR__ . "/$file")) {
        fwrite(STDERR, "Missing file: $file\n");
        exit(1);
    }
    $zip->addFile(__DIR__ . "/$file", "uptimecheck/$file");
}
$zip->close();

echo 'Created ', substr($archive, strlen(__DIR__) + 1), ' (', count(FILES), ' files)', PHP_EOL;
