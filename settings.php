<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$uid = (int) $user['id'];
$tzList = timezone_identifiers_list();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = post('section');
    $tab = $section;
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
                foreach (['tg_enabled', 'tg_notify_down', 'tg_notify_up', 'tg_daily_report', 'tg_login_alert'] as $k) {
                    setting_set($k, isset($_POST[$k]) ? '1' : '0');
                }
                setting_set('tg_remind_minutes', max(0, min(1440, (int) post('tg_remind_minutes'))));
                if ($time !== setting('tg_report_time')) {
                    setting_set('tg_last_report_date', date('H:i') >= $time ? date('Y-m-d') : '');
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
                if ($err = password_strength_error($new)) {
                    throw new InvalidArgumentException($err);
                }
                if ($new !== (string) ($_POST['new_password2'] ?? '')) {
                    throw new InvalidArgumentException('New passwords do not match.');
                }
                $newUser = post('username', $user['username'], 32);
                if (!preg_match('/^[a-zA-Z0-9_.\-]{3,32}$/', $newUser)) {
                    throw new InvalidArgumentException('Invalid username.');
                }
                db_update('users', ['password_hash' => password_hash($new, password_algo()), 'username' => $newUser], 'id = ?', [$uid]);
                session_regenerate_id(true);
                audit('password_changed', 'Password changed');
                flash('success', 'Credentials updated.');
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

layout_start('Settings', 'settings', 'Monitoring, Telegram alerts, security &amp; cron.');
?>
<div class="settings-layout">
  <nav class="tabs card glass" data-tabs>
    <a href="#general" data-tab="general"><?= icon('sliders') ?>General</a>
    <a href="#telegram" data-tab="telegram"><?= icon('send') ?>Telegram alerts</a>
    <a href="#security" data-tab="security"><?= icon('shield') ?>Security</a>
    <a href="#cron" data-tab="cron"><?= icon('terminal') ?>Cron &amp; system</a>
  </nav>

  <div class="tab-panels">
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
            <div class="input-ico"><?= icon('key') ?><input class="input mono" id="tgToken" type="password" name="tg_token" placeholder="<?= $hasToken ? 'Leave blank to keep current token' : '123456789:AAH…' ?>" autocomplete="off">
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
        </div>
        <div class="form-grid">
          <div class="field"><label>Daily report time</label><input class="input" type="time" name="tg_report_time" value="<?= e(setting('tg_report_time')) ?>"></div>
          <div class="field"><label>Repeat “still down” alert every</label>
            <select class="input" name="tg_remind_minutes"><?php foreach ([0 => 'Never', 15 => '15 minutes', 30 => '30 minutes', 60 => '1 hour', 180 => '3 hours', 360 => '6 hours'] as $k => $l): ?><option value="<?= $k ?>" <?= (int) setting('tg_remind_minutes') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost" type="button" data-tg-test><?= icon('send') ?>Send test message</button>
          <button class="btn btn-ghost" type="button" data-send-report data-range="24h"><?= icon('report') ?>Send 24h report now</button>
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

    <!-- SECURITY -->
    <section class="tab-panel" id="tab-security">
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
          <div class="field col-2"><label>Username</label><input class="input" name="username" value="<?= e($u['username']) ?>" required></div>
          <div class="field col-2"><label>Current password</label><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
          <div class="field"><label>New password</label><input class="input" type="password" name="new_password" required autocomplete="new-password" data-strength></div>
          <div class="field"><label>Confirm new password</label><input class="input" type="password" name="new_password2" required autocomplete="new-password"></div>
          <div class="col-2 strength"><div class="strength-bar"><i></i></div><span class="muted small" data-strength-label>Min 10 chars with upper, lower &amp; number</span></div>
        </div>
        <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?>Update credentials</button></div>
      </form>

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
    </section>

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
  </div>
</div>
<?php layout_end('<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" referrerpolicy="no-referrer"></script>');
