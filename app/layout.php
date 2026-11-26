<?php
defined('APP_ROOT') || exit;

/* ------------------------------------------------------------------
 | Page chrome + reusable UI fragments
 * -----------------------------------------------------------------*/

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    $v = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : APP_VERSION;
    return url('assets/' . $path) . '?v=' . $v;
}

function html_head(string $title, string $bodyClass): void
{
    $u = auth_user_cached();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="server-time" content="<?= time() ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<title><?= e($title) ?> · <?= e(app_name()) ?></title>
<link rel="icon" href="<?= e(url('assets/img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<div class="bg-fx" aria-hidden="true"><span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span><div class="grid-fx"></div></div>
<?php
}

function auth_user_cached(): ?array
{
    return $GLOBALS['_AUTH_USER'] ?? null;
}

function nav_link(string $href, string $icon, string $label, string $key, string $active, string $badge = ''): string
{
    return '<a class="nav-link' . ($key === $active ? ' active' : '') . '" href="' . e(url($href)) . '">'
        . icon($icon) . '<span>' . e($label) . '</span>' . $badge . '</a>';
}

function layout_start(string $title, string $active = '', string $subtitle = '', string $actions = ''): void
{
    $user = $GLOBALS['_AUTH_USER'] ?? require_login();
    $GLOBALS['_AUTH_USER'] = $user;
    security_headers();
    $downCount = (int) val("SELECT COUNT(*) FROM servers WHERE enabled = 1 AND status = 'down'");
    $total = (int) val('SELECT COUNT(*) FROM servers');
    $cron = (int) setting('last_cron_run', 0);
    $interval = max(60, (int) setting('check_interval', 60));
    $cronState = !$cron ? 'missing' : ((time() - $cron) > $interval * 3 + 60 ? 'late' : 'ok');
    html_head($title, 'app-body');
    ?>
<aside class="sidebar" id="sidebar">
  <a class="brand" href="<?= e(url('dashboard.php')) ?>">
    <div class="brand-logo"><?= icon('activity') ?></div>
    <div class="brand-text"><div class="brand-name"><?= e(app_name()) ?></div><div class="brand-sub">Infrastructure Monitor</div></div>
  </a>
  <nav class="nav">
    <div class="nav-label">Monitoring</div>
    <?= nav_link('dashboard.php', 'grid', 'Dashboard', 'dashboard', $active, $downCount ? '<span class="nav-badge down">' . $downCount . '</span>' : '') ?>
    <?= nav_link('servers.php', 'server', 'Servers', 'servers', $active, '<span class="nav-badge">' . $total . '</span>') ?>
    <?= nav_link('providers.php', 'building', 'Providers', 'providers', $active) ?>
    <?= nav_link('reports.php', 'report', 'Reports', 'reports', $active) ?>
    <div class="nav-label">System</div>
    <?= nav_link('settings.php', 'sliders', 'Settings', 'settings', $active) ?>
    <?= nav_link('activity.php', 'list', 'Activity Log', 'activity', $active) ?>
  </nav>
  <div class="sidebar-foot">
    <a class="sys-card cron-<?= $cronState ?>" href="<?= e(url('settings.php#cron')) ?>" title="Background checker (cron)">
      <span class="sys-dot"></span>
      <div>
        <div class="sys-title"><?= $cronState === 'ok' ? 'Monitoring active' : ($cronState === 'late' ? 'Cron delayed' : 'Cron not configured') ?></div>
        <div class="sys-sub"><?= $cron ? 'Last run <span data-ago="' . $cron . '">' . e(time_ago($cron)) . '</span>' : 'Click to set up cron job' ?></div>
      </div>
    </a>
    <div class="user-chip">
      <div class="avatar"><?= e(initials($user['username'])) ?></div>
      <div class="user-meta"><div class="user-name"><?= e($user['username']) ?></div><div class="user-role"><?= (int) $user['totp_enabled'] ? 'Admin · 2FA on' : 'Administrator' ?></div></div>
      <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="icon-btn" title="Log out" type="submit"><?= icon('logout') ?></button></form>
    </div>
  </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>
<main class="main">
  <header class="topbar">
    <button class="icon-btn only-mobile" data-sidebar-open aria-label="Menu"><?= icon('menu') ?></button>
    <div class="page-title">
      <h1><?= e($title) ?></h1>
      <?php if ($subtitle): ?><p><?= $subtitle ?></p><?php endif; ?>
    </div>
    <div class="topbar-actions">
      <div class="live-clock hide-sm"><?= icon('clock') ?><span id="liveClock"><?= e(date('h:i:s A')) ?></span></div>
      <?= $actions ?>
      <button class="btn btn-primary" id="btnCheckAll" type="button"><?= icon('refresh') ?><span class="hide-sm">Check all now</span></button>
    </div>
  </header>
  <div class="content">
<?php if (empty($user['email'])): ?>
    <a class="alert alert-error anim-in" href="<?= e(url('settings.php#security')) ?>"><?= icon('alert') ?><span><b>Set your login email.</b> You are still signing in with the username “<?= e($user['username']) ?>”. Add an email in Settings → Security — after that, only the email will work for login.</span></a>
<?php endif; ?>
<?php
}

function layout_end(string $scripts = ''): void
{
    $fl = function_exists('flashes') && session_status() === PHP_SESSION_ACTIVE ? flashes() : [];
    ?>
    <footer class="app-foot"><?= e(app_name()) ?> · v<?= e(APP_VERSION) ?> · <?= e(date_default_timezone_get()) ?></footer>
  </div>
</main>

<div class="modal" id="revealModal" hidden>
  <div class="modal-card">
    <button class="icon-btn modal-close" data-modal-close type="button"><?= icon('x') ?></button>
    <div class="modal-icon"><?= icon('lock') ?></div>
    <h3>Reveal secret</h3>
    <p class="muted" id="revealLabel">Confirm your dashboard password to view this credential.</p>
    <form id="revealForm" autocomplete="off">
      <input type="hidden" name="type" value="">
      <input type="hidden" name="id" value="">
      <div class="field"><input class="input" type="password" name="password" placeholder="Your dashboard password" autocomplete="current-password" required></div>
      <button class="btn btn-primary w-full" type="submit"><?= icon('eye') ?>Reveal</button>
    </form>
    <div class="reveal-result" hidden>
      <div class="secret-box"><code id="revealValue"></code><button class="icon-btn" type="button" id="revealCopy" title="Copy"><?= icon('copy') ?></button></div>
      <p class="muted small">Auto-hides in <span id="revealTimer">30</span>s · this view was logged.</p>
    </div>
  </div>
</div>
<div class="toasts" id="toasts"></div>
<script type="application/json" id="flash-data"><?= json_script($fl) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= $scripts ?>
</body>
</html>
<?php
}

function auth_layout_start(string $title): void
{
    security_headers();
    html_head($title, 'auth-body');
}

function auth_layout_end(): void
{
    $fl = session_status() === PHP_SESSION_ACTIVE ? flashes() : [];
    ?>
<div class="toasts" id="toasts"></div>
<script type="application/json" id="flash-data"><?= json_script($fl) ?></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
<?php
}

/* ---------------- fragments ---------------- */

function status_pill(array $s): string
{
    $m = status_meta($s);
    return '<span class="pill pill-' . $m['cls'] . '" data-role="pill"><span class="dot dot-' . $m['cls'] . '"></span><span data-role="label">' . e($m['label']) . '</span></span>';
}

function provider_badge(?string $name, ?string $color): string
{
    if (!$name) {
        return '<span class="prov-badge muted-badge">No provider</span>';
    }
    $c = safe_color($color);
    return '<span class="prov-badge" style="--pc:' . e($c) . '"><i></i>' . e($name) . '</span>';
}

function uptime_bars(array $cells, string $cls = ''): string
{
    $h = '<div class="ubars ' . e($cls) . '">';
    foreach ($cells as $c) {
        $p = $c['p'];
        $k = $p === null ? 'none' : ($p >= 99.9 ? 'good' : ($p >= 98 ? 'warn' : 'bad'));
        $tip = $c['d'] . ' — ' . ($p === null ? 'No data' : fmt_pct($p) . ($c['down'] ? ' · down ' . fmt_duration($c['down']) : ''));
        $h .= '<span class="ub ub-' . $k . '" data-tip="' . e($tip) . '"></span>';
    }
    return $h . '</div>';
}

function empty_state(string $icon, string $title, string $text, string $action = ''): string
{
    return '<div class="empty"><div class="empty-ico">' . icon($icon) . '</div><h3>' . e($title) . '</h3><p>' . e($text) . '</p>' . $action . '</div>';
}
