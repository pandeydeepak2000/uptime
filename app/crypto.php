<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Encryption (AES-256-GCM) + TOTP (RFC 6238) helpers
 * -----------------------------------------------------------------*/

function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $k = (string) config('app_key', '');
    if (strpos($k, 'base64:') === 0) {
        $k = base64_decode(substr($k, 7), true) ?: '';
    }
    if (strlen($k) !== 32) {
        throw new RuntimeException('Invalid app_key in app/config.php (must be 32 bytes, base64 encoded).');
    }
    return $key = $k;
}

function encrypt_str(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag, 'uptime', 16);
    if ($ct === false) {
        throw new RuntimeException('Encryption failed');
    }
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

function decrypt_str(?string $enc): string
{
    if (!$enc || strpos($enc, 'v1:') !== 0) {
        return '';
    }
    $raw = base64_decode(substr($enc, 3), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct = substr($raw, 28);
    $p = openssl_decrypt($ct, 'aes-256-gcm', app_key(), OPENSSL_RAW_DATA, $iv, $tag, 'uptime');
    return $p === false ? '' : $p;
}

function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function password_algo()
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function password_strength_error(string $pw): ?string
{
    if (strlen($pw) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[a-z]/', $pw) || !preg_match('/[A-Z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must contain uppercase, lowercase and a number.';
    }
    return null;
}

/* ---------------- TOTP (Google Authenticator / Authy) ---------------- */

const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $data): string
{
    if ($data === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= B32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

function base32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    if ($s === '') {
        return '';
    }
    $bits = '';
    foreach (str_split($s) as $c) {
        $bits .= str_pad(decbin(strpos(B32_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) < 8) {
            break;
        }
        $out .= chr(bindec($b));
    }
    return $out;
}

function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secret, int $slice): string
{
    $key = base32_decode($secret);
    $time = pack('N*', 0) . pack('N*', $slice);
    $h = hash_hmac('sha1', $time, $key, true);
    $o = ord($h[19]) & 0xf;
    $code = (((ord($h[$o]) & 0x7f) << 24) | ((ord($h[$o + 1]) & 0xff) << 16) | ((ord($h[$o + 2]) & 0xff) << 8) | (ord($h[$o + 3]) & 0xff)) % 1000000;
    return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code) || $secret === '') {
        return false;
    }
    $slice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $slice + $i), $code)) {
            return true;
        }
    }
    return false;
}

function totp_uri(string $secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
}
