<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$servers = rows('SELECT s.*, p.name AS provider_name, p.color AS provider_color
                 FROM servers s LEFT JOIN providers p ON p.id = s.provider_id ORDER BY p.name, s.name');
$rep24 = [];
foreach (build_report(time() - 86400, time())['servers'] as $r) {
    $rep24[(int) $r['id']] = $r;
}
$rep30 = [];
foreach (build_report(strtotime('today -29 days'), time())['servers'] as $r) {
    $rep30[(int) $r['id']] = $r;
}
$providers = rows('SELECT id, name FROM providers ORDER BY name');

layout_start('Servers', 'servers', 'All VPS & dedicated servers with encrypted root credentials.',
    '<a class="btn btn-ghost" href="' . e(url('server_edit.php')) . '">' . icon('plus') . '<span class="hide-sm">Add server</span></a>');
?>
<?php if (!$servers): ?>
  <div class="card glass anim-in"><?= empty_state('server', 'No servers yet', 'Add a server with its IP, provider and root login — passwords are encrypted with AES-256-GCM.',
      '<a class="btn btn-primary" href="' . e(url('server_edit.php')) . '">' . icon('plus') . 'Add your first server</a>') ?></div>
<?php else: ?>
<section class="toolbar anim-in">
  <div class="search"><?= icon('search') ?><input class="input" type="search" placeholder="Search servers…" data-filter-input="#serverTable"></div>
  <div class="chips" data-filter-chips="#serverTable">
    <button class="chip active" data-status="all" type="button">All</button>
    <button class="chip" data-status="up" type="button"><span class="dot dot-up"></span>Online</button>
    <button class="chip" data-status="down" type="button"><span class="dot dot-down"></span>Offline</button>
    <button class="chip" data-status="paused" type="button"><span class="dot dot-paused"></span>Paused</button>
  </div>
  <select class="input select-sm" data-filter-provider="#serverTable">
    <option value="">All providers</option>
    <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option><?php endforeach; ?>
  </select>
</section>

<div class="card glass table-card anim-in">
  <div class="table-wrap">
    <table class="table" id="serverTable">
      <thead>
        <tr><th>Server</th><th>Provider</th><th>Status</th><th>Up / Down for</th><th>Uptime 24h</th><th>Uptime 30d</th><th>Response</th><th>Root access</th><th class="right">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($servers as $s): $id = (int) $s['id']; $m = status_meta($s); ?>
        <tr data-server="<?= $id ?>" data-status="<?= e($m['status']) ?>" data-provider="<?= (int) $s['provider_id'] ?>"
            data-search="<?= e(strtolower($s['name'] . ' ' . $s['host'] . ' ' . $s['provider_name'] . ' ' . $s['location'] . ' ' . $s['os'])) ?>">
          <td>
            <a class="cell-title" href="<?= e(url('server.php?id=' . $id)) ?>"><?= e($s['name']) ?></a>
            <div class="cell-sub"><span class="mono"><?= e($s['host']) ?></span><button class="copy-btn" type="button" data-copy="<?= e($s['host']) ?>"><?= icon('copy') ?></button>
              <?= $s['location'] ? '<span class="muted">· ' . e($s['location']) . '</span>' : '' ?></div>
          </td>
          <td><?= provider_badge($s['provider_name'], $s['provider_color']) ?></td>
          <td><?= status_pill($s) ?></td>
          <td><b data-role="since" <?= in_array($m['status'], ['up', 'down'], true) ? 'data-since="' . (int) $s['status_since'] . '"' : '' ?>><?= in_array($m['status'], ['up', 'down'], true) ? e(fmt_duration(time() - (int) $s['status_since'])) : '—' ?></b></td>
          <td><b class="txt-<?= uptime_class($rep24[$id]['uptime'] ?? null) ?>"><?= fmt_pct($rep24[$id]['uptime'] ?? null) ?></b></td>
          <td><b class="txt-<?= uptime_class($rep30[$id]['uptime'] ?? null) ?>"><?= fmt_pct($rep30[$id]['uptime'] ?? null) ?></b></td>
          <td class="mono" data-role="ms"><?= $s['last_response_ms'] !== null && $m['status'] === 'up' ? (int) $s['last_response_ms'] . ' ms' : '—' ?></td>
          <td>
            <div class="cred-mini">
              <span class="mono"><?= e($s['ssh_user'] ?: 'root') ?>@:<?= (int) $s['ssh_port'] ?></span>
              <?php if ($s['ssh_pass']): ?>
                <button class="icon-btn xs" type="button" data-reveal="server" data-id="<?= $id ?>" data-label="<?= e($s['name']) ?>" title="Reveal password"><?= icon('key') ?></button>
              <?php else: ?><span class="muted small">no pass</span><?php endif; ?>
            </div>
          </td>
          <td class="right nowrap">
            <button class="icon-btn sm" type="button" data-check="<?= $id ?>" title="Check now"><?= icon('refresh') ?></button>
            <button class="icon-btn sm" type="button" data-toggle-server="<?= $id ?>" title="<?= (int) $s['enabled'] ? 'Pause monitoring' : 'Resume monitoring' ?>"><?= icon((int) $s['enabled'] ? 'pause' : 'play') ?></button>
            <a class="icon-btn sm" href="<?= e(url('server_edit.php?id=' . $id)) ?>" title="Edit"><?= icon('edit') ?></a>
            <form method="post" action="<?= e(url('server_edit.php')) ?>" class="inline" data-confirm="Delete <?= e($s['name']) ?> and all its history? This cannot be undone.">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
              <button class="icon-btn sm danger" type="submit" title="Delete"><?= icon('trash') ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="empty-filter" hidden><?= icon('filter') ?> No servers match your filters.</div>
  </div>
</div>
<?php endif; ?>
<?php layout_end();
