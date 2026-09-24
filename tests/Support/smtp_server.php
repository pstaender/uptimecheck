<?php

/**
 * Fake SMTP server for the tests: accepts everything and stores every
 * message as <dir>/<n>.eml. If <dir>/reject exists, recipients are rejected.
 *
 * Usage: php smtp_server.php <port> <dir>
 */

[, $port, $dir] = $argv;

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "$errstr\n");
    exit(1);
}

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }
    $reply = fn(string $line) => fwrite($conn, "$line\r\n");
    $reply('220 fake smtp');
    $data = null;

    while (($line = fgets($conn)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($data !== null) {
            if ($line === '.') {
                file_put_contents(sprintf('%s/%020d.eml', $dir, hrtime(true)), implode("\r\n", $data));
                $data = null;
                $reply('250 queued');
            } else {
                // undo dot-stuffing
                $data[] = str_starts_with($line, '.') ? substr($line, 1) : $line;
            }
            continue;
        }

        $command = strtoupper(explode(' ', $line)[0]);
        clearstatcache();
        if ($command === 'EHLO') {
            $reply('250-fake smtp');
            $reply('250 AUTH LOGIN');
        } elseif ($command === 'RCPT' && is_file("$dir/reject")) {
            $reply('550 recipient rejected');
        } elseif ($command === 'DATA') {
            $data = [];
            $reply('354 go ahead');
        } elseif ($command === 'QUIT') {
            $reply('221 bye');
            break;
        } else {
            $reply('250 ok');
        }
    }
    fclose($conn);
}
