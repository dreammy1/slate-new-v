<?php
/**
 * Site Timeclock — employee-facing clock app (public route /timeclock).
 * Kiosk-style: pick your name, clock in/out, assign hourly task slots.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('timeclock');
require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$flash    = null;
$selected = isset($_GET['emp']) ? (int) $_GET['emp'] : 0;

// -- Handle actions -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        $action = $_POST['_action'] ?? '';
        $empId  = (int) ($_POST['employee_id'] ?? 0);
        $selected = $empId;

        if ($action === 'clock_in') {
            $siteId  = (int) ($_POST['site_id'] ?? 0);
            $clockIn = TimeclockAPI::sanitizeTime((string) ($_POST['clock_in'] ?? ''));
            if ($empId && $siteId && TimeclockAPI::employee($empId) && TimeclockAPI::site($siteId)) {
                if (TimeclockAPI::clockIn($empId, $siteId, $clockIn, date('Y-m-d'))) {
                    $flash = ['type' => 'success', 'msg' => 'Clocked in at ' . e($clockIn) . '.'];
                } else {
                    $flash = ['type' => 'warning', 'msg' => 'You are already clocked in.'];
                }
            } else {
                $flash = ['type' => 'error', 'msg' => 'Please choose a site.'];
            }
        } elseif ($action === 'clock_out') {
            $clockOut = TimeclockAPI::sanitizeTime((string) ($_POST['clock_out'] ?? ''));
            $tasks    = $_POST['tasks'] ?? [];
            $hasTask  = false;
            foreach ((array) $tasks as $t) { if ($t !== '' && $t !== null) { $hasTask = true; break; } }
            if (!$hasTask) {
                $flash = ['type' => 'error', 'msg' => 'Assign at least one task hour before clocking out.'];
            } else {
                $newId = TimeclockAPI::clockOut($empId, $clockOut, (array) $tasks);
                if ($newId) {
                    $flash = ['type' => 'success', 'msg' => 'Clocked out. Have a good one!'];
                    $selected = 0; // back to grid
                } else {
                    $flash = ['type' => 'error', 'msg' => 'No open shift found.'];
                }
            }
        }
    }
}

$employees = TimeclockAPI::employees();
$sites     = TimeclockAPI::sites();
$tasks     = TimeclockAPI::tasks();
$activeMap = TimeclockAPI::activeMap();

// Branding (read-only settings set in admin → Settings)
$brandName = Database::setting('business_name') ?: (Database::setting('site_name') ?: 'Site Timeclock');
$brandAccent = Database::setting('brand_accent_color');
if (!is_string($brandAccent) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandAccent)) $brandAccent = '#2563EB';

$pageTitle = 'Clock In / Out';
$active    = $selected ? ($activeMap[$selected] ?? null) : null;
$emp       = $selected ? TimeclockAPI::employee($selected) : null;
if (!$emp) { $selected = 0; $active = null; }

// Minimal standalone chrome (public kiosk). Keep it self-contained.
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e($brandName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<?php tc_styles(); /* shared base first, kiosk block below overrides for dark theme */ ?>
<style>
:root{
  /* Dark kiosk palette — shared tc_styles() classes inherit these tokens */
  --accent:<?= $brandAccent ?>;
  --bg:#0a0f1e;--surface:#121a2e;--surface-2:#1b2540;
  --accent-soft:rgba(37,99,235,.16);
  --success:#22c55e;--warning:#f59e0b;--danger:#ef4444;--info:#38bdf8;
  --text:#f1f5f9;--text-2:#cbd5e1;--muted:#94a3b8;--subtle:#64748b;
  --border:rgba(148,163,184,.16);--border-strong:rgba(148,163,184,.32);
  --radius-sm:10px;--radius-lg:18px;--radius-xl:26px;
  --font-sans:"DM Sans",system-ui,-apple-system,sans-serif;
  --font-display:"Syne",var(--font-sans);
  --font-mono:ui-monospace,"SF Mono",Menlo,monospace;
  --space-1:4px;--space-2:8px;--space-3:12px;--space-4:16px;--space-5:20px;--space-6:24px;
  --space-7:32px;--space-8:40px;
}
*{box-sizing:border-box;}
html{-webkit-text-size-adjust:100%;}
body{margin:0;color:var(--text);font-family:var(--font-sans);min-height:100vh;
  padding:var(--space-6) var(--space-5) var(--space-8);
  background:
    radial-gradient(900px 520px at 12% -8%, rgba(37,99,235,.22), transparent 60%),
    radial-gradient(760px 520px at 92% 4%, rgba(56,189,248,.14), transparent 55%),
    linear-gradient(180deg,#0a0f1e 0%, #0b1226 100%);
  background-attachment:fixed;}
/* faint grid texture for the "tech" atmosphere */
body::before{content:"";position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.5;
  background-image:linear-gradient(rgba(148,163,184,.05) 1px,transparent 1px),
    linear-gradient(90deg,rgba(148,163,184,.05) 1px,transparent 1px);
  background-size:34px 34px;
  -webkit-mask-image:radial-gradient(100% 70% at 50% 0%,#000,transparent 75%);
          mask-image:radial-gradient(100% 70% at 50% 0%,#000,transparent 75%);}
a{color:inherit;text-decoration:none;}

.tc-wrap{position:relative;z-index:1;max-width:780px;margin:0 auto;}
.tc-app-head{text-align:center;margin-bottom:var(--space-6);}
.tc-brandmark{display:inline-flex;align-items:center;gap:10px;margin-bottom:var(--space-2);
  font-weight:700;letter-spacing:.02em;color:var(--text-2);}
.tc-brandmark .dot{width:10px;height:10px;border-radius:3px;background:var(--accent);
  box-shadow:0 0 14px var(--accent);}
.tc-app-head h1{font-family:var(--font-display);font-size:2rem;margin:0 0 4px;
  letter-spacing:-.02em;
  background:linear-gradient(180deg,#fff,#9fb4d8);-webkit-background-clip:text;
  background-clip:text;-webkit-text-fill-color:transparent;}
.tc-app-head p{color:var(--muted);margin:0;font-size:.95rem;}

@keyframes tcRise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}
.card{position:relative;border:1px solid var(--border);border-radius:var(--radius-lg);
  padding:var(--space-6);margin-bottom:var(--space-4);
  background:linear-gradient(160deg,rgba(255,255,255,.04),rgba(255,255,255,.01));
  backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  box-shadow:0 24px 60px -34px rgba(0,0,0,.8), inset 0 1px 0 rgba(255,255,255,.04);
  animation:tcRise .5s cubic-bezier(.2,.7,.2,1) both;}
.card h2,.card h3{font-family:var(--font-display);}

.alert{border-radius:var(--radius-sm);padding:var(--space-3) var(--space-4);
  margin-bottom:var(--space-4);font-weight:600;border:1px solid transparent;backdrop-filter:blur(6px);}
.alert-success{background:rgba(34,197,94,.14);color:#86efac;border-color:rgba(34,197,94,.3);}
.alert-error{background:rgba(239,68,68,.14);color:#fca5a5;border-color:rgba(239,68,68,.3);}
.alert-warning{background:rgba(245,158,11,.14);color:#fcd34d;border-color:rgba(245,158,11,.3);}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;
  border:1px solid var(--border-strong);background:rgba(255,255,255,.05);color:var(--text);
  border-radius:999px;padding:12px 22px;font-weight:700;cursor:pointer;font-size:.95rem;
  font-family:var(--font-sans);transition:transform .12s ease,box-shadow .2s ease,background .2s ease;}
.btn:hover{transform:translateY(-1px);background:rgba(255,255,255,.09);}
.btn:active{transform:translateY(0);}
.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;
  box-shadow:0 14px 30px -12px var(--accent);}
.btn-primary:hover{box-shadow:0 18px 38px -12px var(--accent);}
.btn-danger{background:var(--danger);border-color:var(--danger);color:#fff;
  box-shadow:0 14px 30px -12px var(--danger);}
.btn-ghost{background:transparent;border-color:transparent;color:var(--muted);}
.btn-ghost:hover{color:var(--text);background:rgba(255,255,255,.05);}
.btn[disabled]{opacity:.45;cursor:not-allowed;transform:none;box-shadow:none;}
.btn-lg{padding:15px 28px;font-size:1rem;}

.field{margin-bottom:var(--space-4);}
.field-label{display:block;font-weight:600;margin-bottom:8px;color:var(--text-2);}
select,input[type=time],input[type=text],input[type=email]{width:100%;padding:13px 14px;
  border:1px solid var(--border-strong);border-radius:var(--radius-sm);font:inherit;
  background:rgba(10,15,30,.6);color:var(--text);transition:border-color .15s,box-shadow .15s;}
select:focus,input:focus{outline:none;border-color:var(--accent);
  box-shadow:0 0 0 3px var(--accent-soft);}
select option{background:#121a2e;color:var(--text);}
.tc-row{display:flex;gap:var(--space-3);flex-wrap:wrap;align-items:center;}

/* Employee grid — glowing tiles, no link-blue */
.tc-emp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:var(--space-4);}
.tc-emp{display:flex;flex-direction:column;align-items:center;gap:var(--space-3);
  padding:var(--space-5) var(--space-3);border:1px solid var(--border);
  border-radius:var(--radius-lg);background:rgba(255,255,255,.03);cursor:pointer;
  text-align:center;color:var(--text);text-decoration:none;
  transition:transform .14s ease,border-color .14s ease,box-shadow .2s ease,background .2s ease;}
.tc-emp:hover{transform:translateY(-4px);border-color:var(--accent);background:rgba(255,255,255,.06);
  box-shadow:0 18px 40px -20px var(--accent);}
.tc-emp:active{transform:translateY(-1px);}
.tc-emp-avatar{width:60px;height:60px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;color:#fff;font-weight:700;font-size:1.2rem;font-family:var(--font-display);
  box-shadow:0 0 0 4px rgba(255,255,255,.06), 0 10px 24px -8px rgba(0,0,0,.6);}
.tc-emp-name{font-weight:700;color:var(--text);overflow-wrap:anywhere;min-width:0;}
.tc-emp-badge{font-size:.68rem;font-weight:800;padding:3px 12px;border-radius:999px;
  text-transform:uppercase;letter-spacing:.05em;}
.tc-badge-in{background:rgba(34,197,94,.18);color:#86efac;border:1px solid rgba(34,197,94,.4);}
.tc-badge-off{background:rgba(148,163,184,.12);color:var(--muted);border:1px solid var(--border);}

/* Live timer — hero numerals with glow */
.tc-timer{font-family:var(--font-mono);font-size:3rem;font-weight:700;letter-spacing:.06em;
  color:#fff;text-align:center;margin:var(--space-4) 0 0;
  text-shadow:0 0 30px var(--accent);}

/* Hour slots */
.tc-slots{display:flex;flex-direction:column;gap:var(--space-2);}
.tc-slot{display:flex;align-items:center;gap:var(--space-3);border:1px dashed var(--border-strong);
  border-radius:var(--radius-sm);padding:var(--space-2) var(--space-3);min-height:48px;
  background:rgba(10,15,30,.4);transition:border-color .12s,background .12s;}
.tc-slot.tc-over{border-style:solid;border-color:var(--accent);background:var(--accent-soft);}
.tc-slot.tc-filled{border-style:solid;border-color:var(--border-strong);cursor:pointer;}
.tc-slot-hour{font-family:var(--font-mono);color:var(--subtle);width:68px;flex:none;font-size:.78rem;}
.tc-slot-task{flex:1;font-weight:700;color:#fff;border-radius:8px;padding:6px 12px;
  overflow-wrap:anywhere;min-width:0;}
.tc-slot-empty{flex:1;color:var(--subtle);font-size:.85rem;}

/* Task palette */
.tc-palette{display:flex;flex-wrap:wrap;gap:var(--space-2);}
.tc-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:8px 16px;
  color:#fff;font-weight:700;cursor:grab;user-select:none;font-size:.85rem;
  box-shadow:0 8px 20px -10px rgba(0,0,0,.7);transition:transform .1s;}
.tc-chip:hover{transform:translateY(-1px);}
.tc-chip:active{cursor:grabbing;}

.tc-muted{color:var(--muted);}
@media (prefers-reduced-motion:reduce){.card{animation:none}.tc-emp,.btn{transition:none}}
@media (max-width:560px){.tc-app-head h1{font-size:1.6rem}.tc-timer{font-size:2.4rem}}
</style>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="tc-wrap">
  <div class="tc-app-head">
    <span class="tc-brandmark"><span class="dot"></span><?= e($brandName) ?></span>
    <h1>Site Timeclock</h1>
    <p><?= e(date('l, j M Y')) ?></p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
  <?php endif; ?>

  <?php if (!$selected): /* ---- Employee selection grid ---- */ ?>
    <div class="card">
      <h2 style="margin-top:0">Who are you?</h2>
      <?php if (!$employees): ?>
        <p class="tc-muted">No employees set up yet. Ask your administrator to add staff.</p>
      <?php else: ?>
        <div class="tc-emp-grid">
          <?php foreach ($employees as $e):
              $isIn = isset($activeMap[(int)$e['id']]);
              $initials = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z ]/', '', $e['name']), 0, 2));
              if (trim($initials) === '') $initials = mb_strtoupper(mb_substr($e['name'], 0, 2));
          ?>
            <a class="tc-emp" href="?emp=<?= (int)$e['id'] ?>">
              <span class="tc-emp-avatar" style="background:<?= e($e['color']) ?>"><?= e($initials) ?></span>
              <span class="tc-emp-name"><?= e($e['name']) ?></span>
              <span class="tc-emp-badge <?= $isIn ? 'tc-badge-in' : 'tc-badge-off' ?>">
                <?= $isIn ? 'Clocked In' : 'Off' ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif (!$active): /* ---- Clock in ---- */ ?>
    <div class="card">
      <div class="tc-row" style="justify-content:space-between">
        <h2 style="margin:0">Clock in — <?= e($emp['name']) ?></h2>
        <a class="btn btn-ghost" href="?">← Back</a>
      </div>
      <?php if (!$sites): ?>
        <p class="tc-muted">No construction sites configured yet.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="_action" value="clock_in">
          <input type="hidden" name="employee_id" value="<?= (int)$selected ?>">
          <div class="field">
            <label class="field-label" for="site_id">Construction site</label>
            <select id="site_id" name="site_id" required>
              <option value="">Choose a site…</option>
              <?php foreach ($sites as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="field-label" for="clock_in">Clock-in time</label>
            <input type="time" id="clock_in" name="clock_in" value="<?= e(date('H:i')) ?>" required>
          </div>
          <button class="btn btn-primary">Clock In</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h3 style="margin-top:0">Forgot to log yesterday?</h3>
      <p class="tc-muted">Send a correction request to the site owner.</p>
      <?php
        $owner = TimeclockAPI::ownerEmail();
        $subject = rawurlencode('Missed time log — ' . $emp['name']);
        $body = rawurlencode(
            "Hi,\n\nI forgot to log my hours. Please add an entry:\n\n"
          . "Employee: " . $emp['name'] . "\n"
          . "Date: \n"
          . "Site: \n"
          . "Clock in: \n"
          . "Clock out: \n"
          . "Tasks: \n\nThanks."
        );
      ?>
      <?php if ($owner): ?>
        <a class="btn" href="mailto:<?= e($owner) ?>?subject=<?= $subject ?>&body=<?= $body ?>">Email a request</a>
      <?php else: ?>
        <p class="tc-muted">No owner email configured. Ask your administrator to set one.</p>
      <?php endif; ?>
    </div>

  <?php else: /* ---- Clock out ---- */
      $siteRow = TimeclockAPI::site((int)$active['site_id']);
      $startMs = strtotime($active['started_at']) * 1000;
  ?>
    <div class="card">
      <div class="tc-row" style="justify-content:space-between">
        <h2 style="margin:0">On site — <?= e($emp['name']) ?></h2>
        <a class="btn btn-ghost" href="?">← Back</a>
      </div>
      <p class="tc-muted">
        <?= e($siteRow['name'] ?? 'Site') ?> · clocked in at <?= e($active['clock_in']) ?>
      </p>
      <div class="tc-timer" id="tc-timer" data-start="<?= (int)$startMs ?>">00:00:00</div>
    </div>

    <form method="post" id="tc-clockout-form">
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="clock_out">
      <input type="hidden" name="employee_id" value="<?= (int)$selected ?>">

      <div class="card">
        <h3 style="margin-top:0">Assign your hours</h3>
        <p class="tc-muted">Drag a task onto an hour slot (max 10). Click a filled slot to clear it.</p>

        <div class="field">
          <label class="field-label">Tasks</label>
          <div class="tc-palette">
            <?php foreach ($tasks as $t): ?>
              <span class="tc-chip"
                    data-task-id="<?= (int)$t['id'] ?>"
                    data-task-name="<?= e($t['name']) ?>"
                    data-task-color="<?= e($t['color']) ?>"
                    style="background:<?= e($t['color']) ?>"><?= e($t['name']) ?></span>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="tc-slots">
          <?php for ($i = 0; $i < 10; $i++): ?>
            <div class="tc-slot" data-slot="<?= $i ?>">
              <span class="tc-slot-hour">Hour <?= $i + 1 ?></span>
              <span class="tc-slot-empty tc-slot-body">Drag a task here</span>
              <input type="hidden" name="tasks[<?= $i ?>]" value="">
            </div>
          <?php endfor; ?>
        </div>

        <div class="tc-row" style="margin-top:var(--space-4);justify-content:space-between">
          <span id="tc-summary" class="tc-muted">No hours assigned yet.</span>
          <button type="button" class="btn btn-ghost" id="tc-clear-all">Clear All</button>
        </div>
      </div>

      <div class="card">
        <div class="field">
          <label class="field-label" for="clock_out">Clock-out time</label>
          <input type="time" id="clock_out" name="clock_out" value="<?= e(date('H:i')) ?>" required>
        </div>
        <button class="btn btn-danger" id="tc-clockout-btn" disabled>Clock Out</button>
      </div>
    </form>

    <script src="<?= e(SLATE_URL) ?>/plugins/timeclock/assets/js/timeclock.js"></script>
  <?php endif; ?>
</div>
</body>
</html>
