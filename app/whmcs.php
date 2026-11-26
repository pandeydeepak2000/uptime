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
    /** 2FA: base32 secret (auto-generates codes) or a one-time 6-digit code typed by the user */
    public string $totpSecret = '';
    public string $oneTimeCode = '';

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
            throw new WhmcsSyncException('The provider shows a browser/bot check (e.g. Cloudflare). Automatic sync is not possible for this provider.', 1);
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

    /** Text of the provider's error/alert box on a page, e.g. "Login Details Incorrect". */
    public static function alertText(string $html): string
    {
        $x = self::dom($html);
        // ignore hidden templates & modals (e.g. WHMCS password-generator dialog)
        $visible = 'not(ancestor-or-self::*[contains(@class,"modal") or contains(@class,"d-none") or contains(@class,"hidden") or contains(@class,"w-hidden")'
            . ' or contains(translate(@style," ",""),"display:none")])';
        $queries = [
            '//*[(contains(@class,"alert-danger") or contains(@class,"alert-error") or contains(@class,"errorbox")) and ' . $visible . ']',
            '//*[contains(@class,"alert-warning") and ' . $visible . ']', // only if it looks login-related (skip cookie banners etc.)
        ];
        foreach ($queries as $i => $qs) {
            foreach ($x->query($qs) ?: [] as $el) {
                $t = trim(preg_replace('/\s+/', ' ', $el->textContent));
                if ($t === '' || strlen($t) <= 3 || stripos($t, 'cookie') !== false) {
                    continue;
                }
                if ($i === 1 && !preg_match('~login|password|incorrect|banned|attempt|locked|verify~i', $t)) {
                    continue;
                }
                return substr($t, 0, 160);
            }
        }
        foreach (['Login Details Incorrect', 'has been banned', 'too many failed', 'account has been locked', 'account is locked'] as $needle) {
            if (preg_match('~[^<>.]{0,60}' . preg_quote($needle, '~') . '[^<>]{0,80}~i', strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', ' ', $html)), $m)) {
                return substr(trim(preg_replace('/\s+/', ' ', $m[0])), 0, 160);
            }
        }
        return '';
    }

    private static function hasCaptcha(string $html): bool
    {
        // note: WHMCS prints a global `var recaptcha = {...}` config on every page even when the
        // login form has no captcha — so we look for the actual widget / button markers only.
        foreach (['g-recaptcha', 'btn-recaptcha', 'recaptcha-container', '<script src="https://www.google.com/recaptcha/api.js', 'h-captcha', 'hcaptcha.com/1/api.js', 'cf-turnstile', 'challenges.cloudflare.com/turnstile', 'inputCaptcha', 'captcha.php'] as $m) {
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

    /** WHMCS two-factor challenge: a form with a code input and no password field. Returns [form, fieldName] or null. */
    private static function twoFactorForm(string $html): ?array
    {
        if (!preg_match('~two.?factor|2fa|authenticat|verification code|security code|backup ?code~i', $html)) {
            return null;
        }
        $x = self::dom($html);
        foreach ($x->query('//form[not(.//input[@type="password"])]') ?: [] as $form) {
            foreach ($form->getElementsByTagName('input') as $in) {
                $name = $in->getAttribute('name');
                $type = strtolower($in->getAttribute('type') ?: 'text');
                if (in_array($type, ['text', 'number', 'tel'], true) && preg_match('~^(key|code|twofa|otp|verifycode|verificationcode|answer|token_2fa|2fa)$~i', $name)) {
                    return [$form, $name];
                }
            }
        }
        return null;
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
            throw new WhmcsSyncException('This provider uses a CAPTCHA on login, so automatic sync is not possible. Use “Import from saved page” on the provider card instead.', 1);
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

        if (!self::isLoggedIn($html) && ($tf = self::twoFactorForm($html))) {
            [$tfForm, $codeField] = $tf;
            $code = $this->totpSecret !== '' ? totp_code($this->totpSecret, (int) floor(time() / 30)) : $this->oneTimeCode;
            if ($code === '') {
                throw new WhmcsSyncException('This provider asks for a two-factor (2FA) code. Enter the current 6-digit code when you click Sync now, or save the 2FA secret key in the provider settings for automatic daily sync.', 2);
            }
            $tfFields = [];
            foreach ($tfForm->getElementsByTagName('input') as $in) {
                $n = $in->getAttribute('name');
                if ($n !== '' && strtolower($in->getAttribute('type')) === 'hidden') {
                    $tfFields[$n] = $in->getAttribute('value');
                }
            }
            $tfFields[$codeField] = $code;
            $this->step('2FA step: sending ' . ($this->totpSecret !== '' ? 'generated' : 'entered') . ' code');
            $r = $this->req(self::absolute($tfForm->getAttribute('action'), $r['url']), $tfFields, $r['url']);
            $html = $r['body'];
            if (!self::isLoggedIn($html)) {
                $said = self::alertText($html);
                throw new WhmcsSyncException('2FA code was not accepted' . ($said ? ' — provider says: “' . $said . '”' : '') . '. Codes change every 30 seconds — try again with a fresh code' . ($this->totpSecret !== '' ? ' (check the saved 2FA secret and that the server clock is correct)' : '') . '.', 1);
            }
        }
        if (!self::isLoggedIn($html)) {
            $said = self::alertText($html);
            if ($said !== '') {
                $this->step('Provider says: “' . $said . '”');
            }
            $r = $this->req($this->base . 'clientarea.php');
            if (!self::isLoggedIn($r['body'])) {
                if (self::hasCaptcha($html) || self::hasCaptcha($r['body'])) {
                    throw new WhmcsSyncException('The provider asked for a CAPTCHA after the login attempt. Automatic sync is not possible.', 1);
                }
                if ($said === '') {
                    $said = self::alertText($r['body']);
                }
                $hint = preg_match('~banned|too many|locked|blocked~i', $said)
                    ? ' The provider has temporarily blocked logins from this server — wait before trying again.'
                    : ' First log in at the provider yourself (private window) with exactly the same email & password, then re-save the password here.';
                throw new WhmcsSyncException('Login failed' . ($said !== '' ? ' — provider says: “' . $said . '”.' : '.') . $hint, 1);
            }
        }
        $this->step('Logged in ✓');
    }

    /** @return array list of ['id','name','hostname','ip','status','amount','currency','cycle','next_due_raw'] */
    public function services(): array
    {
        $r = $this->req($this->base . 'clientarea.php?action=services');

        // 1) The services LIST has labelled columns (Product · Pricing · Next Due Date · Status) —
        //    the most reliable source for name, price, due date and status.
        $listed = self::parseServicesList($r['body']);
        if ($listed) {
            $live = array_values(array_filter($listed, fn ($s) => !service_is_ended($s['status'])));
            $this->step('Services found: ' . count($listed) . ' (' . (count($listed) - count($live)) . ' cancelled/terminated skipped)');
            $out = [];
            foreach ($live as $s) {
                usleep(300000); // be polite to the provider
                // 2) the details page is only used for IP / hostname (and anything the list lacked)
                $d = $this->serviceDetails((string) $s['id'], []);
                $generic = fn ($n) => $n === '' || preg_match('~^(service #\d+|details|overview|product details|manage|information)$~i', trim($n));
                $out[] = [
                    'id'           => $s['id'],
                    'name'         => !$generic($s['name']) ? $s['name'] : $d['name'],
                    'hostname'     => $s['hostname'] ?: $d['hostname'],
                    'ip'           => $d['ip'] ?: $s['ip'],
                    'status'       => $s['status'] ?: $d['status'],
                    'amount'       => $s['amount'] ?? $d['amount'],
                    'currency'     => $s['currency'] ?: $d['currency'],
                    'cycle'        => $s['cycle'] ?: $d['cycle'],
                    'next_due_raw' => $s['next_due_raw'] ?: $d['next_due_raw'],
                    'login_user'   => $d['login_user'],
                    'login_pass'   => $d['login_pass'],
                ];
            }
            return $out;
        }

        // Fallback for themes without a services table
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
        $this->step('Services found: ' . count($ids) . ' (no services table — reading each details page)');
        $out = [];
        foreach (array_keys($ids) as $id) {
            usleep(300000); // be polite to the provider
            $d = $this->serviceDetails((string) $id, $rowText[$id] ?? []);
            if (!service_is_ended($d['status'])) {
                $out[] = $d;
            }
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

    /** First IPv4 on/after any line labelled with "IP" (Dedicated IP, Main IP Address, Assigned IPs, IPv4…). */
    private static function findIp(array $lines): string
    {
        foreach ($lines as $i => $l) {
            if (!preg_match('/\bIP(s|v4)?\b/', $l) || preg_match('/your ip|logged in|last login|login from|ip whitelist|ipv6/i', $l)) {
                continue;
            }
            $chunk = $l . ' ' . ($lines[$i + 1] ?? '') . ' ' . ($lines[$i + 2] ?? '');
            if (preg_match_all('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', $chunk, $m)) {
                foreach ($m[1] as $cand) {
                    if (filter_var($cand, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $cand;
                    }
                }
            }
        }
        return '';
    }

    /** Text lines of one table cell (keeps <br>/<strong>/<a> boundaries). */
    private static function cellLines(DOMNode $td): array
    {
        $html = $td->ownerDocument->saveHTML($td);
        return self::textLines($html);
    }

    /**
     * Parse a WHMCS "My Products & Services" page (works on a page saved by the user — no login needed).
     * @return array same shape as services()
     */
    public static function parseServicesList(string $html): array
    {
        $x = self::dom($html);
        $out = [];
        foreach ($x->query('//a[contains(@href,"productdetails")] | //tr[contains(@onclick,"productdetails")] | //*[contains(@data-url,"productdetails")]') as $el) {
            $ref = $el->getAttribute('href') . ' ' . $el->getAttribute('onclick') . ' ' . $el->getAttribute('data-url');
            if (!preg_match('~productdetails(?:&|&amp;|/)(?:id=)?(\d+)~i', $ref, $m) || isset($out[$m[1]])) {
                continue;
            }
            $id = $m[1];
            $tr = $el;
            while ($tr && $tr->nodeName !== 'tr' && $tr->parentNode) {
                $tr = $tr->parentNode;
            }
            if (!$tr || $tr->nodeName !== 'tr') {
                continue;
            }
            // header → column index
            $cols = ['name' => 0, 'price' => 1, 'due' => 2, 'status' => 3];
            $table = $tr;
            while ($table && $table->nodeName !== 'table' && $table->parentNode) {
                $table = $table->parentNode;
            }
            if ($table && $table->nodeName === 'table') {
                $i = 0;
                foreach ($x->query('.//thead//th', $table) as $th) {
                    $t = strtolower(trim($th->textContent));
                    if (preg_match('/product|service|name/', $t)) {
                        $cols['name'] = $i;
                    } elseif (preg_match('/pric|amount|billing|cost/', $t)) {
                        $cols['price'] = $i;
                    } elseif (preg_match('/due|renew|expir/', $t)) {
                        $cols['due'] = $i;
                    } elseif (preg_match('/status/', $t)) {
                        $cols['status'] = $i;
                    }
                    $i++;
                }
            }
            $tds = [];
            foreach ($tr->childNodes as $c) {
                if ($c->nodeName === 'td') {
                    $tds[] = $c;
                }
            }
            if (!$tds) {
                continue;
            }
            $cell = fn ($k) => isset($tds[$cols[$k]]) ? self::cellLines($tds[$cols[$k]]) : [];
            $nameLines = $cell('name');
            $priceText = implode(' ', $cell('price'));
            $dueText = implode(' ', $cell('due'));
            $statusText = implode(' ', $cell('status'));
            $all = implode(' ', array_map(fn ($td) => trim($td->textContent), $tds));
            [$amount, $currency] = whmcs_parse_money($priceText);
            $host = '';
            foreach (array_slice($nameLines, 1) as $l) {
                if (strpos($l, ".") !== false && valid_host(strtolower($l))) {
                    $host = strtolower($l);
                    break;
                }
            }
            $ip = preg_match('/\b((?:\d{1,3}\.){3}\d{1,3})\b/', implode(' ', $nameLines), $mm) && filter_var($mm[1], FILTER_VALIDATE_IP) ? $mm[1] : '';
            $out[$id] = [
                'id'           => $id,
                'name'         => substr($nameLines[0] ?? ('Service #' . $id), 0, 190),
                'hostname'     => $host,
                'ip'           => $ip,
                'status'       => substr(ucfirst(strtolower(trim(preg_match('/\b(Active|Suspended|Pending|Cancelled|Terminated|Fraud|Completed)\b/i', $statusText . ' ' . $all, $sm) ? $sm[1] : $statusText))), 0, 32),
                'amount'       => $amount,
                'currency'     => $currency,
                'cycle'        => preg_match('/\b(Monthly|Quarterly|Semi-Annually|Annually|Biennially|Triennially|One Time|Free Account)\b/i', $priceText . ' ' . $all, $cm) ? $cm[1] : '',
                'next_due_raw' => preg_match('~\d{4}-\d{2}-\d{2}|\d{1,2}[/\-.]\d{1,2}[/\-.]\d{4}|\d{1,2}(?:st|nd|rd|th)? [A-Za-z]{3,9},? \d{4}~', $dueText ?: $all, $dm) ? $dm[0] : '',
            ];
        }
        return array_values($out);
    }

    private function serviceDetails(string $id, array $row): array
    {
        $r = $this->req($this->base . 'clientarea.php?action=productdetails&id=' . $id);
        $lines = self::textLines($r['body']);
        $x = self::dom($r['body']);

        $name = '';
        foreach (['//*[contains(@class,"product-name")]', '//*[contains(@class,"product-details")]//h3', '//*[contains(@class,"product-details")]//h4', '//h3[contains(@class,"product")]'] as $q) {
            foreach ($x->query($q) ?: [] as $node) {
                $t = trim(preg_replace('/\s+/', ' ', $node->textContent));
                if ($t !== '' && !preg_match('~^(details|overview|product details|manage|information|billing overview|addons)$~i', $t)) {
                    $name = $t;
                    break 2;
                }
            }
        }
        if ($name === '' && $row) {
            $name = $row[0] ?? '';
        }
        if ($name === '') {
            $name = self::labelValue($lines, ['Product/Service', 'Product', 'Service']);
        }

        $ip = self::findIp($lines);
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

        // Server login shown on the product page (many VPS providers display root user/password here)
        $bad = '~^(\*+|•+|\.+|change|reset|show|hide|copy|click|view|manage|n/?a|-)\b~i';
        $loginUser = self::labelValue($lines, ['Root Username', 'Root User', 'Username', 'User Name', 'Login User']);
        $loginUser = preg_match('/^[a-z_][a-z0-9_.\-]{0,31}$/i', $loginUser) ? $loginUser : '';
        $loginPass = '';
        foreach ($x->query('//input[(contains(translate(@name,"PASWORD","pasword"),"password") or contains(translate(@id,"PASWORD","pasword"),"password")) and @value!=""]') ?: [] as $in) {
            $v = trim($in->getAttribute('value'));
            if ($v !== '' && strlen($v) <= 128 && !preg_match($bad, $v)) {
                $loginPass = $v;
                break;
            }
        }
        if ($loginPass === '') {
            $v = self::labelValue($lines, ['Root Password', 'Server Password', 'Password']);
            if ($v !== '' && strlen($v) <= 128 && strpos($v, ' ') === false && !preg_match($bad, $v)) {
                $loginPass = $v;
            }
        }

        return [
            'login_user'   => $loginPass !== '' ? ($loginUser ?: 'root') : '',
            'login_pass'   => $loginPass,
            'id'           => $id,
            'name'         => substr($name !== '' ? $name : 'Service #' . $id, 0, 190),
            'hostname'     => substr(strpos($hostname, '.') !== false && valid_host(strtolower($hostname)) ? strtolower($hostname) : '', 0, 190),
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

function service_is_ended(?string $status): bool
{
    return in_array(strtolower(trim((string) $status)), ['cancelled', 'canceled', 'terminated', 'fraud'], true);
}

/** active | suspended | pending | ended | other — used by the status filter */
function service_state(?string $status): string
{
    if (service_is_ended($status)) {
        return 'ended';
    }
    $s = strtolower(trim((string) $status));
    return $s === '' ? 'active' : (in_array($s, ['active', 'suspended', 'pending'], true) ? $s : 'other');
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
function provider_sync(int $providerId, string $oneTimeCode = ''): array
{
    $p = row('SELECT * FROM providers WHERE id = ?', [$providerId]);
    if (!$p) {
        return ['ok' => false, 'message' => 'Provider not found', 'trace' => []];
    }
    $finish = function (bool $ok, string $msg, array $trace, array $extra = []) use ($providerId) {
        db_update('providers', ['last_sync_at' => time(), 'last_sync_ok' => $ok ? 1 : 0, 'last_sync_status' => substr($msg, 0, 250)], 'id = ?', [$providerId]);
        audit('provider_sync', $msg);
        return ['ok' => $ok, 'message' => $msg, 'trace' => $trace] + $extra;
    };
    if (!function_exists('curl_init') || !class_exists('DOMDocument')) {
        return $finish(false, $p['name'] . ': PHP cURL/DOM extension missing on this hosting.', []);
    }

    // ---- OVHcloud API ----
    if (($p['api_type'] ?? '') === 'ovh') {
        $trace = [];
        try {
            $cfg = json_decode(decrypt_str($p['api_config']), true) ?: [];
            $list = ovh_services($cfg, $trace);
        } catch (Throwable $e) {
            return $finish(false, $p['name'] . ': ' . $e->getMessage(), $trace);
        }
        $r = provider_save_services($providerId, $list, true, 'ovh');
        return $finish(true, $p['name'] . ': synced ' . $r['count'] . ' server(s) from OVHcloud API, ' . $r['linked'] . ' matched'
            . ($r['unmonitored'] ? ' · ' . $r['unmonitored'] . ' not monitored yet' : '') . '.', $trace);
    }

    // ---- WHMCS client area ----
    $pass = decrypt_str($p['account_pass']);
    if (!$p['panel_url'] || !$p['account_user'] || $pass === '') {
        return $finish(false, $p['name'] . ': save the client area URL, panel username and password first.', []);
    }
    $client = null;
    try {
        $client = new WhmcsClient($p['panel_url']);
        $client->totpSecret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', decrypt_str($p['totp_secret'] ?? '')));
        $client->oneTimeCode = preg_replace('/\D/', '', $oneTimeCode);
        $client->login($p['account_user'], $pass);
        unset($pass);
        $list = $client->services();
        $client->logout();
    } catch (WhmcsSyncException $e) {
        $msg = $p['name'] . ': ' . $e->getMessage();
        if (in_array($e->getCode(), [1, 2], true) && (int) $p['sync_enabled']) {
            // login-type problem: stop automatic retries so the provider doesn't lock the account / ban our IP
            db_update('providers', ['sync_enabled' => 0], 'id = ?', [$providerId]);
            $msg .= ' Auto-sync paused.';
        }
        return $finish(false, $msg, $client ? $client->trace : [], $e->getCode() === 2 ? ['needs_2fa' => true] : []);
    } catch (Throwable $e) {
        log_file('app.log', 'sync ' . $p['name'] . ': ' . $e->getMessage());
        return $finish(false, $p['name'] . ': unexpected error — ' . substr($e->getMessage(), 0, 120), $client ? $client->trace : []);
    }

    $r = provider_save_services($providerId, $list);
    $msg = $p['name'] . ': synced ' . $r['count'] . ' service(s), ' . $r['linked'] . ' matched to your servers'
        . ($r['gone'] ? ', ' . $r['gone'] . ' removed' : '')
        . ($r['logins'] ? ', ' . $r['logins'] . ' root login(s) saved (encrypted)' : '')
        . ($r['unmonitored'] ? ' · ' . $r['unmonitored'] . ' VPS not monitored yet (Services & Licenses → Add to monitoring)' : '') . '.';
    return $finish(true, $msg, $client->trace);
}

/**
 * Store a provider's service list (from sync, OVH API or an uploaded page) and link to servers.
 * $replace = remove synced services that are no longer in the list.
 */
function provider_save_services(int $providerId, array $list, bool $replace = true, string $source = 'whmcs'): array
{
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
    $goodHost = fn ($h) => $h && strpos($h, '.') !== false && valid_host($h);

    $now = time();
    $servers = rows('SELECT id, host, provider_id, monthly_cost, expires_at, ssh_pass FROM servers');
    $byId = [];
    foreach ($servers as $srv) {
        $byId[(int) $srv['id']] = $srv;
    }
    $seen = [];
    $linked = 0;
    $logins = 0;
    foreach ($list as $s) {
        if (service_is_ended($s['status'])) {
            continue; // cancelled / terminated services are not kept
        }
        $due = whmcs_parse_date($s['next_due_raw'], $order);
        $existing = row('SELECT id, kind, server_id, ip, hostname FROM provider_services WHERE provider_id = ? AND source = ? AND remote_id = ?', [$providerId, $source, $s['id']]);
        $ip = $s['ip'] ?: ($existing['ip'] ?? '');
        $host = $goodHost($s['hostname']) ? $s['hostname'] : ($goodHost($existing['hostname'] ?? '') ? $existing['hostname'] : '');
        $guess = service_kind($s['name'] . ' ' . $host);
        // re-classify entries that were stored as "other" because of a placeholder name
        $kind = ($existing && !($existing['kind'] === 'other' && $guess !== 'other')) ? $existing['kind'] : $guess;
        $loginPass = (string) ($s['login_pass'] ?? '');
        $loginUser = (string) ($s['login_user'] ?? '');

        $serverId = ($existing && $existing['server_id'] && isset($byId[(int) $existing['server_id']])) ? (int) $existing['server_id'] : null;
        if (!$serverId) {
            foreach ($servers as $srv) {
                if (($ip && $srv['host'] === $ip) || ($host && $srv['host'] === $host)) {
                    $serverId = (int) $srv['id'];
                    break;
                }
            }
        }
        if ($serverId) {
            $linked++;
            $srv = $byId[$serverId];
            if ($kind !== 'license') {
                $upd = [];
                if ($due) {
                    $upd['expires_at'] = $due;
                }
                if (!$srv['provider_id']) {
                    $upd['provider_id'] = $providerId;
                }
                if ($srv['monthly_cost'] === null && $s['amount'] !== null) {
                    $upd['monthly_cost'] = service_monthly($s['amount'], $s['cycle']);
                    $upd['currency'] = $s['currency'] ?: 'INR';
                }
                if ($loginPass !== '' && empty($srv['ssh_pass'])) {
                    // never overwrite a password you saved yourself
                    $upd['ssh_pass'] = encrypt_str($loginPass);
                    $upd['ssh_user'] = $loginUser ?: 'root';
                }
                if ($upd) {
                    db_update('servers', $upd, 'id = ?', [$serverId]);
                }
            }
        }
        $data = [
            'provider_id' => $providerId, 'server_id' => $serverId, 'source' => $source, 'remote_id' => $s['id'],
            'name' => $s['name'], 'kind' => $kind, 'hostname' => $host ?: null, 'ip' => $ip ?: null,
            'status' => $s['status'] ?: null, 'amount' => $s['amount'], 'currency' => $s['currency'], 'cycle' => $s['cycle'] ?: null,
            'next_due' => $due, 'seen_at' => $now,
        ];
        if (!empty($s['details'])) {
            $data['details'] = json_encode($s['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($loginPass !== '') {
            $data['login_user'] = $loginUser ?: 'root';
            $data['login_pass'] = encrypt_str($loginPass);
            $logins++;
        }
        if ($existing) {
            db_update('provider_services', $data, 'id = ?', [(int) $existing['id']]);
        } else {
            $data['created_at'] = $now;
            db_insert('provider_services', $data);
        }
        $seen[] = (string) $s['id'];
    }
    $gone = q("DELETE FROM provider_services WHERE provider_id = ? AND source = ? AND LOWER(status) IN ('cancelled', 'terminated', 'fraud')", [$providerId, $source])->rowCount();
    if ($replace) {
        foreach (rows('SELECT id, remote_id FROM provider_services WHERE provider_id = ? AND source = ?', [$providerId, $source]) as $old) {
            if (!in_array((string) $old['remote_id'], $seen, true)) {
                q('DELETE FROM provider_services WHERE id = ?', [(int) $old['id']]);
                $gone++;
            }
        }
    }
    $unmonitored = (int) val("SELECT COUNT(*) FROM provider_services WHERE provider_id = ? AND kind = 'vps' AND server_id IS NULL
                              AND (ip IS NOT NULL OR hostname IS NOT NULL) AND (status IS NULL OR LOWER(status) IN ('active', 'suspended'))", [$providerId]);
    return ['count' => count($list), 'linked' => $linked, 'gone' => $gone, 'unmonitored' => $unmonitored, 'logins' => $logins];
}

function service_monthly($amount, ?string $cycle): ?float
{
    if ($amount === null) {
        return null;
    }
    $div = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36][strtolower((string) $cycle)] ?? 1;
    return round((float) $amount / $div, 2);
}

/** Create a monitored server from a synced VPS service. Returns new server id or null. */
function service_to_server(int $serviceId): ?int
{
    $s = row('SELECT * FROM provider_services WHERE id = ?', [$serviceId]);
    if (!$s || $s['server_id']) {
        return $s['server_id'] ?? null;
    }
    $host = $s['ip'] ?: $s['hostname'];
    if (!$host || !valid_host($host)) {
        return null;
    }
    $existing = val('SELECT id FROM servers WHERE host = ?', [$host]);
    if ($existing) {
        db_update('provider_services', ['server_id' => (int) $existing], 'id = ?', [$serviceId]);
        return (int) $existing;
    }
    $det = json_decode((string) ($s['details'] ?? ''), true) ?: [];
    $name = $s['hostname'] && $s['hostname'] !== $host ? $s['hostname'] : $s['name'];
    $id = db_insert('servers', [
        'name' => substr($name, 0, 100), 'provider_id' => $s['provider_id'], 'host' => strtolower($host), 'check_type' => 'auto',
        'ssh_port' => 22, 'ssh_user' => $s['login_user'] ?: 'root', 'ssh_pass' => $s['login_pass'] ?: null,
        'expires_at' => $s['next_due'] ?: null,
        'monthly_cost' => service_monthly($s['amount'], $s['cycle']), 'currency' => $s['currency'] ?: 'INR',
        'location' => isset($det['location']) ? substr((string) $det['location'], 0, 100) : null,
        'os' => isset($det['os']) ? substr((string) $det['os'], 0, 100) : null,
        'specs' => isset($det['specs']) ? substr((string) $det['specs'], 0, 190) : null,
        'notes' => 'Added from ' . $s['name'] . ' (synced service)', 'enabled' => 1, 'status' => 'unknown',
        'status_since' => time(), 'created_at' => time(),
    ]);
    db_update('provider_services', ['server_id' => $id], 'id = ?', [$serviceId]);
    audit('server_added', $name . ' (' . $host . ') from synced service');
    return $id;
}


/** Cron helper: sync at most one provider per run, each roughly every 12 hours. */
function provider_sync_due(): ?array
{
    $p = row('SELECT id FROM providers WHERE sync_enabled = 1 AND (last_sync_at IS NULL OR last_sync_at < ?) ORDER BY last_sync_at', [time() - 43200]);
    return $p ? provider_sync((int) $p['id']) : null;
}
