<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$range = get_param('range', 'today');
$fromStr = get_param('from');
$toStr = get_param('to');
[$from, $to, $label] = report_range($range, $fromStr, $toStr);
$rep = build_report($from, $to);
$t = $rep['totals'];

if (get_param('export') === 'csv') {
    audit('report_exported', 'CSV: ' . $label);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uptime-report-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $csv = function (array $row) use ($out) {
        // neutralise spreadsheet formula injection
        fputcsv($out, array_map(fn ($v) => (is_string($v) && preg_match('/^[=+\-@]/', $v)) ? "'" . $v : $v, $row));
    };
    $csv(['Uptime report', $label]);
    $csv(['Generated', date('d M Y, h:i A')]);
    $csv([]);
    $csv(['Server', 'Host', 'Provider', 'Current status', 'Current status for', 'Uptime %', 'Downtime', 'Downtime (sec)', 'Incidents', 'Longest outage', 'Avg response (ms)', 'Checks']);
    foreach ($rep['servers'] as $s) {
        $csv([$s['name'], $s['host'], $s['provider_name'], $s['state']['label'],
            in_array($s['state']['status'], ['up', 'down'], true) ? fmt_duration(time() - (int) $s['status_since'], 3) : '',
            $s['uptime'] !== null ? round($s['uptime'], 3) : '', fmt_duration($s['downtime'], 3), $s['downtime'], $s['incident_count'],
            fmt_duration($s['longest'], 3), $s['avg_ms'], $s['checks']]);
    }
    $csv([]);
    $csv(['Incidents']);
    $csv(['Server', 'Host', 'Provider', 'Went down', 'Came back', 'Duration', 'Cause']);
    foreach ($rep['incidents'] as $i) {
        $csv([$i['server_name'], $i['host'], $i['provider_name'], date('Y-m-d H:i:s', (int) $i['started_at']),
            $i['ended_at'] ? date('Y-m-d H:i:s', (int) $i['ended_at']) : 'ONGOING',
            fmt_duration($i['ended_at'] ? (int) $i['duration'] : time() - (int) $i['started_at'], 3), $i['cause']]);
    }
    fclose($out);
    exit;
}

$qs = fn (array $extra = []) => e(url('reports.php?' . http_build_query(array_merge(['range' => $range, 'from' => $fromStr, 'to' => $toStr], $extra))));
$ranges = ['today' => 'Today', 'yesterday' => 'Yesterday', '24h' => '24 hours', '7d' => '7 days', '30d' => '30 days', 'month' => 'This month'];
$servers = $rep['servers'];
usort($servers, fn ($a, $b) => [$a['state']['status'] !== 'down', $a['uptime'] ?? 101] <=> [$b['state']['status'] !== 'down', $b['uptime'] ?? 101]);

layout_start('Reports', 'reports', 'Uptime & downtime report · <b>' . e($label) . '</b>',
    '<a class="btn btn-ghost" href="' . $qs(['export' => 'csv']) . '">' . icon('download') . '<span class="hide-sm">CSV</span></a>'
    . '<button class="btn btn-ghost" type="button" data-print>' . icon('printer') . '<span class="hide-sm">PDF</span></button>'
    . '<button class="btn btn-ghost" type="button" data-send-report data-range="' . e($range) . '" data-from="' . e($fromStr) . '" data-to="' . e($toStr) . '">' . icon('send') . '<span class="hide-sm">Telegram</span></button>');
?>
<section class="card glass range-bar anim-in no-print">
  <div class="chips">
    <?php foreach ($ranges as $k => $l): ?>
      <a class="chip <?= $range === $k ? 'active' : '' ?>" href="<?= e(url('reports.php?range=' . $k)) ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <form class="custom-range" method="get">
    <input type="hidden" name="range" value="custom">
    <input class="input select-sm" type="date" name="from" value="<?= e($range === 'custom' ? $fromStr : date('Y-m-d', $from)) ?>" max="<?= date('Y-m-d') ?>">
    <span class="muted">→</span>
    <input class="input select-sm" type="date" name="to" value="<?= e($range === 'custom' ? $toStr : date('Y-m-d', $to)) ?>" max="<?= date('Y-m-d') ?>">
    <button class="btn btn-primary sm" type="submit"><?= icon('filter') ?>Apply</button>
  </form>
</section>

<div class="print-head"><h2><?= e(app_name()) ?> — Uptime report</h2><p><?= e($label) ?> · <?= e(date('d M Y, h:i A', $from)) ?> → <?= e(date('d M Y, h:i A', $to)) ?> · generated <?= e(date('d M Y, h:i A')) ?></p></div>

<section class="report-stats">
  <div class="card glass stat anim-in"><div class="stat-ico ico-violet"><?= icon('server') ?></div><div><span class="stat-lbl">Servers</span><b class="stat-val"><?= $t['servers'] ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.05s"><div class="stat-ico ico-green"><?= icon('activity') ?></div><div><span class="stat-lbl">Avg uptime</span><b class="stat-val txt-<?= uptime_class($t['avg_uptime']) ?>"><?= fmt_pct($t['avg_uptime'], 3) ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.1s"><div class="stat-ico ico-red"><?= icon('zap') ?></div><div><span class="stat-lbl">Incidents</span><b class="stat-val"><?= $t['incidents'] ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.15s"><div class="stat-ico ico-amber"><?= icon('clock') ?></div><div><span class="stat-lbl">Total downtime</span><b class="stat-val"><?= e(fmt_duration($t['downtime'], 2)) ?></b></div></div>
  <div class="card glass stat anim-in" style="--d:.2s"><div class="stat-ico ico-cyan"><?= icon('zap') ?></div><div><span class="stat-lbl">Avg response</span><b class="stat-val"><?= $t['avg_ms'] !== null ? $t['avg_ms'] . '<small> ms</small>' : '—' ?></b></div></div>
</section>

<section class="card glass table-card anim-in">
  <div class="card-head"><h3><?= icon('server') ?>Per-server report</h3><span class="muted small"><?= $t['up'] ?> up · <?= $t['down'] ?> down<?= $t['paused'] ? ' · ' . $t['paused'] . ' paused' : '' ?></span></div>
  <?php if (!$servers): ?><div class="mini-empty"><?= icon('server') ?>No servers yet.</div><?php else: ?>
  <div class="table-wrap">
    <table class="table report-table">
      <thead><tr><th>Server</th><th>Provider</th><th>Current status</th><th>Uptime</th><th>Downtime</th><th>Incidents</th><th>Longest outage</th><th>Avg resp.</th></tr></thead>
      <tbody>
      <?php foreach ($servers as $s): $st = $s['state']; ?>
        <tr>
          <td><a class="cell-title" href="<?= e(url('server.php?id=' . (int) $s['id'])) ?>"><?= e($s['name']) ?></a><div class="cell-sub mono"><?= e($s['host']) ?></div></td>
          <td><?= provider_badge($s['provider_name'], $s['provider_color']) ?></td>
          <td>
            <span class="pill pill-<?= e($st['cls']) ?> xs"><span class="dot dot-<?= e($st['cls']) ?>"></span><?= e($st['label']) ?></span>
            <?php if (in_array($st['status'], ['up', 'down'], true)): ?>
              <div class="cell-sub"><?= $st['status'] === 'up' ? 'up' : 'down' ?> for <b data-since="<?= (int) $s['status_since'] ?>"><?= e(fmt_duration(time() - (int) $s['status_since'])) ?></b></div>
            <?php endif; ?>
          </td>
          <td>
            <div class="pct-cell"><b class="txt-<?= uptime_class($s['uptime']) ?>"><?= fmt_pct($s['uptime'], 3) ?></b>
              <div class="pct-bar"><span class="bg-<?= uptime_class($s['uptime']) ?>" style="width:<?= $s['uptime'] !== null ? max(2, round($s['uptime'], 1)) : 0 ?>%"></span></div></div>
          </td>
          <td><b class="<?= $s['downtime'] ? 'txt-bad' : 'muted' ?>"><?= $s['downtime'] ? e(fmt_duration($s['downtime'], 3)) : '0s' ?></b></td>
          <td><?= $s['incident_count'] ?></td>
          <td><?= $s['longest'] ? e(fmt_duration($s['longest'], 3)) : '—' ?></td>
          <td class="mono"><?= $s['avg_ms'] !== null ? $s['avg_ms'] . ' ms' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<section class="card glass table-card anim-in" id="downtime">
  <div class="card-head"><h3><?= icon('zap') ?>Downtime log</h3><span class="muted small"><?= count($rep['incidents']) ?> incident(s) in period</span></div>
  <?php if (!$rep['incidents']): ?>
    <div class="mini-empty"><?= icon('check') ?>No downtime in this period. 🎉</div>
  <?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Server</th><th>Provider</th><th>Went down</th><th>Came back</th><th>Duration</th><th>Cause</th></tr></thead>
      <tbody>
      <?php foreach ($rep['incidents'] as $i): $open = $i['ended_at'] === null; ?>
        <tr>
          <td><a class="cell-title" href="<?= e(url('server.php?id=' . (int) $i['server_id'])) ?>"><?= e($i['server_name']) ?></a><div class="cell-sub mono"><?= e($i['host']) ?></div></td>
          <td><?= e($i['provider_name'] ?? '—') ?></td>
          <td><?= e(fmt_time($i['started_at'], 'd M, h:i:s A')) ?></td>
          <td><?= $open ? '<span class="pill pill-down xs"><span class="dot dot-down"></span>Still down</span>' : e(fmt_time($i['ended_at'], 'd M, h:i:s A')) ?></td>
          <td><b class="txt-bad" <?= $open ? 'data-since="' . (int) $i['started_at'] . '"' : '' ?>><?= e(fmt_duration($open ? time() - (int) $i['started_at'] : (int) $i['duration'], 3)) ?></b></td>
          <td><?php $why = diagnose_short((string) $i['cause'], ['host' => $i['host']]); ?><?= $why ? '<b class="small">' . e($why) . '</b>' : '' ?><div class="mono small muted"><?= e((string) $i['cause']) ?></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
<?php layout_end();
