<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$servers = rows("SELECT s.*, p.name AS provider_name, p.color AS provider_color
                 FROM servers s LEFT JOIN providers p ON p.id = s.provider_id
                 ORDER BY CASE WHEN s.enabled = 0 THEN 3 WHEN s.status = 'down' THEN 0 WHEN s.status = 'unknown' THEN 2 ELSE 1 END, s.name");
$rep = build_report(time() - 86400, time());
$repBy = [];
foreach ($rep['servers'] as $r) {
    $repBy[(int) $r['id']] = $r;
}
$days = uptime_days(30);
$providers = rows('SELECT p.id, p.name, p.color, COUNT(s.id) AS total,
                          SUM(CASE WHEN s.status = \'up\' AND s.enabled = 1 THEN 1 ELSE 0 END) AS up_count,
                          SUM(CASE WHEN s.status = \'down\' AND s.enabled = 1 THEN 1 ELSE 0 END) AS down_count
                   FROM providers p LEFT JOIN servers s ON s.provider_id = p.id GROUP BY p.id, p.name, p.color ORDER BY p.name');
$recent = rows('SELECT i.*, s.name, s.host FROM incidents i JOIN servers s ON s.id = i.server_id ORDER BY i.started_at DESC LIMIT 8');

$t = $rep['totals'];
$active = $t['servers'] - $t['paused'];
$health = $active > 0 ? round(100 * $t['up'] / $active) : 100;
$downList = array_values(array_filter($servers, fn ($s) => $s['status'] === 'down' && (int) $s['enabled'] === 1));
$incidents24 = count(array_filter($rep['incidents'], fn ($i) => (int) $i['started_at'] >= time() - 86400));

$expiring = array_values(array_filter($servers, function ($s) {
    if (!$s['expires_at']) {
        return false;
    }
    $ts = strtotime($s['expires_at']);
    return $ts && $ts - time() < 15 * 86400;
}));
usort($expiring, fn ($a, $b) => strcmp($a['expires_at'], $b['expires_at']));

$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

layout_start(
    'Dashboard',
    'dashboard',
    e($greet) . ', <b>' . e($user['username']) . '</b> — here is your infrastructure at a glance.',
    '<a class="btn btn-ghost" href="' . e(url('reports.php?range=today')) . '">' . icon('report') . '<span class="hide-sm">Today\'s report</span></a>'
);
?>

<?php if (!$servers): ?>
  <div class="card glass anim-in">
    <?= empty_state('server', 'No servers yet', 'Add your first VPS from EricHost, ServerBroker, Servica or any provider to start monitoring.',
        '<a class="btn btn-primary" href="' . e(url('server_edit.php')) . '">' . icon('plus') . 'Add server</a>') ?>
  </div>
<?php else: ?>

<section class="hero-grid">
  <div class="card glass hero-card anim-in <?= $downList ? 'is-down' : 'is-up' ?>">
    <div class="ring-wrap">
      <svg class="ring" viewBox="0 0 120 120">
        <defs>
          <linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="<?= $downList ? '#ff4d6d' : '#10e5a0' ?>"/>
            <stop offset="100%" stop-color="<?= $downList ? '#ffb547' : '#22d3ee' ?>"/>
          </linearGradient>
        </defs>
        <circle class="ring-bg" cx="60" cy="60" r="52"/>
        <circle class="ring-fg" cx="60" cy="60" r="52" stroke="url(#ringGrad)" data-ring="<?= $health ?>"/>
      </svg>
      <div class="ring-label"><b data-count="<?= $health ?>">0</b><span>% online</span></div>
    </div>
    <div class="hero-info">
      <div class="hero-status" id="heroStatus">
        <?php if ($downList): ?>
          <span class="dot dot-down"></span><?= count($downList) ?> server<?= count($downList) > 1 ? 's need' : ' needs' ?> attention
        <?php else: ?>
          <span class="dot dot-up"></span>All systems operational
        <?php endif; ?>
      </div>
      <p class="muted">Monitoring <b><?= $t['servers'] ?></b> servers across <b><?= count($providers) ?></b> providers · checks every <?= (int) setting('check_interval', 60) ?>s</p>
      <div class="hero-mini">
        <div><span class="lbl">24h uptime</span><b class="txt-<?= uptime_class($t['avg_uptime']) ?>"><?= fmt_pct($t['avg_uptime'], 3) ?></b></div>
        <div><span class="lbl">Downtime 24h</span><b><?= e(fmt_duration($t['downtime'])) ?></b></div>
        <div><span class="lbl">Avg response</span><b><?= $t['avg_ms'] !== null ? $t['avg_ms'] . ' ms' : '—' ?></b></div>
      </div>
    </div>
  </div>

  <div class="stat-grid">
    <div class="card glass stat anim-in" style="--d:.05s">
      <div class="stat-ico ico-violet"><?= icon('server') ?></div>
      <div><span class="stat-lbl">Total servers</span><b class="stat-val" data-count="<?= $t['servers'] ?>" data-sum="total">0</b></div>
    </div>
    <div class="card glass stat anim-in" style="--d:.1s">
      <div class="stat-ico ico-green"><?= icon('up') ?></div>
      <div><span class="stat-lbl">Online</span><b class="stat-val txt-good" data-count="<?= $t['up'] ?>" data-sum="up">0</b></div>
    </div>
    <div class="card glass stat anim-in <?= $t['down'] ? 'stat-alert' : '' ?>" style="--d:.15s">
      <div class="stat-ico ico-red"><?= icon('down') ?></div>
      <div><span class="stat-lbl">Offline</span><b class="stat-val txt-bad" data-count="<?= $t['down'] ?>" data-sum="down">0</b></div>
    </div>
    <div class="card glass stat anim-in" style="--d:.2s">
      <div class="stat-ico ico-amber"><?= icon('zap') ?></div>
      <div><span class="stat-lbl">Incidents (24h)</span><b class="stat-val" data-count="<?= $incidents24 ?>">0</b></div>
    </div>
  </div>
</section>

<?php if ($downList): ?>
<section class="card glass down-panel anim-in">
  <div class="card-head">
    <h3><?= icon('wifi-off') ?>Servers currently down</h3>
    <span class="pill pill-down"><span class="dot dot-down"></span><?= count($downList) ?> offline</span>
  </div>
  <div class="down-list">
    <?php foreach ($downList as $s): ?>
      <a class="down-item" href="<?= e(url('server.php?id=' . (int) $s['id'])) ?>">
        <span class="down-pulse"></span>
        <div class="down-main"><b><?= e($s['name']) ?></b><span class="mono muted"><?= e($s['host']) ?></span></div>
        <?= provider_badge($s['provider_name'], $s['provider_color']) ?>
        <div class="down-time"><span class="lbl">Down for</span><b data-since="<?= (int) $s['status_since'] ?>"><?= e(fmt_duration(time() - (int) $s['status_since'])) ?></b></div>
        <div class="down-err muted small"><?= e((string) $s['last_error']) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="toolbar anim-in">
  <div class="search"><?= icon('search') ?><input class="input" type="search" placeholder="Search name, IP, provider, location…" data-filter-input="#serverGrid"></div>
  <div class="chips" data-filter-chips="#serverGrid">
    <button class="chip active" data-status="all" type="button">All <b><?= $t['servers'] ?></b></button>
    <button class="chip" data-status="up" type="button"><span class="dot dot-up"></span>Online <b data-sum="up"><?= $t['up'] ?></b></button>
    <button class="chip" data-status="down" type="button"><span class="dot dot-down"></span>Offline <b data-sum="down"><?= $t['down'] ?></b></button>
    <button class="chip" data-status="paused" type="button"><span class="dot dot-paused"></span>Paused <b><?= $t['paused'] ?></b></button>
  </div>
  <select class="input select-sm" data-filter-provider="#serverGrid">
    <option value="">All providers</option>
    <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
  <a class="btn btn-ghost" href="<?= e(url('server_edit.php')) ?>"><?= icon('plus') ?><span class="hide-sm">Add server</span></a>
</section>

<section class="server-grid" id="serverGrid">
  <?php foreach ($servers as $i => $s):
      $id = (int) $s['id'];
      $m = status_meta($s);
      $r = $repBy[$id] ?? null;
      $search = strtolower($s['name'] . ' ' . $s['host'] . ' ' . $s['provider_name'] . ' ' . $s['location'] . ' ' . $s['os']);
      ?>
    <article class="srv-card glass st-<?= e($m['cls']) ?> anim-in" style="--d:<?= min(0.6, $i * 0.03) ?>s" data-server="<?= $id ?>" data-status="<?= e($m['status']) ?>" data-provider="<?= (int) $s['provider_id'] ?>" data-search="<?= e($search) ?>">
      <div class="srv-glow"></div>
      <div class="srv-head">
        <div class="srv-ident">
          <div class="srv-ico"><?= icon('server') ?></div>
          <div class="min0">
            <a class="srv-name" href="<?= e(url('server.php?id=' . $id)) ?>"><?= e($s['name']) ?></a>
            <div class="srv-host"><span class="mono"><?= e($s['host']) ?></span><button class="copy-btn" type="button" data-copy="<?= e($s['host']) ?>" title="Copy IP"><?= icon('copy') ?></button></div>
          </div>
        </div>
        <?= status_pill($s) ?>
      </div>
      <div class="srv-meta">
        <?= provider_badge($s['provider_name'], $s['provider_color']) ?>
        <?php if ($s['location']): ?><span class="meta-item"><?= icon('pin') ?><?= e($s['location']) ?></span><?php endif; ?>
        <span class="meta-item"><?= icon('activity') ?><?= e(strtoupper($s['check_type'])) ?></span>
      </div>
      <div class="srv-stats">
        <div>
          <span class="lbl" data-role="since-label"><?= $m['status'] === 'down' ? 'Down for' : ($m['status'] === 'up' ? 'Up for' : 'Status') ?></span>
          <b data-role="since" <?= in_array($m['status'], ['up', 'down'], true) ? 'data-since="' . (int) $s['status_since'] . '"' : '' ?>><?= in_array($m['status'], ['up', 'down'], true) ? e(fmt_duration(time() - (int) $s['status_since'])) : e($m['label']) ?></b>
        </div>
        <div>
          <span class="lbl">Response</span>
          <b data-role="ms"><?= $s['last_response_ms'] !== null && $m['status'] === 'up' ? (int) $s['last_response_ms'] . ' ms' : '—' ?></b>
        </div>
        <div>
          <span class="lbl">Uptime 24h</span>
          <b class="txt-<?= uptime_class($r['uptime'] ?? null) ?>"><?= fmt_pct($r['uptime'] ?? null) ?></b>
        </div>
      </div>
      <?= uptime_bars($days[$id] ?? []) ?>
      <div class="srv-foot">
        <span class="muted small" title="<?= e((string) $s['last_error']) ?>"><?= icon('clock') ?>Checked <span data-role="checked" <?= $s['last_check_at'] ? 'data-ago="' . (int) $s['last_check_at'] . '"' : '' ?>><?= e(time_ago($s['last_check_at'])) ?></span></span>
        <div class="srv-actions">
          <button class="icon-btn sm" type="button" data-check="<?= $id ?>" title="Check now"><?= icon('refresh') ?></button>
          <a class="icon-btn sm" href="<?= e(url('server_edit.php?id=' . $id)) ?>" title="Edit"><?= icon('edit') ?></a>
          <a class="icon-btn sm" href="<?= e(url('server.php?id=' . $id)) ?>" title="Details"><?= icon('external') ?></a>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  <div class="empty-filter" hidden><?= icon('filter') ?> No servers match your filters.</div>
</section>

<section class="bottom-grid">
  <div class="card glass anim-in">
    <div class="card-head"><h3><?= icon('zap') ?>Recent incidents</h3><a class="link" href="<?= e(url('reports.php?range=7d')) ?>">View report →</a></div>
    <?php if (!$recent): ?>
      <div class="mini-empty"><?= icon('check') ?>No incidents recorded yet. Smooth sailing!</div>
    <?php else: ?>
      <ul class="timeline">
        <?php foreach ($recent as $inc): $open = $inc['ended_at'] === null; ?>
          <li class="<?= $open ? 'open' : 'closed' ?>">
            <span class="tl-dot"></span>
            <div class="tl-body">
              <div class="tl-top"><a href="<?= e(url('server.php?id=' . (int) $inc['server_id'])) ?>"><b><?= e($inc['name']) ?></b></a>
                <?= $open ? '<span class="pill pill-down xs">Ongoing</span>' : '<span class="pill pill-up xs">Resolved</span>' ?></div>
              <div class="muted small"><?= e(fmt_time($inc['started_at'])) ?> · <?= $open ? 'down for <b data-since="' . (int) $inc['started_at'] . '">' . e(fmt_duration(time() - (int) $inc['started_at'])) . '</b>' : 'lasted <b>' . e(fmt_duration((int) $inc['duration'], 3)) . '</b>' ?></div>
              <?php if ($inc['cause']): ?><div class="tl-cause mono"><?= e($inc['cause']) ?></div><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="stack-col">
    <div class="card glass anim-in">
      <div class="card-head"><h3><?= icon('building') ?>Providers</h3><a class="link" href="<?= e(url('providers.php')) ?>">Manage →</a></div>
      <div class="prov-list">
        <?php foreach ($providers as $p): $tot = (int) $p['total']; $pu = (int) $p['up_count']; $pd = (int) $p['down_count']; ?>
          <div class="prov-row" style="--pc:<?= e(safe_color($p['color'])) ?>">
            <div class="prov-name"><i></i><?= e($p['name']) ?></div>
            <div class="prov-bar"><span style="width:<?= $tot ? round(100 * $pu / $tot) : 0 ?>%"></span><span class="bad" style="width:<?= $tot ? round(100 * $pd / $tot) : 0 ?>%"></span></div>
            <div class="prov-count"><b><?= $pu ?></b>/<?= $tot ?><?= $pd ? ' <span class="txt-bad">· ' . $pd . ' down</span>' : '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card glass anim-in">
      <div class="card-head"><h3><?= icon('calendar') ?>Upcoming renewals</h3></div>
      <?php if (!$expiring): ?>
        <div class="mini-empty"><?= icon('check') ?>No renewals due in the next 15 days.</div>
      <?php else: ?>
        <div class="renew-list">
          <?php foreach ($expiring as $s): $daysLeft = (int) floor((strtotime($s['expires_at']) - strtotime('today')) / 86400); ?>
            <a class="renew-row" href="<?= e(url('server.php?id=' . (int) $s['id'])) ?>">
              <span><b><?= e($s['name']) ?></b><span class="muted small"> · <?= e($s['provider_name'] ?? '') ?></span></span>
              <span class="pill <?= $daysLeft < 0 ? 'pill-down' : ($daysLeft <= 3 ? 'pill-warn' : 'pill-unknown') ?> xs"><?= $daysLeft < 0 ? 'Expired ' . abs($daysLeft) . 'd ago' : ($daysLeft === 0 ? 'Today' : $daysLeft . ' days') ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php layout_end();
