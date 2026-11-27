<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
require_perm('edit');

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$svc = $id ? row('SELECT * FROM provider_services WHERE id = ?', [$id]) : null;
if ($id && !$svc) {
    flash('error', 'Service not found.');
    redirect('services.php');
}
$synced = $svc && $svc['source'] !== 'manual';
// OVH's API gives no price, and some client areas hide it: then you can type it once (the sync keeps it)
$priceEditable = !$synced || $svc['source'] === 'ovh' || $svc['amount'] === null;
$providers = rows('SELECT id, name FROM providers ORDER BY name');
$servers = rows('SELECT id, name, host FROM servers ORDER BY name');
$errors = [];
$kinds = ['license' => '🔑 License (cPanel, LiteSpeed, CloudLinux…)', 'vps' => '🖥 VPS / dedicated server', 'reseller' => '🏢 Hosting / reseller account (no root)', 'other' => '📦 Other service'];
$cycles = ['Monthly', 'Quarterly', 'Semi-Annually', 'Annually', 'Biennially', 'One Time'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'delete' && $svc) {
        require_perm('admin');
        if ($synced && $svc['remote_id'] !== null && $svc['provider_id']) {
            // remember it, otherwise the next sync / import would bring it straight back
            db_insert('ignored_services', ['provider_id' => (int) $svc['provider_id'], 'source' => $svc['source'],
                'remote_id' => (string) $svc['remote_id'], 'name' => substr((string) $svc['name'], 0, 190), 'created_at' => time()]);
        }
        q('DELETE FROM provider_services WHERE id = ?', [$id]);
        audit('service_deleted', $svc['name'] . ($synced ? ' (synced — hidden from future syncs)' : ''));
        flash('success', 'Deleted "' . $svc['name'] . '"' . ($synced ? ' — it will not come back on the next sync.' : '.'));
        redirect('services.php');
    }
    if ($action === 'restore_hidden') {
        require_perm('admin');
        $n = q('DELETE FROM ignored_services')->rowCount();
        audit('service_restored', $n . ' hidden synced service(s) restored');
        flash('success', $n . ' deleted synced service(s) will come back on the next sync / import.');
        redirect('services.php');
    }
    if ($action === 'renew' && $svc && !$synced) {
        $months = (int) post('months');
        if (in_array($months, [1, 3, 6, 12], true)) {
            $next = add_months($svc['next_due'] ?: date('Y-m-d'), $months);
            db_update('provider_services', ['next_due' => $next], 'id = ?', [$id]);
            audit('server_renewed', $svc['name'] . ' → ' . $next . " (+$months mo)");
            flash('success', 'Marked as renewed — next due ' . date('d M Y', strtotime($next)) . '.');
        }
        redirect('service_edit.php?id=' . $id);
    }

    $kind = post('kind', 'license');
    $serverId = (int) post('server_id') ?: null;
    if (!array_key_exists($kind, $kinds)) {
        $errors[] = 'Invalid type.';
    }
    if ($serverId && !val('SELECT id FROM servers WHERE id = ?', [$serverId])) {
        $serverId = null;
    }
    $cid = (int) post('client_id');
    $d = ['kind' => $kind, 'kind_locked' => $svc && $svc['kind'] !== $kind ? 1 : (int) ($svc['kind_locked'] ?? 0), 'server_id' => $serverId, 'notes' => post('notes', '', 3000) ?: null,
          'client_id' => $cid && val('SELECT id FROM clients WHERE id = ?', [$cid]) ? $cid : null];
    if ($synced && $priceEditable) {
        $d += [
            'amount'   => post('amount') !== '' ? round((float) post('amount'), 2) : null,
            'currency' => in_array(post('currency'), CURRENCIES, true) ? post('currency') : 'INR',
            'cycle'    => in_array(post('cycle'), $cycles, true) ? post('cycle') : 'Monthly',
        ];
    }
    if (!$synced) {
        $d += [
            'name'        => post('name', '', 190),
            'provider_id' => (int) post('provider_id') ?: null,
            'ip'          => post('ip', '', 64) ?: null,
            'status'      => post('status', 'Active', 32) ?: null,
            'amount'      => post('amount') !== '' ? round((float) post('amount'), 2) : null,
            'currency'    => strtoupper(post('currency', 'INR', 8)) ?: 'INR',
            'cycle'       => in_array(post('cycle'), $cycles, true) ? post('cycle') : 'Monthly',
            'next_due'    => post('next_due') ?: null,
        ];
        if ($d['name'] === '') {
            $errors[] = 'Name is required (e.g. “cPanel license – Web-01”).';
        }
        if ($d['ip'] && !filter_var($d['ip'], FILTER_VALIDATE_IP)) {
            $errors[] = 'IP address is not valid.';
        }
        if ($d['next_due'] && !DateTime::createFromFormat('Y-m-d', $d['next_due'])) {
            $errors[] = 'Invalid next due date.';
        }
        if (!$d['ip'] && $serverId) {
            $d['ip'] = (string) val('SELECT host FROM servers WHERE id = ?', [$serverId]);
            if (!filter_var($d['ip'], FILTER_VALIDATE_IP)) {
                $d['ip'] = null;
            }
        }
    }
    if (!$errors) {
        if ($svc) {
            db_update('provider_services', $d, 'id = ?', [$id]);
            audit('service_updated', $svc['name']);
            flash('success', 'Saved.');
        } else {
            $d += ['source' => 'manual', 'created_at' => time()];
            $id = db_insert('provider_services', $d);
            audit('service_added', $d['name']);
            flash('success', 'Added "' . $d['name'] . '" — you will get a Telegram reminder before it is due.');
        }
        if ($serverId && $kind !== 'license' && (!$svc || ($svc['client_id'] ?? null) != $d['client_id'])) {
            assign_server_client($serverId, $d['client_id']); // the VPS service and its server share one client
        }
        redirect('services.php');
    }
    $svc = array_merge($svc ?? [], $d);
}

$s = $svc ?? ['kind' => 'license', 'currency' => 'INR', 'cycle' => 'Monthly', 'status' => 'Active', 'server_id' => (int) ($_GET['server'] ?? 0), 'provider_id' => 0];
$v = fn ($k) => e((string) ($s[$k] ?? ''));
$isEdit = !empty($svc['id']);

layout_start($isEdit ? 'Edit service' : 'Add license / service', 'services',
    $synced ? 'Synced from the provider’s client area — price and due date update automatically.' : 'Track a license or service and get Telegram reminders before renewal.',
    '<a class="btn btn-ghost" href="' . e(url('services.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">Back</span></a>');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('alert') ?><?= e($er) ?></div><?php endforeach; ?>
<div class="narrow">
<form method="post" class="card glass anim-in" autocomplete="off">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $svc['id'] ?>"><?php endif; ?>
  <?php if ($synced): ?>
    <div class="callout"><div class="callout-title"><?= icon('refresh') ?><?= e($s['name']) ?></div>
      <p class="muted small">Price <?= $s['amount'] !== null ? e(($s['currency'] ?? '') . ' ' . number_format((float) $s['amount'], 2) . ' ' . ($s['cycle'] ?? '')) : '—' ?> ·
        next due <?= $s['next_due'] ? e(date('d M Y', strtotime($s['next_due']))) : '—' ?> · status <?= e($s['status'] ?? '—') ?> · IP <?= e($s['ip'] ?? '—') ?></p></div>
  <?php endif; ?>
  <div class="form-grid">
    <?php if (!$synced): ?>
      <div class="field col-2"><label>Name *</label><input class="input" name="name" value="<?= $v('name') ?>" placeholder="cPanel license – Web-01" required></div>
    <?php endif; ?>
    <div class="field"><label>Type</label>
      <select class="input" name="kind"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>" <?= ($s['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Used on server</label>
      <select class="input" name="server_id"><option value="">— None —</option>
        <?php foreach ($servers as $sv): ?><option value="<?= (int) $sv['id'] ?>" <?= (int) ($s['server_id'] ?? 0) === (int) $sv['id'] ? 'selected' : '' ?>><?= e($sv['name'] . ' (' . $sv['host'] . ')') ?></option><?php endforeach; ?>
      </select></div>
    <div class="field col-2"><label>Used by (client) <a class="hint link" href="<?= e(url('client_edit.php')) ?>">+ Add client</a></label>
      <select class="input" name="client_id"><?= client_select_options(isset($s['client_id']) ? (int) $s['client_id'] : null) ?></select></div>
    <?php if ($synced && $priceEditable): ?>
      <div class="field"><label>Price <span class="muted small">(provider sync gives none — kept on next sync)</span></label><input class="input" type="number" step="0.01" min="0" name="amount" value="<?= $v('amount') ?>" placeholder="0.00"></div>
      <div class="field"><label>Currency / cycle</label>
        <div class="row-gap">
          <select class="input select-sm" name="currency"><?php foreach (CURRENCIES as $c): ?><option <?= ($s['currency'] ?: 'INR') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
          <select class="input select-sm" name="cycle"><?php foreach ($cycles as $c): ?><option <?= ($s['cycle'] ?: 'Monthly') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
        </div></div>
    <?php endif; ?>
    <?php if (!$synced): ?>
      <div class="field"><label>Bought from (provider)</label>
        <select class="input" name="provider_id"><option value="">— None —</option>
          <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($s['provider_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
        </select><a class="hint link" href="<?= e(url('provider_edit.php')) ?>">+ Add provider (e.g. SharedLicense)</a></div>
      <div class="field"><label>License IP <span class="muted small">(blank = server's IP)</span></label><input class="input mono" name="ip" value="<?= $v('ip') ?>" placeholder="203.0.113.10"></div>
      <div class="field"><label>Price</label><input class="input" type="number" step="0.01" min="0" name="amount" value="<?= $v('amount') ?>" placeholder="0.00"></div>
      <div class="field"><label>Currency / cycle</label>
        <div class="row-gap">
          <select class="input select-sm" name="currency"><?php foreach (CURRENCIES as $c): ?><option <?= ($s['currency'] ?? 'INR') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
          <select class="input select-sm" name="cycle"><?php foreach ($cycles as $c): ?><option <?= ($s['cycle'] ?? 'Monthly') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select>
        </div></div>
      <div class="field"><label>Next due date</label><input class="input" type="date" name="next_due" value="<?= $v('next_due') ?>"></div>
      <div class="field"><label>Status</label>
        <select class="input" name="status"><?php foreach (['Active', 'Suspended', 'Cancelled'] as $st): ?><option <?= ($s['status'] ?? 'Active') === $st ? 'selected' : '' ?>><?= $st ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="field col-2"><label>Notes</label><textarea class="input" name="notes" rows="3" placeholder="License key / order ID / where to renew…"><?= $v('notes') ?></textarea></div>
  </div>
  <div class="form-actions"><button class="btn btn-primary lg" type="submit"><?= icon('check') ?><?= $isEdit ? 'Save' : 'Add' ?></button></div>
</form>

<?php if ($isEdit && !$synced): ?>
<form method="post" class="card glass anim-in save-card" data-confirm="Mark <?= e($s['name']) ?> as renewed?">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $svc['id'] ?>"><input type="hidden" name="action" value="renew">
  <div><b>Paid the renewal?</b><p class="muted small">Moves the next due date forward from <?= $s['next_due'] ? e(date('d M Y', strtotime($s['next_due']))) : 'today' ?>.</p></div>
  <div class="renew-form">
    <button class="btn btn-ghost sm" name="months" value="1" type="submit">+1 month</button>
    <button class="btn btn-ghost sm" name="months" value="3" type="submit">+3 months</button>
    <button class="btn btn-ghost sm" name="months" value="12" type="submit">+1 year</button>
  </div>
</form>
<?php endif; ?>
</div>
<?php layout_end();
