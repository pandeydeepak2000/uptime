<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Server auto-discovery
 |
 |  READ-ONLY GUARANTEE
 |  - Only the fixed script in DISCOVER_SCRIPT is executed. No user input
 |    is ever placed into a remote command.
 |  - Every command in it only READS (cat, grep, nproc, uname, lsblk, df, ls).
 |    Nothing is written, installed, restarted or changed.
 |  - The root password lives only in memory for this one SSH session and is
 |    never logged.
 |  - The server's SSH host key is checked BEFORE the password is sent; if it
 |    differs from the saved fingerprint the password is not sent at all.
 * -----------------------------------------------------------------*/

const DISCOVER_SCRIPT = <<<'SH'
export LC_ALL=C
echo "@@OS";      cat /etc/os-release 2>/dev/null
echo "@@OSALT";   cat /etc/redhat-release 2>/dev/null || cat /etc/debian_version 2>/dev/null
echo "@@HOST";    hostname 2>/dev/null
echo "@@KERNEL";  uname -r 2>/dev/null
echo "@@ARCH";    uname -m 2>/dev/null
echo "@@CPU";     nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo
echo "@@CPUMODEL"; grep -m1 "model name" /proc/cpuinfo 2>/dev/null
echo "@@MEM";     grep MemTotal /proc/meminfo 2>/dev/null
echo "@@DISKS";   lsblk -b -d -n -o SIZE,TYPE 2>/dev/null
echo "@@ROOTFS";  df -B1 -P / 2>/dev/null | tail -1
echo "@@UPTIME";  cat /proc/uptime 2>/dev/null
echo "@@VIRT";    systemd-detect-virt 2>/dev/null
echo "@@PANEL";   ls -d /usr/local/cpanel /usr/local/psa /usr/local/directadmin /www/server/panel /usr/local/CyberCP /usr/local/hestia 2>/dev/null
echo "@@END"
SH;

function discover_commands_list(): array
{
    return ['cat /etc/os-release', 'hostname', 'uname -r / -m', 'nproc', 'grep /proc/cpuinfo', 'grep /proc/meminfo',
        'lsblk -d (disk sizes)', 'df / (disk usage)', 'cat /proc/uptime', 'systemd-detect-virt', 'ls -d (control-panel folders)'];
}

function ssh_fingerprint(string $hostKey): string
{
    $parts = explode(' ', trim($hostKey));
    $blob = base64_decode($parts[1] ?? $parts[0], true);
    if ($blob === false || $blob === '') {
        return '';
    }
    return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

function valid_fingerprint(string $fp): bool
{
    return (bool) preg_match('~^SHA256:[A-Za-z0-9+/]{43}$~', $fp);
}

/**
 * Log in over SSH and read hardware/OS facts. Never modifies anything.
 */
function discover_ssh(string $host, int $port, string $user, string $pass, ?string $knownFp = null): array
{
    if (!is_file(APP_ROOT . '/app/vendor/autoload.php')) {
        return ['ok' => false, 'error' => 'SSH library missing (app/vendor). Pull the latest code.'];
    }
    require_once APP_ROOT . '/app/vendor/autoload.php';

    try {
        $ssh = new \phpseclib3\Net\SSH2($host, $port, 8);
        $hostKey = @$ssh->getServerPublicHostKey();
        if (!$hostKey) {
            return ['ok' => false, 'error' => "Could not reach SSH on $host:$port (port closed, firewalled, or outbound SSH blocked by this hosting)."];
        }
        $fp = ssh_fingerprint((string) $hostKey);
        $keyType = explode(' ', (string) $hostKey)[0];
        if ($knownFp && $fp !== $knownFp) {
            $ssh->disconnect();
            return [
                'ok' => false, 'fp_mismatch' => true, 'fingerprint' => $fp, 'known' => $knownFp,
                'error' => 'SSH host key changed since last time. The password was NOT sent. If the server was reinstalled this is expected — otherwise someone may be intercepting the connection.',
            ];
        }
        if (!$ssh->login($user, $pass)) {
            return ['ok' => false, 'fingerprint' => $fp, 'error' => 'Login failed — wrong root password, or password login is disabled on this server (key-only).'];
        }
        $ssh->setTimeout(20);
        $out = (string) $ssh->exec(DISCOVER_SCRIPT);
        $ssh->disconnect();
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'Cannot connect') !== false) {
            return ['ok' => false, 'error' => "Could not reach SSH on $host:$port (server down, port closed/firewalled, or this hosting blocks outbound SSH)."];
        }
        return ['ok' => false, 'error' => 'SSH error: ' . substr($msg !== '' ? $msg : get_class($e), 0, 160)];
    }

    if (strpos($out, '@@END') === false) {
        return ['ok' => false, 'fingerprint' => $fp, 'error' => 'Logged in, but the server did not return the expected output (restricted shell?).'];
    }
    return ['ok' => true, 'fingerprint' => $fp, 'key_type' => $keyType] + discover_parse($out);
}

function discover_parse(string $out): array
{
    $sec = [];
    $cur = null;
    foreach (preg_split('/\r?\n/', $out) as $line) {
        if (preg_match('/^@@([A-Z]+)$/', trim($line), $m)) {
            $cur = $m[1];
            $sec[$cur] = [];
            continue;
        }
        if ($cur !== null && trim($line) !== '') {
            $sec[$cur][] = trim($line);
        }
    }
    $first = fn ($k) => $sec[$k][0] ?? '';

    // OS
    $osr = [];
    foreach ($sec['OS'] ?? [] as $l) {
        if (preg_match('/^([A-Z_]+)="?(.*?)"?$/', $l, $m)) {
            $osr[$m[1]] = $m[2];
        }
    }
    $os = $osr['PRETTY_NAME'] ?? trim(($osr['NAME'] ?? '') . ' ' . ($osr['VERSION_ID'] ?? ''));
    if ($os === '') {
        $alt = $first('OSALT');
        $os = preg_match('/^[\d.]+$/', $alt) ? 'Debian ' . $alt : $alt;
    }

    // CPU / RAM / disk
    $cpu = (int) $first('CPU');
    $cpuModel = trim(preg_replace(['/^model name\s*:\s*/', '/\s+/', '/\((R|TM)\)/i', '/ CPU /'], ['', ' ', '', ' '], $first('CPUMODEL')));
    $memKb = preg_match('/(\d+)/', $first('MEM'), $m) ? (int) $m[1] : 0;
    $ramGb = $memKb ? $memKb / 1048576 : 0;
    $diskBytes = 0;
    foreach ($sec['DISKS'] ?? [] as $l) {
        if (preg_match('/^(\d+)\s+disk$/', $l, $m)) {
            $diskBytes += (int) $m[1];
        }
    }
    $rootUsed = null;
    if (preg_match('/^\S+\s+(\d+)\s+(\d+)\s+(\d+)/', $first('ROOTFS'), $m)) {
        if (!$diskBytes) {
            $diskBytes = (int) $m[1];
        }
        $rootUsed = (int) $m[1] > 0 ? round(100 * (int) $m[2] / (int) $m[1]) : null;
    }
    $diskGb = $diskBytes / 1073741824;

    $fmtGb = function (float $gb, bool $ram) {
        if ($gb <= 0) {
            return '';
        }
        if ($gb < 1) {
            return (int) round($gb * 1024) . ' MB';
        }
        $v = $ram ? ceil($gb - 0.05) : round($gb);
        return $v >= 1024 ? rtrim(rtrim(number_format($v / 1024, 1), '0'), '.') . ' TB' : (int) $v . ' GB';
    };
    $specs = implode(' · ', array_filter([
        $cpu ? $cpu . ' vCPU' : '',
        $ramGb ? $fmtGb($ramGb, true) . ' RAM' : '',
        $diskGb ? $fmtGb($diskGb, false) . ' disk' : '',
    ]));

    $virt = strtolower($first('VIRT'));
    $virtMap = ['kvm' => 'KVM', 'openvz' => 'OpenVZ', 'lxc' => 'LXC', 'vmware' => 'VMware', 'microsoft' => 'Hyper-V', 'xen' => 'Xen', 'qemu' => 'QEMU', 'none' => 'Dedicated / bare metal'];
    $panelMap = ['/usr/local/cpanel' => 'cPanel/WHM', '/usr/local/psa' => 'Plesk', '/usr/local/directadmin' => 'DirectAdmin',
        '/www/server/panel' => 'aaPanel', '/usr/local/CyberCP' => 'CyberPanel', '/usr/local/hestia' => 'HestiaCP'];
    $panels = [];
    foreach ($sec['PANEL'] ?? [] as $p) {
        if (isset($panelMap[$p])) {
            $panels[] = $panelMap[$p];
        }
    }
    $uptime = (int) (float) explode(' ', $first('UPTIME'))[0];

    return [
        'os'        => substr($os, 0, 100),
        'specs'     => substr($specs, 0, 190),
        'hostname'  => substr($first('HOST'), 0, 120),
        'kernel'    => substr($first('KERNEL'), 0, 80),
        'arch'      => substr($first('ARCH'), 0, 20),
        'cpu_model' => substr($cpuModel, 0, 120),
        'virt'      => $virt !== '' ? ($virtMap[$virt] ?? strtoupper($virt)) : '',
        'panel'     => implode(', ', $panels),
        'uptime'    => $uptime ? fmt_duration($uptime, 2) : '',
        'disk_used' => $rootUsed,
    ];
}

/**
 * IP → location via ipinfo.io (HTTPS). Only the IP address is sent.
 */
function geo_lookup(string $host): array
{
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : @gethostbyname($host);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return ['ok' => false, 'error' => 'Could not resolve hostname'];
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return ['ok' => false, 'error' => 'Private/local IP — no public location'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL not available'];
    }
    $ch = curl_init('https://ipinfo.io/' . rawurlencode($ip) . '/json');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'UptimeCommand/' . APP_VERSION,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = json_decode((string) $res, true);
    if ($code !== 200 || !is_array($j) || !empty($j['bogon'])) {
        return ['ok' => false, 'error' => $code === 429 ? 'Location service rate-limited — try later' : 'Location lookup failed'];
    }
    $cc = (string) ($j['country'] ?? '');
    $country = $cc;
    if ($cc !== '' && class_exists('Locale')) {
        $name = \Locale::getDisplayRegion('-' . $cc, 'en');
        if ($name && $name !== $cc) {
            $country = $name;
        }
    }
    $loc = implode(', ', array_filter([(string) ($j['city'] ?? ''), $country]));
    return [
        'ok'       => $loc !== '',
        'ip'       => $ip,
        'location' => substr($loc, 0, 100),
        'region'   => (string) ($j['region'] ?? ''),
        'network'  => substr((string) ($j['org'] ?? ''), 0, 120),
        'timezone' => (string) ($j['timezone'] ?? ''),
        'error'    => $loc === '' ? 'No location data for this IP' : null,
    ];
}
