<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | OVHcloud API (official, READ-ONLY)
 |  Uses an application key + secret + consumer key that you create with
 |  GET-only rights. Only GET requests are ever sent.
 |  Docs: https://help.ovhcloud.com/csm/en-api-getting-started-ovhcloud-api
 * -----------------------------------------------------------------*/

const OVH_ENDPOINTS = [
    'ovh-eu' => ['label' => 'OVHcloud Europe (ovh.com / ovhcloud.com)', 'api' => 'https://eu.api.ovh.com/1.0', 'token' => 'https://eu.api.ovh.com/createToken/'],
    'ovh-ca' => ['label' => 'OVHcloud Canada / Asia-Pacific (incl. India)', 'api' => 'https://ca.api.ovh.com/1.0', 'token' => 'https://ca.api.ovh.com/createToken/'],
    'ovh-us' => ['label' => 'OVHcloud US', 'api' => 'https://api.us.ovhcloud.com/1.0', 'token' => 'https://api.us.ovhcloud.com/createToken/'],
];

/** Link that creates a consumer key with GET-only rights on servers. */
function ovh_token_url(string $endpoint): string
{
    $base = OVH_ENDPOINTS[$endpoint]['token'] ?? OVH_ENDPOINTS['ovh-eu']['token'];
    return $base . '?GET=/me&GET=/vps&GET=/vps/*&GET=/dedicated/server&GET=/dedicated/server/*';
}

class OvhApi
{
    private string $api;
    private string $ak;
    private string $as;
    private string $ck;
    private ?int $delta = null;
    public array $trace = [];

    public function __construct(array $cfg)
    {
        $ep = (string) ($cfg['endpoint'] ?? 'ovh-eu');
        $this->api = (string) ($cfg['api_url'] ?? (OVH_ENDPOINTS[$ep]['api'] ?? OVH_ENDPOINTS['ovh-eu']['api']));
        $this->ak = trim((string) ($cfg['app_key'] ?? ''));
        $this->as = trim((string) ($cfg['app_secret'] ?? ''));
        $this->ck = trim((string) ($cfg['consumer_key'] ?? ''));
        if ($this->ak === '' || $this->as === '' || $this->ck === '') {
            throw new RuntimeException('OVH application key, application secret and consumer key are required.');
        }
    }

    private function http(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET        => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code === 0) {
            throw new RuntimeException('Could not reach the OVH API: ' . ($err ?: 'no response'));
        }
        return [$code, json_decode($body, true), $body];
    }

    /** Signed GET. Returns decoded JSON. */
    public function get(string $path)
    {
        if ($this->delta === null) {
            [$c, $t] = $this->http($this->api . '/auth/time', []);
            $this->delta = ($c === 200 && is_numeric($t)) ? ((int) $t - time()) : 0;
        }
        $url = $this->api . $path;
        $ts = (string) (time() + $this->delta);
        $sig = '$1$' . sha1($this->as . '+' . $this->ck . '+GET+' . $url . '++' . $ts);
        [$code, $json, $raw] = $this->http($url, [
            'X-Ovh-Application: ' . $this->ak,
            'X-Ovh-Consumer: ' . $this->ck,
            'X-Ovh-Timestamp: ' . $ts,
            'X-Ovh-Signature: ' . $sig,
        ]);
        $this->trace[] = 'GET ' . $path . ' → HTTP ' . $code;
        if ($code >= 400) {
            $msg = is_array($json) && isset($json['message']) ? $json['message'] : substr($raw, 0, 120);
            throw new OvhApiException($msg, $code);
        }
        return $json;
    }
}

class OvhApiException extends RuntimeException
{
}

/** "Region OpenStack: os-bom1" / "gra3" → "Mumbai (BOM1)" / "Gravelines (GRA3)" */
function ovh_location(string $zone): string
{
    $code = strtolower(trim(preg_replace('~^.*?:\s*~', '', $zone)));
    $code = preg_replace('~^os-~', '', $code);
    if ($code === '') {
        return '';
    }
    $map = ['bom' => 'Mumbai', 'sgp' => 'Singapore', 'syd' => 'Sydney', 'gra' => 'Gravelines', 'sbg' => 'Strasbourg', 'rbx' => 'Roubaix',
        'bhs' => 'Beauharnois', 'waw' => 'Warsaw', 'de' => 'Frankfurt', 'fra' => 'Frankfurt', 'lim' => 'Limburg', 'uk' => 'London', 'eri' => 'London',
        'lon' => 'London', 'vin' => 'Vint Hill', 'hil' => 'Hillsboro', 'tor' => 'Toronto', 'par' => 'Paris', 'mil' => 'Milan', 'mad' => 'Madrid', 'yyz' => 'Toronto'];
    $prefix = preg_match('~^([a-z]+)~', $code, $m) ? $m[1] : $code;
    return isset($map[$prefix]) ? $map[$prefix] . ' (' . strtoupper($code) . ')' : strtoupper($code);
}

/**
 * Read all VPS + dedicated servers. Returns rows in the provider_save_services() shape.
 */
function ovh_services(array $cfg, array &$trace): array
{
    $api = new OvhApi($cfg);
    $out = [];
    $notGranted = fn (OvhApiException $e) => $e->getCode() === 403 && stripos($e->getMessage(), 'not been granted') !== false;
    try {
        $me = $api->get('/me');
        $trace[] = 'Authenticated as ' . ($me['nichandle'] ?? 'OVH account') . ' ✓';
    } catch (OvhApiException $e) {
        // any auth/signature/time error is fatal — never continue (an empty result would wipe saved services)
        if (!$notGranted($e)) {
            $trace = array_merge($trace, $api->trace);
            throw new RuntimeException('OVH rejected the keys (' . $e->getMessage() . '). Check application key/secret and consumer key, and that the consumer key is validated.');
        }
        // /me not granted is fine — continue
    }

    $cycle = fn ($months) => [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually', 12 => 'Annually', 24 => 'Biennially'][(int) $months] ?? ((int) $months ? 'Every ' . (int) $months . ' months' : '');
    $statusOf = function (?array $info, string $state) {
        $s = strtolower((string) ($info['status'] ?? ''));
        if (in_array($s, ['expired', 'unpaid', 'pendingdebt'], true)) {
            return 'Suspended';
        }
        if (in_array(strtolower($state), ['stopped', 'error', 'maintenance'], true)) {
            return 'Suspended';
        }
        return 'Active';
    };

    // ---- VPS ----
    try {
        $vpsList = (array) $api->get('/vps');
    } catch (OvhApiException $e) {
        if (!$notGranted($e)) {
            throw new RuntimeException('OVH API error while listing VPS: ' . $e->getMessage());
        }
        $vpsList = [];
        $trace[] = 'VPS list not granted to this key — skipped';
    }
    foreach ($vpsList as $name) {
        $name = (string) $name;
        $enc = '/vps/' . rawurlencode($name);
        $info = $ips = $svc = [];
        try {
            $info = (array) $api->get($enc);
        } catch (OvhApiException $e) {
        }
        try {
            $ips = (array) $api->get($enc . '/ips');
        } catch (OvhApiException $e) {
        }
        try {
            $svc = (array) $api->get($enc . '/serviceInfos');
        } catch (OvhApiException $e) {
        }
        $ip = '';
        foreach ($ips as $cand) {
            if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ip = $cand;
                break;
            }
        }
        $m = (array) ($info['model'] ?? []);
        $specs = implode(' · ', array_filter([
            !empty($m['vcore']) ? (int) $m['vcore'] . ' vCPU' : '',
            !empty($m['memory']) ? round((int) $m['memory'] / 1024) . ' GB RAM' : '',
            !empty($m['disk']) ? (int) $m['disk'] . ' GB disk' : '',
        ]));
        $out[] = [
            'id'           => 'ovh-' . substr(md5('vps:' . $name), 0, 16),
            'name'         => 'OVH VPS ' . ($m['name'] ?? '') . (!empty($info['displayName']) && $info['displayName'] !== $name ? ' – ' . $info['displayName'] : ''),
            'hostname'     => strpos($name, '.') !== false ? strtolower($name) : '',
            'ip'           => $ip,
            'status'       => $statusOf($svc, (string) ($info['state'] ?? '')),
            'amount'       => null,
            'currency'     => null,
            'cycle'        => $cycle($svc['renew']['period'] ?? 1),
            'next_due_raw' => (string) ($svc['expiration'] ?? ''),
            'details'      => array_filter(['specs' => $specs, 'location' => ovh_location((string) ($info['zone'] ?? '')), 'model' => $m['name'] ?? '']),
            'login_user'   => '',
            'login_pass'   => '',
        ];
        usleep(150000);
    }

    // ---- Dedicated servers ----
    try {
        $dedi = (array) $api->get('/dedicated/server');
    } catch (OvhApiException $e) {
        if (!$notGranted($e)) {
            throw new RuntimeException('OVH API error while listing dedicated servers: ' . $e->getMessage());
        }
        $dedi = [];
        $trace[] = 'Dedicated server list not granted to this key — skipped';
    }
    foreach ($dedi as $name) {
        $name = (string) $name;
        $enc = '/dedicated/server/' . rawurlencode($name);
        $info = $svc = [];
        try {
            $info = (array) $api->get($enc);
        } catch (OvhApiException $e) {
        }
        try {
            $svc = (array) $api->get($enc . '/serviceInfos');
        } catch (OvhApiException $e) {
        }
        $out[] = [
            'id'           => 'ovh-' . substr(md5('dedi:' . $name), 0, 16),
            'name'         => 'OVH Dedicated ' . ($info['commercialRange'] ?? '') . (!empty($info['reverse']) ? ' – ' . rtrim($info['reverse'], '.') : ''),
            'hostname'     => !empty($info['reverse']) ? strtolower(rtrim($info['reverse'], '.')) : (strpos($name, '.') !== false ? strtolower($name) : ''),
            'ip'           => filter_var($info['ip'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $info['ip'] : '',
            'status'       => $statusOf($svc, (string) ($info['state'] ?? '')),
            'amount'       => null,
            'currency'     => null,
            'cycle'        => $cycle($svc['renew']['period'] ?? 1),
            'next_due_raw' => (string) ($svc['expiration'] ?? ''),
            'details'      => array_filter(['os' => $info['os'] ?? '', 'location' => ovh_location((string) ($info['datacenter'] ?? '')), 'specs' => $info['commercialRange'] ?? '']),
            'login_user'   => '',
            'login_pass'   => '',
        ];
        usleep(150000);
    }
    $trace = array_merge($trace, $api->trace);
    if (!$out && !$vpsList && !$dedi) {
        $trace[] = 'No VPS or dedicated servers found on this OVH account.';
    }
    return $out;
}
