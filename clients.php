<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$clients = clients_list();
$byId = [];
foreach ($clients as $c) {
    $byId[(int) $c['id']] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $keys = array_slice(array_map('strval', (array) ($_POST['items'] ?? [])), 0, 2000);
    $to = (int) post('client_id');
    $to = $to && isset($byId[$to]) ? $to : null;
    if (!$keys) {
        flash('error', 'Select at least one server or license first.');
    } else {
        $n = billing_assign($keys, $to);
        audit('billing_assign', $n . ' item(s) → ' . ($to ? $byId[$to]['name'] : 'own use'));
        flash('success', $n . ' item' . ($n === 1 ? '' : 's') . ' now marked as ' . ($to ? 'used by ' . $byId[$to]['name'] : 'own use') . '.');
    }
    $back = post('back');
    redirect(preg_match('~^clients\.php(\?id=(\d+|own))?$~', $back) ? $back : 'clients.php');
}

$items = billing_items();
$view = $_GET['id'] ?? null;               // null = overview, 'own' or a client id = detail
$detail = null;
if ($view !== null) {
    if ($view !== 'own' && !isset($byId[(int) $view])) {
        flash('error', 'Client not found.');
        redirect('clients.php');
    }
    $detail = $view === 'own' ? null : $byId[(int) $view];
}

/** Group helpers */
$ofClient = fn (?int $cid) => array_values(array_filter($items, fn ($it) => $it['client_id'] === $cid));
$count = fn (array $its, string $kind) => count(array_filter($its, fn ($it) => $it['kind'] === $kind));
$byProvider = function (array $its): array {
    $g = [];
    foreach ($its as $it) {
        $k = $it['provider_id'];
        $g[$k] ??= ['name' => $it['provider_name'], 'color' => $it['provider_color'], 'items' => []];
        $g[$k]['items'][] = $it;
    }
    uasort($g, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));
    return $g;
};
$kindMeta = ['license' => ['🔑 License', 'pill-warn'], 'vps' => ['🖥 Server', 'pill-up'], 'other' => ['📦 Other', 'pill-unknown']];
$today = strtotime('today');

/** One table used both for "assign" (overview) and for a single client's items. */
$itemsTable = function (array $its, string $tableId, string $back) use ($byId, $kindMeta, $today, $clients) {
    ob_start(); ?>
  <form method="post" action="<?= e(url('clients.php')) ?>" class="assign-form" id="<?= e($tableId) ?>Form">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="table-wrap">
      <table class="table table-compact" id="<?= e($tableId) ?>">
        <thead><tr><th class="w-check"><input type="checkbox" data-check-all="#<?= e($tableId) ?>" title="Select all shown"></th>
          <th>Server / license</th><th>Type</th><th>Provider (you pay)</th><th>Used by</th><th class="right">Monthly</th><th>Next due</th><th class="right sticky-col"></th></tr></thead>
        <tbody>
        <?php foreach ($its as $it):
            $k = $kindMeta[$it['kind']];
            $cl = $it['client_id'] !== null ? ($byId[$it['client_id']] ?? null) : null;
            $left = $it['next_due'] ? (int) round((strtotime($it['next_due']) - $today) / 86400) : null; ?>
          <tr data-status="<?= e($it['kind']) ?>" data-client="<?= $cl ? (int) $cl['id'] : 'own' ?>" data-provider="<?= (int) $it['provider_id'] ?>"
              data-search="<?= e(strtolower($it['name'] . ' ' . $it['sub'] . ' ' . $it['provider_name'] . ' ' . ($cl['name'] ?? 'own'))) ?>">
            <td class="w-check"><input type="checkbox" name="items[]" value="<?= e($it['key']) ?>" data-row-check></td>
            <td>
              <?php if ($it['server_id']): ?><a class="cell-title" href="<?= e(url('server.php?id=' . $it['server_id'])) ?>"><?= e($it['name']) ?></a>
              <?php else: ?><span class="cell-title"><?= e($it['name']) ?></span><?php endif; ?>
              <div class="cell-sub"><?php if ($it['status']): ?><span class="dot dot-<?= e(in_array($it['status'], ['up', 'down'], true) ? $it['status'] : 'unknown') ?>"></span><?php endif; ?><span class="mono"><?= e($it['sub'] ?: '—') ?></span></div>
            </td>
            <td><span class="pill xs <?= $k[1] ?>"><?= $k[0] ?></span></td>
            <td><?= provider_badge($it['provider_name'], $it['provider_color']) ?></td>
            <td><?= client_badge($cl) ?></td>
            <td class="right nowrap"><?php if ($it['monthly'] === 0.0): ?><span class="muted small">Not recurring</span>
              <?php elseif ($it['monthly'] !== null): ?><b><?= e(money_one($it['monthly'], $it['currency'])) ?></b>
              <?= $it['cycle'] && strtolower((string) $it['cycle']) !== 'monthly' ? '<div class="cell-sub muted">' . e((string) $it['cycle']) . '</div>' : '' ?>
              <?php else: ?><a class="link small" href="<?= e(url($it['edit'])) ?>">Set price</a><?php endif; ?></td>
            <td class="nowrap"><?= $it['next_due'] ? e(date('d M Y', strtotime($it['next_due']))) . ($left !== null && $left <= 7 ? ' <span class="pill xs ' . ($left < 0 ? 'pill-down' : 'pill-warn') . '">' . ($left < 0 ? 'Overdue' : $left . 'd') . '</span>' : '') : '—' ?></td>
            <td class="right sticky-col"><a class="icon-btn sm" href="<?= e(url($it['edit'])) ?>" title="Edit price / details"><?= icon('edit') ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="empty-filter" hidden><?= icon('filter') ?> Nothing matches your filters.</div>
    </div>
    <div class="assign-bar" data-assign-bar hidden>
      <span><b data-selected-count>0</b> selected</span>
      <span class="muted small">Mark as used by</span>
      <select class="input select-sm" name="client_id" form="<?= e($tableId) ?>Form">
        <option value="0">Own use (not a client)</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary sm" type="submit" form="<?= e($tableId) ?>Form"><?= icon('check') ?>Assign</button>
      <button class="btn btn-ghost sm" type="button" data-assign-clear>Clear</button>
    </div>
  </form>
    <?php return ob_get_clean();
};

/* ======================= single client (or own use) ======================= */
if ($view !== null) {
    $its = $ofClient($detail ? (int) $detail['id'] : null);
    $title = $detail ? $detail['name'] : 'Own use';
    $sub = $detail ? trim(($detail['company'] ? $detail['company'] . ' · ' : '') . ($detail['email'] ?: '') . ($detail['phone'] ? ' · ' . $detail['phone'] : ''), ' ·')
                   : 'Servers and licenses you use yourself (not assigned to a client).';
    $up = count(array_filter($its, fn ($it) => $it['kind'] === 'vps' && $it['status'] === 'up'));
    $down = count(array_filter($its, fn ($it) => $it['kind'] === 'vps' && $it['status'] === 'down'));
    layout_start($title, 'clients', e($sub ?: 'Client'),
        '<a class="btn btn-ghost" href="' . e(url('clients.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">All clients</span></a>'
        . ($detail ? '<a class="btn btn-ghost" href="' . e(url('client_edit.php?id=' . (int) $detail['id'])) . '">' . icon('edit') . '<span class="hide-sm">Edit</span></a>' : ''));
    ?>
<section class="report-stats">
  <div class="card glass stat anim-in"><div class="stat-ico ico-green"><?= icon('server') ?></div><div><span class="stat-lbl">Servers</span><b class="stat-val"><?= $count($its, 'vps') ?></b>
    <span class="muted small"><?= $up ?> up<?= $down ? ' · <b class="txt-bad">' . $down . ' down</b>' : '' ?></span></div></div>
  <div class="card glass stat anim-in" style="--d:.05s"><div class="stat-ico ico-amber"><?= icon('key') ?></div><div><span class="stat-lbl">Licenses</span><b class="stat-val"><?= $count($its, 'license') ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.1s"><div class="stat-ico ico-violet"><?= icon('list') ?></div><div><span class="stat-lbl">Other</span><b class="stat-val"><?= $count($its, 'other') ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.15s"><div class="stat-ico ico-cyan"><?= icon('dollar') ?></div><div><span class="stat-lbl">You pay / month</span><b class="stat-val small-val"><?= e(money_fmt(money_totals($its))) ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.2s"><div class="stat-ico ico-red"><?= icon('alert') ?></div><div><span class="stat-lbl">Without price</span><b class="stat-val"><?= items_unpriced($its) ?></b></div></div>
</section>

<div class="card glass anim-in" style="--d:.1s">
  <div class="card-head"><h3><?= icon('building') ?>Paid to which provider</h3></div>
  <?php if (!$its): ?><p class="muted">Nothing assigned yet — go to <a class="link" href="<?= e(url('clients.php#assign')) ?>">All clients → Assign</a> and select servers / licenses.</p>
  <?php else: ?>
  <div class="pay-list">
    <?php foreach ($byProvider($its) as $g): ?>
      <div class="pay-row"><?= provider_badge($g['name'], $g['color']) ?>
        <span class="muted small"><?= $count($g['items'], 'vps') ?> server · <?= $count($g['items'], 'license') ?> license<?= $count($g['items'], 'other') ? ' · ' . $count($g['items'], 'other') . ' other' : '' ?></span>
        <b><?= e(money_fmt(money_totals($g['items']))) ?></b></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($its): ?>
<div class="card glass table-card anim-in" style="--d:.15s">
  <div class="card-head"><h3><?= icon('list') ?>Servers &amp; licenses</h3><span class="muted small">Tick rows to move them to another client</span></div>
  <?= $itemsTable($its, 'clientItems', 'clients.php?id=' . ($detail ? (int) $detail['id'] : 'own')) ?>
</div>
<?php endif; ?>
<?php if ($detail && $detail['notes']): ?><div class="card glass anim-in"><div class="card-head"><h3><?= icon('edit') ?>Notes</h3></div><p class="pre-wrap"><?= e($detail['notes']) ?></p></div><?php endif; ?>
<?php
    layout_end();
    exit;
}

/* ======================= overview ======================= */
$own = $ofClient(null);
$clientItems = array_values(array_filter($items, fn ($it) => $it['client_id'] !== null));
$clientServers = $count($clientItems, 'vps');
$filterProvider = (int) ($_GET['provider'] ?? 0);

layout_start('Clients & Billing', 'clients', 'Which servers and licenses are for which client — and how much you pay each provider every month.',
    '<a class="btn btn-primary" href="' . e(url('client_edit.php')) . '">' . icon('plus') . '<span class="hide-sm">Add client</span></a>');
?>
<section class="report-stats">
  <div class="card glass stat anim-in"><div class="stat-ico ico-cyan"><?= icon('dollar') ?></div><div><span class="stat-lbl">Total / month</span><b class="stat-val small-val"><?= e(money_fmt(money_totals($items), 0)) ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.05s"><div class="stat-ico ico-violet"><?= icon('user') ?></div><div><span class="stat-lbl">Clients</span><b class="stat-val"><?= count($clients) ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.1s"><div class="stat-ico ico-green"><?= icon('server') ?></div><div><span class="stat-lbl">Client servers</span><b class="stat-val"><?= $clientServers ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.15s"><div class="stat-ico ico-amber"><?= icon('shield') ?></div><div><span class="stat-lbl">Own servers / licenses</span><b class="stat-val"><?= $count($own, 'vps') ?> <small>/ <?= $count($own, 'license') ?></small></b></div></div>
  <div class="card glass stat anim-in <?= items_unpriced($items) ? 'stat-alert' : '' ?>" style="--d:.2s"><div class="stat-ico ico-red"><?= icon('alert') ?></div><div><span class="stat-lbl">Without price</span><b class="stat-val"><?= items_unpriced($items) ?></b></div></div>
</section>

<h2 class="section-title anim-in">Who uses what</h2>
<section class="prov-grid client-grid">
  <?php $cards = array_merge(array_map(fn ($c) => ['c' => $c, 'its' => $ofClient((int) $c['id'])], $clients), [['c' => null, 'its' => $own]]);
  foreach ($cards as $i => $card):
      $c = $card['c'];
      $its = $card['its'];
      $up = count(array_filter($its, fn ($it) => $it['kind'] === 'vps' && $it['status'] === 'up'));
      $down = count(array_filter($its, fn ($it) => $it['kind'] === 'vps' && $it['status'] === 'down'));
      $link = url('clients.php?id=' . ($c ? (int) $c['id'] : 'own')); ?>
    <article class="card glass prov-card anim-in" style="--pc:<?= e($c ? safe_color($c['color']) : '#64748b') ?>;--d:<?= $i * 0.04 ?>s">
      <div class="prov-top">
        <div class="prov-logo"><?= $c ? e(initials($c['name'])) : icon('shield') ?></div>
        <div class="min0">
          <h3><a href="<?= e($link) ?>"><?= e($c ? $c['name'] : 'Own use') ?></a></h3>
          <span class="muted small"><?= e($c ? ($c['company'] ?: ($c['email'] ?: 'Client')) : 'Your own servers & licenses') ?></span>
        </div>
        <?php if ($c): ?><div class="prov-actions"><a class="icon-btn sm" href="<?= e(url('client_edit.php?id=' . (int) $c['id'])) ?>" title="Edit client"><?= icon('edit') ?></a></div><?php endif; ?>
      </div>
      <div class="prov-stats">
        <div><span class="lbl">Servers</span><b><?= $count($its, 'vps') ?></b></div>
        <div><span class="lbl">Online</span><b class="txt-good"><?= $up ?></b></div>
        <div><span class="lbl">Offline</span><b class="<?= $down ? 'txt-bad' : '' ?>"><?= $down ?></b></div>
        <div><span class="lbl">Licenses</span><b><?= $count($its, 'license') ?></b></div>
      </div>
      <div class="pay-list compact">
        <?php foreach ($byProvider($its) as $g): ?>
          <div class="pay-row"><?= provider_badge($g['name'], $g['color']) ?><span class="muted small"><?= count($g['items']) ?></span><b><?= e(money_fmt(money_totals($g['items']))) ?></b></div>
        <?php endforeach; ?>
        <?php if (!$its): ?><p class="muted small">Nothing assigned yet.</p><?php endif; ?>
      </div>
      <div class="pay-total"><span>Monthly</span><b><?= e(money_fmt(money_totals($its))) ?></b></div>
      <a class="btn btn-ghost sm" href="<?= e($link) ?>"><?= icon('list') ?>Details</a>
    </article>
  <?php endforeach; ?>
  <a class="card prov-add anim-in" href="<?= e(url('client_edit.php')) ?>"><?= icon('plus') ?><b>Add client</b><span class="muted small">Then tick their servers / licenses below</span></a>
</section>

<div class="card glass table-card anim-in mt-2">
  <div class="card-head"><h3><?= icon('building') ?>Who you pay every month</h3><span class="muted small">Per provider, split by client and own use</span></div>
  <div class="table-wrap">
    <table class="table table-compact">
      <thead><tr><th>Provider</th><th>Servers</th><th>Licenses</th><th class="right">For clients</th><th class="right">Own use</th><th class="right">Total / month</th></tr></thead>
      <tbody>
      <?php foreach ($byProvider($items) as $pid => $g):
          $forClients = array_filter($g['items'], fn ($it) => $it['client_id'] !== null);
          $forOwn = array_filter($g['items'], fn ($it) => $it['client_id'] === null);
          $np = items_unpriced($g['items']); ?>
        <tr>
          <td><?= provider_badge($g['name'], $g['color']) ?><?= $np ? '<div class="cell-sub"><a class="link small" href="' . e(url('clients.php?provider=' . (int) $pid . '#assign')) . '">' . $np . ' without price</a></div>' : '' ?></td>
          <td><?= $count($g['items'], 'vps') ?></td>
          <td><?= $count($g['items'], 'license') ?></td>
          <td class="right nowrap"><?= e(money_fmt(money_totals($forClients))) ?></td>
          <td class="right nowrap"><?= e(money_fmt(money_totals($forOwn))) ?></td>
          <td class="right nowrap"><b><?= e(money_fmt(money_totals($g['items']))) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><th>Total</th><th><?= $count($items, 'vps') ?></th><th><?= $count($items, 'license') ?></th>
        <th class="right nowrap"><?= e(money_fmt(money_totals($clientItems))) ?></th><th class="right nowrap"><?= e(money_fmt(money_totals($own))) ?></th>
        <th class="right nowrap"><?= e(money_fmt(money_totals($items))) ?></th></tr></tfoot>
    </table>
  </div>
</div>

<div class="card glass table-card anim-in" id="assign">
  <div class="card-head"><h3><?= icon('user') ?>Assign servers &amp; licenses to clients</h3><span class="muted small">Tick rows → choose client → Assign. Licenses on a server follow that server.</span></div>
  <?php if (!$items): ?>
    <div class="mini-empty"><?= empty_state('server', 'Nothing to assign yet', 'Add servers or sync a provider first.') ?></div>
  <?php else: ?>
  <div class="toolbar pad-x">
    <div class="search"><?= icon('search') ?><input class="input" type="search" placeholder="Search name, IP, provider, client…" data-filter-input="#assignTable"></div>
    <div class="chips" data-filter-chips="#assignTable">
      <button class="chip active" data-status="all" type="button">All <b><?= count($items) ?></b></button>
      <button class="chip" data-status="vps" type="button">🖥 Servers <b><?= $count($items, 'vps') ?></b></button>
      <button class="chip" data-status="license" type="button">🔑 Licenses <b><?= $count($items, 'license') ?></b></button>
      <?php if ($count($items, 'other')): ?><button class="chip" data-status="other" type="button">📦 Other <b><?= $count($items, 'other') ?></b></button><?php endif; ?>
    </div>
    <select class="input select-sm" data-filter-select="#assignTable" data-filter-key="client">
      <option value="all">Any client</option>
      <option value="own">Own use</option>
      <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
    </select>
    <select class="input select-sm" data-filter-provider="#assignTable">
      <option value="">All providers</option>
      <?php foreach ($byProvider($items) as $pid => $g): ?><option value="<?= (int) $pid ?>" <?= $filterProvider === (int) $pid ? 'selected' : '' ?>><?= e($g['name'] ?: 'No provider') ?></option><?php endforeach; ?>
    </select>
  </div>
  <?= $itemsTable($items, 'assignTable', 'clients.php') ?>
  <?php endif; ?>
</div>
<p class="muted small">Prices come from the provider sync. Where a provider gives no price (e.g. OVHcloud API) click <b>Set price</b> once — the next sync keeps it. Shared licenses (e.g. SharedLicense): add them as a provider, then <a class="link" href="<?= e(url('service_edit.php')) ?>">Add license</a>.</p>
<?php layout_end();
