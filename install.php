<?php
/*
 * One-time web installer. Locks itself once app/config.php exists.
 */
define('SKIP_CONFIG', true);
require __DIR__ . '/app/bootstrap.php';

$cfgFile = APP_ROOT . '/app/config.php';
session_boot();
security_headers();

if (is_file($cfgFile)) {
    http_response_code(403);
    auth_layout_start('Already installed');
    echo '<div class="auth-wrap"><div class="auth-card glass"><div class="auth-logo">' . icon('shield') . '</div><h1>Already installed</h1><p class="muted">The installer is locked because <code>app/config.php</code> exists.</p><a class="btn btn-primary w-full" href="' . e(url('login.php')) . '">Go to login</a></div></div>';
    auth_layout_end();
    exit;
}

$checks = [
    'PHP 8.0+'               => PHP_VERSION_ID >= 80000,
    'PDO extension'          => extension_loaded('pdo'),
    'PDO MySQL or SQLite'    => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
    'OpenSSL (encryption)'   => extension_loaded('openssl'),
    'cURL (Telegram/HTTP)'   => extension_loaded('curl'),
    'app/ folder writable'   => is_writable(APP_ROOT . '/app'),
    'storage/ folder writable' => is_writable(APP_ROOT . '/storage'),
];
$allOk = !in_array(false, $checks, true);
$errors = [];
$done = null;
$tzList = timezone_identifiers_list();

$f = [
    'driver' => post('driver', 'mysql'), 'host' => post('host', 'localhost'), 'port' => post('port', '3306'),
    'name' => post('name'), 'user' => post('user'), 'app_name' => post('app_name', 'Uptime Command'),
    'email' => strtolower(post('email')), 'timezone' => post('timezone', 'Asia/Kolkata'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $errors[] = 'Security token expired, please submit again.';
    }
    $pass = (string) ($_POST['pass'] ?? '');
    $pw = (string) ($_POST['password'] ?? '');
    $pw2 = (string) ($_POST['password2'] ?? '');
    if (!in_array($f['driver'], ['mysql', 'sqlite'], true)) {
        $errors[] = 'Invalid database driver.';
    }
    if ($f['driver'] === 'mysql' && ($f['name'] === '' || $f['user'] === '')) {
        $errors[] = 'Database name and user are required for MySQL.';
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid admin email address (used to log in).';
    }
    if ($err = password_strength_error($pw)) {
        $errors[] = $err;
    }
    if ($pw !== $pw2) {
        $errors[] = 'Passwords do not match.';
    }
    if (!in_array($f['timezone'], $tzList, true)) {
        $errors[] = 'Invalid timezone.';
    }
    if (!$allOk) {
        $errors[] = 'Fix the requirement checks first.';
    }

    if (!$errors) {
        $db = $f['driver'] === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => 'storage/uptime.sqlite']
            : ['driver' => 'mysql', 'host' => $f['host'], 'port' => (int) $f['port'], 'name' => $f['name'], 'user' => $f['user'], 'pass' => $pass];
        try {
            $pdo = db_connect($db);
            foreach (db_schema($f['driver']) as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $ex) {
                    if (stripos($ex->getMessage(), 'Duplicate key name') === false && stripos($ex->getMessage(), 'already exists') === false) {
                        throw $ex;
                    }
                }
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                throw new RuntimeException('This database already contains users. Use an empty database.');
            }
            $appKey = 'base64:' . base64_encode(random_bytes(32));
            $config = [
                'db'                   => $db,
                'app_key'              => $appKey,
                'cron_token'           => random_token(24),
                'timezone'             => $f['timezone'],
                'trust_proxy'          => false,
                'disable_ip_whitelist' => false,
                'debug'                => false,
            ];
            $now = time();
            $display = substr(preg_replace('/[^a-zA-Z0-9_.\-]/', '', explode('@', $f['email'])[0]), 0, 32) ?: 'admin';
            $st = $pdo->prepare('INSERT INTO users (username, email, password_hash, totp_enabled, created_at) VALUES (?, ?, ?, 0, ?)');
            $st->execute([$display, $f['email'], password_hash($pw, password_algo()), $now]);

            $defaults = settings_defaults();
            $defaults['app_name'] = $f['app_name'] !== '' ? substr($f['app_name'], 0, 60) : 'Uptime Command';
            $defaults['timezone'] = $f['timezone'];
            $st = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
            foreach ($defaults as $k => $v) {
                $st->execute([$k, $v]);
            }
            $st = $pdo->prepare('INSERT INTO providers (name, website, color, created_at) VALUES (?, ?, ?, ?)');
            foreach ([['EricHost', '', '#7c5cff'], ['ServerBroker', '', '#22d3ee'], ['Servica', '', '#f59e0b']] as $p) {
                $st->execute([$p[0], $p[1], $p[2], $now]);
            }

            $php = "<?php\n// Generated by installer on " . date('Y-m-d H:i:s') . "\n// KEEP THIS FILE SECRET & BACKED UP — app_key decrypts all stored passwords.\ndefined('APP_ROOT') || exit;\n\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($cfgFile, $php, LOCK_EX) === false) {
                throw new RuntimeException('Could not write app/config.php — check folder permissions.');
            }
            @chmod($cfgFile, 0640);
            $done = $config;
        } catch (Throwable $ex) {
            $errors[] = 'Install failed: ' . $ex->getMessage();
        }
    }
}

auth_layout_start('Install');
?>
<div class="auth-wrap wide">
  <div class="auth-card glass install-card">
    <div class="auth-logo"><?= icon('activity') ?></div>
    <?php if ($done): ?>
      <h1>Installation complete 🎉</h1>
      <p class="muted">Your monitoring dashboard is ready. Set up the cron job below so servers are checked every minute.</p>
      <div class="callout">
        <div class="callout-title"><?= icon('terminal') ?> cPanel → Cron Jobs → “Once Per Minute (* * * * *)”</div>
        <div class="secret-box"><code>/usr/local/bin/php <?= e(APP_ROOT) ?>/cron.php &gt;/dev/null 2&gt;&amp;1</code></div>
        <p class="muted small">No CLI access? Use the URL variant (keep it secret):</p>
        <div class="secret-box"><code>wget -q -O /dev/null "<?= e(full_url('cron.php')) ?>?token=<?= e($done['cron_token']) ?>"</code></div>
      </div>
      <div class="callout warn"><?= icon('alert') ?> Back up <code>app/config.php</code> — it holds the encryption key for all saved root passwords.</div>
      <a class="btn btn-primary w-full lg" href="<?= e(url('login.php')) ?>"><?= icon('lock') ?>Continue to secure login</a>
    <?php else: ?>
      <h1>Install <?= e($f['app_name']) ?></h1>
      <p class="muted">One-time setup. This page locks itself after installation.</p>

      <div class="req-grid">
        <?php foreach ($checks as $label => $ok): ?>
          <div class="req <?= $ok ? 'ok' : 'bad' ?>"><?= icon($ok ? 'check' : 'x') ?><?= e($label) ?></div>
        <?php endforeach; ?>
        <div class="req <?= exec_available() ? 'ok' : 'info' ?>"><?= icon(exec_available() ? 'check' : 'alert') ?>ICMP ping <?= exec_available() ? 'available' : 'disabled (TCP used)' ?></div>
      </div>

      <?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('alert') ?><?= e($er) ?></div><?php endforeach; ?>

      <form method="post" class="form-grid" autocomplete="off">
        <?= csrf_field() ?>
        <h4 class="form-section">Database</h4>
        <div class="field col-2">
          <label>Driver</label>
          <div class="seg" data-seg>
            <label><input type="radio" name="driver" value="mysql" <?= $f['driver'] !== 'sqlite' ? 'checked' : '' ?>><span>MySQL / MariaDB (recommended)</span></label>
            <label><input type="radio" name="driver" value="sqlite" <?= $f['driver'] === 'sqlite' ? 'checked' : '' ?>><span>SQLite (no DB needed)</span></label>
          </div>
        </div>
        <div class="field" data-mysql><label>DB host</label><input class="input" name="host" value="<?= e($f['host']) ?>"></div>
        <div class="field" data-mysql><label>DB port</label><input class="input" name="port" value="<?= e($f['port']) ?>"></div>
        <div class="field" data-mysql><label>DB name</label><input class="input" name="name" value="<?= e($f['name']) ?>" placeholder="cpaneluser_uptime"></div>
        <div class="field" data-mysql><label>DB user</label><input class="input" name="user" value="<?= e($f['user']) ?>" placeholder="cpaneluser_uptime"></div>
        <div class="field col-2" data-mysql><label>DB password</label><input class="input" type="password" name="pass" autocomplete="new-password"></div>

        <h4 class="form-section">Administrator</h4>
        <div class="field"><label>Dashboard name</label><input class="input" name="app_name" value="<?= e($f['app_name']) ?>"></div>
        <div class="field"><label>Timezone</label>
          <select class="input" name="timezone"><?php foreach ($tzList as $tz): ?><option <?= $tz === $f['timezone'] ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field col-2"><label>Admin email (used to log in)</label><input class="input" type="email" name="email" value="<?= e($f['email']) ?>" placeholder="you@company.com" autocomplete="username" required></div>
        <div class="field"><label>Password</label><input class="input" type="password" name="password" autocomplete="new-password" required data-strength></div>
        <div class="field"><label>Confirm password</label><input class="input" type="password" name="password2" autocomplete="new-password" required></div>
        <div class="col-2 strength"><div class="strength-bar"><i></i></div><span class="muted small" data-strength-label>Min 10 chars with upper, lower & number</span></div>
        <button class="btn btn-primary w-full lg col-2" type="submit" <?= $allOk ? '' : 'disabled' ?>><?= icon('zap') ?>Install now</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php auth_layout_end();
