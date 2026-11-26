<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$prov = $id ? row('SELECT * FROM providers WHERE id = ?', [$id]) : null;
if ($id && !$prov) {
    flash('error', 'Provider not found.');
    redirect('providers.php');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'delete' && $prov) {
        q('UPDATE servers SET provider_id = NULL WHERE provider_id = ?', [$id]);
        q('DELETE FROM providers WHERE id = ?', [$id]);
        audit('provider_deleted', $prov['name']);
        flash('success', 'Provider "' . $prov['name'] . '" deleted. Its servers are now unassigned.');
        redirect('providers.php');
    }
    $d = [
        'name'          => post('name', '', 100),
        'website'       => post('website', '', 255) ?: null,
        'panel_url'     => post('panel_url', '', 255) ?: null,
        'account_user'  => post('account_user', '', 190) ?: null,
        'support_email' => post('support_email', '', 190) ?: null,
        'color'         => safe_color(post('color'), '#7c5cff'),
        'notes'         => post('notes', '', 3000) ?: null,
        'sync_enabled'  => isset($_POST['sync_enabled']) ? 1 : 0,
    ];
    if ($d['name'] === '') {
        $errors[] = 'Provider name is required.';
    }
    foreach (['website' => 'Website', 'panel_url' => 'Client area URL'] as $k => $label) {
        if ($d[$k] && (!filter_var($d[$k], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $d[$k]))) {
            $errors[] = "$label must be a valid http(s):// URL.";
        }
    }
    if ($d['support_email'] && !filter_var($d['support_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Support email is invalid.';
    }
    $pw = (string) ($_POST['account_pass'] ?? '');
    $apiType = post('api_type') === 'ovh' ? 'ovh' : 'whmcs';
    $d['api_type'] = $apiType;

    // 2FA secret for WHMCS client areas (base32 key shown under the provider's QR code)
    $totp = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', post('totp_secret')));
    if ($totp !== '' && (strlen($totp) < 16 || base32_decode($totp) === '')) {
        $errors[] = '2FA secret key looks wrong — it is the long code shown under the QR code (letters A–Z and digits 2–7).';
    }

    // OVH API credentials (kept encrypted; blank fields keep the saved value)
    $oldCfg = json_decode(decrypt_str($prov['api_config'] ?? ''), true) ?: [];
    $ovh = [
        'endpoint'     => array_key_exists(post('ovh_endpoint'), OVH_ENDPOINTS) ? post('ovh_endpoint') : ($oldCfg['endpoint'] ?? 'ovh-eu'),
        'app_key'      => post('ovh_app_key', '', 100) ?: ($oldCfg['app_key'] ?? ''),
        'app_secret'   => post('ovh_app_secret', '', 100) ?: ($oldCfg['app_secret'] ?? ''),
        'consumer_key' => post('ovh_consumer_key', '', 100) ?: ($oldCfg['consumer_key'] ?? ''),
    ];
    if ($apiType === 'ovh' && (!$ovh['app_key'] || !$ovh['app_secret'] || !$ovh['consumer_key'])) {
        $errors[] = 'For OVHcloud API enter the application key, application secret and consumer key.';
    }
    if (!$errors) {
        if ($pw !== '') {
            $d['account_pass'] = encrypt_str($pw);
        } elseif (isset($_POST['clear_pass'])) {
            $d['account_pass'] = null;
        }
        if ($totp !== '') {
            $d['totp_secret'] = encrypt_str($totp);
        } elseif (isset($_POST['clear_totp'])) {
            $d['totp_secret'] = null;
        }
        if ($apiType === 'ovh') {
            $d['api_config'] = encrypt_str(json_encode($ovh));
        }
        if ($prov) {
            db_update('providers', $d, 'id = ?', [$id]);
            audit('provider_updated', $d['name']);
            flash('success', 'Provider updated.');
        } else {
            $d['created_at'] = time();
            db_insert('providers', $d);
            audit('provider_added', $d['name']);
            flash('success', 'Provider added.');
        }
        redirect('providers.php');
    }
    $prov = array_merge($prov ?? [], $d);
}

$p = $prov ?? ['color' => ['#7c5cff', '#22d3ee', '#f59e0b', '#10e5a0', '#ff4d6d', '#ec4899'][random_int(0, 5)]];
$v = fn ($k) => e($p[$k] ?? '');
$isEdit = !empty($prov['id']);

layout_start($isEdit ? 'Edit provider' : 'Add provider', 'providers', 'Hosting company details & encrypted client-area login.',
    '<a class="btn btn-ghost" href="' . e(url('providers.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">Back</span></a>');
?>
<?php foreach ($errors as $er): ?><div class="alert alert-error"><?= icon('alert') ?><?= e($er) ?></div><?php endforeach; ?>
<div class="narrow">
<form method="post" class="card glass anim-in" autocomplete="off">
  <?= csrf_field() ?>
  <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $prov['id'] ?>"><?php endif; ?>
  <div class="form-grid">
    <div class="field"><label>Provider name *</label><input class="input" name="name" value="<?= $v('name') ?>" required placeholder="EricHost"></div>
    <div class="field"><label>Brand colour</label><div class="color-row"><input type="color" name="color" value="<?= $v('color') ?>"><span class="muted small">Used for badges &amp; charts</span></div></div>
    <div class="field"><label>Website</label><input class="input" name="website" value="<?= $v('website') ?>" placeholder="https://provider.com"></div>
    <div class="field"><label>Client area / panel URL</label><input class="input" name="panel_url" value="<?= $v('panel_url') ?>" placeholder="https://my.provider.com"></div>
    <div class="field"><label>Panel username / email</label><input class="input mono" name="account_user" value="<?= $v('account_user') ?>"></div>
    <div class="field"><label>Panel password <?= $isEdit && !empty($prov['account_pass']) ? '<span class="muted small">(saved — blank keeps it)</span>' : '' ?></label>
      <div class="input-ico"><?= icon('lock') ?><input class="input mono" id="provPass" type="password" name="account_pass" autocomplete="new-password">
        <button class="icon-btn input-eye" type="button" data-toggle-pw="#provPass"><?= icon('eye') ?></button></div>
      <?php if ($isEdit && !empty($prov['account_pass'])): ?><label class="check mt-1"><input type="checkbox" name="clear_pass"> Remove saved password</label><?php endif; ?>
    </div>
    <div class="field col-2"><label>Support email</label><input class="input" type="email" name="support_email" value="<?= $v('support_email') ?>"></div>
    <div class="field col-2"><label>Notes</label><textarea class="input" name="notes" rows="3"><?= $v('notes') ?></textarea></div>
    <?php $apiType = ($p['api_type'] ?? '') === 'ovh' ? 'ovh' : 'whmcs'; $cfgView = json_decode(decrypt_str($p['api_config'] ?? ''), true) ?: []; ?>
    <h4 class="form-section">Automatic sync</h4>
    <div class="field col-2">
      <div class="seg" data-api-picker>
        <label><input type="radio" name="api_type" value="whmcs" <?= $apiType === 'whmcs' ? 'checked' : '' ?>><span>WHMCS client area (login)</span></label>
        <label><input type="radio" name="api_type" value="ovh" <?= $apiType === 'ovh' ? 'checked' : '' ?>><span>OVHcloud API (keys)</span></label>
      </div>
    </div>

    <div class="field col-2" data-api="whmcs">
      <label>2FA secret key <span class="muted small">(only if this client area asks for a 2FA code)</span>
        <?= !empty($p['totp_secret']) ? '<span class="pill pill-up xs">' . icon('lock') . 'saved &amp; encrypted</span>' : '' ?></label>
      <input class="input mono" name="totp_secret" placeholder="<?= !empty($p['totp_secret']) ? 'Saved — leave blank to keep' : 'e.g. JBSWY3DPEHPK3PXP… (text under the QR code)' ?>" autocomplete="off">
      <span class="hint">With this key the dashboard generates the 6-digit code itself, so daily auto-sync works. Without it you can still type the code each time you click <b>Sync now</b>.
        Note: storing it next to the password means both factors live here (encrypted).</span>
      <?php if (!empty($p['totp_secret'])): ?><label class="check mt-1"><input type="checkbox" name="clear_totp"> Remove saved 2FA key</label><?php endif; ?>
    </div>

    <div class="col-2 form-grid" data-api="ovh">
      <div class="field col-2"><label>OVH region</label>
        <select class="input" name="ovh_endpoint"><?php foreach (OVH_ENDPOINTS as $k => $ep): ?><option value="<?= e($k) ?>" <?= ($cfgView['endpoint'] ?? 'ovh-eu') === $k ? 'selected' : '' ?>><?= e($ep['label']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Application key</label><input class="input mono" name="ovh_app_key" placeholder="<?= !empty($cfgView['app_key']) ? 'Saved — blank keeps it' : '' ?>" autocomplete="off"></div>
      <div class="field"><label>Application secret</label><input class="input mono" type="password" name="ovh_app_secret" placeholder="<?= !empty($cfgView['app_secret']) ? 'Saved — blank keeps it' : '' ?>" autocomplete="new-password"></div>
      <div class="field col-2"><label>Consumer key</label><input class="input mono" type="password" name="ovh_consumer_key" placeholder="<?= !empty($cfgView['consumer_key']) ? 'Saved — blank keeps it' : '' ?>" autocomplete="new-password"></div>
      <div class="col-2 callout">
        <div class="callout-title"><?= icon('key') ?>Create read-only OVH keys (2 minutes)</div>
        <ol class="import-steps">
          <li>Open the token page for your region and log in to OVH:
            <?php foreach (OVH_ENDPOINTS as $k => $ep): ?><a class="link" href="<?= e(ovh_token_url($k)) ?>" target="_blank" rel="noopener noreferrer"><?= e(strtoupper(substr($k, 4))) ?> ↗</a> <?php endforeach; ?></li>
          <li>The rights are pre-filled with <b>GET only</b> (/me, /vps, /dedicated/server). Set validity to <b>Unlimited</b> and create.</li>
          <li>Paste the <b>Application Key</b>, <b>Application Secret</b> and <b>Consumer Key</b> here and Save.</li>
        </ol>
        <p class="muted small">GET-only keys can read servers but cannot reboot, reinstall, order or delete anything.</p>
      </div>
    </div>

    <div class="col-2 sync-optin">
      <label class="switch"><input type="checkbox" name="sync_enabled" <?= (int) ($p['sync_enabled'] ?? 0) ? 'checked' : '' ?>><span class="slider"></span><span><b>Auto-sync services daily</b></span></label>
      <p class="muted small"><b>Read-only.</b> WHMCS: logs in, reads <b>My Services</b> (name, IP, status, price, next due date, and root login if the provider shows it) and logs out. OVH: reads servers through the API.
        Never orders, pays, cancels or changes anything. Pauses itself on login problems. Your provider may email a “new login” notice each time.</p>
    </div>
  </div>
  <div class="form-actions">
    <button class="btn btn-primary lg" type="submit"><?= icon('check') ?><?= $isEdit ? 'Save provider' : 'Add provider' ?></button>
  </div>
</form>
<?php if ($isEdit): ?>
<form method="post" class="card glass danger-zone anim-in" data-confirm="Delete provider <?= e($prov['name']) ?>? Servers will be kept but unassigned.">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $prov['id'] ?>"><input type="hidden" name="action" value="delete">
  <div><b>Delete provider</b><p class="muted small">Servers linked to this provider will remain and become unassigned.</p></div>
  <button class="btn btn-danger" type="submit"><?= icon('trash') ?>Delete</button>
</form>
<?php endif; ?>
</div>
<?php layout_end();
