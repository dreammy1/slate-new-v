<?php
/**
 * Site Timeclock — admin: Reports (weekly / monthly).
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.view');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Reports';
$currentNav = 'timeclock-reports';

$period = (($_GET['period'] ?? 'weekly') === 'monthly') ? 'monthly' : 'weekly';
$ref    = preg_replace('/[^0-9\-]/', '', (string)($_GET['ref'] ?? date('Y-m-d')));
if (!$ref) $ref = date('Y-m-d');

$range   = TimeclockAPI::periodRange($period, $ref);
$entries = TimeclockAPI::entries(['from' => $range['from'], 'to' => $range['to']]);

$employees = TimeclockAPI::employees();
$sites     = TimeclockAPI::sites();
$taskMap   = tc_task_map();

$empName = []; foreach ($employees as $e) $empName[(int)$e['id']] = $e['name'];
$siteName = []; foreach ($sites as $s) $siteName[(int)$s['id']] = $s['name'];

// Aggregate stats
$totalHours = 0.0;
$sessions   = count($entries);
$activeSites = [];
$totalTaskHours = 0;
$perEmp = []; // emp_id => ['sessions','hours','tasks'=>[tid=>h]]

foreach ($entries as $en) {
    $totalHours += (float)$en['total_hours'];
    $activeSites[(int)$en['site_id']] = true;
    $eid = (int)$en['employee_id'];
    if (!isset($perEmp[$eid])) $perEmp[$eid] = ['sessions' => 0, 'hours' => 0.0, 'tasks' => []];
    $perEmp[$eid]['sessions']++;
    $perEmp[$eid]['hours'] += (float)$en['total_hours'];
    foreach (TimeclockAPI::taskHours($en['tasks_json']) as $tid => $h) {
        $perEmp[$eid]['tasks'][$tid] = ($perEmp[$eid]['tasks'][$tid] ?? 0) + $h;
        $totalTaskHours += $h;
    }
}

$csvUrl = SLATE_URL . '/plugins/timeclock/admin/export.php?'
        . http_build_query(['from' => $range['from'], 'to' => $range['to']]);

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock', 'href' => SLATE_URL . '/plugins/timeclock/admin/index.php'],
    ['label' => 'Reports'],
]); ?>

<?php tc_subnav('reports'); ?>

<header class="tc-hero tc-reveal">
  <div class="tc-hero-inner">
    <div>
      <h1>Reports</h1>
      <p class="tc-hero-sub">
        <span class="tc-kbd"><?= e(ucfirst($period)) ?></span>
        <?= e($range['from']) ?> &rarr; <?= e($range['to']) ?>
      </p>
    </div>
    <div class="tc-toolbar">
      <a class="btn btn-primary" href="<?= e($csvUrl) ?>">Export CSV</a>
    </div>
  </div>
</header>

<div class="card tc-reveal">
  <form method="get" class="field-row field-row-3">
    <div class="field">
      <label class="field-label" for="period">Period</label>
      <select id="period" name="period">
        <option value="weekly"  <?= $period === 'weekly'  ? 'selected' : '' ?>>Weekly (Mon–Sun)</option>
        <option value="monthly" <?= $period === 'monthly' ? 'selected' : '' ?>>Monthly</option>
      </select>
    </div>
    <div class="field">
      <label class="field-label" for="ref">Reference date</label>
      <input type="date" id="ref" name="ref" value="<?= e($ref) ?>">
    </div>
    <div class="field" style="display:flex;align-items:flex-end">
      <button class="btn btn-primary">Update</button>
    </div>
  </form>
</div>

<div class="tc-stat-grid">
  <div class="tc-stat tc-reveal">
    <div class="tc-stat-ic"><?= tc_icon('clock') ?></div>
    <div class="tc-stat-label">Total hours</div>
    <div class="tc-stat-value"><?= number_format($totalHours, 2) ?></div>
    <div class="tc-stat-foot">across the period</div>
  </div>
  <div class="tc-stat tc-reveal">
    <div class="tc-stat-ic"><?= tc_icon('layers') ?></div>
    <div class="tc-stat-label">Work sessions</div>
    <div class="tc-stat-value"><?= (int)$sessions ?></div>
    <div class="tc-stat-foot">completed shifts</div>
  </div>
  <div class="tc-stat tc-reveal">
    <div class="tc-stat-ic"><?= tc_icon('pin') ?></div>
    <div class="tc-stat-label">Active sites</div>
    <div class="tc-stat-value"><?= count($activeSites) ?></div>
    <div class="tc-stat-foot">with logged work</div>
  </div>
  <div class="tc-stat tc-reveal">
    <div class="tc-stat-ic"><?= tc_icon('tag') ?></div>
    <div class="tc-stat-label">Task hours</div>
    <div class="tc-stat-value"><?= (int)$totalTaskHours ?></div>
    <div class="tc-stat-foot">assigned to tasks</div>
  </div>
</div>

<!-- Employee breakdown -->
<div class="tc-sec-head"><h2>Employee breakdown</h2></div>
<div class="card tc-reveal">
  <?php if (!$perEmp): ?>
    <div class="empty"><p class="empty-title">No work logged in this period.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($perEmp as $eid => $d):
        $name = $empName[$eid] ?? ('#' . $eid);
        $taskBar = tc_task_bar($d['tasks'], $taskMap);
        slate_data_row([
          'avatar'       => mb_strtoupper(mb_substr($name, 0, 2)),
          'avatar_color' => 'accent',
          'title'        => $name,
          'meta'         => $d['sessions'] . ' session' . ($d['sessions'] === 1 ? '' : 's') . ' · ' . number_format($d['hours'], 2) . 'h',
          'detail'       => [
            'Sessions' => (string)$d['sessions'],
            'Hours'    => number_format($d['hours'], 2),
            'Tasks'    => ['label' => 'Tasks', 'html' => $taskBar],
          ],
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<!-- Daily breakdown -->
<div class="tc-sec-head"><h2>Daily breakdown</h2></div>
<div class="card tc-reveal">
  <?php if (!$entries): ?>
    <div class="empty"><p class="empty-title">No entries in this period.</p></div>
  <?php else: ?>
    <div class="data-list" data-single-open>
      <?php foreach ($entries as $en):
        $th = TimeclockAPI::taskHours($en['tasks_json']);
        $eName = $empName[(int)$en['employee_id']] ?? ('#' . $en['employee_id']);
        $sName = $siteName[(int)$en['site_id']] ?? ('#' . $en['site_id']);
        slate_data_row([
          'avatar'       => mb_strtoupper(mb_substr($eName, 0, 2)),
          'avatar_color' => 'muted',
          'title'        => $eName . ' — ' . $sName,
          'meta'         => $en['work_date'] . ' · ' . $en['clock_in'] . '–' . $en['clock_out'] . ' · ' . number_format((float)$en['total_hours'], 2) . 'h',
          'detail'       => [
            'Date'  => $en['work_date'],
            'Times' => $en['clock_in'] . ' – ' . $en['clock_out'],
            'Hours' => number_format((float)$en['total_hours'], 2),
            'Tasks' => ['label' => 'Tasks', 'html' => tc_task_bar($th, $taskMap)],
          ],
        ]);
      endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
  <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
