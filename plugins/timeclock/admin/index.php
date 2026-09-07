<?php
/**
 * Site Timeclock — admin: Time Entries.
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.view');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Time Entries';
$currentNav = 'timeclock';
$flash      = null;

$canManage = Auth::can('timeclock.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } elseif (!$canManage) {
        $flash = ['type' => 'error', 'msg' => 'You do not have permission to change entries.'];
    } else {
        $action = $_POST['_action'] ?? '';

        // Build a slot-keyed task array from posted tasks[].
        $tasks = [];
        foreach ((array)($_POST['tasks'] ?? []) as $slot => $tid) {
            $tasks[(int)$slot] = $tid;
        }

        if ($action === 'create') {
            TimeclockAPI::createEntry([
                'employee_id' => (int)($_POST['employee_id'] ?? 0),
                'site_id'     => (int)($_POST['site_id'] ?? 0),
                'work_date'   => (string)($_POST['work_date'] ?? date('Y-m-d')),
                'clock_in'    => (string)($_POST['clock_in'] ?? '08:00'),
                'clock_out'   => (string)($_POST['clock_out'] ?? '17:00'),
                'tasks'       => $tasks,
            ]);
            AuditLog::record('timeclock.entry_created', 'manual entry');
            $flash = ['type' => 'success', 'msg' => 'Entry added.'];
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id && TimeclockAPI::entry($id)) {
                TimeclockAPI::updateEntry($id, [
                    'employee_id' => (int)($_POST['employee_id'] ?? 0),
                    'site_id'     => (int)($_POST['site_id'] ?? 0),
                    'work_date'   => (string)($_POST['work_date'] ?? date('Y-m-d')),
                    'clock_in'    => (string)($_POST['clock_in'] ?? '08:00'),
                    'clock_out'   => (string)($_POST['clock_out'] ?? '17:00'),
                    'tasks'       => $tasks,
                ]);
                AuditLog::record('timeclock.entry_updated', "entry#$id");
                $flash = ['type' => 'success', 'msg' => 'Entry updated.'];
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                TimeclockAPI::deleteEntry($id);
                AuditLog::record('timeclock.entry_deleted', "entry#$id");
                $flash = ['type' => 'success', 'msg' => 'Entry deleted.'];
            }
        }
    }
}

// Filters
$filters = [];
if (!empty($_GET['employee_id'])) $filters['employee_id'] = (int)$_GET['employee_id'];
if (!empty($_GET['date']))        $filters['date']        = preg_replace('/[^0-9\-]/', '', $_GET['date']);

$employees = TimeclockAPI::employees();
$sites     = TimeclockAPI::sites();
$tasks     = TimeclockAPI::tasks();
$entries   = TimeclockAPI::entries($filters);
$taskMap   = tc_task_map();

$empName = []; foreach ($employees as $e) $empName[(int)$e['id']] = $e['name'];
$siteName = []; foreach ($sites as $s) $siteName[(int)$s['id']] = $s['name'];

// Live status for the hero
$activeNow = count(TimeclockAPI::activeMap());
$clockUrl  = SLATE_URL . '/timeclock';

// Edit target
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId ? TimeclockAPI::entry($editId) : null;

// CSV export link carrying current filters
$csvParams = ['_action' => 'csv'];
if (!empty($filters['employee_id'])) $csvParams['employee_id'] = $filters['employee_id'];
if (!empty($filters['date'])) { $csvParams['from'] = $filters['date']; $csvParams['to'] = $filters['date']; }
$csvUrl = SLATE_URL . '/plugins/timeclock/admin/export.php?' . http_build_query($csvParams);

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock'],
]); ?>

<?php tc_subnav('entries'); ?>

<header class="tc-hero tc-reveal">
  <div class="tc-hero-inner">
    <div>
      <h1>Time Entries</h1>
      <p class="tc-hero-sub">
        <span class="tc-pulse" aria-hidden="true"></span>
        <?php if ($activeNow): ?>
          <strong><?= (int)$activeNow ?></strong>&nbsp;currently on the clock
        <?php else: ?>
          No one clocked in right now
        <?php endif; ?>
        <span class="tc-kbd"><?= count($entries) ?> entries shown</span>
      </p>
    </div>
    <div class="tc-toolbar">
      <a class="btn btn-primary" href="<?= e($csvUrl) ?>">Export CSV</a>
      <a class="btn" href="<?= e($clockUrl) ?>" target="_blank" rel="noopener">Open clock app &nearr;</a>
      <a class="btn" href="<?= e(SLATE_URL) ?>/clock-portal" target="_blank" rel="noopener">Landing page &nearr;</a>
    </div>
  </div>
</header>

<?php if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- Filters -->
<div class="card">
  <form method="get" class="field-row field-row-3">
    <div class="field">
      <label class="field-label" for="f_emp">Employee</label>
      <select id="f_emp" name="employee_id">
        <option value="">All employees</option>
        <?php foreach ($employees as $e): ?>
          <option value="<?= (int)$e['id'] ?>" <?= (!empty($filters['employee_id']) && $filters['employee_id'] == $e['id']) ? 'selected' : '' ?>>
            <?= e($e['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="f_date">Date</label>
      <input type="date" id="f_date" name="date" value="<?= e($filters['date'] ?? '') ?>">
    </div>
    <div class="field" style="display:flex;align-items:flex-end;gap:8px">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-ghost" href="?">Reset</a>
    </div>
  </form>
</div>

<?php if ($canManage): ?>
<!-- Add / edit entry -->
<div class="card">
  <div class="card-header"><h2><?= $editing ? 'Edit entry' : 'Add entry (back-date / correct)' ?></h2></div>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>

    <div class="field-row field-row-3">
      <div class="field">
        <label class="field-label" for="employee_id">Employee <span class="field-required">*</span></label>
        <select id="employee_id" name="employee_id" required>
          <?php foreach ($employees as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= ($editing && $editing['employee_id'] == $e['id']) ? 'selected' : '' ?>><?= e($e['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="site_id">Site <span class="field-required">*</span></label>
        <select id="site_id" name="site_id" required>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ($editing && $editing['site_id'] == $s['id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field-label" for="work_date">Date <span class="field-required">*</span></label>
        <input type="date" id="work_date" name="work_date" required value="<?= e($editing['work_date'] ?? date('Y-m-d')) ?>">
      </div>
    </div>

    <div class="field-row field-row-2">
      <div class="field">
        <label class="field-label" for="clock_in">Clock in <span class="field-required">*</span></label>
        <input type="time" id="clock_in" name="clock_in" required value="<?= e($editing['clock_in'] ?? '08:00') ?>">
      </div>
      <div class="field">
        <label class="field-label" for="clock_out">Clock out <span class="field-required">*</span></label>
        <input type="time" id="clock_out" name="clock_out" required value="<?= e($editing['clock_out'] ?? '17:00') ?>">
      </div>
    </div>

    <div class="field">
      <label class="field-label">Task hours (10 slots — choose a task per worked hour)</label>
      <?php $editTasks = $editing ? TimeclockAPI::decodeTasks($editing['tasks_json']) : []; ?>
      <div class="field-row field-row-3">
        <?php for ($i = 0; $i < 10; $i++): $cur = $editTasks[(string)$i] ?? ($editTasks[$i] ?? ''); ?>
          <div class="field">
            <label class="field-label tc-muted" for="slot<?= $i ?>">Hour <?= $i + 1 ?></label>
            <select id="slot<?= $i ?>" name="tasks[<?= $i ?>]">
              <option value="">—</option>
              <?php foreach ($tasks as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= ((int)$cur === (int)$t['id']) ? 'selected' : '' ?>><?= e($t['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="tc-row">
      <button class="btn btn-primary"><?= $editing ? 'Save changes' : 'Add entry' ?></button>
      <?php if ($editing): ?><a class="btn btn-ghost" href="?">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Entries list -->
<div class="card">
  <div class="card-header"><h2><?= count($entries) ?> entr<?= count($entries) === 1 ? 'y' : 'ies' ?></h2></div>
  <?php if (!$entries): ?>
    <div class="empty"><p class="empty-title">No entries match your filters.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($entries as $en):
        $th = TimeclockAPI::taskHours($en['tasks_json']);
        $eName = $empName[(int)$en['employee_id']] ?? ('#' . $en['employee_id']);
        $sName = $siteName[(int)$en['site_id']] ?? ('#' . $en['site_id']);
        $actions = '';
        if ($canManage) {
          $actions =
            '<a class="btn btn-sm" href="?edit=' . (int)$en['id'] . '">Edit</a> '
          . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this entry?\')">'
          . csrf_field()
          . '<input type="hidden" name="_action" value="delete">'
          . '<input type="hidden" name="id" value="' . (int)$en['id'] . '">'
          . '<button class="btn btn-sm btn-danger">Delete</button></form>';
        }
        slate_data_row([
          'avatar'       => mb_strtoupper(mb_substr($eName, 0, 2)),
          'avatar_color' => 'accent',
          'title'        => $eName . ' — ' . $sName,
          'meta'         => $en['work_date'] . ' · ' . number_format((float)$en['total_hours'], 2) . 'h',
          'badge'        => [$en['clock_in'] . '–' . $en['clock_out'], 'accent'],
          'detail'       => [
            'Date'      => $en['work_date'],
            'Clock in'  => $en['clock_in'],
            'Clock out' => $en['clock_out'],
            'Hours'     => number_format((float)$en['total_hours'], 2),
            'Tasks'     => ['label' => 'Tasks', 'html' => tc_task_bar($th, $taskMap)],
          ],
          'actions'      => $actions,
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
