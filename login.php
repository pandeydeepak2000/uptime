<?php
require __DIR__ . '/app/bootstrap.php';
session_boot();

if (auth_user()) {
    redirect('dashboard.php');
}

$ip = client_ip();
if (!ip_allowed($ip)) {
    http_response_code(403);
    security_headers();
    exit('Access denied.');
}

$error = '';
$info = isset($_GET['expired']) ? 'Your session expired. Please sign in again.' : '';
$pending = $_SESSION['pending_2fa'] ?? null;
if ($pending && (time() - (int) $pending['at']) > 300) {
    unset($_SESSION['pending_2fa']);
    $pending = null;
    $info = '2FA step timed out. Please sign in again.';
}
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    if (!csrf_valid()) {
        $error = 'Security token expired. Please try again.';
    } elseif ($action === 'cancel') {
        unset($_SESSION['pending_2fa']);
        redirect('login.php');
    } elseif (post('website') !== '') {
        // honeypot field filled → bot
        record_attempt($ip, 'bot', false);
        $error = 'Invalid username or password.';
    } else {
        $username = post('username', '', 64);
        $lock = login_lock_seconds($ip, $action === '2fa' ? '' : $username);
        if ($lock > 0) {
            $error = 'Too many failed attempts. Try again in ' . fmt_duration($lock) . '.';
        } elseif ($action === 'password') {
            $pw = (string) ($_POST['password'] ?? '');
            $u = $username !== '' ? row('SELECT * FROM users WHERE username = ?', [$username]) : null;
            // always run password_verify to keep timing constant
            $hash = $u['password_hash'] ?? password_hash(random_token(8), password_algo());
            $ok = password_verify($pw, $hash) && $u;
            if (!$ok) {
                record_attempt($ip, $username, false);
                audit('login_failed', 'Username: ' . $username, null);
                usleep(random_int(250000, 650000));
                $left = LOGIN_MAX_IP_FAILS - (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?', [$ip, time() - LOGIN_WINDOW]);
                $error = 'Invalid username or password.' . ($left > 0 && $left <= 3 ? " $left attempt(s) left." : '');
            } else {
                if (password_needs_rehash($u['password_hash'], password_algo())) {
                    db_update('users', ['password_hash' => password_hash($pw, password_algo())], 'id = ?', [(int) $u['id']]);
                }
                if ((int) $u['totp_enabled'] === 1) {
                    session_regenerate_id(true);
                    $_SESSION['pending_2fa'] = ['uid' => (int) $u['id'], 'at' => time()];
                    $pending = $_SESSION['pending_2fa'];
                } else {
                    record_attempt($ip, $username, true);
                    login_user($u);
                    redirect('dashboard.php');
                }
            }
        } elseif ($action === '2fa' && $pending) {
            $u = row('SELECT * FROM users WHERE id = ?', [(int) $pending['uid']]);
            $code = post('code', '', 10);
            if ($u && totp_verify(decrypt_str($u['totp_secret']), $code)) {
                unset($_SESSION['pending_2fa']);
                record_attempt($ip, $u['username'], true);
                login_user($u);
                redirect('dashboard.php');
            }
            record_attempt($ip, $u['username'] ?? '', false);
            audit('2fa_failed', 'Invalid authenticator code', $u ? (int) $u['id'] : null);
            usleep(random_int(250000, 650000));
            $error = 'Invalid authentication code.';
        }
    }
}

auth_layout_start('Sign in');
?>
<div class="auth-wrap">
  <div class="auth-side hide-md">
    <div class="auth-hero">
      <div class="hero-badge"><span class="dot dot-up"></span> Live infrastructure monitoring</div>
      <h2>Every server.<br><span class="grad-text">Every second.</span></h2>
      <p>Real-time uptime for all your VPS across EricHost, ServerBroker, Servica and more — with encrypted credential vault and instant Telegram alerts.</p>
      <div class="hero-stats">
        <div><b>AES-256</b><span>Encrypted vault</span></div>
        <div><b>2FA</b><span>TOTP protected</span></div>
        <div><b>24/7</b><span>Telegram alerts</span></div>
      </div>
      <div class="pulse-line" aria-hidden="true"><svg viewBox="0 0 600 80" preserveAspectRatio="none"><path d="M0 40 H180 L200 40 L215 10 L232 70 L248 25 L262 48 L275 40 H600"/></svg></div>
    </div>
  </div>
  <div class="auth-card glass">
    <div class="auth-logo"><?= icon($pending ? 'smartphone' : 'shield') ?></div>
    <?php if ($pending): ?>
      <h1>Two-factor check</h1>
      <p class="muted">Enter the 6-digit code from your authenticator app.</p>
      <?php if ($error): ?><div class="alert alert-error shake"><?= icon('alert') ?><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="2fa">
        <input class="input otp-input" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" autofocus required>
        <button class="btn btn-primary w-full lg" type="submit"><?= icon('check') ?>Verify &amp; sign in</button>
      </form>
      <form method="post" class="center mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="link-btn" type="submit">← Use a different account</button></form>
    <?php else: ?>
      <h1>Welcome back</h1>
      <p class="muted">Sign in to <b><?= e(app_name()) ?></b></p>
      <?php if ($info): ?><div class="alert alert-info"><?= icon('clock') ?><?= e($info) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error shake"><?= icon('alert') ?><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on" class="stack">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="password">
        <div class="hp" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
        <div class="field">
          <label for="username">Username</label>
          <div class="input-ico"><?= icon('user') ?><input class="input" id="username" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus></div>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <div class="input-ico"><?= icon('lock') ?><input class="input" id="password" type="password" name="password" autocomplete="current-password" required>
            <button class="icon-btn input-eye" type="button" data-toggle-pw="#password" aria-label="Show password"><?= icon('eye') ?></button></div>
        </div>
        <button class="btn btn-primary w-full lg" type="submit"><?= icon('lock') ?>Secure sign in</button>
      </form>
    <?php endif; ?>
    <div class="auth-foot"><?= icon('shield') ?> Protected by rate-limiting, CSRF tokens &amp; encrypted sessions · IP <?= e($ip) ?></div>
  </div>
</div>
<?php auth_layout_end();
