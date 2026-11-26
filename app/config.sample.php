<?php
// Reference only — the installer (install.php) generates app/config.php for you.
defined('APP_ROOT') || exit;

return [
    'db' => [
        'driver' => 'mysql',          // 'mysql' or 'sqlite'
        'host'   => 'localhost',
        'port'   => 3306,
        'name'   => 'cpaneluser_uptime',
        'user'   => 'cpaneluser_uptime',
        'pass'   => 'change-me',
        // 'path' => 'storage/uptime.sqlite',   // for sqlite
    ],
    'app_key'              => 'base64:GENERATE_32_RANDOM_BYTES',  // never change after install
    'cron_token'           => 'random-long-token',
    'timezone'             => 'Asia/Kolkata',
    'trust_proxy'          => false,   // true if behind Cloudflare / reverse proxy
    'disable_ip_whitelist' => false,   // emergency switch if you lock yourself out
    'debug'                => false,
];
