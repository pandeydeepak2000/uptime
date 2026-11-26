<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login(true);
header('X-Content-Type-Options: nosniff');

$action = (string) ($_GET['action'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    csrf_check(true);
}

try {
    switch ($action) {
        case 'status':
            json_out(['ok' => true] + status_snapshot());

        case 'check_all':
        case 'check':
            if (!$isPost) {
                json_out(['ok' => false, 'error' => 'POST required'], 405);
            }
            $ids = $action === 'check' ? [(int) ($_POST['id'] ?? 0)] : null;
            $res = run_monitor($ids, true, true);
            $up = count(array_filter($res['results'] ?? [], fn ($r) => $r['ok']));
            $total = (int) ($res['checked'] ?? 0);
            json_out([
                'ok'      => true,
                'checked' => $total,
                'up'      => $up,
                'failing' => $total - $up,
                'events'  => array_map(fn ($e) => ['type' => $e['type'], 'name' => $e['server']['name']], $res['events'] ?? []),
                'message' => $total ? "Checked $total server(s): $up responding" . ($total - $up ? ', ' . ($total - $up) . ' failing' : '') : 'No active servers to check',
            ] + status_snapshot());

        case 'toggle':
            if (!$isPost) {
                json_out(['ok' => false], 405);
            }
            $id = (int) ($_POST['id'] ?? 0);
            $s = row('SELECT id, name, enabled FROM servers WHERE id = ?', [$id]);
            if (!$s) {
                json_out(['ok' => false, 'error' => 'Server not found'], 404);
            }
            $enable = (int) $s['enabled'] === 0;
            set_server_enabled($id, $enable);
            audit($enable ? 'server_resume' : 'server_pause', $s['name']);
            json_out(['ok' => true, 'enabled' => $enable, 'message' => $s['name'] . ($enable ? ' resumed' : ' paused')]);

        case 'reveal':
            if (!$isPost) {
                json_out(['ok' => false], 405);
            }
            $fails = (int) ($_SESSION['reveal_fails'] ?? 0);
            if ($fails >= 5) {
                audit('reveal_locked', 'Too many wrong passwords — session terminated');
                logout_user();
                json_out(['ok' => false, 'error' => 'Too many wrong attempts. You have been logged out.', 'login' => true], 401);
            }
            $pw = (string) ($_POST['password'] ?? '');
            if (!verify_current_password((int) $user['id'], $pw)) {
                $_SESSION['reveal_fails'] = $fails + 1;
                audit('reveal_failed', 'Wrong password while revealing a secret');
                usleep(400000);
                json_out(['ok' => false, 'error' => 'Wrong password (' . (5 - $fails - 1) . ' attempts left)'], 403);
            }
            $_SESSION['reveal_fails'] = 0;
            $type = (string) ($_POST['type'] ?? '');
            $id = (int) ($_POST['id'] ?? 0);
            if ($type === 'server') {
                $r = row('SELECT name, ssh_user, ssh_pass FROM servers WHERE id = ?', [$id]);
                $val = $r ? decrypt_str($r['ssh_pass']) : '';
                $label = $r ? $r['name'] . ' (' . ($r['ssh_user'] ?: 'root') . ')' : '';
            } elseif ($type === 'provider') {
                $r = row('SELECT name, account_pass FROM providers WHERE id = ?', [$id]);
                $val = $r ? decrypt_str($r['account_pass']) : '';
                $label = $r ? $r['name'] . ' panel' : '';
            } else {
                json_out(['ok' => false, 'error' => 'Invalid type'], 400);
            }
            if (!$r) {
                json_out(['ok' => false, 'error' => 'Not found'], 404);
            }
            audit('secret_revealed', 'Viewed password for ' . $label);
            json_out(['ok' => true, 'value' => $val, 'empty' => $val === '']);

        case 'tg_test':
            if (!$isPost) {
                json_out(['ok' => false], 405);
            }
            $res = tg_send("✅ <b>Test notification</b>\n\nTelegram alerts are working for <b>" . e(app_name()) . "</b>.\n🕒 " . e(date('d M Y, h:i:s A')) . tg_footer());
            json_out(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Test message sent to Telegram' : 'Failed: ' . $res['error']]);

        case 'send_report':
            if (!$isPost) {
                json_out(['ok' => false], 405);
            }
            [$from, $to, $label] = report_range((string) ($_POST['range'] ?? 'today'), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''));
            $res = tg_send(tg_report_text(build_report($from, $to), 'Uptime Report — ' . $label));
            audit('report_sent', 'Telegram report: ' . $label);
            json_out(['ok' => $res['ok'], 'message' => $res['ok'] ? 'Report sent to Telegram' : 'Failed: ' . $res['error']]);

        default:
            json_out(['ok' => false, 'error' => 'Unknown action'], 404);
    }
} catch (Throwable $e) {
    log_file('app.log', 'API ' . $action . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['ok' => false, 'error' => config('debug') ? $e->getMessage() : 'Server error — see storage/logs/app.log'], 500);
}
