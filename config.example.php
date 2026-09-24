<?php

// Copy to config.php and adjust.
return [
    "sites" => [
        // defaults: GET, status code 200, redirects are not followed
        "https://example.com" => [],
        "https://api.example.com/health" => [
            "method" => "POST",                     // GET, HEAD, POST, …
            "headers" => ["X-Authtoken" => "secret"],
            "body" => '{"ping":true}',              // optional request body
            "status_code" => [200, 204],            // accepted status codes
            "follow_redirects" => true,             // overrides the global follow_redirects
            "timeout" => 10,                        // overrides the global timeout
            "max_response_time" => 2,               // overrides the global max_response_time
        ],
    ],
    // a site is marked down (and a notification is sent) after this many failed checks in a row;
    // 1 means: notify on the first failed check
    "tolerated_failures_in_a_row" => 1,
    // seconds
    "timeout" => 5,
    // follow redirects and check the final response (default: false, a redirect is a failure unless its status code is accepted)
    "follow_redirects" => false,
    // seconds; slower responses count as failed checks (omit to disable)
    "max_response_time" => 0.5,
    // by default a slow response is a failed check (a slow page is not really "up" for its users);
    // set to true to still record slow responses, but count them as up and never notify about them
    "dont_send_notifications_on_slow_pages" => false,
    // checks older than this are deleted
    "retention_days" => 90,
    "email" => [
        "from" => "uptime@example.com",
        "to" => ["admin@example.com"],
        // omit "smtp" to use php's mail()
        "smtp" => [
            "host" => "smtp.example.com",
            "port" => 587,
            "username" => "user",
            "password" => "password",
            "encryption" => "tls", // "tls" (STARTTLS), "ssl" (implicit TLS) or ""
        ],
    ],
    "db" => [
        "host" => "localhost",
        "port" => 5432,
        "username" => "uptime",
        "password" => "",
        "database" => "uptime",
    ],
    "auth" => [
        "user" => "admin",
        // php -r 'echo password_hash("your-password", PASSWORD_DEFAULT);'
        "password" => '$2y$12$REPLACE.WITH.OUTPUT.OF.PASSWORD_HASH',
    ],
];
