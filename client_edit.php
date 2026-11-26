<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$client = $id ? row('SELECT * FROM clients WHERE id = ?', [$id]) : null;
if ($id && !$client) {
    flash('error', 'Client not found.');
    redirect('clients.php');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'delete' && $client) {
        q('UPDATE servers SET client_id = NULL WHERE client_id = ?', [$id]);
        q('UPDATE provider_services SET client_id = NULL WHERE client_id = ?', [$id]);
        q('DELETE FROM clients WHERE id = ?', [$id]);
        audit('client_deleted', $client['name']);
        flash('success', 'Client "' . $client['name'] . '" deleted — their servers and licenses are now marked as own use.');
        redirect('clients.php');
    }
    $d = [
        'name'    => post('name', '', 100),
        'company' => post('company', '', 150) ?: null,
        'email'   => post('email', '', 190) ?: null,
        'phone'   => post('phone', '', 40) ?: null,
        'color'   => safe_color(post('color'), '#7c5cff'),
        'notes'   => post('notes', '', 3000) ?: null,
    ];
    if ($d['name'] === '') {
        $errors[] = 'Client name is required.';
    }
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email is invalid.';
    }
    if (!$errors) {
        if ($client) {
            db_update('clients', $d, 'id = ?', [$id]);
            audit('client_updated', $d['name']);
            flash('success', 'Client updated.');
        } else {
            $d['created_at'] = time();
            $id = db_insert('clients', $d);
            audit('client_added', $d['name']);
            flash('success', 'Client "' . $d['name'] . '" added — now tick their servers / licenses and click Assign.');
            redirect('clients.php#assign');
        }
        redirect('clients.php?id=' . $id);
    }
    $client = array_merge($client ?? [], $d);
}

$c = $client ?? ['color' => ['#7c5cff', '#22d3ee', '#f59e0b', '#10e5a0', '#ff4d6d', '#ec4899'][random_int(0, 5)]];
$v = fn ($k) => e((string) ($c[$k] ?? ''));
$isEdit = !empty($client['id']);

layout_start($isEdit ? 'Edit client' : 'Add client', 'clients', 'A customer whose servers or licenses you manage.',
    '<a class="btn btn-ghost" href="' . e(url('clients.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">Back</span></a>');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('alert') ?><?= e($er) ?></div><?php endforeach; ?>
<div class="narrow">
<form method="post" class="card glass anim-in" autocomplete="off">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><?php endif; ?>
  <div class="form-grid">
    <div class="field"><label>Client name *</label><input class="input" name="name" value="<?= $v('name') ?>" required placeholder="Rahul Sharma"></div>
    <div class="field"><label>Colour</label><div class="color-row"><input type="color" name="color" value="<?= $v('color') ?>"><span class="muted small">Used for badges</span></div></div>
    <div class="field"><label>Company</label><input class="input" name="company" value="<?= $v('company') ?>" placeholder="Acme Pvt Ltd"></div>
    <div class="field"><label>Email</label><input class="input" type="email" name="email" value="<?= $v('email') ?>"></div>
    <div class="field"><label>Phone / WhatsApp</label><input class="input" name="phone" value="<?= $v('phone') ?>"></div>
    <div class="field col-2"><label>Notes</label><textarea class="input" name="notes" rows="3" placeholder="Plan, billing date, contact person…"><?= $v('notes') ?></textarea></div>
  </div>
  <div class="form-actions"><button class="btn btn-primary lg" type="submit"><?= icon('check') ?><?= $isEdit ? 'Save' : 'Add client' ?></button></div>
</form>
<?php if ($isEdit): ?>
<form method="post" class="card glass anim-in save-card" data-confirm="Delete client <?= e($c['name']) ?>? Their servers and licenses stay — they just become “own use”.">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $client['id'] ?>"><input type="hidden" name="action" value="delete">
  <div><b>Delete this client</b><p class="muted small">Servers and licenses are not deleted — they move to “own use”.</p></div>
  <button class="btn btn-danger" type="submit"><?= icon('trash') ?>Delete client</button>
</form>
<?php endif; ?>
</div>
<?php layout_end();
