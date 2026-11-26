<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$server = $id ? row('SELECT * FROM servers WHERE id = ?', [$id]) : null;
if ($id && !$server) {
    flash('error', 'Server not found.');
    redirect('servers.php');
}
$providers = rows('SELECT id, name FROM providers ORDER BY name');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'delete' && $server) {
        q('DELETE FROM checks WHERE server_id = ?', [$id]);
        q('DELETE FROM incidents WHERE server_id = ?', [$id]);
        q('DELETE FROM servers WHERE id = ?', [$id]);
        audit('server_deleted', $server['name'] . ' (' . $server['host'] . ')');
        flash('success', 'Server "' . $server['name'] . '" deleted.');
        redirect('servers.php');
    }

    if ($action === 'renew' && $server) {
        $months = (int) post('months');
        if (!in_array($months, [1, 3, 6, 12], true)) {
            flash('error', 'Invalid renewal period.');
            redirect('server.php?id=' . $id);
        }
        $from = $server['expires_at'] ?: date('Y-m-d');
        $next = add_months($from, $months);
        db_update('servers', ['expires_at' => $next], 'id = ?', [$id]);
        audit('server_renewed', $server['name'] . ': ' . $from . ' → ' . $next . " (+$months mo)");
        flash('success', 'Marked as renewed — next due date ' . date('d M Y', strtotime($next)) . '.');
        redirect('server.php?id=' . $id);
    }

    $d = [
        'name'         => post('name', '', 100),
        'provider_id'  => (int) post('provider_id') ?: null,
        'host'         => strtolower(post('host', '', 255)),
        'check_type'   => post('check_type', 'auto'),
        'port'         => post('port') !== '' ? (int) post('port') : null,
        'url'          => post('url', '', 255) ?: null,
        'ssh_port'     => (int) (post('ssh_port') ?: 22),
        'ssh_user'     => post('ssh_user', '', 64) ?: 'root',
        'location'     => post('location', '', 100) ?: null,
        'os'           => post('os', '', 100) ?: null,
        'specs'        => post('specs', '', 190) ?: null,
        'monthly_cost' => post('monthly_cost') !== '' ? round((float) post('monthly_cost'), 2) : null,
        'currency'     => strtoupper(post('currency', 'INR', 8)) ?: 'INR',
        'expires_at'   => post('expires_at') ?: null,
        'notes'        => post('notes', '', 3000) ?: null,
    ];
    $cid = (int) post('client_id');
    $d['client_id'] = $cid && val('SELECT id FROM clients WHERE id = ?', [$cid]) ? $cid : null;
    $fp = post('ssh_fingerprint', '', 120);
    if ($fp !== '' && valid_fingerprint($fp)) {
        $d['ssh_fingerprint'] = $fp;
    } elseif ($server && $server['host'] !== $d['host']) {
        $d['ssh_fingerprint'] = null; // new machine → forget old host key
    }
    $enabled = isset($_POST['enabled']) ? 1 : 0;

    if ($d['name'] === '') {
        $errors[] = 'Server name is required.';
    }
    if (!valid_host($d['host'])) {
        $errors[] = 'Enter a valid IP address or hostname.';
    }
    if (!array_key_exists($d['check_type'], CHECK_TYPES)) {
        $errors[] = 'Invalid check method.';
    }
    if ($d['check_type'] === 'tcp' && ($d['port'] === null || $d['port'] < 1 || $d['port'] > 65535)) {
        $errors[] = 'TCP check needs a valid port (1–65535).';
    }
    if ($d['check_type'] === 'http' && (!$d['url'] || !filter_var($d['url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $d['url']))) {
        $errors[] = 'HTTP check needs a valid http(s):// URL.';
    }
    if ($d['url'] && !preg_match('~^https?://~i', $d['url'])) {
        $d['url'] = null;
    }
    if ($d['ssh_port'] < 1 || $d['ssh_port'] > 65535) {
        $errors[] = 'Invalid SSH port.';
    }
    if ($d['expires_at'] && !DateTime::createFromFormat('Y-m-d', $d['expires_at'])) {
        $errors[] = 'Invalid renewal date.';
    }
    if ($d['provider_id'] && !val('SELECT id FROM providers WHERE id = ?', [$d['provider_id']])) {
        $d['provider_id'] = null;
    }

    $newPass = (string) ($_POST['ssh_pass'] ?? '');
    if ($newPass !== '' && verify_current_password((int) $user['id'], $newPass)) {
        $errors[] = 'That is your Uptime dashboard login password, not the server root password — your browser probably auto-filled it. Nothing was saved; type the root password.';
        $newPass = '';
    }
    if (!$errors) {
        if ($newPass !== '') {
            $d['ssh_pass'] = encrypt_str($newPass);
        } elseif (isset($_POST['clear_pass'])) {
            $d['ssh_pass'] = null;
        }
        if ($server) {
            db_update('servers', $d, 'id = ?', [$id]);
            if (($server['client_id'] ?? null) != $d['client_id']) {
                assign_server_client($id, $d['client_id']); // licenses on this server follow it
            }
            if ((int) $server['enabled'] !== $enabled) {
                set_server_enabled($id, (bool) $enabled);
            }
            if ($server['host'] !== $d['host'] || $server['check_type'] !== $d['check_type']) {
                db_update('servers', ['last_check_at' => null], 'id = ?', [$id]);
            }
            audit('server_updated', $d['name'] . ($newPass !== '' ? ' · root password changed' : ''));
            flash('success', 'Server updated.');
        } else {
            $d += ['enabled' => $enabled, 'status' => $enabled ? 'unknown' : 'paused', 'status_since' => time(), 'created_at' => time()];
            $id = db_insert('servers', $d);
            audit('server_added', $d['name'] . ' (' . $d['host'] . ')');
            if ($enabled) {
                try {
                    run_monitor([$id], true, true);
                } catch (Throwable $e) {
                    log_file('app.log', 'initial check failed: ' . $e->getMessage());
                }
            }
            flash('success', 'Server added and first check completed.');
        }
        redirect('server.php?id=' . $id);
    }
    $server = array_merge($server ?? [], $d, ['enabled' => $enabled]);
}

$s = $server ?? ['check_type' => 'auto', 'ssh_port' => 22, 'ssh_user' => 'root', 'currency' => 'INR', 'enabled' => 1, 'provider_id' => (int) ($_GET['provider'] ?? 0)];
$v = fn ($k, $d = '') => e($s[$k] ?? $d);
$isEdit = !empty($server['id']);

layout_start($isEdit ? 'Edit server' : 'Add server', 'servers',
    $isEdit ? 'Update <b>' . e($server['name']) . '</b>' : 'Add a VPS from any provider. Credentials are encrypted before they touch the database.',
    '<a class="btn btn-ghost" href="' . e(url($isEdit ? 'server.php?id=' . (int) $server['id'] : 'servers.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">Back</span></a>');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error anim-in"><?= icon('alert') ?><?= e($er) ?></div><?php endforeach; ?>

<form method="post" class="edit-layout" autocomplete="off">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $server['id'] ?>"><?php endif; ?>
  <div class="stack-col">
    <div class="card glass anim-in">
      <div class="card-head"><h3><?= icon('server') ?>Server details</h3></div>
      <div class="form-grid">
        <div class="field"><label>Server name *</label><input class="input" name="name" value="<?= $v('name') ?>" placeholder="e.g. Web-01 Mumbai" required maxlength="100"></div>
        <div class="field"><label>Provider</label>
          <select class="input" name="provider_id">
            <option value="">— None —</option>
            <?php foreach ($providers as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) ($s['provider_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
          <a class="hint link" href="<?= e(url('provider_edit.php')) ?>">+ Add new provider</a>
        </div>
        <div class="field col-2"><label>IP address / hostname *</label><input class="input mono" name="host" value="<?= $v('host') ?>" placeholder="203.0.113.10" required></div>
        <div class="field"><label>Location</label><input class="input" name="location" value="<?= $v('location') ?>" placeholder="Mumbai, IN"></div>
        <div class="field"><label>Operating system</label><input class="input" name="os" value="<?= $v('os') ?>" placeholder="Ubuntu 22.04 / AlmaLinux 9"></div>
        <div class="field col-2"><label>Specs</label><input class="input" name="specs" value="<?= $v('specs') ?>" placeholder="4 vCPU · 8 GB RAM · 160 GB NVMe"></div>
      </div>
    </div>

    <div class="card glass anim-in" style="--d:.05s">
      <div class="card-head"><h3><?= icon('activity') ?>Uptime check</h3></div>
      <div class="method-grid" data-method-picker>
        <?php foreach (CHECK_TYPES as $k => $label): ?>
          <label class="method <?= ($s['check_type'] ?? 'auto') === $k ? 'active' : '' ?>">
            <input type="radio" name="check_type" value="<?= e($k) ?>" <?= ($s['check_type'] ?? 'auto') === $k ? 'checked' : '' ?>>
            <b><?= e(['auto' => 'Smart', 'tcp' => 'TCP port', 'ping' => 'ICMP ping', 'http' => 'HTTP(S)'][$k]) ?></b>
            <span><?= e(['auto' => 'Tries SSH, 80 & 443 in parallel, then ping. Best for VPS.', 'tcp' => 'Checks one specific port is accepting connections.', 'ping' => 'Classic ping. Falls back to Smart if exec() is disabled.', 'http' => 'Requests a URL — any response < 500 counts as up.'][$k]) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-grid mt-2">
        <div class="field" data-show-for="tcp"><label>TCP port</label><input class="input" type="number" min="1" max="65535" name="port" value="<?= $v('port') ?>" placeholder="22"></div>
        <div class="field col-2" data-show-for="http"><label>URL</label><input class="input mono" name="url" value="<?= $v('url') ?>" placeholder="https://example.com/health"></div>
      </div>
      <?php if (!exec_available()): ?><p class="muted small mt-1"><?= icon('alert') ?> ICMP ping is disabled on this hosting (exec() off) — TCP checks will be used.</p><?php endif; ?>
    </div>
  </div>

  <div class="stack-col">
    <div class="card glass anim-in vault-card" style="--d:.1s">
      <div class="card-head"><h3><?= icon('key') ?>Root access vault</h3><span class="pill pill-up xs"><?= icon('lock') ?>AES-256-GCM</span></div>
      <div class="form-grid">
        <div class="field"><label>SSH user</label><input class="input mono" name="ssh_user" value="<?= $v('ssh_user', 'root') ?>"></div>
        <div class="field"><label>SSH port</label><input class="input" type="number" min="1" max="65535" name="ssh_port" value="<?= $v('ssh_port', 22) ?>"></div>
        <div class="field col-2">
          <label>Root password <?= $isEdit && !empty($server['ssh_pass']) ? '<span class="muted small">(saved — leave blank to keep)</span>' : '' ?></label>
          <div class="input-ico"><?= icon('lock') ?><input class="input mono" id="sshPass" type="password" name="ssh_pass" autocomplete="new-password" readonly data-no-autofill placeholder="<?= $isEdit && !empty($server['ssh_pass']) ? '••••••••••••' : 'Enter root password' ?>">
            <button class="icon-btn input-eye" type="button" data-toggle-pw="#sshPass"><?= icon('eye') ?></button></div>
          <?php if ($isEdit && !empty($server['ssh_pass'])): ?>
            <label class="check mt-1"><input type="checkbox" name="clear_pass"> Remove saved password</label>
          <?php endif; ?>
        </div>
      </div>
      <p class="muted small"><?= icon('shield') ?> Encrypted with your unique app key before saving. Viewing requires your dashboard password and is logged.</p>
      <input type="hidden" name="ssh_fingerprint" value="">
      <div class="discover-box" data-discover data-server-id="<?= $isEdit ? (int) $server['id'] : 0 ?>" data-known-fp="<?= e((string) ($server['ssh_fingerprint'] ?? '')) ?>">
        <button class="btn btn-ghost w-full" type="button" data-discover-run><?= icon('zap') ?>Auto-detect location, OS &amp; specs</button>
        <div class="discover-note">
          <span class="pill pill-up xs"><?= icon('eye') ?>Read-only</span>
          <span class="muted small">Nothing is installed or changed on the server. Only the IP is sent to ipinfo.io for location.</span>
        </div>
        <details class="discover-cmds">
          <summary class="muted small">Exactly what runs on the server</summary>
          <code><?= e(implode(' · ', discover_commands_list())) ?></code>
          <p class="muted small">The SSH host key is checked before the password is sent. The login shows up in the server's own login history (e.g. <code>last</code>).</p>
        </details>
        <div class="discover-result" hidden></div>
      </div>
    </div>

    <div class="card glass anim-in" style="--d:.15s">
      <div class="card-head"><h3><?= icon('dollar') ?>Billing</h3></div>
      <div class="form-grid">
        <div class="field"><label>Monthly cost</label><input class="input" type="number" step="0.01" min="0" name="monthly_cost" value="<?= $v('monthly_cost') ?>" placeholder="0.00"></div>
        <div class="field"><label>Currency</label>
          <select class="input" name="currency"><?php foreach (CURRENCIES as $c): ?><option <?= ($s['currency'] ?? 'INR') === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></div>
        <div class="field col-2"><label>Used by (client) <a class="hint link" href="<?= e(url('client_edit.php')) ?>">+ Add client</a></label>
          <select class="input" name="client_id"><?= client_select_options(isset($s['client_id']) ? (int) $s['client_id'] : null) ?></select></div>
        <div class="field col-2"><label>Next due date (renewal) <span class="muted small">— Telegram reminder before this date</span></label><input class="input" type="date" name="expires_at" value="<?= $v('expires_at') ?>"></div>
        <div class="field col-2"><label>Notes</label><textarea class="input" name="notes" rows="3" placeholder="Control panel URL, purpose, contacts…"><?= $v('notes') ?></textarea></div>
      </div>
    </div>

    <div class="card glass anim-in save-card" style="--d:.2s">
      <label class="switch"><input type="checkbox" name="enabled" <?= (int) ($s['enabled'] ?? 1) ? 'checked' : '' ?>><span class="slider"></span><span>Monitoring enabled</span></label>
      <button class="btn btn-primary lg" type="submit"><?= icon('check') ?><?= $isEdit ? 'Save changes' : 'Add server & check now' ?></button>
    </div>
  </div>
</form>
<?php layout_end();
