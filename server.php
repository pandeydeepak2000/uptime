<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$s = row('SELECT s.*, p.name AS provider_name, p.color AS provider_color, p.panel_url, p.website FROM servers s LEFT JOIN providers p ON p.id = s.provider_id WHERE s.id = ?', [$id]);
if (!$s) {
    flash('error', 'Server not found.');
    redirect('servers.php');
}
$m = status_meta($s);
$now = time();
$periods = [
    '24h' => build_report($now - 86400, $now, $id)['servers'][0] ?? null,
    '7d'  => build_report(strtotime('today -6 days'), $now, $id)['servers'][0] ?? null,
    '30d' => build_report(strtotime('today -29 days'), $now, $id)['servers'][0] ?? null,
    '90d' => build_report(strtotime('today -89 days'), $now, $id)['servers'][0] ?? null,
];
$bars = uptime_days(90, $id)[$id] ?? [];
$incidents = rows('SELECT * FROM incidents WHERE server_id = ? ORDER BY started_at DESC LIMIT 50', [$id]);
$services = rows('SELECT ps.*, p.name AS provider_name FROM provider_services ps LEFT JOIN providers p ON p.id = ps.provider_id WHERE ps.server_id = ? ORDER BY ps.kind, ps.name', [$id]);

// response-time chart: last 24h, bucketed to 5 minutes
$raw = rows('SELECT checked_at, ok, response_ms FROM checks WHERE server_id = ? AND checked_at >= ? ORDER BY checked_at', [$id, $now - 86400]);
$buckets = [];
foreach ($raw as $c) {
    $b = intdiv((int) $c['checked_at'], 300) * 300;
    $buckets[$b]['n'] = ($buckets[$b]['n'] ?? 0) + 1;
    if ((int) $c['ok'] === 1) {
        $buckets[$b]['sum'] = ($buckets[$b]['sum'] ?? 0) + (int) $c['response_ms'];
        $buckets[$b]['okn'] = ($buckets[$b]['okn'] ?? 0) + 1;
    } else {
        $buckets[$b]['fail'] = ($buckets[$b]['fail'] ?? 0) + 1;
    }
}
$chart = [];
foreach ($buckets as $t => $b) {
    $chart[] = ['t' => $t, 'v' => !empty($b['okn']) ? (int) round($b['sum'] / $b['okn']) : null, 'f' => (int) ($b['fail'] ?? 0)];
}
$sshCmd = 'ssh ' . ($s['ssh_user'] ?: 'root') . '@' . $s['host'] . ((int) $s['ssh_port'] !== 22 ? ' -p ' . (int) $s['ssh_port'] : '');

$actions = '<a class="btn btn-ghost" href="' . e(url('server_edit.php?id=' . $id)) . '">' . icon('edit') . '<span class="hide-sm">Edit</span></a>'
    . '<button class="btn btn-ghost" type="button" data-check="' . $id . '">' . icon('refresh') . '<span class="hide-sm">Check now</span></button>';
layout_start($s['name'], 'servers', '<span class="mono">' . e($s['host']) . '</span> · ' . e($s['provider_name'] ?? 'No provider'), $actions);
?>
<section class="detail-hero card glass st-<?= e($m['cls']) ?> anim-in" data-server="<?= $id ?>" data-status="<?= e($m['status']) ?>">
  <div class="dh-status">
    <div class="big-dot dot-<?= e($m['cls']) ?>"><span></span></div>
    <div>
      <?= status_pill($s) ?>
      <h2 class="dh-since"><span data-role="since-label"><?= $m['status'] === 'down' ? 'Down for' : ($m['status'] === 'up' ? 'Up for' : '') ?></span>
        <b data-role="since" <?= in_array($m['status'], ['up', 'down'], true) ? 'data-since="' . (int) $s['status_since'] . '"' : '' ?>><?= in_array($m['status'], ['up', 'down'], true) ? e(fmt_duration($now - (int) $s['status_since'], 3)) : e($m['label']) ?></b></h2>
      <p class="muted small">Since <?= e(fmt_time($s['status_since'])) ?> · last check <span data-role="checked" <?= $s['last_check_at'] ? 'data-ago="' . (int) $s['last_check_at'] . '"' : '' ?>><?= e(time_ago($s['last_check_at'])) ?></span>
        <?= $s['last_error'] ? ' · <span class="txt-bad">' . e($s['last_error']) . '</span>' : '' ?></p>
    </div>
  </div>
  <div class="dh-stats">
    <?php foreach ($periods as $label => $p): ?>
      <div class="dh-stat"><span class="lbl">Uptime <?= e($label) ?></span><b class="txt-<?= uptime_class($p['uptime'] ?? null) ?>"><?= fmt_pct($p['uptime'] ?? null, 3) ?></b>
        <span class="muted small"><?= (int) ($p['incident_count'] ?? 0) ?> incidents · <?= e(fmt_duration($p['downtime'] ?? 0)) ?> down</span></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card glass anim-in" style="--d:.05s">
  <div class="card-head">
    <h3><?= icon('activity') ?>Response time · last 24h</h3>
    <div class="legend">
      <span><i class="lg-line"></i>Avg <?= $periods['24h']['avg_ms'] !== null ? $periods['24h']['avg_ms'] . ' ms' : '—' ?></span>
      <span class="muted">Min <?= $periods['24h']['min_ms'] ?? '—' ?> · Max <?= $periods['24h']['max_ms'] ?? '—' ?> ms</span>
      <span><i class="lg-fail"></i>Failed checks</span>
    </div>
  </div>
  <div class="chart" id="respChart" data-chart="#chartData"><?= !$chart ? '<div class="mini-empty">' . icon('clock') . 'No data yet — the chart fills in as checks run.</div>' : '' ?></div>
  <script type="application/json" id="chartData"><?= json_script($chart) ?></script>
</section>

<section class="card glass anim-in" style="--d:.1s">
  <div class="card-head"><h3><?= icon('calendar') ?>90-day uptime</h3><span class="muted small">Hover a bar for details</span></div>
  <?= uptime_bars($bars, 'ubars-lg') ?>
  <div class="ubar-axis"><span>90 days ago</span><span>Today</span></div>
</section>

<section class="detail-grid">
  <div class="card glass anim-in vault-card" style="--d:.15s">
    <div class="card-head"><h3><?= icon('key') ?>Root access</h3><span class="pill pill-up xs"><?= icon('lock') ?>Encrypted</span></div>
    <dl class="kv">
      <dt>Host / IP</dt><dd><span class="mono"><?= e($s['host']) ?></span><button class="copy-btn" type="button" data-copy="<?= e($s['host']) ?>"><?= icon('copy') ?></button></dd>
      <dt>SSH user</dt><dd><span class="mono"><?= e($s['ssh_user'] ?: 'root') ?></span><button class="copy-btn" type="button" data-copy="<?= e($s['ssh_user'] ?: 'root') ?>"><?= icon('copy') ?></button></dd>
      <dt>SSH port</dt><dd class="mono"><?= (int) $s['ssh_port'] ?></dd>
      <dt>Host key</dt><dd><?= $s['ssh_fingerprint'] ? '<span class="mono small" title="Saved SSH host key fingerprint — auto-detect refuses to send the password if it changes">' . e($s['ssh_fingerprint']) . '</span>' : '<span class="muted small">Not verified yet — run Auto-detect in Edit</span>' ?></dd>
      <dt>Password</dt><dd><?php if ($s['ssh_pass']): ?><span class="mono">••••••••••••</span>
        <button class="btn btn-ghost xs" type="button" data-reveal="server" data-id="<?= $id ?>" data-label="<?= e($s['name']) ?>"><?= icon('eye') ?>Reveal</button><?php else: ?><span class="muted">Not saved</span><?php endif; ?></dd>
    </dl>
    <div class="secret-box"><code><?= e($sshCmd) ?></code><button class="icon-btn" type="button" data-copy="<?= e($sshCmd) ?>" title="Copy SSH command"><?= icon('copy') ?></button></div>
  </div>

  <div class="card glass anim-in" style="--d:.2s">
    <div class="card-head"><h3><?= icon('cpu') ?>Server info</h3></div>
    <dl class="kv">
      <dt>Provider</dt><dd><?= provider_badge($s['provider_name'], $s['provider_color']) ?>
        <?php if ($s['panel_url']): ?><a class="link small" href="<?= e($s['panel_url']) ?>" target="_blank" rel="noopener noreferrer">Panel ↗</a><?php endif; ?></dd>
      <dt>Check method</dt><dd><?= e(CHECK_TYPES[$s['check_type']] ?? $s['check_type']) ?><?= $s['check_type'] === 'tcp' ? ' · port ' . (int) $s['port'] : '' ?><?= $s['check_type'] === 'http' ? ' · ' . e($s['url']) : '' ?></dd>
      <dt>Location</dt><dd><?= e($s['location'] ?: '—') ?></dd>
      <dt>OS</dt><dd><?= e($s['os'] ?: '—') ?></dd>
      <dt>Specs</dt><dd><?= e($s['specs'] ?: '—') ?></dd>
      <dt>Cost</dt><dd><?= $s['monthly_cost'] !== null ? e($s['currency'] . ' ' . number_format((float) $s['monthly_cost'], 2)) . ' / month' : '—' ?></dd>
      <dt>Next due</dt><dd>
        <?php if ($s['expires_at']):
            $left = (int) round((strtotime($s['expires_at']) - strtotime('today')) / 86400); ?>
          <?= e(date('d M Y', strtotime($s['expires_at']))) ?>
          <span class="pill xs <?= $left < 0 ? 'pill-down' : ($left <= 3 ? 'pill-warn' : 'pill-unknown') ?>"><?= $left < 0 ? 'Overdue ' . abs($left) . 'd' : ($left === 0 ? 'Due today' : $left . ' days left') ?></span>
        <?php else: ?>—<?php endif; ?>
      </dd>
      <dt>Mark renewed</dt><dd>
        <form method="post" action="<?= e(url('server_edit.php')) ?>" class="renew-form" data-confirm="Mark <?= e($s['name']) ?> as renewed? The next due date moves forward from <?= e($s['expires_at'] ? date('d M Y', strtotime($s['expires_at'])) : 'today') ?>.">
          <?= csrf_field() ?><input type="hidden" name="action" value="renew"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn-ghost xs" name="months" value="1" type="submit">+1 month</button>
          <button class="btn btn-ghost xs" name="months" value="3" type="submit">+3 months</button>
          <button class="btn btn-ghost xs" name="months" value="6" type="submit">+6 months</button>
          <button class="btn btn-ghost xs" name="months" value="12" type="submit">+1 year</button>
        </form>
      </dd>
      <dt>Added</dt><dd><?= e(fmt_time($s['created_at'], 'd M Y')) ?></dd>
    </dl>
    <?php if ($s['notes']): ?><div class="notes"><?= nl2br(e($s['notes'])) ?></div><?php endif; ?>
  </div>
</section>

<section class="card glass table-card anim-in" style="--d:.2s">
  <div class="card-head"><h3><?= icon('key') ?>Licenses &amp; services on this server</h3>
    <a class="btn btn-ghost xs" href="<?= e(url('service_edit.php?server=' . $id)) ?>"><?= icon('plus') ?>Add license</a></div>
  <?php if (!$services): ?>
    <div class="mini-empty"><?= icon('key') ?>No licenses linked. Add cPanel / LiteSpeed / CloudLinux licenses to get renewal reminders.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Name</th><th>Provider</th><th>Price</th><th>Next due</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($services as $sv): $l = $sv['next_due'] ? (int) round((strtotime($sv['next_due']) - strtotime('today')) / 86400) : null; ?>
      <tr>
        <td><b><?= e($sv['name']) ?></b> <span class="muted small"><?= $sv['kind'] === 'license' ? '🔑' : '' ?> <?= $sv['source'] === 'whmcs' ? 'synced' : 'manual' ?></span></td>
        <td><?= e($sv['provider_name'] ?? '—') ?></td>
        <td class="nowrap"><?= $sv['amount'] !== null ? e(($sv['currency'] ?? '') . ' ' . number_format((float) $sv['amount'], 2) . ' ' . strtolower((string) $sv['cycle'])) : '—' ?></td>
        <td class="nowrap"><?= $sv['next_due'] ? e(date('d M Y', strtotime($sv['next_due']))) . ' <span class="pill xs ' . ($l < 0 ? 'pill-down' : ($l <= 3 ? 'pill-warn' : 'pill-unknown')) . '">' . ($l < 0 ? 'Overdue' : $l . 'd') . '</span>' : '—' ?></td>
        <td><?= e($sv['status'] ?? '—') ?></td>
        <td class="right"><a class="icon-btn sm" href="<?= e(url('service_edit.php?id=' . (int) $sv['id'])) ?>"><?= icon('edit') ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card glass table-card anim-in" style="--d:.25s">
  <div class="card-head"><h3><?= icon('zap') ?>Incident history</h3><span class="muted small">Last 50</span></div>
  <?php if (!$incidents): ?>
    <div class="mini-empty"><?= icon('check') ?>No downtime recorded for this server.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Status</th><th>Went down</th><th>Came back</th><th>Duration</th><th>Cause</th></tr></thead>
      <tbody>
      <?php foreach ($incidents as $i): $open = $i['ended_at'] === null; ?>
        <tr>
          <td><?= $open ? '<span class="pill pill-down xs"><span class="dot dot-down"></span>Ongoing</span>' : '<span class="pill pill-up xs">Resolved</span>' ?></td>
          <td><?= e(fmt_time($i['started_at'], 'd M Y, h:i:s A')) ?></td>
          <td><?= $open ? '—' : e(fmt_time($i['ended_at'], 'd M Y, h:i:s A')) ?></td>
          <td><b <?= $open ? 'data-since="' . (int) $i['started_at'] . '"' : '' ?>><?= e(fmt_duration($open ? $now - (int) $i['started_at'] : (int) $i['duration'], 3)) ?></b></td>
          <td class="mono small muted"><?= e((string) $i['cause']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php layout_end();
