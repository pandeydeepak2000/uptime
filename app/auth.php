<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Sessions, CSRF, authentication, rate-limiting, audit log
 * -----------------------------------------------------------------*/

const LOGIN_WINDOW = 900;      // 15 minutes
const LOGIN_MAX_IP_FAILS = 5;  // per IP inside window
const LOGIN_MAX_USER_FAILS = 20;

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', '86400');
    session_name('UPTIME_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $dir = APP_ROOT . '/storage/sessions';
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = random_token(32);
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $t);
}

function csrf_check(bool $json = false): void
{
    if (!csrf_valid()) {
        if ($json) {
            json_out(['ok' => false, 'error' => 'Security token expired. Refresh the page.'], 419);
        }
        http_response_code(419);
        flash('error', 'Security token expired — please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? '';
        header('Location: ' . (($back && strpos($back, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== false) ? $back : url('dashboard.php')));
        exit;
    }
}

function ua_hash(): string
{
    return hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

/** Returns the logged-in user or null (validates timeout / fingerprint). */
function auth_user(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    session_boot();
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if (!$uid) {
        return $cache = null;
    }
    $timeout = max(5, (int) setting('session_timeout', 30)) * 60;
    $expired = (time() - (int) ($_SESSION['last'] ?? 0)) > $timeout;
    $absolute = (time() - (int) ($_SESSION['login_at'] ?? 0)) > 43200; // 12h max session
    $badUa = !hash_equals((string) ($_SESSION['ua'] ?? ''), ua_hash());
    if ($expired || $absolute || $badUa || !ip_allowed(client_ip())) {
        logout_user();
        return $cache = null;
    }
    $u = row('SELECT id, username, email, totp_enabled, last_login_at, last_login_ip FROM users WHERE id = ?', [$uid]);
    if (!$u) {
        logout_user();
        return $cache = null;
    }
    $_SESSION['last'] = time();
    $GLOBALS['_AUTH_USER'] = $u;
    return $cache = $u;
}

function require_login(bool $api = false): array
{
    $u = auth_user();
    if ($u) {
        return $u;
    }
    if ($api) {
        json_out(['ok' => false, 'error' => 'Session expired. Please log in again.', 'login' => true], 401);
    }
    redirect('login.php?expired=1');
    return [];
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['uid'] = (int) $user['id'];
    $_SESSION['ua'] = ua_hash();
    $_SESSION['last'] = time();
    $_SESSION['login_at'] = time();
    $ip = client_ip();
    db_update('users', ['last_login_at' => time(), 'last_login_ip' => $ip], 'id = ?', [(int) $user['id']]);
    $u = full_url('');
    if ($u) {
        setting_set('app_url', $u);
    }
    audit('login', 'Successful login', (int) $user['id']);
    if (setting('tg_enabled') === '1' && setting('tg_login_alert') === '1') {
        tg_send("🔐 <b>New dashboard login</b>\n👤 User: <b>" . e($user['username']) . "</b>\n🌐 IP: <code>" . e($ip) . "</code>\n🕒 " . e(date('d M Y, h:i:s A'))
            . "\n💻 " . e(substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)));
    }
}

function logout_user(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

/** Seconds left on lockout (0 = allowed). */
function login_lock_seconds(string $ip, string $username = ''): int
{
    $since = time() - LOGIN_WINDOW;
    $r = row('SELECT COUNT(*) AS c, MAX(attempted_at) AS last FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at > ?', [$ip, $since]);
    if ($r && (int) $r['c'] >= LOGIN_MAX_IP_FAILS) {
        return max(1, (int) $r['last'] + LOGIN_WINDOW - time());
    }
    if ($username !== '') {
        $username = substr($username, 0, 64);
        $r = row('SELECT COUNT(*) AS c, MAX(attempted_at) AS last FROM login_attempts WHERE username = ? AND success = 0 AND attempted_at > ?', [$username, $since]);
        if ($r && (int) $r['c'] >= LOGIN_MAX_USER_FAILS) {
            return max(1, (int) $r['last'] + LOGIN_WINDOW - time());
        }
    }
    return 0;
}

function record_attempt(string $ip, string $username, bool $success): void
{
    db_insert('login_attempts', [
        'ip' => $ip, 'username' => substr($username, 0, 64), 'attempted_at' => time(), 'success' => $success ? 1 : 0,
    ]);
    if ($success) {
        q('DELETE FROM login_attempts WHERE ip = ? AND success = 0', [$ip]);
    }
}

function ip_allowed(string $ip): bool
{
    if (config('disable_ip_whitelist')) {
        return true;
    }
    $list = trim((string) setting('ip_whitelist', ''));
    if ($list === '') {
        return true;
    }
    foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) as $rule) {
        if ($rule === $ip) {
            return true;
        }
        if (strpos($rule, '/') !== false && ip_in_cidr($ip, $rule)) {
            return true;
        }
    }
    return false;
}

function ip_in_cidr(string $ip, string $cidr): bool
{
    [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
    $ipBin = @inet_pton($ip);
    $netBin = @inet_pton((string) $net);
    if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    $rem = $bits % 8;
    if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr((0xff << (8 - $rem)) & 0xff);
    return (($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask));
}

/**
 * Find the account for a login identifier.
 * Email is the login. Username works only while no email has been set (upgrade path).
 */
function find_login_user(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    if (strpos($login, '@') !== false) {
        return row('SELECT * FROM users WHERE LOWER(email) = ?', [strtolower($login)]);
    }
    return row("SELECT * FROM users WHERE username = ? AND (email IS NULL OR email = '')", [$login]);
}

function verify_current_password(int $uid, string $pw): bool
{
    $hash = (string) val('SELECT password_hash FROM users WHERE id = ?', [$uid]);
    return $hash !== '' && password_verify($pw, $hash);
}

function audit(string $action, string $details = '', ?int $uid = null): void
{
    try {
        db_insert('audit_log', [
            'user_id'    => $uid ?? (isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null),
            'action'     => substr($action, 0, 64),
            'details'    => substr($details, 0, 500),
            'ip'         => PHP_SAPI === 'cli' ? 'cli' : client_ip(),
            'created_at' => time(),
        ]);
    } catch (Throwable $e) {
        log_file('app.log', 'audit failed: ' . $e->getMessage());
    }
}
