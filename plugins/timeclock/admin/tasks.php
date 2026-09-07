<?php
/**
 * Site Timeclock — admin: Task types.
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.manage');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Tasks';
$currentNav = 'timeclock-tasks';
$flash      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        $name   = trim((string)($_POST['name'] ?? ''));
        $color  = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : '#10B981';

        if ($action === 'create' && $name !== '') {
            Database::insert('timeclock_tasks', [
                'tenant_id' => current_tenant_id(),
                'name'      => mb_substr($name, 0, 120),
                'color'     => $color,
            ]);
            AuditLog::record('timeclock.task_created', $name);
            $flash = ['type' => 'success', 'msg' => 'Task added.'];
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id && $name !== '') {
                Database::update('timeclock_tasks',
                    ['name' => mb_substr($name, 0, 120), 'color' => $color],
                    'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                AuditLog::record('timeclock.task_updated', "task#$id");
                $flash = ['type' => 'success', 'msg' => 'Task updated.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                Database::delete('timeclock_tasks', 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                AuditLog::record('timeclock.task_deleted', "task#$id");
                $flash = ['type' => 'success', 'msg' => 'Task deleted.'];
            }
        }
    }
}

$tasks   = TimeclockAPI::tasks();
$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId ? TimeclockAPI::task($editId) : null;

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock', 'href' => SLATE_URL . '/plugins/timeclock/admin/index.php'],
    ['label' => 'Tasks'],
]); ?>

<?php tc_subnav('tasks'); ?>
<div class="page-header"><div><h1>Task types</h1><p class="page-header-sub">Used in hour slots and reports.</p></div></div>

<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2><?= $editing ? 'Edit task' : 'Add task' ?></h2></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="field-row field-row-2">
      <div class="field">
        <label class="field-label" for="name">Task name <span class="field-required">*</span></label>
        <input type="text" id="name" name="name" required maxlength="120" value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="field">
        <label class="field-label" for="color">Colour</label>
        <input type="color" id="color" name="color" value="<?= e($editing['color'] ?? '#10B981') ?>">
      </div>
    </div>
    <div class="tc-row">
      <button class="btn btn-primary"><?= $editing ? 'Save' : 'Add' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="?">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h2><?= count($tasks) ?> task type<?= count($tasks) === 1 ? '' : 's' ?></h2></div>
  <?php if (!$tasks): ?>
    <div class="empty"><p class="empty-title">No task types yet.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($tasks as $t):
        $actions =
          '<a class="btn btn-sm" href="?edit=' . (int)$t['id'] . '">Edit</a> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this task?\')">'
        . csrf_field()
        . '<input type="hidden" name="_action" value="delete">'
        . '<input type="hidden" name="id" value="' . (int)$t['id'] . '">'
        . '<button class="btn btn-sm btn-danger">Delete</button></form>';
        slate_data_row([
          'avatar'       => mb_strtoupper(mb_substr($t['name'], 0, 2)),
          'avatar_color' => 'success',
          'title'        => $t['name'],
          'meta'         => $t['color'],
          'detail'       => ['Colour' => ['label' => 'Colour', 'html' => '<span class="tc-task-chip" style="background:' . e($t['color']) . '">' . e($t['name']) . '</span>']],
          'actions'      => $actions,
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
