<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

$filter = get_param('type');
$groups = [
    'security'   => ['login', 'logout', 'login_failed', '2fa_failed', '2fa_enabled', '2fa_disabled', 'password_changed', 'password_change_failed', 'secret_revealed', 'reveal_failed', 'reveal_locked'],
    'monitoring' => ['server_down', 'server_up', 'server_pause', 'server_resume'],
    'changes'    => ['server_added', 'server_updated', 'server_deleted', 'provider_added', 'provider_updated', 'provider_deleted', 'settings_updated', 'report_sent', 'report_exported', 'server_renewed', 'server_discover', 'provider_sync', 'service_added', 'service_updated', 'service_deleted'],
];
$sql = 'SELECT a.*, u.username FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
$params = [];
if (isset($groups[$filter])) {
    $sql .= ' WHERE a.action IN (' . implode(',', array_fill(0, count($groups[$filter]), '?')) . ')';
    $params = $groups[$filter];
}
$logs = rows($sql . ' ORDER BY a.id DESC LIMIT 300', $params);

$style = function (string $a): array {
    if (in_array($a, ['login_failed', '2fa_failed', 'reveal_failed', 'reveal_locked', 'server_down', 'server_deleted', 'password_change_failed', 'provider_deleted'], true)) {
        return ['down', 'alert'];
    }
    if (in_array($a, ['secret_revealed', 'server_pause', '2fa_disabled'], true)) {
        return ['warn', 'key'];
    }
    if (in_array($a, ['login', 'server_up', '2fa_enabled', 'server_added', 'server_resume'], true)) {
        return ['up', 'check'];
    }
    return ['unknown', 'activity'];
};

layout_start('Activity log', 'activity', 'Every login, credential view, change and outage — kept for 12 months.');
?>
<section class="toolbar anim-in">
  <div class="chips">
    <a class="chip <?= $filter === '' ? 'active' : '' ?>" href="<?= e(url('activity.php')) ?>">All</a>
    <a class="chip <?= $filter === 'security' ? 'active' : '' ?>" href="<?= e(url('activity.php?type=security')) ?>"><?= icon('shield') ?>Security</a>
    <a class="chip <?= $filter === 'monitoring' ? 'active' : '' ?>" href="<?= e(url('activity.php?type=monitoring')) ?>"><?= icon('activity') ?>Monitoring</a>
    <a class="chip <?= $filter === 'changes' ? 'active' : '' ?>" href="<?= e(url('activity.php?type=changes')) ?>"><?= icon('edit') ?>Changes</a>
  </div>
</section>
<div class="card glass table-card anim-in">
  <?php if (!$logs): ?><div class="mini-empty"><?= icon('list') ?>Nothing logged yet.</div><?php else: ?>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Event</th><th>Details</th><th>User</th><th>IP</th><th>When</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $l): [$cls, $ic] = $style($l['action']); ?>
        <tr>
          <td><span class="pill pill-<?= $cls ?> xs"><?= icon($ic) ?><?= e(ucwords(str_replace('_', ' ', $l['action']))) ?></span></td>
          <td class="small"><?= e((string) $l['details']) ?></td>
          <td><?= e($l['username'] ?? ($l['user_id'] ? '#' . $l['user_id'] : 'system')) ?></td>
          <td class="mono small"><?= e((string) $l['ip']) ?></td>
          <td class="nowrap small" title="<?= e(fmt_time($l['created_at'], 'd M Y, h:i:s A')) ?>"><?= e(fmt_time($l['created_at'], 'd M, h:i A')) ?><div class="muted"><span data-ago="<?= (int) $l['created_at'] ?>"><?= e(time_ago($l['created_at'])) ?></span></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php layout_end();
