<?php
/**
 * Site Timeclock — admin: Sites (+ owner email config for "forgot to log").
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.manage');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Sites';
$currentNav = 'timeclock-sites';
$flash      = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save_owner') {
            $email = trim((string)($_POST['owner_email'] ?? ''));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Database::setSetting('timeclock.owner_email', $email);
                $flash = ['type' => 'success', 'msg' => 'Owner email saved.'];
            } else {
                $flash = ['type' => 'error', 'msg' => 'That does not look like a valid email.'];
            }
        } else {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($action === 'create' && $name !== '') {
                Database::insert('timeclock_sites', [
                    'tenant_id' => current_tenant_id(),
                    'name'      => mb_substr($name, 0, 160),
                ]);
                AuditLog::record('timeclock.site_created', $name);
                $flash = ['type' => 'success', 'msg' => 'Site added.'];
            } elseif ($action === 'update') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id && $name !== '') {
                    Database::update('timeclock_sites',
                        ['name' => mb_substr($name, 0, 160)],
                        'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                    AuditLog::record('timeclock.site_updated', "site#$id");
                    $flash = ['type' => 'success', 'msg' => 'Site updated.'];
                }
            } elseif ($action === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    Database::delete('timeclock_sites', 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                    AuditLog::record('timeclock.site_deleted', "site#$id");
                    $flash = ['type' => 'success', 'msg' => 'Site deleted.'];
                }
            }
        }
    }
}

$sites      = TimeclockAPI::sites();
$ownerEmail = TimeclockAPI::ownerEmail();
$editId     = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing    = $editId ? TimeclockAPI::site($editId) : null;

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock', 'href' => SLATE_URL . '/plugins/timeclock/admin/index.php'],
    ['label' => 'Sites'],
]); ?>

<?php tc_subnav('sites'); ?>
<div class="page-header"><div><h1>Sites</h1><p class="page-header-sub">Construction sites.</p></div></div>

<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
  <div class="card-header"><h2>Owner email</h2></div>
  <p class="tc-muted">Used by the “Forgot to log yesterday?” request on the clock app.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="save_owner">
    <div class="field">
      <label class="field-label" for="owner_email">Email address</label>
      <input type="email" id="owner_email" name="owner_email" value="<?= e($ownerEmail) ?>" placeholder="owner@example.com">
    </div>
    <button class="btn btn-primary">Save email</button>
  </form>
</div>

<div class="card">
  <div class="card-header"><h2><?= $editing ? 'Edit site' : 'Add site' ?></h2></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <div class="field">
      <label class="field-label" for="name">Site name <span class="field-required">*</span></label>
      <input type="text" id="name" name="name" required maxlength="160" value="<?= e($editing['name'] ?? '') ?>">
    </div>
    <div class="tc-row">
      <button class="btn btn-primary"><?= $editing ? 'Save' : 'Add' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="?">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-header"><h2><?= count($sites) ?> site<?= count($sites) === 1 ? '' : 's' ?></h2></div>
  <?php if (!$sites): ?>
    <div class="empty"><p class="empty-title">No sites yet.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($sites as $s):
        $actions =
          '<a class="btn btn-sm" href="?edit=' . (int)$s['id'] . '">Edit</a> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this site?\')">'
        . csrf_field()
        . '<input type="hidden" name="_action" value="delete">'
        . '<input type="hidden" name="id" value="' . (int)$s['id'] . '">'
        . '<button class="btn btn-sm btn-danger">Delete</button></form>';
        slate_data_row([
          'avatar'       => mb_strtoupper(mb_substr($s['name'], 0, 2)),
          'avatar_color' => 'info',
          'title'        => $s['name'],
          'actions'      => $actions,
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
