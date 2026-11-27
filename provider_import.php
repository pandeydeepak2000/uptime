<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
require_perm('admin');

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
$p = row('SELECT * FROM providers WHERE id = ?', [$id]);
if (!$p) {
    flash('error', 'Provider not found.');
    redirect('providers.php');
}

/** Pull the HTML out of a saved page (.html, or Chrome/Edge "single file" .mhtml). */
function saved_page_html(string $raw): string
{
    if (stripos(substr($raw, 0, 2000), 'MIME-Version') !== false && preg_match('~boundary="?([^"\r\n;]+)"?~i', $raw, $b)) {
        foreach (explode('--' . $b[1], $raw) as $part) {
            if (stripos($part, 'Content-Type: text/html') === false) {
                continue;
            }
            $split = preg_split("/\r?\n\r?\n/", $part, 2);
            $hdr = $split[0];
            $body = $split[1] ?? '';
            if (stripos($hdr, 'quoted-printable') !== false) {
                $body = quoted_printable_decode($body);
            } elseif (stripos($hdr, 'base64') !== false) {
                $body = (string) base64_decode($body);
            }
            return $body;
        }
    }
    return $raw;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $html = '';
    if (!empty($_FILES['page']['tmp_name']) && is_uploaded_file($_FILES['page']['tmp_name'])) {
        if ((int) $_FILES['page']['size'] > 8 * 1024 * 1024) {
            flash('error', 'File is too large (max 8 MB).');
            redirect('provider_import.php?id=' . $id);
        }
        $html = (string) file_get_contents($_FILES['page']['tmp_name']);
        @unlink($_FILES['page']['tmp_name']);
    } elseif (trim((string) ($_POST['html'] ?? '')) !== '') {
        $html = substr((string) $_POST['html'], 0, 8 * 1024 * 1024);
    }
    if ($html === '') {
        flash('error', 'Choose the saved page file (or paste the page source).');
        redirect('provider_import.php?id=' . $id);
    }
    $list = WhmcsClient::parseServicesList(saved_page_html($html));
    if (!$list) {
        flash('error', 'No services found in that page. Make sure you saved the “My Products & Services” page while logged in (clientarea.php?action=services), and that it lists your services.');
        redirect('provider_import.php?id=' . $id);
    }
    $r = provider_save_services($id, $list, isset($_POST['replace']));
    $msg = $p['name'] . ': imported ' . $r['count'] . ' service(s) from saved page, ' . $r['linked'] . ' matched to your servers'
        . ($r['gone'] ? ', ' . $r['gone'] . ' removed' : '') . '.';
    db_update('providers', ['last_sync_at' => time(), 'last_sync_ok' => 1, 'last_sync_status' => substr($msg, 0, 250)], 'id = ?', [$id]);
    audit('provider_sync', $msg);
    flash('success', $msg . ($r['unmonitored'] ? ' ' . $r['unmonitored'] . ' VPS can be added to monitoring.' : ''));
    redirect('services.php');
}

$servicesUrl = $p['panel_url'] ? rtrim(WhmcsClient::baseUrl($p['panel_url']), '/') . '/clientarea.php?action=services' : '';

layout_start('Import services', 'providers', 'For <b>' . e($p['name']) . '</b> — works even when the provider uses CAPTCHA or 2FA.',
    '<a class="btn btn-ghost" href="' . e(url('providers.php')) . '">' . icon('arrow-left') . '<span class="hide-sm">Back</span></a>');
?>
<div class="narrow">
  <div class="card glass anim-in">
    <div class="card-head"><h3><?= icon('download') ?>Import from a saved page</h3><span class="pill pill-up xs"><?= icon('lock') ?>No password needed</span></div>
    <ol class="import-steps">
      <li>Log in to <b><?= e($p['name']) ?></b> yourself (solve the CAPTCHA / 2FA as usual).</li>
      <li>Open <b>Services → My Services</b><?= $servicesUrl ? ': <a class="link" href="' . e($servicesUrl) . '" target="_blank" rel="noopener noreferrer">open it ↗</a>' : '' ?>. Set the list to show <b>all</b> entries if it is paged.</li>
      <li>Press <b>Ctrl + S</b> and save the page (“Webpage, HTML only” or “Single file” both work).</li>
      <li>Upload that file below. The page is read once and not stored.</li>
    </ol>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
      <label class="file-drop"><?= icon('download') ?><br><b>Choose the saved “My Services” page</b><br><span class="small">.html / .htm / .mhtml — max 8 MB</span>
        <input type="file" name="page" accept=".html,.htm,.mhtml,.mht,text/html"></label>
      <details>
        <summary class="muted small">…or paste the page source instead (Ctrl + U → Ctrl + A → Ctrl + C)</summary>
        <textarea class="input mono mt-1" name="html" rows="5" placeholder="<html>…"></textarea>
      </details>
      <label class="check"><input type="checkbox" name="replace" checked> Remove services that are no longer on the page</label>
      <button class="btn btn-primary lg" type="submit"><?= icon('check') ?>Import services</button>
    </form>
  </div>
  <p class="muted small mt-2"><?= icon('shield') ?> Only the service list (name, IP/hostname, price, cycle, next due date, status) is read. Nothing is sent to the provider.</p>
</div>
<?php layout_end();
