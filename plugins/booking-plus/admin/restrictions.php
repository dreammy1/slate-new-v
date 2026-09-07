<?php
/**
 * Booking+ — Reserved slots CRUD.
 *
 * Lets the practitioner reserve weekly time windows for a specific
 * service — e.g. "Every Thursday 12:00-12:20 = Discovery Call only".
 * Any candidate slot the /book widget considers is filtered against
 * these rules via the `booking_slot_allowed` filter in BookingPlus.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

Auth::require();
Auth::requirePerm('bookingplus.manage_settings');
BookingPlusAPI::ensureSchema();

$pageTitle  = 'Booking+ · Reserved slots';
$currentNav = 'bookingplus-restrictions';
$tid        = current_tenant_id();

$dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
             4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

$services  = Database::rows("SELECT id, name FROM booking_services  WHERE tenant_id = ? AND is_active = 1 ORDER BY sort_order, name", [$tid]);
$providers = Database::rows("SELECT id, name FROM booking_providers WHERE tenant_id = ? AND is_active = 1 ORDER BY name", [$tid]);

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } elseif (($_POST['_action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            Database::delete('bookingplus_slot_restrictions', 'id = ? AND tenant_id = ?', [$id, $tid]);
            $flash = ['type' => 'success', 'msg' => 'Reserved slot removed.'];
        }
    } else {
        $sid   = (int)($_POST['service_id'] ?? 0);
        $pid   = (int)($_POST['provider_id'] ?? 0); // 0 = all
        $dow   = (int)($_POST['day_of_week'] ?? -1);
        $start = trim((string)($_POST['start_time'] ?? ''));
        $end   = trim((string)($_POST['end_time'] ?? ''));
        $label = trim((string)($_POST['label'] ?? ''));

        if ($sid <= 0)                            $flash = ['type' => 'error', 'msg' => 'Pick a service.'];
        elseif ($dow < 0 || $dow > 6)             $flash = ['type' => 'error', 'msg' => 'Pick a day of the week.'];
        elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end))
                                                  $flash = ['type' => 'error', 'msg' => 'Times must be HH:MM.'];
        elseif ($end <= $start)                   $flash = ['type' => 'error', 'msg' => 'End time must be after start time.'];
        else {
            Database::insert('bookingplus_slot_restrictions', [
                'tenant_id'   => $tid,
                'service_id'  => $sid,
                'provider_id' => $pid > 0 ? $pid : null,
                'day_of_week' => $dow,
                'start_time'  => $start . ':00',
                'end_time'    => $end   . ':00',
                'label'       => $label !== '' ? mb_substr($label, 0, 80) : null,
            ]);
            $flash = ['type' => 'success', 'msg' => 'Reserved slot added.'];
        }
    }
}

$rules = Database::rows(
    "SELECT r.*, s.name AS service_name, p.name AS provider_name
       FROM bookingplus_slot_restrictions r
       JOIN booking_services  s ON s.id = r.service_id
  LEFT JOIN booking_providers p ON p.id = r.provider_id
      WHERE r.tenant_id = ?
      ORDER BY r.day_of_week, r.start_time",
    [$tid]
);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Booking+',  'href' => plugin_url('booking-plus', 'admin/index.php')],
    ['label' => 'Reserved slots'],
]);
?>

<div class="page-header">
    <div>
        <h1>Reserved slots</h1>
        <p class="text-muted">Reserve specific weekly time windows for a specific service. Example: <em>Every Thursday 12:00–12:20 is Discovery Call only</em>.</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] === 'success' ? 'success' : 'danger') ?>" style="margin-bottom:var(--space-4);">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding:var(--space-4);margin-bottom:var(--space-4);">
    <h2 style="margin-top:0;">Add reserved slot</h2>
    <form method="post" style="display:grid;grid-template-columns:repeat(6, 1fr);gap:var(--space-3);align-items:end;">
        <?= csrf_field() ?>

        <div class="field" style="grid-column:span 2;">
            <label class="field-label" for="service_id">Reserved for</label>
            <select id="service_id" name="service_id" required>
                <option value="">— pick a service —</option>
                <?php foreach ($services as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="field-label" for="day_of_week">Day</label>
            <select id="day_of_week" name="day_of_week" required>
                <?php foreach ($dayNames as $n => $lbl): ?>
                    <option value="<?= (int)$n ?>"><?= e($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="field-label" for="start_time">Start</label>
            <input type="time" id="start_time" name="start_time" required step="60">
        </div>

        <div class="field">
            <label class="field-label" for="end_time">End</label>
            <input type="time" id="end_time" name="end_time" required step="60">
        </div>

        <div class="field">
            <label class="field-label" for="provider_id">Provider</label>
            <select id="provider_id" name="provider_id">
                <option value="0">All</option>
                <?php foreach ($providers as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="grid-column:1 / -1;">
            <label class="field-label" for="label">Label <span style="color:#94a3b8;font-weight:normal;">(optional)</span></label>
            <input type="text" id="label" name="label" maxlength="80" placeholder="e.g. Thursday discovery block">
        </div>

        <div class="field" style="grid-column:1 / -1;display:flex;justify-content:flex-end;">
            <button type="submit" class="btn btn-primary">Add reserved slot</button>
        </div>
    </form>
</div>

<?php if (!$rules): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">No reserved slots yet</div>
            <p class="text-sm">Add one above and it starts filtering the /book widget immediately.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <div class="table-wrap"><table class="table">
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Reserved for</th>
                    <th>Provider</th>
                    <th>Label</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rules as $r): ?>
                <tr>
                    <td><?= e($dayNames[(int)$r['day_of_week']] ?? '?') ?></td>
                    <td><?= e(substr($r['start_time'], 0, 5)) ?> – <?= e(substr($r['end_time'], 0, 5)) ?></td>
                    <td><?= e($r['service_name']) ?></td>
                    <td><?= $r['provider_name'] ? e($r['provider_name']) : '<span class="text-muted">All</span>' ?></td>
                    <td><?= $r['label'] ? e($r['label']) : '<span class="text-muted">—</span>' ?></td>
                    <td style="text-align:right;">
                        <form method="post" style="display:inline;margin:0;" onsubmit="return confirm('Remove this reserved slot?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-danger">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
