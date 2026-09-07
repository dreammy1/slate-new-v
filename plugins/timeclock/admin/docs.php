<?php
/**
 * Site Timeclock — admin: Documentation (in-app).
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.view');

require_once dirname(__DIR__) . '/TimeclockAPI.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$pageTitle  = 'Documentation';
$currentNav = 'timeclock-docs';

$clockUrl = SLATE_URL . '/timeclock';
$ver      = '1.0.0';

require SLATE_ROOT . '/admin/partials/header.php';
tc_styles();
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Timeclock', 'href' => SLATE_URL . '/plugins/timeclock/admin/index.php'],
    ['label' => 'Documentation'],
]); ?>

<?php tc_subnav('docs'); ?>

<header class="tc-hero tc-reveal">
  <div class="tc-hero-inner">
    <div>
      <h1>Documentation</h1>
      <p class="tc-hero-sub">
        <span class="tc-kbd">v<?= e($ver) ?></span>
        Everything you need to run Site Timeclock.
      </p>
    </div>
    <div class="tc-toolbar">
      <a class="btn btn-primary" href="<?= e($clockUrl) ?>" target="_blank" rel="noopener">Open clock app &nearr;</a>
    </div>
  </div>
</header>

<div class="tc-doc tc-reveal">
  <!-- TOC -->
  <nav class="tc-doc-toc" aria-label="On this page">
    <strong style="display:block;margin-bottom:8px;font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)">On this page</strong>
    <a href="#overview">Overview</a>
    <a href="#quickstart">Quick start</a>
    <a href="#employee">Employee clock app</a>
    <a href="#entries">Managing entries</a>
    <a href="#people">Employees, sites &amp; tasks</a>
    <a href="#reports">Reports &amp; CSV</a>
    <a href="#forgot">“Forgot to log”</a>
    <a href="#perms">Permissions</a>
    <a href="#faq">Troubleshooting</a>
  </nav>

  <!-- Body -->
  <div class="tc-doc-body">

    <section class="card">
      <h2 id="overview">Overview</h2>
      <p>Site Timeclock tracks construction crews: workers clock in against a site,
         a live timer runs while they work, and on clock-out they assign their
         hours across task types. Completed shifts become permanent entries you
         can filter, correct, report on, and export.</p>
      <div class="tc-callout">
        The worker-facing app lives at <code><?= e($clockUrl) ?></code> — a kiosk-style
        page that needs no login. There is also a branded landing page at
        <code><?= e(SLATE_URL) ?>/clock-portal</code> with feature highlights and all
        sign-in links (crew clock, admin, staff portal). Everything under this admin
        area is permission-gated.
      </div>
    </section>

    <section class="card">
      <h2 id="quickstart">Quick start</h2>
      <div class="tc-step"><span class="tc-step-n">1</span><div><strong>Add your crew.</strong> Go to <em>Employees</em> and add each worker with a name and colour (the colour drives their avatar).</div></div>
      <div class="tc-step"><span class="tc-step-n">2</span><div><strong>Add your sites.</strong> Under <em>Sites</em>, list each construction site. Set the owner email here too.</div></div>
      <div class="tc-step"><span class="tc-step-n">3</span><div><strong>Define task types.</strong> <em>Tasks</em> seeds a few defaults (General Labour, Concrete, Framing, Cleanup) — edit or add your own, each with a colour.</div></div>
      <div class="tc-step"><span class="tc-step-n">4</span><div><strong>Share the clock app.</strong> Open <code><?= e($clockUrl) ?></code> on the site tablet or kiosk. Workers tap their name to clock in.</div></div>
      <div class="tc-step"><span class="tc-step-n">5</span><div><strong>Review &amp; report.</strong> Entries appear here as they complete. Use <em>Reports</em> for weekly/monthly rollups and CSV export.</div></div>
    </section>

    <section class="card">
      <h2 id="employee">Employee clock app</h2>
      <p>At <code><?= e($clockUrl) ?></code> workers see a grid of avatars, each badged
         <strong>Clocked In</strong> or <strong>Off</strong> for today.</p>
      <h3>Clocking in</h3>
      <p>Tap your name, pick a construction site from the dropdown, confirm the
         clock-in time (defaults to now), and tap <em>Clock In</em>. A live
         <code>HH:MM:SS</code> timer starts and keeps running until clock-out.</p>
      <h3>Clocking out &amp; assigning hours</h3>
      <p>The workday has ten one-hour slots. Drag a task chip onto a slot to fill it,
         click a filled slot to clear it, or use <em>Clear All</em> to reset. A summary
         shows total assigned hours.</p>
      <div class="tc-callout">
        Clock-out is intentionally blocked until at least one task hour is assigned —
        the button stays disabled so no shift is recorded without task detail.
      </div>
    </section>

    <section class="card">
      <h2 id="entries">Managing entries</h2>
      <p>The <em>Time Entries</em> page lists every completed shift with employee,
         site, date, clock in/out, total hours, and a colour-coded task bar.</p>
      <h3>Filtering</h3>
      <p>Narrow the list by employee, by date, or both. The CSV export respects the
         active filter.</p>
      <h3>Adding &amp; correcting</h3>
      <p>Use the add form to back-date a missed shift or fix an error — pick the
         employee, site, date, times, and a task per worked hour. Editing an entry
         recomputes total hours from the times automatically. Deleting asks for
         confirmation and is permanent.</p>
    </section>

    <section class="card">
      <h2 id="people">Employees, sites &amp; tasks</h2>
      <p>Three management pages, each with the same list / add / edit / delete flow:</p>
      <p><strong>Employees</strong> — name + colour (avatar). <strong>Sites</strong> —
         construction sites, plus the owner-email setting. <strong>Tasks</strong> —
         task types with colours used in the hour slots and every report.</p>
      <div class="tc-callout">
        Deleting an employee, site, or task does not rewrite historical entries —
        past entries keep the values recorded at the time.
      </div>
    </section>

    <section class="card">
      <h2 id="reports">Reports &amp; CSV</h2>
      <p>Choose <em>Weekly</em> (Monday–Sunday) or <em>Monthly</em> and a reference
         date; the period is computed for you. Stat tiles show total hours, work
         sessions, active sites, and total task hours. Below that, a per-employee
         breakdown and a daily breakdown, each with task visuals.</p>
      <h3>CSV columns</h3>
      <p>Exports cover completed entries only:
         <code>Date, Employee, Site, Clock In, Clock Out, Total Hours</code>, then one
         column per task type holding the hours spent. Export from either the
         <em>Time Entries</em> page or directly from <em>Reports</em>; the Reports
         export is scoped to the chosen period.</p>
    </section>

    <section class="card">
      <h2 id="forgot">“Forgot to log yesterday?”</h2>
      <p>On the clock-in screen, workers get a button that opens their email client
         with a pre-filled correction request addressed to the owner. Set that
         address under <em>Sites → Owner email</em>; if it is blank, the plugin falls
         back to the site-wide business email.</p>
    </section>

    <section class="card">
      <h2 id="perms">Permissions</h2>
      <p>Access is controlled by two permissions in the Roles editor:</p>
      <p><code>timeclock.view</code> — see entries, reports, and these docs.
         <code>timeclock.manage</code> — add/edit/delete entries, employees, sites,
         and tasks. Both are granted to the Manager role by default; Super Admin
         always has full access.</p>
      <div class="tc-callout">
        There is no separate plugin password. The admin area uses Slate's own login
        and roles, which is the secure, native approach on this platform.
      </div>
    </section>

    <section class="card">
      <h2 id="faq">Troubleshooting</h2>
      <h3>A worker can’t clock out</h3>
      <p>At least one hour slot must hold a task. Drag any task chip onto a slot and
         the button enables.</p>
      <h3>An avatar shows “Off” but they’re working</h3>
      <p>Badges reflect today only. If they clocked in on a previous day without
         clocking out, correct it from <em>Time Entries</em>.</p>
      <h3>The clock app shows no employees or sites</h3>
      <p>Add at least one of each in the admin pages first.</p>
    </section>

  </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
