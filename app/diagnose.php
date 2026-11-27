<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Turn a check error ("Port 22 timed out, Port 80 refused, No ICMP
 | reply") into a plain-language reason + commands to verify it.
 | Nothing here runs anything — it only explains.
 * -----------------------------------------------------------------*/

/** IP this dashboard checks from (to whitelist in the server's firewall). */
function monitor_ip(): string
{
    $ip = (string) setting('monitor_ip', '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/**
 * @param string $error     check error text (servers.last_error or incidents.cause)
 * @param array  $s         server row (host, check_type, port, url, ssh_port)
 * @param int    $flaps     incidents in the last 24h (to spot an unstable server)
 * @param array  $services  linked provider_services rows (to spot a suspended / overdue service)
 */
function diagnose(string $error, array $s, int $flaps = 0, array $services = []): array
{
    $host = (string) ($s['host'] ?? '');
    $sshPort = (int) ($s['ssh_port'] ?? 22) ?: 22;
    $e = strtolower($error);
    preg_match_all('/port (\d+) timed out/', $e, $to);
    preg_match_all('/port (\d+) refused/', $e, $rf);
    $timedOut = array_map('intval', $to[1]);
    $refused = array_map('intval', $rf[1]);
    $noPing = str_contains($e, 'no icmp reply');
    $mon = monitor_ip();
    $monTxt = $mon !== '' ? $mon : 'YOUR_MONITOR_IP';

    $pcCheck = [
        'ping ' . $host,
        'tracert ' . $host . '   (Windows)  ·  mtr -rw ' . $host . '   (Linux/Mac)',
        'Test-NetConnection ' . $host . ' -Port ' . $sshPort . '   (Windows PowerShell)  ·  nc -zv ' . $host . ' ' . $sshPort,
    ];
    $health = ['uptime', 'free -m', 'df -h', 'dmesg -T | tail -30', 'journalctl -p err -b --no-pager | tail -30'];

    $d = ['key' => 'unknown', 'title' => 'Check failed', 'summary' => $error !== '' ? $error : 'No response from the server.',
          'causes' => [], 'pc' => $pcCheck, 'server' => $health, 'fix' => []];

    // provider side first: suspended / unpaid services explain everything
    foreach ($services as $sv) {
        $st = strtolower((string) ($sv['status'] ?? ''));
        $overdue = !empty($sv['next_due']) && strtotime($sv['next_due']) < strtotime('today');
        if ($st === 'suspended' || ($overdue && ($sv['kind'] ?? '') !== 'license')) {
            $d['causes'][] = $st === 'suspended'
                ? 'The provider shows this service as SUSPENDED — usually an unpaid invoice or abuse report.'
                : 'The renewal date (' . date('d M Y', strtotime($sv['next_due'])) . ') has passed — the provider may have suspended it for non-payment.';
            $d['fix'][] = 'Open the provider client area → pay the invoice / read the suspension reason, then click Check now.';
            break;
        }
    }

    if (str_contains($e, 'dns lookup failed') || str_contains($e, 'could not resolve')) {
        $d['key'] = 'dns';
        $d['title'] = 'Domain name does not resolve (DNS)';
        $d['summary'] = 'The hostname ' . $host . ' has no IP address right now, so nothing could be checked.';
        $d['causes'] = array_merge($d['causes'], ['Domain expired or its nameservers changed', 'DNS A record deleted or pointing nowhere', 'DNS provider (e.g. Cloudflare) problem']);
        $d['pc'] = ['nslookup ' . $host, 'nslookup ' . $host . ' 8.8.8.8', 'whois ' . preg_replace('/^.*?([^.]+\.[^.]+)$/', '$1', $host) . '   (expiry date)'];
        $d['server'] = [];
        $d['fix'] = array_merge($d['fix'], ['Fix the A record / renew the domain, or put the server IP in "Host" instead of the name.']);
    } elseif (preg_match('/http (5\d\d)/', $e, $m)) {
        $d['key'] = 'http5xx';
        $d['title'] = 'Website returns error ' . $m[1];
        $d['summary'] = 'The server is online, but the web application answered with HTTP ' . $m[1] . ' (server-side error).';
        $d['causes'] = array_merge($d['causes'], ['PHP / application crash or PHP-FPM down', 'Database not reachable', 'Web server overloaded (502/503/504 = backend too slow or stopped)']);
        $d['pc'] = ['curl -sI ' . ($s['url'] ?: 'https://' . $host)];
        $d['server'] = ['systemctl status nginx httpd lsws php-fpm --no-pager', 'tail -50 /var/log/nginx/error.log   or   tail -50 /usr/local/apache/logs/error_log', 'systemctl status mysqld mariadb --no-pager', 'uptime && free -m'];
        $d['fix'] = array_merge($d['fix'], ['Restart the failed service (e.g. systemctl restart php-fpm) and read the error log for the real cause.']);
    } elseif (preg_match('/http (4\d\d)/', $e, $m)) {
        $d['key'] = 'http4xx';
        $d['title'] = 'Website returns ' . $m[1];
        $d['summary'] = 'The server answered, but the checked URL gives HTTP ' . $m[1] . ($m[1] === '403' ? ' (forbidden — often a firewall/WAF blocking the monitor)' : ($m[1] === '404' ? ' (page not found)' : '')) . '.';
        $d['causes'] = array_merge($d['causes'], ['Wrong URL in the check settings', 'Cloudflare / ModSecurity / firewall blocking the monitor IP ' . ($mon ?: ''), 'Site needs a login']);
        $d['pc'] = ['curl -sI ' . ($s['url'] ?: 'https://' . $host)];
        $d['server'] = [];
        $d['fix'] = array_merge($d['fix'], ['Use a public URL that returns 200 (e.g. the home page), or whitelist ' . $monTxt . ' in the WAF.']);
    } elseif (str_contains($e, 'ssl') || str_contains($e, 'certificate')) {
        $d['key'] = 'ssl';
        $d['title'] = 'SSL certificate problem';
        $d['summary'] = 'HTTPS failed because the certificate is expired, self-signed or for another name.';
        $d['causes'] = array_merge($d['causes'], ['Certificate expired (AutoSSL / Let\'s Encrypt did not renew)', 'Certificate is for a different hostname']);
        $d['pc'] = ['curl -vI https://' . $host . ' 2>&1 | grep -iE "expire|subject|issuer|error"'];
        $d['server'] = ['/usr/local/cpanel/bin/autossl_check --all   (cPanel)', 'certbot renew --dry-run   (Let\'s Encrypt)'];
        $d['fix'] = array_merge($d['fix'], ['Renew / reissue the certificate, or check over plain TCP instead of HTTPS.']);
    } elseif ($refused && !$timedOut) {
        $d['key'] = 'refused';
        $d['title'] = 'Server is online, but its services are not answering';
        $d['summary'] = 'The machine replied "connection refused" on port ' . implode(', ', $refused) . ' — the network works, the service on that port is stopped (or SSH moved to another port).';
        $d['causes'] = array_merge($d['causes'], [
            in_array($sshPort, $refused, true) ? 'SSH (sshd) stopped, or SSH now runs on a different port than ' . $sshPort : 'Service on that port stopped',
            'Web server stopped (ports 80/443)', 'Firewall REJECT rule for the monitor IP',
        ]);
        $d['server'] = ['systemctl status sshd --no-pager', 'ss -tlnp | grep -E ":(22|80|443) "', 'systemctl status nginx httpd lsws --no-pager', 'grep -i "^port" /etc/ssh/sshd_config'];
        $d['fix'] = array_merge($d['fix'], ['Start the service (systemctl restart sshd / httpd), or set the real SSH port in Edit → SSH port.']);
    } elseif ($timedOut || $noPing || str_contains($e, 'unreachable') || str_contains($e, 'no response')) {
        $d['key'] = 'unreachable';
        $all = $timedOut && $noPing;
        $d['title'] = $all ? 'Server not reachable at all' : 'Server not reachable on ' . ($timedOut ? 'port ' . implode(', ', $timedOut) : 'ping');
        $d['summary'] = $all
            ? 'No answer on any port and no ping reply. The server is OFF/crashed, its network is down, or a firewall is silently dropping the monitor.'
            : 'Packets got no reply (timeout) — a firewall is dropping them or the server is too busy to answer.';
        $d['causes'] = array_merge($d['causes'], [
            'VPS powered off, crashed or stuck while booting (check the provider panel / VNC console)',
            'Provider network or node outage',
            'Firewall (CSF / fail2ban / iptables / provider firewall) blocking the monitor IP ' . ($mon ?: '') . ' — common because the monitor connects every minute',
            'Server overloaded (100% CPU, out of RAM, disk full) so it cannot answer',
        ]);
        $d['server'] = array_merge(['csf -g ' . $monTxt . '   (is the monitor IP blocked?)', 'fail2ban-client status sshd'], $health);
        $d['fix'] = array_merge($d['fix'], [
            'If ping works from YOUR computer but the dashboard says down → whitelist the monitor: csf -a ' . $monTxt . '  (or fail2ban: fail2ban-client set sshd addignoreip ' . $monTxt . ')',
            'If it does not work from your computer either → open the provider panel: start/reboot the VPS, use the VNC console, or open a ticket.',
        ]);
    } elseif (str_contains($e, 'icmp unavailable')) {
        $d['key'] = 'config';
        $d['title'] = 'Ping is not possible from this hosting';
        $d['summary'] = 'This cPanel account cannot run ping. The server itself may be fine.';
        $d['pc'] = [];
        $d['server'] = [];
        $d['fix'] = ['Edit the server → Check method: Smart or TCP port.'];
    }

    if ($flaps >= 3) {
        $d['flapping'] = true;
        array_unshift($d['causes'], $flaps . ' short outages in the last 24 hours — the server is UNSTABLE rather than off: packet loss on the provider network, overload, or a firewall rate-limiting the monitor.');
        $d['fix'][] = 'For flapping: whitelist ' . $monTxt . ' in CSF/fail2ban, and run "mtr -rw ' . $host . '" from your PC to see packet loss.';
    }
    $d['causes'] = array_values(array_unique($d['causes']));
    return $d;
}

/** One short line for lists and Telegram. */
function diagnose_short(string $error, array $s = [], int $flaps = 0): string
{
    $d = diagnose($error, $s, $flaps);
    return $d['key'] === 'unknown' ? '' : $d['title'];
}

function diagnose_card(array $d, string $heading, string $error): string
{
    $h = '<section class="card glass diag-card anim-in"><div class="card-head"><h3>' . icon('alert') . e($heading) . '</h3>'
        . (!empty($d['flapping']) ? '<span class="pill pill-warn xs">Unstable</span>' : '') . '</div>'
        . '<p class="diag-title"><b>' . e($d['title']) . '</b></p><p class="muted">' . e($d['summary']) . '</p>'
        . ($error !== '' ? '<p class="small">Check result: <code>' . e($error) . '</code></p>' : '');
    if ($d['causes']) {
        $h .= '<h4 class="diag-h">Possible reasons</h4><ul class="diag-list">';
        foreach ($d['causes'] as $c) {
            $h .= '<li>' . e($c) . '</li>';
        }
        $h .= '</ul>';
    }
    $cmds = function (array $list, string $title) {
        if (!$list) {
            return '';
        }
        $o = '<details class="diag-more"><summary class="diag-h">' . e($title) . ' <span class="muted">(' . count($list) . ')</span></summary><div class="diag-cmds">';
        foreach ($list as $c) {
            $copy = trim(preg_split('/\s{3,}/', $c)[0]);
            $o .= '<div class="secret-box"><code>' . e($c) . '</code><button class="icon-btn" type="button" data-copy="' . e($copy) . '" title="Copy">' . icon('copy') . '</button></div>';
        }
        return $o . '</div></details>';
    };
    if ($d['fix']) {
        $h .= '<h4 class="diag-h">What to do</h4><ul class="diag-list fix">';
        foreach ($d['fix'] as $f) {
            $h .= '<li>' . e($f) . '</li>';
        }
        $h .= '</ul>';
    }
    $h .= $cmds($d['pc'], 'Verify from your computer');
    $h .= $cmds($d['server'], 'If you can log in (SSH or the provider\'s VNC console) — read-only checks');
    if (monitor_ip() !== '') {
        $h .= '<p class="muted small">This dashboard checks from IP <code>' . e(monitor_ip()) . '</code>.</p>';
    }
    return $h . '</section>';
}
