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
    <table class="table table-compact" id="serverTable">
      <thead>
        <tr><th>Server</th><th>Provider</th><th>Status</th><th>Uptime <span class="th-sub">24h · 30d</span></th><th>Response</th><th class="right sticky-col">Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($servers as $s): $id = (int) $s['id']; $m = status_meta($s); $live = in_array($m['status'], ['up', 'down'], true); ?>
        <tr data-server="<?= $id ?>" data-status="<?= e($m['status']) ?>" data-provider="<?= (int) $s['provider_id'] ?>"
            data-search="<?= e(strtolower($s['name'] . ' ' . $s['host'] . ' ' . $s['provider_name'] . ' ' . $s['location'] . ' ' . $s['os'])) ?>">
          <td class="cell-server">
            <a class="cell-title" href="<?= e(url('server.php?id=' . $id)) ?>"><?= e($s['name']) ?></a>
            <div class="cell-sub"><span class="mono"><?= e($s['host']) ?></span><button class="copy-btn" type="button" data-copy="<?= e($s['host']) ?>" title="Copy IP"><?= icon('copy') ?></button>
              <?= $s['ssh_pass'] ? '<span class="key-ok" title="Root password saved (encrypted)">' . icon('key') . '</span>' : '' ?></div>
            <?php if ($s['location']): ?><div class="cell-sub muted"><?= icon('pin') ?><?= e($s['location']) ?></div><?php endif; ?>
          </td>
          <td><?= provider_badge($s['provider_name'], $s['provider_color']) ?></td>
          <td class="nowrap">
            <?= status_pill($s) ?>
            <div class="cell-sub"><span data-role="since-label"><?= $m['status'] === 'down' ? 'Down for' : ($m['status'] === 'up' ? 'Up for' : '') ?></span>
              <b data-role="since" <?= $live ? 'data-since="' . (int) $s['status_since'] . '"' : '' ?>><?= $live ? e(fmt_duration(time() - (int) $s['status_since'])) : '' ?></b></div>
          </td>
          <td class="nowrap">
            <b class="txt-<?= uptime_class($rep24[$id]['uptime'] ?? null) ?>"><?= fmt_pct($rep24[$id]['uptime'] ?? null) ?></b>
            <div class="cell-sub">30d <b class="txt-<?= uptime_class($rep30[$id]['uptime'] ?? null) ?>"><?= fmt_pct($rep30[$id]['uptime'] ?? null) ?></b></div>
          </td>
          <td class="mono nowrap" data-role="ms"><?= $s['last_response_ms'] !== null && $m['status'] === 'up' ? (int) $s['last_response_ms'] . ' ms' : '—' ?></td>
          <td class="right nowrap sticky-col">
            <div class="row-actions">
              <button class="icon-btn sm" type="button" data-check="<?= $id ?>" title="Check now"><?= icon('refresh') ?></button>
              <a class="icon-btn sm" href="<?= e(url('server_edit.php?id=' . $id)) ?>" title="Edit"><?= icon('edit') ?></a>
              <details class="row-menu">
                <summary class="icon-btn sm" title="More"><?= icon('more') ?></summary>
                <div class="menu">
                  <a href="<?= e(url('server.php?id=' . $id)) ?>"><?= icon('external') ?>Open details</a>
                  <?php if ($s['ssh_pass']): ?>
                    <button type="button" data-reveal="server" data-id="<?= $id ?>" data-label="<?= e($s['name']) ?>"><?= icon('key') ?>Show root password</button>
                  <?php else: ?>
                    <a href="<?= e(url('server_edit.php?id=' . $id)) ?>"><?= icon('key') ?>Add root password</a>
                  <?php endif; ?>
                  <button type="button" data-copy="<?= e('ssh ' . ($s['ssh_user'] ?: 'root') . '@' . $s['host'] . ((int) $s['ssh_port'] !== 22 ? ' -p ' . (int) $s['ssh_port'] : '')) ?>"><?= icon('terminal') ?>Copy SSH command</button>
                  <button type="button" data-toggle-server="<?= $id ?>"><?= icon((int) $s['enabled'] ? 'pause' : 'play') ?><?= (int) $s['enabled'] ? 'Pause monitoring' : 'Resume monitoring' ?></button>
                  <form method="post" action="<?= e(url('server_edit.php')) ?>" data-confirm="Delete <?= e($s['name']) ?> and all its history? This cannot be undone.">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="danger"><?= icon('trash') ?>Delete server</button>
                  </form>
                </div>
              </details>
            </div>
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
