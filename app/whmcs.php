<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | WHMCS client-area sync (READ-ONLY)
 |
 |  What it does:  log in with the saved (encrypted) client login, open
 |                 "My Services" and each service's details page, read
 |                 name / IP / status / price / next due date, log out.
 |  What it never does: submit any form other than the login form, click
 |                 any action, order, cancel, pay or change anything.
 |  It stops (and tells you) if the provider shows a CAPTCHA or asks for
 |  two-factor authentication — those are never bypassed.
 |  The password is only held in memory and never written to any log.
 * -----------------------------------------------------------------*/

class WhmcsSyncException extends RuntimeException
{
}

class WhmcsClient
{
    private $ch;
    private string $base;
    public array $trace = [];

    public function __construct(string $panelUrl)
    {
        $this->base = self::baseUrl($panelUrl);
        $this->ch = curl_init();
        curl_setopt_array($this->ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 6,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_COOKIEFILE     => '',          // in-memory cookie jar
            CURLOPT_ENCODING       => '',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: en-US,en;q=0.9'],
        ]);
    }

    public function __destruct()
    {
        if ($this->ch) {
            curl_close($this->ch);
        }
    }

    /** https://clients.x.com/clientarea.php → https://clients.x.com/ ; https://x.com/billing/login → https://x.com/billing/ */
    public static function baseUrl(string $url): string
    {
        $p = parse_url(trim($url));
        if (empty($p['host'])) {
            throw new WhmcsSyncException('Client area URL is not valid.');
        }
        $scheme = ($p['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $path = $p['path'] ?? '/';
        if (preg_match('~\.php$~i', $path)) {
            $path = dirname($path);
        } else {
            $path = preg_replace('~/(login|clientarea|index\.php)/?$~i', '', rtrim($path, '/'));
        }
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return $scheme . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $path;
    }

    private function step(string $msg): void
    {
        $this->trace[] = $msg;
    }

    private function req(string $url, ?array $post = null, ?string $referer = null): array
    {
        curl_setopt($this->ch, CURLOPT_URL, $url);
        if ($post !== null) {
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, http_build_query($post));
        } else {
            curl_setopt($this->ch, CURLOPT_HTTPGET, true);
        }
        if ($referer) {
            curl_setopt($this->ch, CURLOPT_REFERER, $referer);
        }
        $body = (string) curl_exec($this->ch);
        $err = curl_error($this->ch);
        $code = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
        $final = (string) curl_getinfo($this->ch, CURLINFO_EFFECTIVE_URL);
        $path = parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) ? '?' . preg_replace('/(token|key)=[^&]*/i', '$1=…', (string) parse_url($url, PHP_URL_QUERY)) : '');
        $this->step(($post !== null ? 'POST ' : 'GET ') . $path . ' → HTTP ' . $code . ($err ? ' (' . $err . ')' : '') . ' · ' . self::title($body));
        if ($code === 0) {
            throw new WhmcsSyncException('Could not connect to the client area: ' . ($err ?: 'no response'));
        }
        if (self::isBotWall($body, $code)) {
            throw new WhmcsSyncException('The provider shows a browser/bot check (e.g. Cloudflare). Automatic sync is not possible for this provider.');
        }
        return ['code' => $code, 'body' => $body, 'url' => $final];
    }

    public static function title(string $html): string
    {
        return preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? substr(trim(html_entity_decode(strip_tags($m[1]))), 0, 70) : '(no title)';
    }

    private static function isBotWall(string $html, int $code): bool
    {
        return ($code === 403 || $code === 503) && (stripos($html, 'cf-chl') !== false || stripos($html, 'Just a moment') !== false || stripos($html, 'challenge-platform') !== false);
    }

    private static function hasCaptcha(string $html): bool
    {
        foreach (['g-recaptcha', 'recaptcha/api.js', 'h-captcha', 'hcaptcha.com', 'cf-turnstile', 'challenges.cloudflare.com/turnstile', 'name="code" id="inputCaptcha"', 'inputCaptcha', 'captcha.php'] as $m) {
            if (stripos($html, $m) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function dom(string $html): DOMXPath
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        return new DOMXPath($doc);
    }

    private static function absolute(string $href, string $pageUrl): string
    {
        $href = html_entity_decode(trim($href));
        if ($href === '') {
            return $pageUrl;
        }
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $p = parse_url($pageUrl);
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($href[0] === '/') {
            return $origin . $href;
        }
        if ($href[0] === '?') {
            return $origin . ($p['path'] ?? '/') . $href;
        }
        $dir = rtrim(dirname(($p['path'] ?? '/') . 'x'), '/');
        return $origin . $dir . '/' . $href;
    }

    private static function loginForm(string $html): ?DOMElement
    {
        $x = self::dom($html);
        $forms = $x->query('//form[.//input[@type="password"]]');
        return $forms && $forms->length ? $forms->item(0) : null;
    }

    private static function isLoggedIn(string $html): bool
    {
        return (bool) preg_match('~(logout\.php|rp=/?logout|action=logout|/logout["\'])~i', $html) && !self::loginForm($html);
    }

    public function login(string $user, string $pass): void
    {
        $candidates = [$this->base . 'login.php', $this->base . 'index.php?rp=/login', $this->base . 'clientarea.php', $this->base . 'login'];
        $page = null;
        $form = null;
        foreach ($candidates as $u) {
            $r = $this->req($u);
            if ($r['code'] >= 400) {
                continue;
            }
            $f = self::loginForm($r['body']);
            if ($f) {
                $page = $r;
                $form = $f;
                break;
            }
        }
        if (!$form) {
            throw new WhmcsSyncException('Could not find a login form. Check the "Client area / panel URL" (e.g. https://clients.provider.com/clientarea.php).');
        }
        if (self::hasCaptcha($page['body'])) {
            throw new WhmcsSyncException('This provider uses a CAPTCHA on login, so automatic sync is not possible. Keep this provider manual.');
        }

        $fields = [];
        $userField = null;
        $passField = null;
        foreach ($form->getElementsByTagName('input') as $in) {
            $name = $in->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $type = strtolower($in->getAttribute('type') ?: 'text');
            if ($type === 'password') {
                $passField = $name;
            } elseif (in_array($type, ['text', 'email'], true) && $userField === null && preg_match('/user|email|login/i', $name)) {
                $userField = $name;
            } elseif (in_array($type, ['hidden'], true)) {
                $fields[$name] = $in->getAttribute('value');
            } elseif ($type === 'checkbox' && preg_match('/remember/i', $name)) {
                // leave unchecked
            }
        }
        if (!$userField || !$passField) {
            throw new WhmcsSyncException('Login form has unexpected fields — this client area is probably not WHMCS.');
        }
        $fields[$userField] = $user;
        $fields[$passField] = $pass;
        $action = self::absolute($form->getAttribute('action'), $page['url']);
        $this->step('Login form found (fields: ' . implode(', ', array_diff(array_keys($fields), [$passField])) . ', password)');

        $r = $this->req($action, $fields, $page['url']);
        unset($fields, $pass);
        $html = $r['body'];

        if (preg_match('~two.?factor|2fa|backup ?code|authenticator|name="key"~i', $html) && !self::isLoggedIn($html)) {
            throw new WhmcsSyncException('This account has two-factor authentication on the provider side, so automatic sync is not possible.');
        }
        if (!self::isLoggedIn($html)) {
            $r = $this->req($this->base . 'clientarea.php');
            if (!self::isLoggedIn($r['body'])) {
                if (self::hasCaptcha($html) || self::hasCaptcha($r['body'])) {
                    throw new WhmcsSyncException('The provider asked for a CAPTCHA after the login attempt. Automatic sync is not possible.');
                }
                throw new WhmcsSyncException('Login failed — check the panel username/email and password saved for this provider.');
            }
        }
        $this->step('Logged in ✓');
    }

    /** @return array list of ['id','name','hostname','ip','status','amount','currency','cycle','next_due_raw'] */
    public function services(): array
    {
        $r = $this->req($this->base . 'clientarea.php?action=services');
        $x = self::dom(preg_replace('~<br\s*/?>~i', ' ', $r['body']));
        $ids = [];
        $rowText = [];
        foreach ($x->query('//a[contains(@href,"productdetails")] | //tr[contains(@onclick,"productdetails")] | //*[contains(@data-url,"productdetails")]') as $el) {
            $ref = $el->getAttribute('href') . ' ' . $el->getAttribute('onclick') . ' ' . $el->getAttribute('data-url');
            if (preg_match('~productdetails(?:&|&amp;|/)(?:id=)?(\d+)~i', $ref, $m)) {
                $id = $m[1];
                $ids[$id] = true;
                $tr = $el;
                while ($tr && $tr->nodeName !== 'tr' && $tr->parentNode) {
                    $tr = $tr->parentNode;
                }
                if ($tr && $tr->nodeName === 'tr' && empty($rowText[$id])) {
                    $cells = [];
                    foreach ($tr->getElementsByTagName('td') as $td) {
                        $cells[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
                    }
                    $rowText[$id] = $cells;
                }
            }
        }
        $this->step('Services found: ' . count($ids));
        $out = [];
        foreach (array_keys($ids) as $id) {
            usleep(300000); // be polite to the provider
            $out[] = $this->serviceDetails((string) $id, $rowText[$id] ?? []);
        }
        return $out;
    }

    private static function textLines(string $html): array
    {
        $html = preg_replace('~<(script|style|noscript)\b.*?</\1>~is', ' ', $html);
        $html = preg_replace('~<(br|/?p|/?div|/?li|/?tr|/?td|/?th|/?h\d|/?dt|/?dd|/?label|/?span|/?strong|/?b)\b[^>]*>~i', "\n$0\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $l) {
            $l = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $l));
            if ($l !== '') {
                $lines[] = $l;
            }
        }
        return $lines;
    }

    private static function labelValue(array $lines, array $labels): string
    {
        foreach ($labels as $label) {
            foreach ($lines as $i => $l) {
                if (stripos($l, $label) === 0) {
                    $rest = trim(ltrim(substr($l, strlen($label)), " :\t-"));
                    if ($rest !== '') {
                        return $rest;
                    }
                    for ($j = $i + 1; $j < min(count($lines), $i + 4); $j++) {
                        if (trim($lines[$j], ' :') !== '') {
                            return trim($lines[$j], ' :');
                        }
                    }
                }
            }
        }
        return '';
    }

    private function serviceDetails(string $id, array $row): array
    {
        $r = $this->req($this->base . 'clientarea.php?action=productdetails&id=' . $id);
        $lines = self::textLines($r['body']);
        $x = self::dom($r['body']);

        $name = '';
        foreach (['//*[contains(@class,"product-name")]', '//*[contains(@class,"product-details")]//h3', '//*[contains(@class,"product-details")]//h4', '//h3[contains(@class,"product")]'] as $q) {
            $n = $x->query($q);
            if ($n && $n->length && trim($n->item(0)->textContent) !== '') {
                $name = trim(preg_replace('/\s+/', ' ', $n->item(0)->textContent));
                break;
            }
        }
        if ($name === '' && $row) {
            $name = $row[0] ?? '';
        }
        if ($name === '') {
            $name = self::labelValue($lines, ['Product/Service', 'Product', 'Service']);
        }

        $ip = self::labelValue($lines, ['Dedicated IP', 'Primary IP', 'IP Address', 'Main IP', 'Assigned IPs', 'IP']);
        $ip = preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $ip, $m) ? $m[1] : '';
        $hostname = self::labelValue($lines, ['Hostname', 'Domain', 'Server Name']);
        if (!$ip && preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $hostname . ' ' . implode(' ', $row), $m)) {
            $ip = $m[1];
        }
        $amountRaw = self::labelValue($lines, ['Recurring Amount', 'Recurring Price', 'First Payment Amount', 'Price']);
        if ($amountRaw === '' && isset($row[1])) {
            $amountRaw = $row[1];
        }
        $due = self::labelValue($lines, ['Next Due Date', 'Next Due', 'Due Date', 'Expiry Date', 'Renewal Date']);
        if ($due === '' && $row) {
            foreach ($row as $c) {
                if (preg_match('~\d{1,4}[/\-.]\d{1,2}[/\-.]\d{2,4}|\d{1,2}(st|nd|rd|th)? [A-Za-z]{3,9},? \d{4}~', $c, $m)) {
                    $due = $m[0];
                }
            }
        }
        $status = self::labelValue($lines, ['Status']);
        if ($status === '' || strlen($status) > 20) {
            $status = '';
            foreach (['Active', 'Suspended', 'Pending', 'Cancelled', 'Terminated', 'Fraud', 'Completed'] as $s) {
                if (preg_match('/\b' . $s . '\b/i', implode(' ', $row) . ' ' . implode(' ', array_slice($lines, 0, 80)))) {
                    $status = $s;
                    break;
                }
            }
        }
        $cycle = self::labelValue($lines, ['Billing Cycle', 'Payment Cycle']);
        if ($cycle === '' && preg_match('/\b(Monthly|Quarterly|Semi-Annually|Annually|Biennially|Triennially|One Time|Free Account)\b/i', $amountRaw . ' ' . implode(' ', $row), $m)) {
            $cycle = $m[1];
        }
        [$amount, $currency] = whmcs_parse_money($amountRaw);

        return [
            'id'           => $id,
            'name'         => substr($name !== '' ? $name : 'Service #' . $id, 0, 190),
            'hostname'     => substr(valid_host(strtolower($hostname)) ? strtolower($hostname) : '', 0, 190),
            'ip'           => $ip,
            'status'       => substr(ucfirst(strtolower(trim($status))), 0, 32),
            'amount'       => $amount,
            'currency'     => $currency,
            'cycle'        => substr(trim($cycle), 0, 32),
            'next_due_raw' => substr($due, 0, 40),
        ];
    }

    public function logout(): void
    {
        try {
            $this->req($this->base . 'logout.php');
        } catch (Throwable $e) {
            // ignore
        }
    }
}

function whmcs_parse_money(string $s): array
{
    $cur = null;
    if (preg_match('/\b(INR|USD|EUR|GBP|AED|CAD|AUD|SGD)\b/i', $s, $m)) {
        $cur = strtoupper($m[1]);
    } elseif (strpos($s, '₹') !== false || preg_match('/\bRs\.?/i', $s)) {
        $cur = 'INR';
    } elseif (strpos($s, '€') !== false) {
        $cur = 'EUR';
    } elseif (strpos($s, '£') !== false) {
        $cur = 'GBP';
    } elseif (strpos($s, '$') !== false) {
        $cur = 'USD';
    }
    $amt = preg_match('/(\d[\d,]*(?:\.\d+)?)/', $s, $m) ? round((float) str_replace(',', '', $m[1]), 2) : null;
    return [$amt, $cur];
}

/** Normalise WHMCS dates (any configured format) to Y-m-d. $order = 'dmy' | 'mdy' */
function whmcs_parse_date(string $raw, string $order = 'dmy'): ?string
{
    $raw = trim(preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', $raw));
    if ($raw === '' || preg_match('/^(n\/?a|-|—)$/i', $raw)) {
        return null;
    }
    if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})/', $raw, $m)) {
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/', $raw, $m)) {
        [$a, $b, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($a > 12) {
            $order = 'dmy';
        } elseif ($b > 12) {
            $order = 'mdy';
        }
        [$d, $mo] = $order === 'mdy' ? [$b, $a] : [$a, $b];
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
    $ts = strtotime($raw);
    return $ts ? date('Y-m-d', $ts) : null;
}

function service_kind(string $name): string
{
    if (preg_match('/licen[cs]e|cpanel|whm|plesk|litespeed|lsws|cloudlinux|softaculous|imunify|jetbackup|sitepad|directadmin|kernelcare|whmreseller|virtualizor|solusvm/i', $name)) {
        return 'license';
    }
    if (preg_match('/vps|kvm|vds|cloud|server|dedicated|ssd|nvme|openvz|ryzen|xeon|epyc/i', $name)) {
        return 'vps';
    }
    return 'other';
}

/**
 * Sync one provider. Returns ['ok'=>bool,'message'=>string,'trace'=>[]]
 */
function provider_sync(int $providerId): array
{
    $p = row('SELECT * FROM providers WHERE id = ?', [$providerId]);
    if (!$p) {
        return ['ok' => false, 'message' => 'Provider not found', 'trace' => []];
    }
    $finish = function (bool $ok, string $msg, array $trace) use ($providerId) {
        db_update('providers', ['last_sync_at' => time(), 'last_sync_ok' => $ok ? 1 : 0, 'last_sync_status' => substr($msg, 0, 250)], 'id = ?', [$providerId]);
        audit('provider_sync', $msg);
        return ['ok' => $ok, 'message' => $msg, 'trace' => $trace];
    };
    if (!function_exists('curl_init') || !class_exists('DOMDocument')) {
        return $finish(false, $p['name'] . ': PHP cURL/DOM extension missing on this hosting.', []);
    }
    $pass = decrypt_str($p['account_pass']);
    if (!$p['panel_url'] || !$p['account_user'] || $pass === '') {
        return $finish(false, $p['name'] . ': save the client area URL, panel username and password first.', []);
    }
    $client = null;
    try {
        $client = new WhmcsClient($p['panel_url']);
        $client->login($p['account_user'], $pass);
        unset($pass);
        $list = $client->services();
        $client->logout();
    } catch (WhmcsSyncException $e) {
        return $finish(false, $p['name'] . ': ' . $e->getMessage(), $client ? $client->trace : []);
    } catch (Throwable $e) {
        log_file('app.log', 'sync ' . $p['name'] . ': ' . $e->getMessage());
        return $finish(false, $p['name'] . ': unexpected error — ' . substr($e->getMessage(), 0, 120), $client ? $client->trace : []);
    }

    // detect day/month order from all dates
    $order = 'dmy';
    foreach ($list as $s) {
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.]\d{4}/', $s['next_due_raw'], $m)) {
            if ((int) $m[2] > 12) {
                $order = 'mdy';
                break;
            }
            if ((int) $m[1] > 12) {
                break;
            }
        }
    }

    $now = time();
    $servers = rows('SELECT id, host, provider_id, monthly_cost, expires_at FROM servers');
    $seen = [];
    $linked = 0;
    foreach ($list as $s) {
        $due = whmcs_parse_date($s['next_due_raw'], $order);
        $kind = service_kind($s['name'] . ' ' . $s['hostname']);
        $serverId = null;
        foreach ($servers as $srv) {
            if (($s['ip'] && $srv['host'] === $s['ip']) || ($s['hostname'] && $srv['host'] === $s['hostname'])) {
                $serverId = (int) $srv['id'];
                $linked++;
                if ($kind !== 'license' && !in_array(strtolower($s['status']), ['cancelled', 'terminated'], true)) {
                    $upd = [];
                    if ($due) {
                        $upd['expires_at'] = $due;
                    }
                    if (!$srv['provider_id']) {
                        $upd['provider_id'] = $providerId;
                    }
                    if ($srv['monthly_cost'] === null && $s['amount'] !== null) {
                        $div = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36][strtolower($s['cycle'])] ?? 1;
                        $upd['monthly_cost'] = round($s['amount'] / $div, 2);
                        $upd['currency'] = $s['currency'] ?: 'INR';
                    }
                    if ($upd) {
                        db_update('servers', $upd, 'id = ?', [$serverId]);
                    }
                }
                break;
            }
        }
        $data = [
            'provider_id' => $providerId, 'server_id' => $serverId, 'source' => 'whmcs', 'remote_id' => $s['id'],
            'name' => $s['name'], 'kind' => $kind, 'hostname' => $s['hostname'] ?: null, 'ip' => $s['ip'] ?: null,
            'status' => $s['status'] ?: null, 'amount' => $s['amount'], 'currency' => $s['currency'], 'cycle' => $s['cycle'] ?: null,
            'next_due' => $due, 'seen_at' => $now,
        ];
        $existing = row("SELECT id, kind FROM provider_services WHERE provider_id = ? AND source = 'whmcs' AND remote_id = ?", [$providerId, $s['id']]);
        if ($existing) {
            unset($data['kind']); // keep a kind the user may have corrected
            db_update('provider_services', $data, 'id = ?', [(int) $existing['id']]);
        } else {
            $data['created_at'] = $now;
            db_insert('provider_services', $data);
        }
        $seen[] = $s['id'];
    }
    // services that disappeared from the client area
    $gone = 0;
    foreach (rows("SELECT id, remote_id FROM provider_services WHERE provider_id = ? AND source = 'whmcs'", [$providerId]) as $old) {
        if (!in_array((string) $old['remote_id'], $seen, true)) {
            q('DELETE FROM provider_services WHERE id = ?', [(int) $old['id']]);
            $gone++;
        }
    }
    $msg = $p['name'] . ': synced ' . count($list) . ' service(s), ' . $linked . ' matched to your servers' . ($gone ? ", $gone removed" : '') . '.';
    return $finish(true, $msg, $client->trace);
}

/** Cron helper: sync at most one provider per run, each roughly every 12 hours. */
function provider_sync_due(): ?array
{
    $p = row('SELECT id FROM providers WHERE sync_enabled = 1 AND (last_sync_at IS NULL OR last_sync_at < ?) ORDER BY last_sync_at', [time() - 43200]);
    return $p ? provider_sync((int) $p['id']) : null;
}
