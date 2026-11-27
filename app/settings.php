<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Key/value settings stored in the database
 * -----------------------------------------------------------------*/

function settings_defaults(): array
{
    return [
        'app_name'            => 'Uptime Command',
        'timezone'            => 'Asia/Kolkata',
        'check_interval'      => '60',
        'check_timeout'       => '5',
        'fail_threshold'      => '2',
        'retention_days'      => '90',
        'session_timeout'     => '30',
        'ip_whitelist'        => '',
        'tg_enabled'          => '0',
        'tg_token'            => '',
        'tg_chat_ids'         => '',
        'tg_notify_down'      => '1',
        'tg_notify_up'        => '1',
        'tg_remind_minutes'   => '0',
        'tg_daily_report'     => '1',
        'tg_report_time'      => '09:00',
        'tg_login_alert'      => '1',
        'tg_last_report_date' => '',
        'tg_renewal'          => '1',
        'tg_renewal_days'     => '7,3,1,0',
        'tg_renewal_overdue'  => '1',
        'tg_last_renewal_date' => '',
        'last_cron_run'       => '0',
        'last_prune_date'     => '',
        'app_url'             => '',
        'db_version'          => '9',
    ];
}

function settings_all(bool $refresh = false): array
{
    if ($refresh || !isset($GLOBALS['_SETTINGS'])) {
        $s = [];
        foreach (rows('SELECT k, v FROM settings') as $r) {
            $s[$r['k']] = $r['v'];
        }
        $GLOBALS['_SETTINGS'] = $s + settings_defaults();
    }
    return $GLOBALS['_SETTINGS'];
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    return (array_key_exists($key, $all) && $all[$key] !== null) ? $all[$key] : $default;
}

function setting_set(string $key, $value): void
{
    $value = $value === null ? null : (string) $value;
    $exists = (int) val('SELECT COUNT(*) FROM settings WHERE k = ?', [$key]);
    if ($exists) {
        q('UPDATE settings SET v = ? WHERE k = ?', [$value, $key]);
    } else {
        q('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
    }
    if (isset($GLOBALS['_SETTINGS'])) {
        $GLOBALS['_SETTINGS'][$key] = $value;
    }
}

function setting_secret(string $key): string
{
    return decrypt_str((string) setting($key, ''));
}

function setting_set_secret(string $key, string $plain): void
{
    setting_set($key, $plain === '' ? '' : encrypt_str($plain));
}

function app_name(): string
{
    if (empty($GLOBALS['CONFIG'])) {
        return 'Uptime Command';
    }
    try {
        return (string) setting('app_name', 'Uptime Command');
    } catch (Throwable $e) {
        return 'Uptime Command';
    }
}
