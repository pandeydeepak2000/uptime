<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Monitoring engine
 |  - TCP checks run in parallel (async sockets)
 |  - HTTP checks run in parallel (curl_multi)
 |  - ICMP ping via system `ping` when exec() is available
 * -----------------------------------------------------------------*/

const CHECK_TYPES = [
    'auto' => 'Smart (SSH/80/443 + Ping)',
    'tcp'  => 'TCP Port',
    'ping' => 'ICMP Ping',
    'http' => 'HTTP(S) URL',
];

function exec_available(): bool
{
    static $r = null;
    if ($r !== null) {
        return $r;
    }
    if (!function_exists('exec')) {
        return $r = false;
    }
    $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));
    return $r = !in_array('exec', $disabled, true);
}

function clean_net_error(string $err, int $port): string
{
    if (stripos($err, 'getaddrinfo') !== false || stripos($err, 'getaddresses') !== false || stripos($err, 'name or service') !== false) {
        return 'DNS lookup failed';
    }
    if (stripos($err, 'refused') !== false) {
        return "Port $port refused";
    }
    if (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) {
        return "Port $port timed out";
    }
    if (stripos($err, 'unreachable') !== false) {
        return 'Host unreachable';
    }
    return "Port $port: " . ($err !== '' ? substr($err, 0, 80) : 'connection failed');
}

/**
 * @param array $targets key => [host, port]
 */
function probe_tcp_many(array $targets, float $timeout): array
{
    $results = [];
    $socks = [];
    $starts = [];
    foreach ($targets as $key => $t) {
        [$host, $port] = $t;
        $h = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$host]" : $host;
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $s = @stream_socket_client("tcp://$h:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
        if ($s === false) {
            $results[$key] = ['ok' => false, 'ms' => null, 'error' => clean_net_error((string) $errstr, (int) $port)];
            continue;
        }
        stream_set_blocking($s, false);
        $socks[$key] = $s;
        $starts[$key] = $start;
    }

    $deadline = microtime(true) + $timeout;
    while ($socks) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            break;
        }
        $r = null;
        $w = $socks;
        $x = $socks;
        $n = @stream_select($r, $w, $x, (int) floor($left), (int) (($left - floor($left)) * 1000000));
        if ($n === false) {
            break;
        }
        if ($n === 0) {
            continue;
        }
        foreach ($x as $key => $s) {
            if (!isset($socks[$key])) {
                continue;
            }
            $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'Port ' . $targets[$key][1] . ' refused'];
            @fclose($s);
            unset($socks[$key]);
        }
        foreach ($w as $key => $s) {
            if (!isset($socks[$key])) {
                continue;
            }
            $ms = (int) round((microtime(true) - $starts[$key]) * 1000);
            if (@stream_socket_get_name($s, true) !== false) {
                $results[$key] = ['ok' => true, 'ms' => max(1, $ms), 'error' => null];
            } else {
                $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'Port ' . $targets[$key][1] . ' refused'];
            }
            @fclose($s);
            unset($socks[$key]);
        }
    }
    foreach ($socks as $key => $s) {
        $results[$key] = ['ok' => false, 'ms' => null, 'error' => 'Port ' . $targets[$key][1] . ' timed out'];
        @fclose($s);
    }
    return $results;
}

/**
 * @param array $targets key => url
 */
function probe_http_many(array $targets, float $timeout): array
{
    $results = [];
    if (!function_exists('curl_multi_init')) {
        foreach ($targets as $k => $u) {
            $results[$k] = ['ok' => false, 'ms' => null, 'error' => 'cURL extension not available'];
        }
        return $results;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($targets as $key => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => (int) ceil($timeout),
            CURLOPT_TIMEOUT        => (int) ceil($timeout * 2),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'UptimeCommand/' . APP_VERSION . ' (+monitor)',
            CURLOPT_HTTPHEADER     => ['Accept: */*', 'Cache-Control: no-cache'],
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($handles as $key => $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $err = curl_error($ch);
        $ok = $code >= 200 && $code < 500;
        $results[$key] = [
            'ok'    => $ok,
            'ms'    => $ok ? max(1, (int) round($time * 1000)) : null,
            'error' => $ok ? null : ($code ? "HTTP $code" : substr($err ?: 'No response', 0, 120)),
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

function probe_ping(string $host, float $timeout): array
{
    if (!exec_available()) {
        return ['ok' => false, 'ms' => null, 'error' => 'ICMP unavailable (exec disabled)'];
    }
    if (!valid_host($host)) {
        return ['ok' => false, 'ms' => null, 'error' => 'Invalid host'];
    }
    $t = max(1, (int) ceil($timeout));
    $win = stripos(PHP_OS, 'WIN') === 0;
    $cmd = $win
        ? 'ping -n 1 -w ' . ($t * 1000) . ' ' . escapeshellarg($host)
        : 'ping -c 1 -W ' . $t . ' ' . escapeshellarg($host) . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    $txt = implode("\n", $out);
    if ($code === 0 && preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $txt, $m)) {
        return ['ok' => true, 'ms' => max(1, (int) round((float) $m[1])), 'error' => null];
    }
    return ['ok' => false, 'ms' => null, 'error' => 'No ICMP reply'];
}

/**
 * Run checks for many servers at once. Returns server_id => result.
 */
function run_checks(array $servers): array
{
    $timeout = max(1, min(30, (float) setting('check_timeout', 5)));
    $canPing = exec_available();
    $tcp = [];
    $http = [];
    $plan = [];

    foreach ($servers as $s) {
        $id = (int) $s['id'];
        $type = $s['check_type'] ?: 'auto';
        if ($type === 'ping' && !$canPing) {
            $type = 'auto'; // graceful fallback on shared hosting
        }
        $plan[$id] = $type;
        if ($type === 'tcp') {
            $tcp["$id:0"] = [$s['host'], (int) ($s['port'] ?: ($s['ssh_port'] ?: 22))];
        } elseif ($type === 'http') {
            $http[$id] = $s['url'] ?: ('http://' . $s['host']);
        } elseif ($type === 'auto') {
            $ports = array_values(array_unique([(int) ($s['ssh_port'] ?: 22), 80, 443]));
            foreach ($ports as $i => $p) {
                $tcp["$id:$i"] = [$s['host'], $p];
            }
        }
    }

    $tr = $tcp ? probe_tcp_many($tcp, $timeout) : [];
    $hr = $http ? probe_http_many($http, $timeout) : [];

    $grouped = [];
    foreach ($tr as $k => $r) {
        $sid = (int) explode(':', (string) $k)[0];
        $grouped[$sid][] = $r;
    }

    $out = [];
    foreach ($servers as $s) {
        $id = (int) $s['id'];
        $type = $plan[$id];
        if ($type === 'http') {
            $out[$id] = ($hr[$id] ?? ['ok' => false, 'ms' => null, 'error' => 'No result']) + ['method' => 'HTTP'];
            continue;
        }
        if ($type === 'ping') {
            $out[$id] = probe_ping($s['host'], $timeout) + ['method' => 'ICMP'];
            continue;
        }
        $rs = $grouped[$id] ?? [];
        $oks = array_values(array_filter($rs, fn ($r) => $r['ok']));
        if ($oks) {
            $out[$id] = ['ok' => true, 'ms' => min(array_column($oks, 'ms')), 'error' => null, 'method' => 'TCP'];
            continue;
        }
        $err = implode(', ', array_unique(array_filter(array_column($rs, 'error'))));
        if ($type === 'auto' && $canPing) {
            $p = probe_ping($s['host'], min($timeout, 3));
            if ($p['ok']) {
                $out[$id] = $p + ['method' => 'ICMP'];
                continue;
            }
            $err .= ($err ? ', ' : '') . $p['error'];
        }
        $out[$id] = ['ok' => false, 'ms' => null, 'error' => substr($err ?: 'No response', 0, 250), 'method' => 'TCP'];
    }
    return $out;
}

/**
 * Persist one result and handle UP/DOWN transitions. Returns an event or null.
 */
function apply_check_result(array $s, array $r, int $now, int $threshold): ?array
{
    $id = (int) $s['id'];
    db_insert('checks', [
        'server_id'   => $id,
        'checked_at'  => $now,
        'ok'          => $r['ok'] ? 1 : 0,
        'response_ms' => $r['ms'],
        'error'       => $r['ok'] ? null : substr((string) $r['error'], 0, 250),
    ]);

    $upd = [
        'last_check_at'    => $now,
        'last_response_ms' => $r['ms'],
        'last_error'       => $r['ok'] ? null : substr((string) $r['error'], 0, 250),
    ];
    $event = null;

    if ($r['ok']) {
        $upd['fail_count'] = 0;
        $upd['first_fail_at'] = null;
        if ($s['status'] === 'down') {
            $inc = row('SELECT * FROM incidents WHERE server_id = ? AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1', [$id]);
            $start = $inc ? (int) $inc['started_at'] : (int) ($s['status_since'] ?: $now);
            $dur = max(0, $now - $start);
            q('UPDATE incidents SET ended_at = ?, duration = ? WHERE server_id = ? AND ended_at IS NULL', [$now, $dur, $id]);
            $event = ['type' => 'up', 'server' => $s, 'duration' => $dur, 'ms' => $r['ms'], 'at' => $now];
        }
        if ($s['status'] !== 'up') {
            $upd['status'] = 'up';
            $upd['status_since'] = $now;
        }
    } else {
        $fails = (int) $s['fail_count'] + 1;
        $firstFail = (int) ($s['first_fail_at'] ?: $now);
        $upd['fail_count'] = $fails;
        $upd['first_fail_at'] = $firstFail;
        if ($s['status'] !== 'down' && $fails >= $threshold) {
            $upd['status'] = 'down';
            $upd['status_since'] = $firstFail;
            $upd['last_alert_at'] = $now;
            db_insert('incidents', [
                'server_id'  => $id,
                'started_at' => $firstFail,
                'ended_at'   => null,
                'duration'   => null,
                'cause'      => substr((string) $r['error'], 0, 250),
            ]);
            $event = ['type' => 'down', 'server' => $s, 'error' => $r['error'], 'since' => $firstFail, 'at' => $now];
        }
    }
    db_update('servers', $upd, 'id = ?', [$id]);
    return $event;
}

function monitor_lock(bool $wait)
{
    $file = APP_ROOT . '/storage/monitor.lock';
    $fp = @fopen($file, 'c');
    if (!$fp) {
        return true; // cannot lock (read-only FS) — proceed anyway
    }
    if (!flock($fp, $wait ? LOCK_EX : (LOCK_EX | LOCK_NB))) {
        fclose($fp);
        return null;
    }
    return $fp;
}

/**
 * Check servers. $ids = null → all enabled. $force = ignore interval.
 */
function run_monitor(?array $ids = null, bool $force = false, bool $wait = true): array
{
    $lock = monitor_lock($wait);
    if ($lock === null) {
        return ['ok' => false, 'skipped' => true, 'checked' => 0, 'events' => [], 'error' => 'Another check is already running'];
    }
    try {
        @set_time_limit(300);
        $now = time();
        $interval = max(60, (int) setting('check_interval', 60));
        $sql = 'SELECT s.*, p.name AS provider_name FROM servers s LEFT JOIN providers p ON p.id = s.provider_id WHERE s.enabled = 1';
        if ($ids) {
            $ids = array_map('intval', $ids);
            $sql .= ' AND s.id IN (' . implode(',', $ids) . ')';
        }
        $servers = rows($sql);
        if (!$force) {
            $servers = array_values(array_filter($servers, fn ($s) => !$s['last_check_at'] || ($now - (int) $s['last_check_at']) >= $interval - 15));
        }
        if (!$servers) {
            return ['ok' => true, 'checked' => 0, 'events' => [], 'results' => []];
        }

        $results = run_checks($servers);
        $threshold = max(1, (int) setting('fail_threshold', 2));
        $now = time();
        $events = [];

        db()->beginTransaction();
        try {
            foreach ($servers as $s) {
                $ev = apply_check_result($s, $results[(int) $s['id']], $now, $threshold);
                if ($ev) {
                    $events[] = $ev;
                }
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
            throw $e;
        }

        foreach ($events as $ev) {
            audit($ev['type'] === 'down' ? 'server_down' : 'server_up', $ev['server']['name'] . ' (' . $ev['server']['host'] . ')'
                . ($ev['type'] === 'down' ? ' — ' . $ev['error'] : ' — downtime ' . fmt_duration($ev['duration'], 3)), null);
        }
        if ($events) {
            tg_notify_events($events);
        }
        return ['ok' => true, 'checked' => count($servers), 'events' => $events, 'results' => $results];
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** Pause / resume a server and keep incidents consistent. */
function set_server_enabled(int $id, bool $enabled): void
{
    $now = time();
    if (!$enabled) {
        $open = row('SELECT * FROM incidents WHERE server_id = ? AND ended_at IS NULL', [$id]);
        if ($open) {
            q('UPDATE incidents SET ended_at = ?, duration = ? WHERE id = ?', [$now, $now - (int) $open['started_at'], (int) $open['id']]);
        }
        db_update('servers', ['enabled' => 0, 'status' => 'paused', 'status_since' => $now, 'fail_count' => 0, 'first_fail_at' => null], 'id = ?', [$id]);
    } else {
        db_update('servers', ['enabled' => 1, 'status' => 'unknown', 'status_since' => $now, 'fail_count' => 0, 'first_fail_at' => null, 'last_check_at' => null], 'id = ?', [$id]);
    }
}

/** Snapshot used by the live dashboard (api.php?action=status). */
function status_snapshot(): array
{
    $list = rows('SELECT id, name, host, status, enabled, status_since, last_check_at, last_response_ms, last_error, fail_count FROM servers');
    $out = [];
    $sum = ['total' => 0, 'up' => 0, 'down' => 0, 'paused' => 0, 'unknown' => 0];
    foreach ($list as $s) {
        $m = status_meta($s);
        $sum['total']++;
        $sum[$m['status']]++;
        $out[] = [
            'id'      => (int) $s['id'],
            'status'  => $m['status'],
            'label'   => $m['label'],
            'cls'     => $m['cls'],
            'since'   => (int) $s['status_since'],
            'checked' => (int) $s['last_check_at'],
            'ms'      => $s['last_response_ms'] !== null ? (int) $s['last_response_ms'] : null,
            'error'   => $s['last_error'],
        ];
    }
    return ['servers' => $out, 'summary' => $sum, 'cron' => (int) setting('last_cron_run', 0), 'now' => time()];
}
