<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | General helpers
 * -----------------------------------------------------------------*/

function config(string $key, $default = null)
{
    $c = $GLOBALS['CONFIG'] ?? [];
    foreach (explode('.', $key) as $k) {
        if (!is_array($c) || !array_key_exists($k, $c)) {
            return $default;
        }
        $c = $c[$k];
    }
    return $c;
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $bp = null;
    if ($bp !== null) {
        return $bp;
    }
    if (PHP_SAPI === 'cli') {
        return $bp = '/';
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $bp = rtrim($dir, '/') . '/';
}

function url(string $path = ''): string
{
    return base_path() . ltrim($path, '/');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (config('trust_proxy')) {
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        if (stripos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false) {
            return true;
        }
    }
    return false;
}

function full_url(string $path = ''): string
{
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        $base = rtrim((string) setting('app_url', ''), '/');
        return $base ? $base . '/' . ltrim($path, '/') : '';
    }
    $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', (string) $_SERVER['HTTP_HOST']);
    return (is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function client_ip(): string
{
    if (config('trust_proxy')) {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function post(string $key, string $default = '', int $max = 5000): string
{
    $v = $_POST[$key] ?? $default;
    if (!is_string($v)) {
        return $default;
    }
    return substr(trim($v), 0, $max);
}

function get_param(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? substr(trim($v), 0, 500) : $default;
}

function flash(string $type, string $msg): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_attr($data): string
{
    return e(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function json_script($data): string
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
}

function security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

/* ------------------------------------------------------------------
 | Formatting
 * -----------------------------------------------------------------*/

function fmt_duration($sec, int $parts = 2): string
{
    if ($sec === null || $sec === '') {
        return '—';
    }
    $s = max(0, (int) $sec);
    if ($s < 60) {
        return $s . 's';
    }
    $units = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    $out = [];
    foreach ($units as $u => $n) {
        if ($s >= $n) {
            $v = intdiv($s, $n);
            $s -= $v * $n;
            $out[] = $v . $u;
        } elseif ($out) {
            // keep units contiguous (e.g. 2d 0h) — skip silently
            continue;
        }
        if (count($out) >= $parts) {
            break;
        }
    }
    return implode(' ', $out);
}

function fmt_time($ts, string $fmt = 'd M Y, h:i A'): string
{
    return $ts ? date($fmt, (int) $ts) : '—';
}

function time_ago($ts): string
{
    if (!$ts) {
        return 'never';
    }
    $d = time() - (int) $ts;
    return $d < 5 ? 'just now' : fmt_duration($d) . ' ago';
}

function fmt_pct($p, int $dec = 2): string
{
    if ($p === null) {
        return '—';
    }
    if ($p >= 100) {
        return '100%';
    }
    return number_format((float) $p, $dec) . '%';
}

function uptime_class($pct): string
{
    if ($pct === null) {
        return 'none';
    }
    if ($pct >= 99.9) {
        return 'good';
    }
    if ($pct >= 98) {
        return 'warn';
    }
    return 'bad';
}

function status_meta(array $s): array
{
    $status = $s['status'] ?? 'unknown';
    if ((int) ($s['enabled'] ?? 1) === 0) {
        $status = 'paused';
    }
    switch ($status) {
        case 'up':
            if ((int) ($s['fail_count'] ?? 0) > 0) {
                return ['label' => 'Retrying', 'cls' => 'warn', 'status' => 'up'];
            }
            return ['label' => 'Online', 'cls' => 'up', 'status' => 'up'];
        case 'down':
            return ['label' => 'Offline', 'cls' => 'down', 'status' => 'down'];
        case 'paused':
            return ['label' => 'Paused', 'cls' => 'paused', 'status' => 'paused'];
        default:
            return ['label' => 'Pending', 'cls' => 'unknown', 'status' => 'unknown'];
    }
}

function safe_color($c, string $fallback = '#7c5cff'): string
{
    return (is_string($c) && preg_match('/^#[0-9a-f]{6}$/i', $c)) ? $c : $fallback;
}

function valid_host(string $h): bool
{
    if ($h === '' || strlen($h) > 253) {
        return false;
    }
    if (filter_var($h, FILTER_VALIDATE_IP)) {
        return true;
    }
    return (bool) preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/i', $h);
}

function initials(string $name): string
{
    $name = trim($name);
    return strtoupper($name === '' ? '?' : substr($name, 0, 1));
}

function log_file(string $name, string $line): void
{
    $dir = APP_ROOT . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents($dir . '/' . $name, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
