<?php
/*
 * Uptime Command — bootstrap
 * Loaded by every public entry point.
 */

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('PHP 8.0 or newer is required. Current: ' . PHP_VERSION);
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(APP_ROOT . '/storage/logs')) {
    ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
}

// After a `git pull`, some hosts' OPcache keeps serving old copies of a few files for a while
// (new page + old bootstrap = "undefined function"). When the checked-out commit changes, clear
// OPcache once and reload the page so every file is read fresh.
(function () {
    $head = APP_ROOT . '/.git/HEAD';
    $ref = is_file($head) ? trim((string) @file_get_contents($head)) : '';
    $refFile = str_starts_with($ref, 'ref: ') ? APP_ROOT . '/.git/' . substr($ref, 5) : $head;
    $sig = is_file($refFile) ? trim((string) @file_get_contents($refFile)) : '';
    $mark = APP_ROOT . '/storage/.deployed';
    if ($sig === '' || !is_dir(APP_ROOT . '/storage') || @file_get_contents($mark) === $sig) {
        return;
    }
    @file_put_contents($mark, $sig, LOCK_EX);
    $reset = function_exists('opcache_reset') && @opcache_reset();
    if (!$reset && function_exists('opcache_invalidate')) {
        foreach (array_merge(glob(APP_ROOT . '/app/*.php') ?: [], glob(APP_ROOT . '/*.php') ?: []) as $f) {
            @opcache_invalidate($f, true);
        }
    }
    if (PHP_SAPI !== 'cli' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !headers_sent()) {
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/'), true, 302);
        header('Cache-Control: no-store');
        exit;
    }
})();

foreach (['helpers', 'db', 'crypto', 'settings', 'auth', 'monitor', 'telegram', 'report', 'discover', 'whmcs', 'ovh', 'billing', 'icons', 'layout'] as $f) {
    require __DIR__ . '/' . $f . '.php';
}

if (!defined('SKIP_CONFIG')) {
    $cfgFile = __DIR__ . '/config.php';
    if (!is_file($cfgFile)) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Not installed yet. Open install.php in your browser first.\n");
            exit(1);
        }
        header('Location: ' . url('install.php'));
        exit;
    }
    $GLOBALS['CONFIG'] = require $cfgFile;
    if (config('debug')) {
        ini_set('display_errors', '1');
    }
    date_default_timezone_set((string) config('timezone', 'Asia/Kolkata'));
    try {
        $tz = (string) setting('timezone', '');
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }
        db_migrate();
    } catch (Throwable $e) {
        if (PHP_SAPI !== 'cli') {
            http_response_code(500);
            exit('Database connection failed. Check app/config.php. ' . (config('debug') ? e($e->getMessage()) : ''));
        }
        throw $e;
    }
}
