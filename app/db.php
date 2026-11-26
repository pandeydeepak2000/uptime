<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Database layer (PDO — MySQL / MariaDB or SQLite)
 * -----------------------------------------------------------------*/

function db_connect(array $c): PDO
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $path = (string) ($c['path'] ?? 'storage/uptime.sqlite');
        if (!preg_match('~^([a-z]:)?[\\\\/]~i', $path)) {
            $path = APP_ROOT . '/' . $path;
        }
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost',
        (int) ($c['port'] ?? 3306),
        $c['name'] ?? ''
    );
    return new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['pass'] ?? ''), $opts);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect((array) config('db', []));
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $params = []): int
{
    $set = [];
    foreach (array_keys($data) as $c) {
        $set[] = '`' . $c . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . $where;
    return q($sql, array_merge(array_values($data), $params))->rowCount();
}

const DB_VERSION = 2;

/**
 * Upgrade an existing database in place (runs once per version bump).
 */
function db_migrate(): void
{
    $v = (int) setting('db_version', 1);
    if ($v >= DB_VERSION) {
        return;
    }
    if ($v < 2) {
        try {
            db()->exec('ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL');
        } catch (PDOException $e) {
            if (stripos($e->getMessage(), 'duplicate') === false) {
                throw $e;
            }
        }
    }
    setting_set('db_version', DB_VERSION);
}

/**
 * Schema for a fresh install. Returns a list of SQL statements.
 */
function db_schema(string $driver): array
{
    $my = $driver !== 'sqlite';
    $id = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $bigId = $my ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $end = $my ? ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ')';

    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS `users` (
            `id` $id,
            `username` VARCHAR(64) NOT NULL UNIQUE,
            `email` VARCHAR(190) NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `totp_secret` TEXT NULL,
            `totp_enabled` INT NOT NULL DEFAULT 0,
            `created_at` INT NOT NULL DEFAULT 0,
            `last_login_at` INT NULL,
            `last_login_ip` VARCHAR(64) NULL
        $end",
        'login_attempts' => "CREATE TABLE IF NOT EXISTS `login_attempts` (
            `id` $id,
            `ip` VARCHAR(64) NOT NULL,
            `username` VARCHAR(64) NULL,
            `attempted_at` INT NOT NULL,
            `success` INT NOT NULL DEFAULT 0
        $end",
        'providers' => "CREATE TABLE IF NOT EXISTS `providers` (
            `id` $id,
            `name` VARCHAR(100) NOT NULL,
            `website` VARCHAR(255) NULL,
            `panel_url` VARCHAR(255) NULL,
            `account_user` VARCHAR(190) NULL,
            `account_pass` TEXT NULL,
            `support_email` VARCHAR(190) NULL,
            `color` VARCHAR(16) NULL,
            `notes` TEXT NULL,
            `created_at` INT NOT NULL DEFAULT 0
        $end",
        'servers' => "CREATE TABLE IF NOT EXISTS `servers` (
            `id` $id,
            `provider_id` INT NULL,
            `name` VARCHAR(100) NOT NULL,
            `host` VARCHAR(255) NOT NULL,
            `check_type` VARCHAR(10) NOT NULL DEFAULT 'auto',
            `port` INT NULL,
            `url` VARCHAR(255) NULL,
            `ssh_port` INT NOT NULL DEFAULT 22,
            `ssh_user` VARCHAR(64) NULL,
            `ssh_pass` TEXT NULL,
            `location` VARCHAR(100) NULL,
            `os` VARCHAR(100) NULL,
            `specs` VARCHAR(190) NULL,
            `monthly_cost` DECIMAL(10,2) NULL,
            `currency` VARCHAR(8) NULL,
            `expires_at` VARCHAR(10) NULL,
            `notes` TEXT NULL,
            `enabled` INT NOT NULL DEFAULT 1,
            `status` VARCHAR(10) NOT NULL DEFAULT 'unknown',
            `status_since` INT NULL,
            `last_check_at` INT NULL,
            `last_response_ms` INT NULL,
            `last_error` VARCHAR(255) NULL,
            `fail_count` INT NOT NULL DEFAULT 0,
            `first_fail_at` INT NULL,
            `last_alert_at` INT NULL,
            `created_at` INT NOT NULL DEFAULT 0
        $end",
        'checks' => "CREATE TABLE IF NOT EXISTS `checks` (
            `id` $bigId,
            `server_id` INT NOT NULL,
            `checked_at` INT NOT NULL,
            `ok` INT NOT NULL DEFAULT 0,
            `response_ms` INT NULL,
            `error` VARCHAR(255) NULL
        $end",
        'incidents' => "CREATE TABLE IF NOT EXISTS `incidents` (
            `id` $id,
            `server_id` INT NOT NULL,
            `started_at` INT NOT NULL,
            `ended_at` INT NULL,
            `duration` INT NULL,
            `cause` VARCHAR(255) NULL
        $end",
        'settings' => "CREATE TABLE IF NOT EXISTS `settings` (
            `k` VARCHAR(64) NOT NULL PRIMARY KEY,
            `v` TEXT NULL
        $end",
        'audit_log' => "CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` $id,
            `user_id` INT NULL,
            `action` VARCHAR(64) NOT NULL,
            `details` VARCHAR(500) NULL,
            `ip` VARCHAR(64) NULL,
            `created_at` INT NOT NULL
        $end",
    ];

    $indexes = [
        'CREATE INDEX idx_attempts_ip ON login_attempts (ip, attempted_at)',
        'CREATE INDEX idx_checks_server ON checks (server_id, checked_at)',
        'CREATE INDEX idx_checks_time ON checks (checked_at)',
        'CREATE INDEX idx_incidents_server ON incidents (server_id, started_at)',
        'CREATE INDEX idx_audit_time ON audit_log (created_at)',
    ];
    if (!$my) {
        $indexes = array_map(fn ($s) => str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $s), $indexes);
    }
    return array_merge(array_values($tables), $indexes);
}
