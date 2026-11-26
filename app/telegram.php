<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Telegram notifications
 * -----------------------------------------------------------------*/

function tg_chunks(string $text, int $max = 3800): array
{
    if (strlen($text) <= $max) {
        return [$text];
    }
    $chunks = [];
    $cur = '';
    foreach (explode("\n", $text) as $line) {
        if (strlen($cur) + strlen($line) + 1 > $max && $cur !== '') {
            $chunks[] = $cur;
            $cur = '';
        }
        $cur .= ($cur === '' ? '' : "\n") . $line;
    }
    if ($cur !== '') {
        $chunks[] = $cur;
    }
    return $chunks;
}

function tg_send(string $text, ?string $token = null, ?string $chatIds = null): array
{
    $token = $token ?? setting_secret('tg_token');
    $chatIds = $chatIds ?? (string) setting('tg_chat_ids', '');
    if ($token === '' || trim($chatIds) === '') {
        return ['ok' => false, 'sent' => 0, 'error' => 'Telegram bot token or chat ID is not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'sent' => 0, 'error' => 'cURL extension not available.'];
    }
    $errors = [];
    $sent = 0;
    foreach (preg_split('/[\s,]+/', $chatIds, -1, PREG_SPLIT_NO_EMPTY) as $chat) {
        foreach (tg_chunks($text) as $chunk) {
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query([
                    'chat_id'                  => $chat,
                    'text'                     => $chunk,
                    'parse_mode'               => 'HTML',
                    'disable_web_page_preview' => 'true',
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            $j = json_decode((string) $res, true);
            if (!empty($j['ok'])) {
                $sent++;
            } else {
                $errors[] = $chat . ': ' . ($j['description'] ?? ($err ?: 'unknown error'));
            }
        }
    }
    if ($errors) {
        log_file('telegram.log', implode(' | ', $errors));
    }
    return ['ok' => $sent > 0 && !$errors, 'sent' => $sent, 'error' => implode('; ', $errors)];
}

function tg_enabled(): bool
{
    return setting('tg_enabled') === '1';
}

function tg_footer(): string
{
    $u = (string) setting('app_url', '');
    return $u ? "\n\n🔗 <a href=\"" . e($u . 'dashboard.php') . '">Open dashboard</a>' : '';
}

function tg_server_line(array $s): string
{
    $p = !empty($s['provider_name']) ? ' · ' . e($s['provider_name']) : '';
    return '<b>' . e($s['name']) . '</b> (<code>' . e($s['host']) . '</code>)' . $p;
}

function tg_notify_events(array $events): void
{
    if (!tg_enabled()) {
        return;
    }
    $down = setting('tg_notify_down') === '1' ? array_values(array_filter($events, fn ($e) => $e['type'] === 'down')) : [];
    $up = setting('tg_notify_up') === '1' ? array_values(array_filter($events, fn ($e) => $e['type'] === 'up')) : [];
    if (!$down && !$up) {
        return;
    }
    $msgs = [];
    if (count($down) + count($up) <= 3) {
        foreach ($down as $ev) {
            $msgs[] = "🔴 <b>SERVER DOWN</b>\n\n" . tg_server_line($ev['server'])
                . "\n⚠️ Reason: " . e($ev['error'])
                . "\n🕒 Down since: " . e(date('d M Y, h:i:s A', (int) $ev['since']))
                . tg_footer();
        }
        foreach ($up as $ev) {
            $msgs[] = "🟢 <b>SERVER RECOVERED</b>\n\n" . tg_server_line($ev['server'])
                . "\n⏱ Downtime: <b>" . e(fmt_duration($ev['duration'], 3)) . '</b>'
                . ($ev['ms'] ? "\n⚡ Response: " . (int) $ev['ms'] . ' ms' : '')
                . "\n🕒 Back online: " . e(date('d M Y, h:i:s A', (int) $ev['at']))
                . tg_footer();
        }
    } else {
        $t = "🚨 <b>Status changes (" . (count($down) + count($up)) . ")</b>\n";
        if ($down) {
            $t .= "\n🔴 <b>DOWN</b>\n";
            foreach ($down as $ev) {
                $t .= '• ' . tg_server_line($ev['server']) . ' — ' . e($ev['error']) . "\n";
            }
        }
        if ($up) {
            $t .= "\n🟢 <b>RECOVERED</b>\n";
            foreach ($up as $ev) {
                $t .= '• ' . tg_server_line($ev['server']) . ' — down ' . e(fmt_duration($ev['duration'], 3)) . "\n";
            }
        }
        $msgs[] = $t . tg_footer();
    }
    foreach ($msgs as $m) {
        tg_send($m);
    }
}

function tg_report_text(array $rep, string $title): string
{
    $t = $rep['totals'];
    $txt = '📊 <b>' . e($title) . "</b>\n"
        . '🗓 ' . e(date('d M Y, h:i A', $rep['from'])) . ' → ' . e(date('d M Y, h:i A', $rep['to'])) . "\n\n"
        . '🖥 Servers: <b>' . $t['servers'] . '</b>  ·  🟢 ' . $t['up'] . ' up  ·  🔴 ' . $t['down'] . ' down'
        . ($t['paused'] ? '  ·  ⏸ ' . $t['paused'] . ' paused' : '') . "\n"
        . '📈 Avg uptime: <b>' . fmt_pct($t['avg_uptime'], 3) . "</b>\n"
        . '⚠️ Incidents: <b>' . $t['incidents'] . '</b>  ·  Total downtime: <b>' . e(fmt_duration($t['downtime'], 3)) . "</b>\n";

    $downNow = array_filter($rep['servers'], fn ($s) => $s['status'] === 'down' && (int) $s['enabled'] === 1);
    if ($downNow) {
        $txt .= "\n🔴 <b>Currently DOWN</b>\n";
        foreach ($downNow as $s) {
            $txt .= '• <b>' . e($s['name']) . '</b> — down for ' . e(fmt_duration(time() - (int) $s['status_since'], 3)) . "\n";
        }
    }
    $hit = array_filter($rep['servers'], fn ($s) => $s['incident_count'] > 0);
    if ($hit) {
        usort($hit, fn ($a, $b) => $b['downtime'] <=> $a['downtime']);
        $txt .= "\n⚠️ <b>Had outages</b>\n";
        foreach ($hit as $s) {
            $txt .= '• <b>' . e($s['name']) . '</b> — ' . $s['incident_count'] . ' outage(s), ' . e(fmt_duration($s['downtime'], 3))
                . ' down (' . fmt_pct($s['uptime']) . ")\n";
        }
    }
    $txt .= "\n✅ <b>All servers</b>\n";
    foreach ($rep['servers'] as $s) {
        $m = status_meta($s);
        $icon = ['up' => '🟢', 'down' => '🔴', 'paused' => '⏸', 'unknown' => '⚪'][$m['status']] ?? '⚪';
        $state = $m['status'] === 'up' ? 'up ' . fmt_duration(time() - (int) $s['status_since'])
            : ($m['status'] === 'down' ? 'DOWN ' . fmt_duration(time() - (int) $s['status_since']) : strtolower($m['label']));
        $txt .= $icon . ' ' . e($s['name']) . ' — ' . fmt_pct($s['uptime']) . ' · ' . e($state) . "\n";
    }
    return $txt . tg_footer();
}

function tg_maybe_daily_report(): ?array
{
    if (!tg_enabled() || setting('tg_daily_report') !== '1') {
        return null;
    }
    $today = date('Y-m-d');
    $time = (string) setting('tg_report_time', '09:00');
    if (date('H:i') < $time || setting('tg_last_report_date') === $today) {
        return null;
    }
    setting_set('tg_last_report_date', $today);
    $rep = build_report(time() - 86400, time());
    return tg_send(tg_report_text($rep, 'Daily Uptime Report — last 24 hours'));
}

function tg_send_reminders(): int
{
    $mins = (int) setting('tg_remind_minutes', 0);
    if (!tg_enabled() || $mins <= 0 || setting('tg_notify_down') !== '1') {
        return 0;
    }
    $now = time();
    $list = rows("SELECT s.*, p.name AS provider_name FROM servers s LEFT JOIN providers p ON p.id = s.provider_id
                  WHERE s.enabled = 1 AND s.status = 'down' AND (s.last_alert_at IS NULL OR s.last_alert_at <= ?)", [$now - $mins * 60]);
    if (!$list) {
        return 0;
    }
    $t = "⏰ <b>Still DOWN</b>\n";
    foreach ($list as $s) {
        $t .= "\n• " . tg_server_line($s) . "\n   down for <b>" . e(fmt_duration($now - (int) $s['status_since'], 3)) . '</b> — ' . e((string) $s['last_error']);
        db_update('servers', ['last_alert_at' => $now], 'id = ?', [(int) $s['id']]);
    }
    tg_send($t . tg_footer());
    return count($list);
}
