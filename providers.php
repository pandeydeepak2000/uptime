<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$providers = rows('SELECT p.*, COUNT(s.id) AS total,
                          SUM(CASE WHEN s.status = \'up\' AND s.enabled = 1 THEN 1 ELSE 0 END) AS up_count,
                          SUM(CASE WHEN s.status = \'down\' AND s.enabled = 1 THEN 1 ELSE 0 END) AS down_count,
                          SUM(CASE WHEN s.enabled = 0 THEN 1 ELSE 0 END) AS paused_count
                   FROM providers p LEFT JOIN servers s ON s.provider_id = p.id
                   GROUP BY p.id, p.name, p.website, p.panel_url, p.account_user, p.account_pass, p.support_email, p.color, p.notes, p.created_at,
                            p.sync_enabled, p.last_sync_at, p.last_sync_ok, p.last_sync_status
                   ORDER BY p.name');
$uptime = [];
foreach (build_report(strtotime('today -29 days'), time())['servers'] as $r) {
    if ($r['provider_id'] && $r['uptime'] !== null && (int) $r['enabled'] === 1) {
        $uptime[(int) $r['provider_id']][] = $r['uptime'];
    }
}
$svcCount = [];
foreach (rows('SELECT provider_id, COUNT(*) AS c FROM provider_services GROUP BY provider_id') as $c) {
    $svcCount[(int) $c['provider_id']] = (int) $c['c'];
}
$cost = [];
foreach (rows('SELECT provider_id, currency, SUM(monthly_cost) AS total FROM servers WHERE monthly_cost IS NOT NULL GROUP BY provider_id, currency') as $c) {
    $cost[(int) $c['provider_id']][] = $c['currency'] . ' ' . number_format((float) $c['total'], 2);
}

layout_start('Providers', 'providers', 'Hosting companies and their client-area logins.',
    '<a class="btn btn-ghost" href="' . e(url('provider_edit.php')) . '">' . icon('plus') . '<span class="hide-sm">Add provider</span></a>');
?>
<?php if (!$providers): ?>
  <div class="card glass"><?= empty_state('building', 'No providers', 'Add EricHost, ServerBroker, Servica or any other host.', '<a class="btn btn-primary" href="' . e(url('provider_edit.php')) . '">' . icon('plus') . 'Add provider</a>') ?></div>
<?php else: ?>
<section class="prov-grid">
  <?php foreach ($providers as $i => $p):
      $pid = (int) $p['id'];
      $tot = (int) $p['total'];
      $up = (int) $p['up_count'];
      $down = (int) $p['down_count'];
      $avg = !empty($uptime[$pid]) ? array_sum($uptime[$pid]) / count($uptime[$pid]) : null;
      ?>
    <article class="card glass prov-card anim-in" style="--pc:<?= e(safe_color($p['color'])) ?>;--d:<?= $i * 0.05 ?>s">
      <div class="prov-top">
        <div class="prov-logo"><?= e(initials($p['name'])) ?></div>
        <div class="min0">
          <h3><?= e($p['name']) ?></h3>
          <?php if ($p['website']): ?><a class="link small" href="<?= e($p['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('~^https?://~', '', $p['website'])) ?> ↗</a><?php else: ?><span class="muted small">No website</span><?php endif; ?>
        </div>
        <div class="prov-actions">
          <a class="icon-btn sm" href="<?= e(url('provider_edit.php?id=' . $pid)) ?>" title="Edit"><?= icon('edit') ?></a>
        </div>
      </div>
      <div class="prov-stats">
        <div><span class="lbl">Servers</span><b><?= $tot ?></b></div>
        <div><span class="lbl">Online</span><b class="txt-good"><?= $up ?></b></div>
        <div><span class="lbl">Offline</span><b class="<?= $down ? 'txt-bad' : '' ?>"><?= $down ?></b></div>
        <div><span class="lbl">30d uptime</span><b class="txt-<?= uptime_class($avg) ?>"><?= fmt_pct($avg) ?></b></div>
      </div>
      <div class="prov-bar lg"><span style="width:<?= $tot ? round(100 * $up / $tot) : 0 ?>%"></span><span class="bad" style="width:<?= $tot ? round(100 * $down / $tot) : 0 ?>%"></span></div>
      <dl class="kv compact">
        <dt>Client area</dt><dd><?= $p['panel_url'] ? '<a class="link" href="' . e($p['panel_url']) . '" target="_blank" rel="noopener noreferrer">Open panel ↗</a>' : '—' ?></dd>
        <dt>Login</dt><dd><?php if ($p['account_user']): ?><span class="mono"><?= e($p['account_user']) ?></span><button class="copy-btn" type="button" data-copy="<?= e($p['account_user']) ?>"><?= icon('copy') ?></button><?php else: ?>—<?php endif; ?>
          <?php if ($p['account_pass']): ?><button class="btn btn-ghost xs" type="button" data-reveal="provider" data-id="<?= $pid ?>" data-label="<?= e($p['name']) ?> panel"><?= icon('key') ?>Password</button><?php endif; ?></dd>
        <dt>Support</dt><dd><?= $p['support_email'] ? '<span class="mono small">' . e($p['support_email']) . '</span>' : '—' ?></dd>
        <dt>Monthly</dt><dd><?= !empty($cost[$pid]) ? e(implode(' + ', $cost[$pid])) : '—' ?></dd>
      </dl>
      <div class="sync-box <?= $p['last_sync_at'] ? ((int) $p['last_sync_ok'] ? 'ok' : 'bad') : '' ?>">
        <div class="sync-head">
          <span><?= icon('refresh') ?><b>Client-area sync</b> <?= (int) $p['sync_enabled'] ? '<span class="pill pill-up xs">Auto daily</span>' : '<span class="pill pill-unknown xs">Manual</span>' ?></span>
          <span class="muted small"><?= (int) ($svcCount[$pid] ?? 0) ?> service(s)</span>
        </div>
        <p class="small sync-status"><?= $p['last_sync_at'] ? e((string) $p['last_sync_status']) . ' <span class="muted">· <span data-ago="' . (int) $p['last_sync_at'] . '">' . e(time_ago($p['last_sync_at'])) . '</span></span>' : '<span class="muted">Never synced. Works with WHMCS client areas (no CAPTCHA / 2FA).</span>' ?></p>
        <div class="sync-trace" hidden></div>
        <div class="row-gap">
          <button class="btn btn-ghost sm" type="button" data-sync-provider="<?= $pid ?>" <?= $p['account_pass'] && $p['panel_url'] ? '' : 'disabled title="Save client area URL, username and password first"' ?>><?= icon('refresh') ?>Sync now</button>
          <a class="btn btn-ghost sm" href="<?= e(url('provider_import.php?id=' . $pid)) ?>" title="For providers with CAPTCHA / 2FA"><?= icon('download') ?>Import saved page</a>
        </div>
      </div>
      <div class="prov-foot">
        <a class="btn btn-ghost sm" href="<?= e(url('servers.php')) ?>"><?= icon('server') ?>Servers</a>
        <a class="btn btn-ghost sm" href="<?= e(url('server_edit.php?provider=' . $pid)) ?>"><?= icon('plus') ?>Add server</a>
      </div>
    </article>
  <?php endforeach; ?>
  <a class="card prov-add anim-in" href="<?= e(url('provider_edit.php')) ?>"><?= icon('plus') ?><span>Add provider</span></a>
</section>
<?php endif; ?>
<?php layout_end();
