<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Uptime calculations & reports
 * -----------------------------------------------------------------*/

function incidents_between(int $from, int $to, ?int $serverId = null): array
{
    $sql = 'SELECT * FROM incidents WHERE started_at < ? AND (ended_at IS NULL OR ended_at > ?)';
    $p = [$to, $from];
    if ($serverId) {
        $sql .= ' AND server_id = ?';
        $p[] = $serverId;
    }
    return rows($sql . ' ORDER BY started_at ASC', $p);
}

function clip_incident(array $inc, int $from, int $to, int $now): int
{
    $end = $inc['ended_at'] !== null ? (int) $inc['ended_at'] : $now;
    return max(0, min($end, $to) - max((int) $inc['started_at'], $from));
}

function downtime_in_window(array $incidents, int $from, int $to, int $now): int
{
    $sum = 0;
    foreach ($incidents as $i) {
        $sum += clip_incident($i, $from, $to, $now);
    }
    return $sum;
}

function build_report(int $from, int $to, ?int $serverId = null): array
{
    $now = time();
    $to = min($to, $now);
    $servers = rows(
        'SELECT s.*, p.name AS provider_name, p.color AS provider_color FROM servers s LEFT JOIN providers p ON p.id = s.provider_id'
        . ($serverId ? ' WHERE s.id = ?' : '') . ' ORDER BY s.name',
        $serverId ? [$serverId] : []
    );
    $by = [];
    foreach (incidents_between($from, $to, $serverId) as $i) {
        $by[(int) $i['server_id']][] = $i;
    }
    $stats = [];
    foreach (rows('SELECT server_id, COUNT(*) AS total, SUM(ok) AS ok_count, AVG(CASE WHEN ok = 1 THEN response_ms END) AS avg_ms,
                          MIN(CASE WHEN ok = 1 THEN response_ms END) AS min_ms, MAX(CASE WHEN ok = 1 THEN response_ms END) AS max_ms
                   FROM checks WHERE checked_at BETWEEN ? AND ?' . ($serverId ? ' AND server_id = ?' : '') . ' GROUP BY server_id',
        $serverId ? [$from, $to, $serverId] : [$from, $to]) as $r) {
        $stats[(int) $r['server_id']] = $r;
    }

    $out = [];
    $allIncidents = [];
    $totals = ['servers' => 0, 'up' => 0, 'down' => 0, 'paused' => 0, 'unknown' => 0, 'incidents' => 0, 'downtime' => 0, 'avg_uptime' => null, 'avg_ms' => null];
    $pcts = [];
    $msList = [];
    foreach ($servers as $s) {
        $id = (int) $s['id'];
        $start = max($from, (int) $s['created_at']);
        $window = max(0, $to - $start);
        $list = $by[$id] ?? [];
        $down = min($window, downtime_in_window($list, $start, $to, $now));
        $pct = $window > 0 ? max(0, 100 * ($window - $down) / $window) : null;
        $longest = 0;
        foreach ($list as $i) {
            $d = clip_incident($i, $from, $to, $now);
            $longest = max($longest, $d);
            $allIncidents[] = $i + ['server_name' => $s['name'], 'host' => $s['host'], 'provider_name' => $s['provider_name'], 'clipped' => $d];
        }
        $st = $stats[$id] ?? null;
        $m = status_meta($s);
        $row = $s + [
            'uptime'         => $pct,
            'downtime'       => $down,
            'incident_count' => count($list),
            'longest'        => $longest,
            'avg_ms'         => $st && $st['avg_ms'] !== null ? (int) round((float) $st['avg_ms']) : null,
            'min_ms'         => $st && $st['min_ms'] !== null ? (int) $st['min_ms'] : null,
            'max_ms'         => $st && $st['max_ms'] !== null ? (int) $st['max_ms'] : null,
            'checks'         => $st ? (int) $st['total'] : 0,
            'checks_ok'      => $st ? (int) $st['ok_count'] : 0,
            'state'          => $m,
        ];
        $out[] = $row;
        $totals['servers']++;
        $totals[$m['status']]++;
        $totals['incidents'] += count($list);
        $totals['downtime'] += $down;
        if ($pct !== null && (int) $s['enabled'] === 1) {
            $pcts[] = $pct;
        }
        if ($row['avg_ms'] !== null) {
            $msList[] = $row['avg_ms'];
        }
    }
    $totals['avg_uptime'] = $pcts ? array_sum($pcts) / count($pcts) : null;
    $totals['avg_ms'] = $msList ? (int) round(array_sum($msList) / count($msList)) : null;
    usort($allIncidents, fn ($a, $b) => (int) $b['started_at'] <=> (int) $a['started_at']);

    return ['from' => $from, 'to' => $to, 'servers' => $out, 'totals' => $totals, 'incidents' => $allIncidents];
}

/** Per-day uptime for the last N days: server_id => [ [label, pct|null, down], ... ] */
function uptime_days(int $days, ?int $serverId = null): array
{
    $now = time();
    $first = strtotime('today -' . ($days - 1) . ' days');
    $servers = rows('SELECT id, created_at FROM servers' . ($serverId ? ' WHERE id = ?' : ''), $serverId ? [$serverId] : []);
    $by = [];
    foreach (incidents_between($first, $now, $serverId) as $i) {
        $by[(int) $i['server_id']][] = $i;
    }
    $bounds = [];
    for ($d = 0; $d < $days; $d++) {
        $ds = strtotime("+$d day", $first);
        $bounds[] = [$ds, strtotime('+1 day', $ds)];
    }
    $out = [];
    foreach ($servers as $s) {
        $id = (int) $s['id'];
        $created = (int) $s['created_at'];
        $list = $by[$id] ?? [];
        $cells = [];
        foreach ($bounds as [$ds, $de]) {
            $ws = max($ds, $created);
            $we = min($de, $now);
            if ($we <= $ws) {
                $cells[] = ['d' => date('d M', $ds), 'p' => null, 'down' => 0];
                continue;
            }
            $down = min($we - $ws, downtime_in_window($list, $ws, $we, $now));
            $cells[] = ['d' => date('d M', $ds), 'p' => round(100 * (1 - $down / ($we - $ws)), 3), 'down' => $down];
        }
        $out[$id] = $cells;
    }
    return $out;
}

/** Resolve a named range to [from, to, label]. */
function report_range(string $range, string $fromStr = '', string $toStr = ''): array
{
    $now = time();
    $today = strtotime('today');
    switch ($range) {
        case 'yesterday':
            return [$today - 86400, $today - 1, 'Yesterday (' . date('d M Y', $today - 86400) . ')'];
        case '24h':
            return [$now - 86400, $now, 'Last 24 hours'];
        case '7d':
            return [strtotime('today -6 days'), $now, 'Last 7 days'];
        case '30d':
            return [strtotime('today -29 days'), $now, 'Last 30 days'];
        case 'month':
            return [strtotime(date('Y-m-01')), $now, 'This month (' . date('F Y') . ')'];
        case 'custom':
            $f = DateTime::createFromFormat('!Y-m-d', $fromStr);
            $t = DateTime::createFromFormat('!Y-m-d', $toStr);
            if ($f && $t) {
                $a = $f->getTimestamp();
                $b = min($now, $t->getTimestamp() + 86399);
                if ($b > $a) {
                    return [$a, $b, date('d M Y', $a) . ' → ' . date('d M Y', $b)];
                }
            }
            // fallthrough to today
        default:
            return [$today, $now, 'Today (' . date('d M Y') . ')'];
    }
}
