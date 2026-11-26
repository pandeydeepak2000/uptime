<?php
/*
 * Background checker — run every minute from cPanel → Cron Jobs:
 *   /usr/local/bin/php /home/USER/path/to/uptime/cron.php >/dev/null 2>&1
 * or via URL (if CLI is not available):
 *   wget -q -O /dev/null "https://your-domain/uptime/cron.php?token=YOUR_CRON_TOKEN"
 */
require __DIR__ . '/app/bootstrap.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $token = (string) ($_GET['token'] ?? '');
    if ($token === '' || !hash_equals((string) config('cron_token', ''), $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    ignore_user_abort(true);
}
@set_time_limit(280);

$started = microtime(true);
$out = [];
try {
    setting_set('last_cron_run', time());
    $res = run_monitor(null, false, false);
    if (!empty($res['skipped'])) {
        $out[] = 'Skipped: previous run still in progress.';
    } else {
        $down = count(array_filter($res['results'] ?? [], fn ($r) => !$r['ok']));
        $out[] = sprintf('Checked %d server(s), %d failing, %d status change(s).', $res['checked'], $down, count($res['events']));
    }

    $rem = tg_send_reminders();
    if ($rem) {
        $out[] = "Sent reminder for $rem down server(s).";
    }

    $rep = tg_maybe_daily_report();
    if ($rep !== null) {
        $out[] = 'Daily report: ' . ($rep['ok'] ? 'sent' : 'failed — ' . $rep['error']);
    }

    // Daily housekeeping
    if (setting('last_prune_date') !== date('Y-m-d')) {
        setting_set('last_prune_date', date('Y-m-d'));
        $keep = max(7, (int) setting('retention_days', 90));
        $n = q('DELETE FROM checks WHERE checked_at < ?', [time() - $keep * 86400])->rowCount();
        q('DELETE FROM login_attempts WHERE attempted_at < ?', [time() - 30 * 86400]);
        q('DELETE FROM audit_log WHERE created_at < ?', [time() - 365 * 86400]);
        $out[] = "Pruned $n old check rows.";
    }
} catch (Throwable $e) {
    log_file('cron.log', 'ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $out[] = 'ERROR: ' . $e->getMessage();
}
$out[] = sprintf('Done in %.2fs at %s', microtime(true) - $started, date('Y-m-d H:i:s'));
echo implode(PHP_EOL, $out) . PHP_EOL;
