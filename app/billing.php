<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Billing: everything you pay for, as one list of "items".
 |  - every active service / license (synced or manual)
 |  - every monitored server that has no service behind it (manual cost)
 | A server that is linked to a VPS service is counted once (via the service).
 | client_id NULL = own use.
 * -----------------------------------------------------------------*/

const CURRENCIES = ['INR', 'USD', 'EUR', 'GBP', 'CAD', 'AED'];

function billing_items(): array
{
    $servers = [];
    foreach (rows('SELECT s.id, s.name, s.host, s.provider_id, s.client_id, s.monthly_cost, s.currency, s.expires_at, s.status, s.enabled,
                          p.name AS provider_name, p.color AS provider_color
                   FROM servers s LEFT JOIN providers p ON p.id = s.provider_id') as $s) {
        $servers[(int) $s['id']] = $s;
    }
    $covered = [];
    $items = [];
    foreach (rows('SELECT ps.*, p.name AS provider_name, p.color AS provider_color
                   FROM provider_services ps LEFT JOIN providers p ON p.id = ps.provider_id') as $ps) {
        if (service_is_ended($ps['status'])) {
            continue;
        }
        $srv = $ps['server_id'] ? ($servers[(int) $ps['server_id']] ?? null) : null;
        $monthly = service_monthly($ps['amount'], $ps['cycle']);
        $currency = $ps['currency'] ?: null;
        if ($monthly === null && $srv && $ps['kind'] !== 'license' && $srv['monthly_cost'] !== null) {
            // e.g. OVH: the API gives no price, but you typed the monthly cost on the server
            $monthly = (float) $srv['monthly_cost'];
            $currency = $srv['currency'];
        }
        if ($srv && $ps['kind'] !== 'license') {
            $covered[(int) $srv['id']] = true;
        }
        $items[] = [
            'key'            => 'service:' . (int) $ps['id'],
            'type'           => 'service',
            'id'             => (int) $ps['id'],
            'name'           => $ps['name'],
            'sub'            => $ps['hostname'] ?: ($ps['ip'] ?: ($srv['host'] ?? '')),
            'kind'           => in_array($ps['kind'], ['vps', 'license'], true) ? $ps['kind'] : 'other',
            'provider_id'    => (int) $ps['provider_id'],
            'provider_name'  => $ps['provider_name'],
            'provider_color' => $ps['provider_color'],
            'client_id'      => $ps['client_id'] !== null ? (int) $ps['client_id'] : null,
            'monthly'        => $monthly,
            'currency'       => $currency ?: 'INR',
            'cycle'          => $ps['cycle'],
            'next_due'       => $ps['next_due'],
            'server_id'      => $srv ? (int) $srv['id'] : null,
            'status'         => $srv ? ((int) $srv['enabled'] ? $srv['status'] : 'paused') : null,
            'state'          => service_state($ps['status']),
            'synced'         => $ps['source'] !== 'manual',
            'edit'           => 'service_edit.php?id=' . (int) $ps['id'],
        ];
    }
    foreach ($servers as $sid => $s) {
        if (isset($covered[$sid])) {
            continue;
        }
        $items[] = [
            'key'            => 'server:' . $sid,
            'type'           => 'server',
            'id'             => $sid,
            'name'           => $s['name'],
            'sub'            => $s['host'],
            'kind'           => 'vps',
            'provider_id'    => (int) $s['provider_id'],
            'provider_name'  => $s['provider_name'],
            'provider_color' => $s['provider_color'],
            'client_id'      => $s['client_id'] !== null ? (int) $s['client_id'] : null,
            'monthly'        => $s['monthly_cost'] !== null ? (float) $s['monthly_cost'] : null,
            'currency'       => $s['currency'] ?: 'INR',
            'cycle'          => $s['monthly_cost'] !== null ? 'Monthly' : null,
            'next_due'       => $s['expires_at'],
            'server_id'      => $sid,
            'status'         => (int) $s['enabled'] ? $s['status'] : 'paused',
            'state'          => 'active',
            'synced'         => false,
            'edit'           => 'server_edit.php?id=' . $sid,
        ];
    }
    usort($items, fn ($a, $b) => [strtolower((string) $a['provider_name']), $a['kind'], strtolower($a['name'])]
                             <=> [strtolower((string) $b['provider_name']), $b['kind'], strtolower($b['name'])]);
    return $items;
}

/** Sum monthly cost per currency: ['INR' => 5498.0, 'USD' => 97.87] */
function money_totals(array $items): array
{
    $t = [];
    foreach ($items as $it) {
        if ($it['monthly'] !== null && $it['monthly'] > 0) {
            $t[$it['currency']] = ($t[$it['currency']] ?? 0) + $it['monthly'];
        }
    }
    ksort($t);
    return $t;
}

function money_fmt(array $totals, int $dec = 2): string
{
    if (!$totals) {
        return '—';
    }
    $out = [];
    foreach ($totals as $cur => $v) {
        $out[] = $cur . ' ' . number_format($v, $dec);
    }
    return implode(' + ', $out);
}

function money_one(?float $v, string $cur): string
{
    return $v === null ? '—' : $cur . ' ' . number_format($v, 2);
}

/** Items without a known price (so totals are honest about what's missing). */
function items_unpriced(array $items): int
{
    return count(array_filter($items, fn ($it) => $it['monthly'] === null && $it['state'] !== 'pending'));
}

function clients_list(): array
{
    return rows('SELECT * FROM clients ORDER BY name');
}

/**
 * Assign items to a client (null = own use). Assigning a server (or the VPS service
 * that represents it) also moves the licenses that run on that server.
 */
function billing_assign(array $keys, ?int $clientId): int
{
    $n = 0;
    foreach ($keys as $key) {
        if (!preg_match('/^(service|server):(\d+)$/', (string) $key, $m)) {
            continue;
        }
        $id = (int) $m[2];
        if ($m[1] === 'server') {
            $n += assign_server_client($id, $clientId) ? 1 : 0;
            continue;
        }
        $ps = row('SELECT id, kind, server_id FROM provider_services WHERE id = ?', [$id]);
        if (!$ps) {
            continue;
        }
        if ($ps['server_id'] && $ps['kind'] !== 'license') {
            assign_server_client((int) $ps['server_id'], $clientId);
        }
        db_update('provider_services', ['client_id' => $clientId], 'id = ?', [$id]);
        $n++;
    }
    return $n;
}

/** Set a server's client and carry it over to every service / license linked to it. */
function assign_server_client(int $serverId, ?int $clientId): bool
{
    if (!val('SELECT id FROM servers WHERE id = ?', [$serverId])) {
        return false;
    }
    db_update('servers', ['client_id' => $clientId], 'id = ?', [$serverId]);
    db_update('provider_services', ['client_id' => $clientId], 'server_id = ?', [$serverId]);
    return true;
}

function client_select_options(?int $selected): string
{
    $h = '<option value="0">Own use (not a client)</option>';
    foreach (clients_list() as $c) {
        $h .= '<option value="' . (int) $c['id'] . '"' . ((int) $selected === (int) $c['id'] ? ' selected' : '') . '>'
            . e($c['name'] . ($c['company'] ? ' — ' . $c['company'] : '')) . '</option>';
    }
    return $h;
}

function client_badge(?array $c): string
{
    if (!$c) {
        return '<span class="pill pill-unknown xs">Own use</span>';
    }
    return provider_badge($c['name'], $c['color'] ?? null);
}
