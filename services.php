<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$list = rows('SELECT ps.*, p.name AS provider_name, p.color AS provider_color, p.panel_url, s.name AS server_name, s.host AS server_host
              FROM provider_services ps
              LEFT JOIN providers p ON p.id = ps.provider_id
              LEFT JOIN servers s ON s.id = ps.server_id
              ORDER BY CASE WHEN ps.next_due IS NULL OR ps.next_due = \'\' THEN 1 ELSE 0 END, ps.next_due, ps.name');
$providers = rows('SELECT id, name FROM providers ORDER BY name');
$today = strtotime('today');
$states = [];
foreach ($list as $s) {
    $k = service_state($s['status']);
    $states[$k] = ($states[$k] ?? 0) + 1;
}
$counts = ['all' => count($list), 'license' => 0, 'vps' => 0, 'other' => 0, 'due7' => 0];
$spend = [];
foreach ($list as $s) {
    $counts[$s['kind']] = ($counts[$s['kind']] ?? 0) + 1;
    if ($s['next_due'] && strtotime($s['next_due']) - $today <= 7 * 86400 && !in_array(strtolower((string) $s['status']), ['cancelled', 'terminated'], true)) {
        $counts['due7']++;
    }
    if ($s['amount'] !== null && !in_array(strtolower((string) $s['status']), ['cancelled', 'terminated'], true)) {
        $div = ['monthly' => 1, 'quarterly' => 3, 'semi-annually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36][strtolower((string) $s['cycle'])] ?? 1;
        $cur = $s['currency'] ?: 'INR';
        $spend[$cur] = ($spend[$cur] ?? 0) + (float) $s['amount'] / $div;
    }
}
$canMonitor = fn ($s) => $s['kind'] === 'vps' && !$s['server_id'] && ($s['ip'] || $s['hostname'])
    && in_array(strtolower((string) ($s['status'] ?: 'active')), ['active', 'suspended'], true);
$unmonitored = count(array_filter($list, $canMonitor));
$kindMeta = ['license' => ['🔑 License', 'pill-warn'], 'vps' => ['🖥 VPS', 'pill-up'], 'other' => ['📦 Other', 'pill-unknown']];

layout_start('Services & Licenses', 'services', 'Everything you pay for — synced from provider client areas or added by hand.',
    '<a class="btn btn-ghost" href="' . e(url('service_edit.php')) . '">' . icon('plus') . '<span class="hide-sm">Add license</span></a>');
?>
<section class="report-stats">
  <div class="card glass stat anim-in"><div class="stat-ico ico-violet"><?= icon('list') ?></div><div><span class="stat-lbl">Total</span><b class="stat-val"><?= $counts['all'] ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.05s"><div class="stat-ico ico-amber"><?= icon('key') ?></div><div><span class="stat-lbl">Licenses</span><b class="stat-val"><?= $counts['license'] ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.1s"><div class="stat-ico ico-green"><?= icon('server') ?></div><div><span class="stat-lbl">VPS services</span><b class="stat-val"><?= $counts['vps'] ?></b></div></div>
  <div class="card glass stat anim-in <?= $counts['due7'] ? 'stat-alert' : '' ?>" style="--d:.15s"><div class="stat-ico ico-red"><?= icon('calendar') ?></div><div><span class="stat-lbl">Due in 7 days</span><b class="stat-val"><?= $counts['due7'] ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.2s"><div class="stat-ico ico-cyan"><?= icon('dollar') ?></div><div><span class="stat-lbl">Monthly spend</span><b class="stat-val small-val"><?= $spend ? e(implode(' + ', array_map(fn ($c, $v) => $c . ' ' . number_format($v, 0), array_keys($spend), $spend))) : '—' ?></b></div></div>
</section>

<?php if ($unmonitored): ?>
  <div class="card glass monitor-banner anim-in">
    <div class="mb-text"><?= icon('server') ?><div><b><?= $unmonitored ?> VPS from your providers <?= $unmonitored > 1 ? 'are' : 'is' ?> not monitored yet.</b>
      <p class="muted small">Add them to uptime monitoring in one click — IP, provider, price and next due date are filled in automatically. Root password can be added later.</p></div></div>
    <button class="btn btn-primary" type="button" data-monitor-all><?= icon('plus') ?>Add all <?= $unmonitored ?> to monitoring</button>
  </div>
<?php endif; ?>

<?php if (!$list): ?>
  <div class="card glass anim-in"><?= empty_state('key', 'No services or licenses yet',
      'Turn on “Auto-sync” for a provider (WHMCS client area) or add licenses like cPanel / LiteSpeed by hand to get renewal reminders on Telegram.',
      '<a class="btn btn-primary" href="' . e(url('service_edit.php')) . '">' . icon('plus') . 'Add license</a>') ?></div>
<?php else: ?>
<section class="toolbar anim-in">
  <div class="search"><?= icon('search') ?><input class="input" type="search" placeholder="Search name, IP, provider…" data-filter-input="#svcTable"></div>
  <div class="chips" data-filter-chips="#svcTable">
    <button class="chip active" data-status="all" type="button">All <b><?= $counts['all'] ?></b></button>
    <button class="chip" data-status="license" type="button">🔑 Licenses <b><?= $counts['license'] ?></b></button>
    <button class="chip" data-status="vps" type="button">🖥 VPS <b><?= $counts['vps'] ?></b></button>
    <button class="chip" data-status="other" type="button">📦 Other <b><?= $counts['other'] ?></b></button>
  </div>
  <div class="chips" data-filter-chips="#svcTable" data-filter-key="state">
    <button class="chip active" data-status="all" type="button">Any status</button>
    <button class="chip" data-status="active" type="button"><span class="dot dot-up"></span>Active <b><?= $states['active'] ?? 0 ?></b></button>
    <button class="chip" data-status="suspended" type="button"><span class="dot dot-warn"></span>Suspended <b><?= $states['suspended'] ?? 0 ?></b></button>
    <?php if (!empty($states['pending'])): ?><button class="chip" data-status="pending" type="button">Pending <b><?= $states['pending'] ?></b></button><?php endif; ?>
    <?php if (!empty($states['ended'])): ?><button class="chip" data-status="ended" type="button">Cancelled <b><?= $states['ended'] ?></b></button><?php endif; ?>
  </div>
  <select class="input select-sm" data-filter-provider="#svcTable">
    <option value="">All providers</option>
    <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
</section>
<div class="card glass table-card anim-in">
  <div class="table-wrap">
    <table class="table" id="svcTable">
      <thead><tr><th>Service</th><th>Type</th><th>Provider</th><th>Server / IP</th><th>Status</th><th>Price</th><th>Next due</th><th class="right sticky-col">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($list as $s):
          $k = $kindMeta[$s['kind']] ?? $kindMeta['other'];
          $left = $s['next_due'] ? (int) round((strtotime($s['next_due']) - $today) / 86400) : null;
          $dead = in_array(strtolower((string) $s['status']), ['cancelled', 'terminated'], true);
          ?>
        <tr data-status="<?= e($s['kind']) ?>" data-state="<?= e(service_state($s['status'])) ?>" data-provider="<?= (int) $s['provider_id'] ?>"
            data-search="<?= e(strtolower($s['name'] . ' ' . $s['ip'] . ' ' . $s['hostname'] . ' ' . $s['provider_name'] . ' ' . $s['server_name'])) ?>" class="<?= $dead ? 'row-dim' : '' ?>">
          <td>
            <span class="cell-title"><?= e($s['name']) ?></span>
            <div class="cell-sub"><?= $s['source'] !== 'manual' ? '<span class="pill pill-up xs">' . icon('refresh') . 'Synced</span>' : '<span class="pill pill-unknown xs">Manual</span>' ?>
              <?= $s['hostname'] ? '<span class="mono">' . e($s['hostname']) . '</span>' : '' ?></div>
          </td>
          <td><span class="pill xs <?= $k[1] ?>"><?= $k[0] ?></span></td>
          <td><?= provider_badge($s['provider_name'], $s['provider_color']) ?></td>
          <td>
            <?php if ($s['server_id']): ?><a class="cell-title" href="<?= e(url('server.php?id=' . (int) $s['server_id'])) ?>"><?= e($s['server_name']) ?></a><?php endif; ?>
            <div class="cell-sub mono"><?= e($s['ip'] ?: ($s['server_host'] ?: '—')) ?></div>
          </td>
          <td><?= $s['status'] ? '<span class="pill xs ' . ($dead ? 'pill-down' : (strtolower($s['status']) === 'active' ? 'pill-up' : 'pill-warn')) . '">' . e($s['status']) . '</span>' : '—' ?></td>
          <td class="nowrap"><?= $s['amount'] !== null ? e(($s['currency'] ?: '') . ' ' . number_format((float) $s['amount'], 2)) . '<div class="cell-sub">' . e((string) $s['cycle']) . '</div>' : '—' ?></td>
          <td class="nowrap">
            <?php if ($s['next_due']): ?>
              <?= e(date('d M Y', strtotime($s['next_due']))) ?>
              <div><span class="pill xs <?= $dead ? 'pill-unknown' : ($left < 0 ? 'pill-down' : ($left <= 3 ? 'pill-warn' : 'pill-unknown')) ?>"><?= $left < 0 ? 'Overdue ' . abs($left) . 'd' : ($left === 0 ? 'Due today' : $left . ' days') ?></span></div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="right nowrap sticky-col">
            <?php if ($canMonitor($s)): ?><button class="btn btn-ghost xs" type="button" data-monitor-service="<?= (int) $s['id'] ?>" title="Add this VPS to uptime monitoring"><?= icon('plus') ?>Monitor</button><?php endif; ?>
            <?php if ($s['panel_url']): ?><a class="icon-btn sm" href="<?= e($s['panel_url']) ?>" target="_blank" rel="noopener noreferrer" title="Open client area"><?= icon('external') ?></a><?php endif; ?>
            <a class="icon-btn sm" href="<?= e(url('service_edit.php?id=' . (int) $s['id'])) ?>" title="Edit"><?= icon('edit') ?></a>
            <?php if ($s['source'] === 'manual'): ?>
              <form method="post" action="<?= e(url('service_edit.php')) ?>" class="inline" data-confirm="Delete <?= e($s['name']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="icon-btn sm danger" type="submit" title="Delete"><?= icon('trash') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="empty-filter" hidden><?= icon('filter') ?> Nothing matches your filters.</div>
  </div>
</div>
<?php endif; ?>
<?php layout_end();
