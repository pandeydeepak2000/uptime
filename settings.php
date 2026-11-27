<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$uid = (int) $user['id'];
$tzList = timezone_identifiers_list();
$isAdmin = can('admin');

/** Team changes need the admin's own password again (5 wrong tries → logged out). */
$confirmAdmin = function () use ($uid): void {
    $fails = (int) ($_SESSION['sudo_fails'] ?? 0);
    if ($fails >= 5) {
        audit('reveal_locked', 'Too many wrong password confirmations on team changes — session ended');
        logout_user();
        redirect('login.php?expired=1');
    }
    if (!verify_current_password($uid, (string) ($_POST['admin_password'] ?? ''))) {
        $_SESSION['sudo_fails'] = $fails + 1;
        audit('password_change_failed', 'Wrong password confirmation on a team change');
        usleep(400000);
        throw new InvalidArgumentException('Your password is incorrect (' . (4 - $fails) . ' tries left). Nothing was changed.');
    }
    $_SESSION['sudo_fails'] = 0;
};
$validName = fn (string $n) => (bool) preg_match('/^[\p{L}\p{N}_.\- ]{3,32}$/u', $n);
$activeAdmins = fn (int $exceptId) => (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$exceptId]);
$emailTaken = fn (string $email, int $exceptId) => (bool) val('SELECT id FROM users WHERE LOWER(email) = ? AND id <> ?', [strtolower($email), $exceptId]);
$nameTaken = fn (string $name, int $exceptId) => (bool) val('SELECT id FROM users WHERE username = ? AND id <> ?', [$name, $exceptId]);
$teamAlert = function (string $text): void {
    if (tg_enabled()) {
        tg_send("👥 <b>Team change</b>\n" . $text . "\n🌐 IP: <code>" . e(client_ip()) . "</code>\n🕒 " . e(date('d M Y, h:i:s A')));
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = post('section');
    $tab = $section;
    if (in_array($section, ['general', 'telegram', 'security', 'user_add', 'user_update', 'user_reset', 'user_delete'], true)) {
        require_perm('admin');
    }
    if (str_starts_with($section, 'user_')) {
        $tab = 'team';
    }
    try {
        switch ($section) {
            case 'general':
                $tz = post('timezone');
                if (!in_array($tz, $tzList, true)) {
                    throw new InvalidArgumentException('Invalid timezone.');
                }
                setting_set('app_name', post('app_name', '', 60) ?: 'Uptime Command');
                setting_set('timezone', $tz);
                setting_set('check_interval', max(60, min(3600, (int) post('check_interval'))));
                setting_set('check_timeout', max(1, min(30, (int) post('check_timeout'))));
                setting_set('fail_threshold', max(1, min(10, (int) post('fail_threshold'))));
                setting_set('retention_days', max(7, min(730, (int) post('retention_days'))));
                audit('settings_updated', 'General settings');
                flash('success', 'General settings saved.');
                break;

            case 'telegram':
                $chats = trim(preg_replace('/[\s,]+/', ', ', post('tg_chat_ids')), ', ');
                foreach (preg_split('/[\s,]+/', $chats, -1, PREG_SPLIT_NO_EMPTY) as $c) {
                    if (!preg_match('/^(-?\d{3,20}|@[A-Za-z0-9_]{4,64})$/', $c)) {
                        throw new InvalidArgumentException("Invalid chat ID: $c");
                    }
                }
                $token = post('tg_token');
                if ($token !== '') {
                    if (!preg_match('/^\d{5,15}:[A-Za-z0-9_\-]{30,60}$/', $token)) {
                        throw new InvalidArgumentException('Bot token format looks wrong (expected 123456:ABC-xyz…).');
                    }
                    setting_set_secret('tg_token', $token);
                }
                if (isset($_POST['tg_clear_token'])) {
                    setting_set('tg_token', '');
                }
                $time = post('tg_report_time', '09:00');
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                    throw new InvalidArgumentException('Invalid report time.');
                }
                setting_set('tg_chat_ids', $chats);
                foreach (['tg_enabled', 'tg_notify_down', 'tg_notify_up', 'tg_daily_report', 'tg_login_alert', 'tg_renewal', 'tg_renewal_overdue'] as $k) {
                    setting_set($k, isset($_POST[$k]) ? '1' : '0');
                }
                $rd = [];
                foreach (preg_split('/[\s,]+/', post('tg_renewal_days', '7,3,1,0'), -1, PREG_SPLIT_NO_EMPTY) as $d) {
                    if (!ctype_digit($d) || (int) $d > 90) {
                        throw new InvalidArgumentException('Renewal days must be numbers between 0 and 90, e.g. 7,3,1,0');
                    }
                    $rd[] = (int) $d;
                }
                $rd = array_values(array_unique($rd));
                rsort($rd);
                setting_set('tg_renewal_days', $rd ? implode(',', $rd) : '7,3,1,0');
                setting_set('tg_remind_minutes', max(0, min(1440, (int) post('tg_remind_minutes'))));
                if ($time !== setting('tg_report_time')) {
                    setting_set('tg_last_report_date', date('H:i') >= $time ? date('Y-m-d') : '');
                    setting_set('tg_last_renewal_date', date('H:i') >= $time ? date('Y-m-d') : '');
                }
                setting_set('tg_report_time', $time);
                audit('settings_updated', 'Telegram settings');
                flash('success', 'Telegram settings saved.');
                break;

            case 'password':
                $cur = (string) ($_POST['current_password'] ?? '');
                $new = (string) ($_POST['new_password'] ?? '');
                if (!verify_current_password($uid, $cur)) {
                    audit('password_change_failed', 'Wrong current password');
                    throw new InvalidArgumentException('Current password is incorrect.');
                }
                // only an admin may change a login email (team members' emails are managed by the admin)
                $email = $isAdmin ? strtolower(post('email', '', 190)) : strtolower((string) $user['email']);
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid login email address.');
                }
                if ($emailTaken($email, $uid)) {
                    throw new InvalidArgumentException('Another account already uses that email.');
                }
                $newUser = post('username', $user['username'], 32);
                if (!$validName($newUser)) {
                    throw new InvalidArgumentException('Display name must be 3–32 characters (letters, numbers, space, _ . -).');
                }
                if ($nameTaken($newUser, $uid)) {
                    throw new InvalidArgumentException('Another team member already uses that name.');
                }
                if ((int) $user['must_change_pw'] && (string) ($_POST['new_password'] ?? '') === '') {
                    throw new InvalidArgumentException('Please choose a new password (the one you received was temporary).');
                }
                $upd = ['username' => $newUser, 'email' => $email];
                $changes = [];
                if ($email !== strtolower((string) ($user['email'] ?? ''))) {
                    $changes[] = 'login email';
                }
                if ($new !== '') {
                    if ($err = password_strength_error($new)) {
                        throw new InvalidArgumentException($err);
                    }
                    if ($new !== (string) ($_POST['new_password2'] ?? '')) {
                        throw new InvalidArgumentException('New passwords do not match.');
                    }
                    if (password_verify($new, (string) val('SELECT password_hash FROM users WHERE id = ?', [$uid]))) {
                        throw new InvalidArgumentException('The new password must be different from the current one.');
                    }
                    $upd['password_hash'] = password_hash($new, password_algo());
                    $upd['must_change_pw'] = 0;
                    $changes[] = 'password';
                }
                if ($changes) {
                    // log out every other session of this account; keep this one
                    $epoch = (int) val('SELECT session_epoch FROM users WHERE id = ?', [$uid]) + 1;
                    $upd['session_epoch'] = $epoch;
                    $_SESSION['epoch'] = $epoch;
                }
                db_update('users', $upd, 'id = ?', [$uid]);
                session_regenerate_id(true);
                audit($changes ? 'password_changed' : 'settings_updated', $changes ? 'Changed: ' . implode(', ', $changes) : 'Display name updated');
                if ($changes && tg_enabled()) {
                    tg_send("🛡 <b>Account security change</b>\nChanged: <b>" . e(implode(', ', $changes)) . "</b>\n🌐 IP: <code>" . e(client_ip()) . "</code>\n🕒 " . e(date('d M Y, h:i:s A')));
                }
                flash('success', $changes ? 'Updated: ' . implode(' & ', $changes) . '. Use your email to sign in from now on.' : 'Profile updated.');
                $tab = 'security';
                break;

            case '2fa_enable':
                $secret = (string) ($_SESSION['2fa_setup'] ?? '');
                if ($secret === '' || !totp_verify($secret, post('code'))) {
                    throw new InvalidArgumentException('Code did not match. Make sure your phone time is correct and try again.');
                }
                db_update('users', ['totp_secret' => encrypt_str($secret), 'totp_enabled' => 1], 'id = ?', [$uid]);
                unset($_SESSION['2fa_setup']);
                audit('2fa_enabled', 'Two-factor authentication enabled');
                flash('success', 'Two-factor authentication is now ON.');
                $tab = 'security';
                break;

            case '2fa_disable':
                $u = row('SELECT totp_secret FROM users WHERE id = ?', [$uid]);
                if (!verify_current_password($uid, (string) ($_POST['password'] ?? '')) || !totp_verify(decrypt_str($u['totp_secret'] ?? ''), post('code'))) {
                    throw new InvalidArgumentException('Password or authenticator code is incorrect.');
                }
                db_update('users', ['totp_secret' => null, 'totp_enabled' => 0], 'id = ?', [$uid]);
                audit('2fa_disabled', 'Two-factor authentication disabled');
                flash('success', 'Two-factor authentication disabled.');
                $tab = 'security';
                break;

            case 'security':
                $list = [];
                foreach (preg_split('/[\s,]+/', post('ip_whitelist'), -1, PREG_SPLIT_NO_EMPTY) as $ip) {
                    $base = explode('/', $ip)[0];
                    if (!filter_var($base, FILTER_VALIDATE_IP)) {
                        throw new InvalidArgumentException("Invalid IP/CIDR: $ip");
                    }
                    $list[] = $ip;
                }
                if ($list && !in_array(client_ip(), $list, true)) {
                    $tmp = setting('ip_whitelist');
                    setting_set('ip_whitelist', implode("\n", $list));
                    $mine = ip_allowed(client_ip());
                    setting_set('ip_whitelist', $tmp);
                    if (!$mine) {
                        throw new InvalidArgumentException('Your current IP (' . client_ip() . ') is not in the list — you would be locked out. Add it first.');
                    }
                }
                setting_set('ip_whitelist', implode("\n", $list));
                setting_set('session_timeout', max(5, min(720, (int) post('session_timeout'))));
                audit('settings_updated', 'Security settings');
                flash('success', 'Security settings saved.');
                break;

            /* ---------------- team (admin only — checked above) ---------------- */
            case 'user_add':
                $confirmAdmin();
                $name = trim(post('name', '', 32));
                $email = strtolower(post('email', '', 190));
                $role = post('role');
                $pw = (string) ($_POST['password'] ?? '');
                if (!$validName($name)) {
                    throw new InvalidArgumentException('Name must be 3–32 characters (letters, numbers, space, _ . -).');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid email — it is their login.');
                }
                if (!isset(ROLES[$role])) {
                    throw new InvalidArgumentException('Choose a role.');
                }
                if ($emailTaken($email, 0) || $nameTaken($name, 0)) {
                    throw new InvalidArgumentException('A team member with that email or name already exists.');
                }
                if ($err = password_strength_error($pw)) {
                    throw new InvalidArgumentException('Their password: ' . $err);
                }
                $force = isset($_POST['force_change']) ? 1 : 0;
                $newId = db_insert('users', [
                    'username' => $name, 'email' => $email, 'password_hash' => password_hash($pw, password_algo()),
                    'role' => $role, 'can_reveal' => $role === 'admin' || isset($_POST['can_reveal']) ? 1 : 0,
                    'active' => 1, 'must_change_pw' => $force, 'session_epoch' => 0, 'totp_enabled' => 0,
                    'created_by' => $uid, 'created_at' => time(),
                ]);
                audit('user_added', $name . ' <' . $email . '> as ' . ROLES[$role] . ' (id ' . $newId . ')');
                $teamAlert('➕ Added <b>' . e($name) . '</b> (' . e($email) . ') as <b>' . e(ROLES[$role]) . '</b> by ' . e($user['username']));
                flash('success', 'Added ' . $name . '. Share the email + password privately (not in a group chat)' . ($force ? ' — they will choose their own password at first login.' : '.'));
                break;

            case 'user_update':
                $confirmAdmin();
                $tid = (int) post('id');
                $t = row('SELECT * FROM users WHERE id = ?', [$tid]);
                if (!$t) {
                    throw new InvalidArgumentException('User not found.');
                }
                if ($tid === $uid) {
                    throw new InvalidArgumentException('Change your own account under “My account”. (You cannot remove your own admin rights.)');
                }
                $name = trim(post('name', '', 32));
                $email = strtolower(post('email', '', 190));
                $role = post('role');
                $active = isset($_POST['active']) ? 1 : 0;
                if (!$validName($name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(ROLES[$role])) {
                    throw new InvalidArgumentException('Check the name, email and role.');
                }
                if ($emailTaken($email, $tid) || $nameTaken($name, $tid)) {
                    throw new InvalidArgumentException('Another team member already uses that email or name.');
                }
                if ($t['role'] === 'admin' && ($role !== 'admin' || !$active) && $activeAdmins($tid) < 1) {
                    throw new InvalidArgumentException('There must always be at least one active admin.');
                }
                $upd = ['username' => $name, 'email' => $email, 'role' => $role, 'active' => $active,
                        'can_reveal' => $role === 'admin' || isset($_POST['can_reveal']) ? 1 : 0];
                $changed = [];
                foreach (['email' => 'email', 'role' => 'role', 'active' => 'status', 'can_reveal' => 'root-password access'] as $k => $l) {
                    if ((string) $t[$k] !== (string) $upd[$k]) {
                        $changed[] = $l;
                    }
                }
                if ($changed) {
                    $upd['session_epoch'] = (int) $t['session_epoch'] + 1; // signs them out everywhere
                }
                db_update('users', $upd, 'id = ?', [$tid]);
                audit('user_updated', $name . ' (id ' . $tid . ')' . ($changed ? ' — changed ' . implode(', ', $changed) : ''));
                if ($changed) {
                    $teamAlert('✏️ <b>' . e($name) . '</b>: changed ' . e(implode(', ', $changed)) . ' → ' . e(ROLES[$role]) . ($active ? '' : ' (DISABLED)') . ' by ' . e($user['username']));
                }
                flash('success', 'Saved ' . $name . ($changed ? ' — they were signed out and must log in again.' : '.'));
                break;

            case 'user_reset':
                $confirmAdmin();
                $tid = (int) post('id');
                $t = row('SELECT * FROM users WHERE id = ?', [$tid]);
                if (!$t || $tid === $uid) {
                    throw new InvalidArgumentException($t ? 'Use “My account” to change your own password.' : 'User not found.');
                }
                $pw = (string) ($_POST['password'] ?? '');
                if ($err = password_strength_error($pw)) {
                    throw new InvalidArgumentException('New password: ' . $err);
                }
                $force = isset($_POST['force_change']) ? 1 : 0;
                $upd = ['password_hash' => password_hash($pw, password_algo()), 'must_change_pw' => $force, 'session_epoch' => (int) $t['session_epoch'] + 1];
                if (isset($_POST['reset_2fa'])) {
                    $upd += ['totp_secret' => null, 'totp_enabled' => 0];
                }
                db_update('users', $upd, 'id = ?', [$tid]);
                audit('user_password_reset', $t['username'] . ' (id ' . $tid . ')' . (isset($_POST['reset_2fa']) ? ' + 2FA reset' : '') . ($force ? ' (must change at next login)' : ''));
                $teamAlert('🔑 Password changed for <b>' . e($t['username']) . '</b>' . (isset($_POST['reset_2fa']) ? ' (2FA also reset)' : '') . ' by ' . e($user['username']));
                flash('success', 'Password changed for ' . $t['username'] . '. They were signed out' . ($force ? ' and will choose their own password at next login.' : ' — they log in with the new password.'));
                break;

            case 'user_delete':
                $confirmAdmin();
                $tid = (int) post('id');
                $t = row('SELECT * FROM users WHERE id = ?', [$tid]);
                if (!$t || $tid === $uid) {
                    throw new InvalidArgumentException($t ? 'You cannot delete your own account.' : 'User not found.');
                }
                if ($t['role'] === 'admin' && $activeAdmins($tid) < 1) {
                    throw new InvalidArgumentException('There must always be at least one active admin.');
                }
                q('DELETE FROM users WHERE id = ?', [$tid]);
                audit('user_deleted', $t['username'] . ' <' . $t['email'] . '> (id ' . $tid . ')');
                $teamAlert('🗑 Deleted <b>' . e($t['username']) . '</b> (' . e((string) $t['email']) . ') by ' . e($user['username']));
                flash('success', 'Deleted ' . $t['username'] . '. Their activity stays in the log.');
                break;
        }
    } catch (InvalidArgumentException $ex) {
        flash('error', $ex->getMessage());
    }
    header('Location: ' . url('settings.php') . '#' . $tab);
    exit;
}

$u = row('SELECT * FROM users WHERE id = ?', [$uid]);
if (!(int) $u['totp_enabled'] && empty($_SESSION['2fa_setup'])) {
    $_SESSION['2fa_setup'] = totp_generate_secret();
}
$setupSecret = (string) ($_SESSION['2fa_setup'] ?? '');
$otpUri = totp_uri($setupSecret, $u['username'], app_name());
$cronCmd = '/usr/local/bin/php ' . APP_ROOT . '/cron.php >/dev/null 2>&1';
$cronUrl = full_url('cron.php') . '?token=' . config('cron_token');
$lastCron = (int) setting('last_cron_run', 0);
$hasToken = setting('tg_token', '') !== '';
$chk = fn ($k) => setting($k) === '1' ? 'checked' : '';

$team = $isAdmin ? rows('SELECT id, username, email, role, can_reveal, active, totp_enabled, must_change_pw, last_login_at, last_login_ip, created_at FROM users ORDER BY active DESC, role, username') : [];
// Password box for team members: type one, or press Generate (browser CSPRNG); copy + show/hide.
$pwField = function (string $id, string $label): string {
    return '<div class="field"><label>' . $label . '</label>'
        . '<div class="pw-box"><input class="input mono" id="' . $id . '" type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="Type one or press Generate" spellcheck="false">'
        . '<button class="icon-btn" type="button" data-toggle-pw="#' . $id . '" title="Show / hide">' . icon('eye') . '</button>'
        . '<button class="icon-btn" type="button" data-copy-from="#' . $id . '" title="Copy">' . icon('copy') . '</button>'
        . '<button class="btn btn-ghost sm" type="button" data-gen-pw="#' . $id . '">' . icon('refresh') . 'Generate</button></div>'
        . '<span class="hint">Min 10 characters with upper case, lower case and a number.</span></div>';
};

layout_start($isAdmin ? 'Settings' : 'My account', 'settings', $isAdmin ? 'Monitoring, Telegram alerts, team, security &amp; cron.' : 'Your login, password and two-factor authentication.');
?>
<div class="settings-layout">
  <nav class="tabs card glass" data-tabs>
    <?php if ($isAdmin): ?>
    <a href="#general" data-tab="general"><?= icon('sliders') ?>General</a>
    <a href="#telegram" data-tab="telegram"><?= icon('send') ?>Telegram alerts</a>
    <a href="#team" data-tab="team"><?= icon('user') ?>Team</a>
    <?php endif; ?>
    <a href="#security" data-tab="security"><?= icon('shield') ?><?= $isAdmin ? 'Security' : 'My account' ?></a>
    <?php if ($isAdmin): ?><a href="#cron" data-tab="cron"><?= icon('terminal') ?>Cron &amp; system</a><?php endif; ?>
  </nav>

  <div class="tab-panels">
    <?php if ($isAdmin): ?>
    <!-- GENERAL -->
    <section class="tab-panel" id="tab-general">
      <form method="post" class="card glass">
        <?= csrf_field() ?><input type="hidden" name="section" value="general">
        <div class="card-head"><h3><?= icon('sliders') ?>General</h3></div>
        <div class="form-grid">
          <div class="field"><label>Dashboard name</label><input class="input" name="app_name" value="<?= e(app_name()) ?>" maxlength="60"></div>
          <div class="field"><label>Timezone</label><select class="input" name="timezone"><?php foreach ($tzList as $tz): ?><option <?= $tz === date_default_timezone_get() ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Check interval</label>
            <select class="input" name="check_interval"><?php foreach ([60 => 'Every 1 minute', 120 => 'Every 2 minutes', 300 => 'Every 5 minutes', 600 => 'Every 10 minutes', 900 => 'Every 15 minutes'] as $k => $l): ?><option value="<?= $k ?>" <?= (int) setting('check_interval') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Connection timeout (sec)</label><input class="input" type="number" min="1" max="30" name="check_timeout" value="<?= e(setting('check_timeout')) ?>"></div>
          <div class="field"><label>Mark DOWN after N failed checks</label><input class="input" type="number" min="1" max="10" name="fail_threshold" value="<?= e(setting('fail_threshold')) ?>">
            <span class="hint">2 = avoids false alarms from one dropped packet</span></div>
          <div class="field"><label>Keep check history (days)</label><input class="input" type="number" min="7" max="730" name="retention_days" value="<?= e(setting('retention_days')) ?>"></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button></div>
      </form>
    </section>

    <!-- TELEGRAM -->
    <section class="tab-panel" id="tab-telegram">
      <form method="post" class="card glass" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="section" value="telegram">
        <div class="card-head"><h3><?= icon('send') ?>Telegram bot</h3>
          <label class="switch"><input type="checkbox" name="tg_enabled" <?= $chk('tg_enabled') ?>><span class="slider"></span><span>Enabled</span></label></div>
        <div class="form-grid">
          <div class="field col-2"><label>Bot token <?= $hasToken ? '<span class="pill pill-up xs">' . icon('lock') . 'saved &amp; encrypted</span>' : '' ?></label>
            <div class="input-ico"><?= icon('key') ?><input class="input mono" id="tgToken" type="password" readonly data-no-autofill name="tg_token" placeholder="<?= $hasToken ? 'Leave blank to keep current token' : '123456789:AAH…' ?>" autocomplete="off">
              <button class="icon-btn input-eye" type="button" data-toggle-pw="#tgToken"><?= icon('eye') ?></button></div>
            <?php if ($hasToken): ?><label class="check mt-1"><input type="checkbox" name="tg_clear_token"> Remove saved token</label><?php endif; ?>
          </div>
          <div class="field col-2"><label>Chat ID(s)</label><input class="input mono" name="tg_chat_ids" value="<?= e(setting('tg_chat_ids')) ?>" placeholder="123456789, -1001234567890">
            <span class="hint">Your user ID, a group ID (starts with -100…) or @channelname. Separate multiple with commas.</span></div>
        </div>
        <div class="toggle-grid">
          <label class="switch"><input type="checkbox" name="tg_notify_down" <?= $chk('tg_notify_down') ?>><span class="slider"></span><span>🔴 Alert when a server goes <b>down</b></span></label>
          <label class="switch"><input type="checkbox" name="tg_notify_up" <?= $chk('tg_notify_up') ?>><span class="slider"></span><span>🟢 Alert when it <b>recovers</b> (with downtime)</span></label>
          <label class="switch"><input type="checkbox" name="tg_daily_report" <?= $chk('tg_daily_report') ?>><span class="slider"></span><span>📊 Send <b>daily report</b></span></label>
          <label class="switch"><input type="checkbox" name="tg_login_alert" <?= $chk('tg_login_alert') ?>><span class="slider"></span><span>🔐 Alert on every <b>dashboard login</b></span></label>
          <label class="switch"><input type="checkbox" name="tg_renewal" <?= $chk('tg_renewal') ?>><span class="slider"></span><span>💳 <b>Renewal reminders</b> before VPS due date</span></label>
          <label class="switch"><input type="checkbox" name="tg_renewal_overdue" <?= $chk('tg_renewal_overdue') ?>><span class="slider"></span><span>⏰ Remind <b>daily when overdue</b> until marked renewed</span></label>
        </div>
        <div class="form-grid">
          <div class="field"><label>Daily report &amp; renewal reminder time</label><input class="input" type="time" name="tg_report_time" value="<?= e(setting('tg_report_time')) ?>"></div>
          <div class="field"><label>Renewal reminder — days before due date</label><input class="input mono" name="tg_renewal_days" value="<?= e(setting('tg_renewal_days')) ?>" placeholder="7,3,1,0">
            <span class="hint">0 = on the due date. Uses each server's “Next due date”.</span></div>
          <div class="field"><label>Repeat “still down” alert every</label>
            <select class="input" name="tg_remind_minutes"><?php foreach ([0 => 'Never', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 180 => '3 hours', 360 => '6 hours'] as $k => $l): ?><option value="<?= $k ?>" <?= (int) setting('tg_remind_minutes') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" type="button" data-tg-test><?= icon('send') ?>Send test message</button>
          <button class="btn btn-ghost" type="button" data-send-report data-range="24h"><?= icon('report') ?>Send 24h report now</button>
          <button class="btn btn-ghost" type="button" data-renewal-now><?= icon('calendar') ?>Send renewal reminder now</button>
          <button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button>
        </div>
      </form>
      <div class="card glass guide">
        <h4><?= icon('smartphone') ?>Setup in 2 minutes</h4>
        <ol>
          <li>Open Telegram → search <b>@BotFather</b> → send <code>/newbot</code> → choose a name → copy the <b>bot token</b>.</li>
          <li>Open your new bot and press <b>Start</b> (or add the bot to your team group).</li>
          <li>Get your chat ID from <b>@userinfobot</b> (for groups: add <b>@RawDataBot</b> temporarily — the ID starts with <code>-100</code>).</li>
          <li>Paste both above, enable, <b>Save</b>, then click <b>Send test message</b>.</li>
        </ol>
      </div>
    </section>

    <!-- TEAM -->
    <section class="tab-panel" id="tab-team">
      <div class="card glass table-card">
        <div class="card-head"><h3><?= icon('user') ?>Team members</h3><span class="muted small">Only admins can add people or change their email / role</span></div>
        <div class="table-wrap">
          <table class="table table-compact">
            <thead><tr><th>Member</th><th>Role</th><th>Root passwords</th><th>2FA</th><th>Last login</th><th class="right">Manage</th></tr></thead>
            <tbody>
            <?php foreach ($team as $m): $self = (int) $m['id'] === $uid; ?>
              <tr class="<?= (int) $m['active'] ? '' : 'row-dim' ?>">
                <td><b><?= e($m['username']) ?></b><?= $self ? ' <span class="pill pill-up xs">You</span>' : '' ?><?= (int) $m['active'] ? '' : ' <span class="pill pill-down xs">Disabled</span>' ?><?= (int) $m['must_change_pw'] ? ' <span class="pill pill-warn xs" title="Must choose their own password at next login">Must change password</span>' : '' ?>
                  <div class="cell-sub mono"><?= e((string) $m['email']) ?></div></td>
                <td><span class="pill xs <?= $m['role'] === 'admin' ? 'pill-host' : ($m['role'] === 'support' ? 'pill-up' : 'pill-unknown') ?>"><?= e(ROLES[$m['role']] ?? $m['role']) ?></span></td>
                <td><?= $m['role'] === 'admin' || ($m['role'] === 'support' && (int) $m['can_reveal']) ? '<span class="txt-warn small">Can view</span>' : '<span class="muted small">No</span>' ?></td>
                <td><?= (int) $m['totp_enabled'] ? '<span class="pill pill-up xs">On</span>' : '<span class="pill pill-warn xs">Off</span>' ?></td>
                <td class="small"><?= $m['last_login_at'] ? e(fmt_time($m['last_login_at'], 'd M, h:i A')) . '<div class="cell-sub mono">' . e((string) $m['last_login_ip']) . '</div>' : '<span class="muted">Never</span>' ?></td>
                <td class="right">
                  <?php if ($self): ?><a class="link small" href="#security" data-tab-link="security">My account</a>
                  <?php else: ?>
                  <button class="btn btn-ghost xs" type="button" data-toggle-row="#team-edit-<?= (int) $m['id'] ?>"><?= icon('edit') ?>Manage</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php if (!$self): ?>
              <tr class="team-edit-row" id="team-edit-<?= (int) $m['id'] ?>" hidden><td colspan="6">
                    <div class="team-forms">
                      <form method="post" class="form-grid" autocomplete="off">
                        <?= csrf_field() ?><input type="hidden" name="section" value="user_update"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div class="field"><label>Name</label><input class="input" name="name" value="<?= e($m['username']) ?>" required></div>
                        <div class="field"><label>Login email</label><input class="input" type="email" name="email" value="<?= e((string) $m['email']) ?>" required></div>
                        <div class="field"><label>Role</label><select class="input" name="role"><?php foreach (ROLES as $rk => $rl): ?><option value="<?= $rk ?>" <?= $m['role'] === $rk ? 'selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></div>
                        <div class="field"><label>&nbsp;</label>
                          <label class="check"><input type="checkbox" name="can_reveal" <?= (int) $m['can_reveal'] ? 'checked' : '' ?>> Can view root passwords (support)</label>
                          <label class="check"><input type="checkbox" name="active" <?= (int) $m['active'] ? 'checked' : '' ?>> Account active</label></div>
                        <div class="field col-2"><label>Your password <span class="muted small">(to confirm)</span></label><input class="input" type="password" name="admin_password" required autocomplete="current-password"></div>
                        <div class="form-actions col-2"><button class="btn btn-primary sm" type="submit"><?= icon('check') ?>Save</button></div>
                      </form>
                      <form method="post" class="form-grid" autocomplete="off">
                        <?= csrf_field() ?><input type="hidden" name="section" value="user_reset"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div class="field col-2"><h4 class="team-form-title"><?= icon('key') ?>Change password of <?= e($m['username']) ?></h4></div>
                        <?= $pwField('pw-' . (int) $m['id'], 'New password') ?>
                        <div class="field"><label>&nbsp;</label>
                          <label class="check"><input type="checkbox" name="force_change"> Make them choose their own password at next login</label>
                          <label class="check"><input type="checkbox" name="reset_2fa"> Also reset their 2FA (lost phone)</label></div>
                        <div class="field col-2"><label>Your password <span class="muted small">(to confirm)</span></label><input class="input" type="password" name="admin_password" required autocomplete="current-password"></div>
                        <div class="form-actions col-2"><button class="btn btn-ghost sm" type="submit"><?= icon('key') ?>Change password</button></div>
                      </form>
                      <form method="post" class="form-grid" autocomplete="off" data-confirm="Delete <?= e($m['username']) ?>? They will not be able to log in. Their activity stays in the log.">
                        <?= csrf_field() ?><input type="hidden" name="section" value="user_delete"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div class="field col-2"><label>Your password <span class="muted small">(to confirm)</span></label><input class="input" type="password" name="admin_password" required autocomplete="current-password"></div>
                        <div class="form-actions col-2"><button class="btn btn-danger sm" type="submit"><?= icon('trash') ?>Delete member</button></div>
                      </form>
                    </div>
              </td></tr>
              <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <form method="post" class="card glass" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="section" value="user_add">
        <div class="card-head"><h3><?= icon('plus') ?>Add team member</h3></div>
        <div class="form-grid">
          <div class="field"><label>Name</label><input class="input" name="name" required placeholder="Rahul Support" maxlength="32"></div>
          <div class="field"><label>Login email</label><input class="input" type="email" name="email" required placeholder="rahul@company.com" autocomplete="off"></div>
          <div class="field"><label>Role</label><select class="input" name="role"><option value="support" selected><?= e(ROLES['support']) ?></option><option value="viewer"><?= e(ROLES['viewer']) ?></option><option value="admin"><?= e(ROLES['admin']) ?></option></select></div>
          <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="can_reveal"> Can view root passwords</label></div>
          <?= $pwField('pw-new', 'Their password <span class="muted small">(share privately)</span>') ?>
          <div class="field"><label>Your password <span class="muted small">(to confirm)</span></label><input class="input" type="password" name="admin_password" required autocomplete="current-password"></div>
          <div class="field col-2"><label class="check"><input type="checkbox" name="force_change"> Make them choose their own password at first login <span class="muted small">(more private — then even you won't know it)</span></label></div>
        </div>
        <ul class="sec-list">
          <li><?= icon('check') ?><b>Support</b> — check servers, pause/resume, add &amp; edit servers, licenses and clients. Root passwords only if you tick the box (every view is logged). No settings, provider logins, deleting or team changes.</li>
          <li><?= icon('check') ?><b>Viewer</b> — read-only dashboard, servers, licenses, clients and reports.</li>
          <li><?= icon('check') ?><b>Admin</b> — everything, including this page.</li>
          <li><?= icon('check') ?><span>You can change any member's password later from <b>Manage</b>. They can also change it and turn on 2FA under “My account”. Only an admin can change their email. Changing role / email / status signs them out everywhere.</span></li>
        </ul>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('plus') ?>Add member</button></div>
      </form>
    </section>
    <?php endif; ?>

    <!-- SECURITY -->
    <section class="tab-panel" id="tab-security">
      <?php if ((int) $u['must_change_pw']): ?>
        <div class="alert alert-warn"><?= icon('alert') ?>Your password was set by the admin. Choose your own password below (and turn on 2FA) to continue.</div>
      <?php endif; ?>
      <div class="card glass">
        <div class="card-head"><h3><?= icon('smartphone') ?>Two-factor authentication</h3>
          <?= (int) $u['totp_enabled'] ? '<span class="pill pill-up">' . icon('check') . 'Enabled</span>' : '<span class="pill pill-warn">Not enabled</span>' ?></div>
        <?php if ((int) $u['totp_enabled']): ?>
          <p class="muted">Logins require a 6-digit code from your authenticator app. To disable, confirm password and a current code.</p>
          <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?><input type="hidden" name="section" value="2fa_disable">
            <div class="field"><label>Password</label><input class="input" type="password" name="password" required autocomplete="current-password"></div>
            <div class="field"><label>Authenticator code</label><input class="input mono" name="code" inputmode="numeric" maxlength="6" required></div>
            <div class="form-actions col-2"><button class="btn btn-danger" type="submit"><?= icon('x') ?>Disable 2FA</button></div>
          </form>
        <?php else: ?>
          <div class="twofa">
            <div class="qr-box"><div id="qrcode" data-qr="<?= e($otpUri) ?>"></div></div>
            <div>
              <p>1. Scan with <b>Google Authenticator</b>, <b>Authy</b> or <b>Microsoft Authenticator</b>.</p>
              <p class="muted small">Can't scan? Enter this key manually:</p>
              <div class="secret-box"><code><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></code><button class="icon-btn" type="button" data-copy="<?= e($setupSecret) ?>"><?= icon('copy') ?></button></div>
              <form method="post" class="inline-form" autocomplete="off">
                <?= csrf_field() ?><input type="hidden" name="section" value="2fa_enable">
                <p>2. Enter the 6-digit code to confirm:</p>
                <div class="row-gap"><input class="input mono otp-sm" name="code" inputmode="numeric" maxlength="6" placeholder="123456" required>
                  <button class="btn btn-primary" type="submit"><?= icon('shield') ?>Enable 2FA</button></div>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <form method="post" class="card glass" autocomplete="off">
        <?= csrf_field() ?><input type="hidden" name="section" value="password">
        <div class="card-head"><h3><?= icon('lock') ?>Login credentials</h3></div>
        <div class="form-grid">
          <div class="field"><label>Login email<?= $isAdmin ? '' : ' <span class="muted small">(only the admin can change it)</span>' ?></label><input class="input" type="email" name="email" value="<?= e((string) ($u['email'] ?? '')) ?>" placeholder="you@company.com" required autocomplete="username" <?= $isAdmin ? '' : 'readonly' ?>></div>
          <div class="field"><label>Display name</label><input class="input" name="username" value="<?= e($u['username']) ?>" required></div>
          <div class="field col-2"><label>Current password <span class="muted small">(required to save any change)</span></label><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="field"><label>New password <span class="muted small">(leave blank to keep)</span></label><input class="input" type="password" name="new_password" autocomplete="new-password" data-strength></div>
          <div class="field"><label>Confirm new password</label><input class="input" type="password" name="new_password2" autocomplete="new-password"></div>
          <div class="col-2 strength"><div class="strength-bar"><i></i></div><span class="muted small" data-strength-label>Min 10 chars with upper, lower &amp; number</span></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Update credentials</button></div>
      </form>

      <?php if ($isAdmin): ?>
      <form method="post" class="card glass">
        <?= csrf_field() ?><input type="hidden" name="section" value="security">
        <div class="card-head"><h3><?= icon('shield') ?>Access control</h3></div>
        <div class="form-grid">
          <div class="field"><label>Auto-logout after inactivity (minutes)</label><input class="input" type="number" min="5" max="720" name="session_timeout" value="<?= e(setting('session_timeout')) ?>"></div>
          <div class="field"><label>Your current IP</label><div class="secret-box"><code><?= e(client_ip()) ?></code><button class="icon-btn" type="button" data-copy="<?= e(client_ip()) ?>"><?= icon('copy') ?></button></div></div>
          <div class="field col-2"><label>IP whitelist <span class="muted small">(optional — one IP or CIDR per line; empty = allow all)</span></label>
            <textarea class="input mono" name="ip_whitelist" rows="3" placeholder="203.0.113.5&#10;198.51.100.0/24"><?= e(setting('ip_whitelist')) ?></textarea>
            <span class="hint">Locked out? Set <code>'disable_ip_whitelist' =&gt; true</code> in app/config.php.</span></div>
        </div>
        <ul class="sec-list">
          <li><?= icon('check') ?>Passwords hashed with <?= defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt' ?> · vault encrypted with AES-256-GCM</li>
          <li><?= icon('check') ?>Login lockout after <?= LOGIN_MAX_IP_FAILS ?> failed attempts per IP for 15 minutes</li>
          <li><?= icon('check') ?>CSRF tokens, strict CSP, HttpOnly/SameSite cookies, session fingerprinting</li>
          <li><?= icon('check') ?>Last login: <?= e(fmt_time($u['last_login_at'])) ?> from <?= e($u['last_login_ip'] ?? '—') ?></li>
        </ul>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Save</button></div>
      </form>
      <?php endif; ?>
    </section>

    <?php if ($isAdmin): ?>
    <!-- CRON -->
    <section class="tab-panel" id="tab-cron">
      <div class="card glass">
        <div class="card-head"><h3><?= icon('terminal') ?>Background checker (cron)</h3>
          <?= $lastCron && time() - $lastCron < 300 ? '<span class="pill pill-up"><span class="dot dot-up"></span>Running · ' . e(time_ago($lastCron)) . '</span>' : '<span class="pill pill-down"><span class="dot dot-down"></span>' . ($lastCron ? 'Last run ' . e(time_ago($lastCron)) : 'Never run') . '</span>' ?></div>
        <p class="muted">cPanel → <b>Cron Jobs</b> → Common settings: <b>Once Per Minute (* * * * *)</b> → paste this command:</p>
        <div class="secret-box"><code><?= e($cronCmd) ?></code><button class="icon-btn" type="button" data-copy="<?= e($cronCmd) ?>"><?= icon('copy') ?></button></div>
        <p class="muted small">If <code>/usr/local/bin/php</code> doesn't work on your host try <code>php</code> or <code>/usr/bin/php</code>. Alternative (URL trigger — keep secret):</p>
        <div class="secret-box"><code>wget -q -O /dev/null "<?= e($cronUrl) ?>"</code><button class="icon-btn" type="button" data-copy="<?= e('wget -q -O /dev/null "' . $cronUrl . '"') ?>"><?= icon('copy') ?></button></div>
      </div>
      <div class="card glass">
        <div class="card-head"><h3><?= icon('cpu') ?>System</h3></div>
        <dl class="kv">
          <dt>App version</dt><dd><?= e(APP_VERSION) ?></dd>
          <dt>PHP</dt><dd><?= e(PHP_VERSION) ?></dd>
          <dt>Database</dt><dd><?= e(config('db.driver') === 'sqlite' ? 'SQLite' : 'MySQL / MariaDB') ?></dd>
          <dt>ICMP ping</dt><dd><?= exec_available() ? '<span class="txt-good">Available</span>' : '<span class="txt-warn">Disabled — TCP checks used</span>' ?></dd>
          <dt>cURL</dt><dd><?= function_exists('curl_init') ? '<span class="txt-good">Available</span>' : '<span class="txt-bad">Missing</span>' ?></dd>
          <dt>HTTPS</dt><dd><?= is_https() ? '<span class="txt-good">Yes</span>' : '<span class="txt-warn">No — enable SSL for this domain</span>' ?></dd>
          <dt>Server time</dt><dd><?= e(date('d M Y, h:i:s A T')) ?></dd>
          <dt>Check history rows</dt><dd><?= number_format((int) val('SELECT COUNT(*) FROM checks')) ?></dd>
        </dl>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php layout_end('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>');
