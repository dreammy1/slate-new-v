<?php
/**
 * Site Timeclock — admin: Employees.
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.manage');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Employees';
$currentNav = 'timeclock-employees';
$flash      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        $name   = trim((string)($_POST['name'] ?? ''));
        $color  = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#2563EB';

        if ($action === 'create' && $name !== '') {
            Database::insert('timeclock_employees', [
                'tenant_id' => current_tenant_id(),
                'name'      => mb_substr($name, 0, 120),
                'color'     => $color,
            ]);
            AuditLog::record('timeclock.employee_created', $name);
            $flash = ['type' => 'success', 'msg' => 'Employee added.'];
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id && $name !== '') {
                Database::update('timeclock_employees',
                    ['name' => mb_substr($name, 0, 120), 'color' => $color],
                    'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                AuditLog::record('timeclock.employee_updated', "employee#$id");
                $flash = ['type' => 'success', 'msg' => 'Employee updated.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                Database::delete('timeclock_employees', 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                AuditLog::record('timeclock.employee_deleted', "employee#$id");
                $flash = ['type' => 'success', 'msg' => 'Employee deleted.'];
            }
        }
    }
}

$employees = TimeclockAPI::employees();
$editId    = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing   = $editId ? TimeclockAPI::employee($editId) : null;

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock', 'href' => SLATE_URL . '/plugins/timeclock/admin/index.php'],
    ['label' => 'Employees'],
]); ?>

<?php tc_subnav('employees'); ?>
<div class="page-header"><div><h1>Employees</h1><p class="page-header-sub">Staff who clock in.</p></div></div>

<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2><?= $editing ? 'Edit employee' : 'Add employee' ?></h2></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="field-row field-row-2">
      <div class="field">
        <label class="field-label" for="name">Name <span class="field-required">*</span></label>
        <input type="text" id="name" name="name" required maxlength="120" value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label class="field-label" for="color">Colour</label>
        <input type="color" id="color" name="color" value="<?= e($editing['color'] ?? '#2563EB') ?>">
      </div>
    </div>
    <div class="tc-row">
      <button class="btn btn-primary"><?= $editing ? 'Save' : 'Add' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="?">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h2><?= count($employees) ?> employee<?= count($employees) === 1 ? '' : 's' ?></h2></div>
  <?php if (!$employees): ?>
    <div class="empty"><p class="empty-title">No employees yet.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($employees as $emp):
        $initials = mb_strtoupper(mb_substr($emp['name'], 0, 2));
        $actions =
          '<a class="btn btn-sm" href="?edit=' . (int)$emp['id'] . '">Edit</a> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this employee?\')">'
        . csrf_field()
        . '<input type="hidden" name="_action" value="delete">'
        . '<input type="hidden" name="id" value="' . (int)$emp['id'] . '">'
        . '<button class="btn btn-sm btn-danger">Delete</button></form>';
        slate_data_row([
          'avatar'       => $initials,
          'avatar_color' => 'accent',
          'title'        => $emp['name'],
          'meta'         => $emp['color'],
          'detail'       => ['Colour' => $emp['color']],
          'actions'      => $actions,
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
