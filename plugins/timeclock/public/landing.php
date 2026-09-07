<?php
/**
 * Site Timeclock — branded landing page (public route /clock-portal).
 * Front door: brand, feature highlights, live stats, and entry/login links.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('timeclock');
require_once dirname(__DIR__) . '/TimeclockAPI.php';

// Branding
$brandName = Database::setting('business_name') ?: (Database::setting('site_name') ?: 'Site Timeclock');
$brandAccent = Database::setting('brand_accent_color');
if (!is_string($brandAccent) || !preg_match('/^#[0-9A-Fa-f]{6}$/', $brandAccent)) $brandAccent = '#2563EB';
$tagline = 'Time tracking built for the job site.';

// Live, non-sensitive stats (counts only)
$empCount  = count(TimeclockAPI::employees());
$siteCount = count(TimeclockAPI::sites());
$onClock   = count(TimeclockAPI::activeMap());

// Entry / login links
$clockUrl    = SLATE_URL . '/timeclock';
$adminUrl    = SLATE_URL . '/admin/';
$portalUrl   = SLATE_URL . '/customer/login';

$features = [
    ['tap',   'Tap-to-clock',        'Crew pick their name from an avatar grid — no passwords, no fumbling on a dusty tablet.'],
    ['timer', 'Live on-site timer',  'A running HH:MM:SS clock from the moment they start, so hours are honest and automatic.'],
    ['grid',  'Hourly task slots',   'Drag tasks across a 10-hour day. Every hour is accounted for before clock-out is allowed.'],
    ['chart', 'Weekly & monthly reports', 'Totals, sessions, active sites and task breakdowns — with one-click CSV export.'],
    ['shield','Role-based admin',    'Managers correct entries and run payroll exports; everyone else only sees what they should.'],
    ['mail',  'Missed a day?',       'A built-in “forgot to log” request emails the site owner a pre-filled correction.'],
];

function tc_land_icon(string $n): string {
    $p = [
        'tap'   => '<path d="M9 11V6a2 2 0 1 1 4 0v5"/><path d="M13 8a2 2 0 0 1 4 0v3"/><path d="M17 9a2 2 0 0 1 4 0v6a6 6 0 0 1-6 6h-2a7 7 0 0 1-5-2l-3-3a2 2 0 0 1 3-3l1 1"/>',
        'timer' => '<circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M9 3h6"/>',
        'grid'  => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'chart' => '<path d="M3 3v18h18"/><rect x="7" y="11" width="3" height="6"/><rect x="12" y="7" width="3" height="10"/><rect x="17" y="13" width="3" height="4"/>',
        'shield'=> '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'mail'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($brandName) ?> · Site Timeclock</title>
<meta name="description" content="<?= e($tagline) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --accent:<?= $brandAccent ?>;
  --bg:#0a0f1e;--surface:#121a2e;--text:#f1f5f9;--text-2:#cbd5e1;--muted:#94a3b8;--subtle:#64748b;
  --border:rgba(148,163,184,.16);--border-strong:rgba(148,163,184,.32);
  --font-sans:"DM Sans",system-ui,-apple-system,sans-serif;--font-display:"Syne",var(--font-sans);
  --font-mono:ui-monospace,"SF Mono",Menlo,monospace;
}
*{box-sizing:border-box;}
body{margin:0;color:var(--text);font-family:var(--font-sans);line-height:1.6;
  background:
    radial-gradient(1000px 600px at 8% -10%, rgba(37,99,235,.26), transparent 58%),
    radial-gradient(820px 560px at 96% 0%, rgba(56,189,248,.16), transparent 54%),
    linear-gradient(180deg,#0a0f1e 0%, #0b1226 100%);
  background-attachment:fixed;min-height:100vh;overflow-x:hidden;}
body::before{content:"";position:fixed;inset:0;z-index:0;pointer-events:none;opacity:.5;
  background-image:linear-gradient(rgba(148,163,184,.05) 1px,transparent 1px),
    linear-gradient(90deg,rgba(148,163,184,.05) 1px,transparent 1px);background-size:36px 36px;
  -webkit-mask-image:radial-gradient(100% 60% at 50% 0%,#000,transparent 78%);
          mask-image:radial-gradient(100% 60% at 50% 0%,#000,transparent 78%);}
a{color:inherit;text-decoration:none;}
.wrap{position:relative;z-index:1;max-width:1080px;margin:0 auto;padding:0 24px;}

/* Top bar */
.nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0;}
.brand{display:flex;align-items:center;gap:11px;font-weight:700;font-size:1.05rem;letter-spacing:.01em;}
.brand .mark{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(145deg,var(--accent),#1e40af);color:#fff;font-family:var(--font-display);
  font-weight:800;box-shadow:0 0 22px -4px var(--accent);}
.nav-links{display:flex;gap:10px;align-items:center;}

/* Buttons */
@keyframes rise{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:999px;
  padding:12px 22px;font-weight:700;font-size:.95rem;cursor:pointer;border:1px solid var(--border-strong);
  background:rgba(255,255,255,.05);color:var(--text);transition:transform .12s,box-shadow .2s,background .2s;}
.btn:hover{transform:translateY(-2px);background:rgba(255,255,255,.09);}
.btn-primary{background:var(--accent);border-color:var(--accent);color:#fff;box-shadow:0 16px 34px -14px var(--accent);}
.btn-primary:hover{box-shadow:0 22px 44px -14px var(--accent);}
.btn-lg{padding:15px 30px;font-size:1.02rem;}
.btn-sm{padding:9px 16px;font-size:.88rem;}
.btn-ghost{background:transparent;border-color:transparent;color:var(--text-2);}
.btn-ghost:hover{color:#fff;background:rgba(255,255,255,.06);}

/* Hero */
.hero{text-align:center;padding:56px 0 30px;animation:rise .6s cubic-bezier(.2,.7,.2,1) both;}
.pill{display:inline-flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--text-2);
  border:1px solid var(--border);background:rgba(255,255,255,.04);border-radius:999px;padding:6px 14px;margin-bottom:22px;}
.pill .live{width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 0 rgba(34,197,94,.6);animation:pulse 2s infinite;}
@keyframes pulse{0%{box-shadow:0 0 0 0 rgba(34,197,94,.5)}70%{box-shadow:0 0 0 8px rgba(34,197,94,0)}100%{box-shadow:0 0 0 0 rgba(34,197,94,0)}}
.hero h1{font-family:var(--font-display);font-size:clamp(2.4rem,6vw,4rem);line-height:1.05;margin:0 0 18px;
  letter-spacing:-.03em;background:linear-gradient(180deg,#fff 30%,#9fb4d8);-webkit-background-clip:text;
  background-clip:text;-webkit-text-fill-color:transparent;}
.hero p.lede{font-size:clamp(1.05rem,2.2vw,1.3rem);color:var(--text-2);max-width:620px;margin:0 auto 30px;}
.hero-cta{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;}

/* Live stat strip */
.stats{display:flex;justify-content:center;gap:14px;flex-wrap:wrap;margin:38px 0 10px;
  animation:rise .6s .1s cubic-bezier(.2,.7,.2,1) both;}
.stat{border:1px solid var(--border);background:rgba(255,255,255,.03);border-radius:16px;
  padding:18px 26px;min-width:140px;text-align:center;backdrop-filter:blur(8px);}
.stat .n{font-family:var(--font-mono);font-weight:700;font-size:2rem;line-height:1;}
.stat .l{color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;margin-top:8px;}

/* Features */
.section-title{text-align:center;margin:70px 0 8px;font-family:var(--font-display);font-size:1.9rem;letter-spacing:-.02em;}
.section-sub{text-align:center;color:var(--muted);margin:0 auto 38px;max-width:520px;}
.features{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;}
.feature{border:1px solid var(--border);border-radius:18px;padding:26px;
  background:linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,255,255,.01));
  backdrop-filter:blur(8px);transition:transform .18s,border-color .18s,box-shadow .2s;}
.feature:hover{transform:translateY(-4px);border-color:var(--accent);box-shadow:0 22px 50px -26px var(--accent);}
.feature .ic{width:46px;height:46px;border-radius:13px;display:flex;align-items:center;justify-content:center;
  background:rgba(37,99,235,.16);color:#93b4ff;margin-bottom:16px;}
.feature .ic svg{width:24px;height:24px;}
.feature h3{font-family:var(--font-display);margin:0 0 8px;font-size:1.18rem;}
.feature p{margin:0;color:var(--text-2);font-size:.95rem;}

/* How it works */
.steps{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:18px;margin-top:10px;}
.step{position:relative;border:1px solid var(--border);border-radius:18px;padding:26px;background:rgba(255,255,255,.03);}
.step .num{font-family:var(--font-mono);font-weight:700;color:var(--accent);font-size:1.5rem;}
.step h4{font-family:var(--font-display);margin:10px 0 6px;font-size:1.1rem;}
.step p{margin:0;color:var(--text-2);font-size:.92rem;}

/* Access / login band */
.access{margin:70px 0;border:1px solid var(--border);border-radius:24px;padding:40px;
  background:linear-gradient(150deg,rgba(37,99,235,.14),rgba(255,255,255,.02));text-align:center;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.05);}
.access h2{font-family:var(--font-display);font-size:1.9rem;margin:0 0 6px;letter-spacing:-.02em;}
.access p{color:var(--text-2);margin:0 auto 26px;max-width:460px;}
.access-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;text-align:left;}
.access-card{border:1px solid var(--border);border-radius:16px;padding:22px;background:rgba(10,15,30,.5);
  display:flex;flex-direction:column;gap:6px;transition:border-color .15s,transform .15s;}
.access-card:hover{border-color:var(--accent);transform:translateY(-3px);}
.access-card h3{font-family:var(--font-display);margin:0;font-size:1.12rem;}
.access-card p{margin:0 0 14px;color:var(--muted);font-size:.9rem;}
.access-card .btn{margin-top:auto;}

footer{text-align:center;color:var(--subtle);font-size:.85rem;padding:30px 0 50px;}
footer a:hover{color:var(--text-2);}
@media (prefers-reduced-motion:reduce){.hero,.stats,.feature{animation:none!important}.feature:hover,.access-card:hover,.btn:hover{transform:none}.pill .live{animation:none}}
@media (max-width:560px){.nav-links .btn-portal{display:none;}}
</style>
</head>
<body>
<div class="wrap">

  <nav class="nav">
    <div class="brand"><span class="mark"><?= e(mb_strtoupper(mb_substr($brandName, 0, 1))) ?></span><?= e($brandName) ?></div>
    <div class="nav-links">
      <a class="btn btn-ghost btn-sm btn-portal" href="<?= e($portalUrl) ?>">Staff portal</a>
      <a class="btn btn-sm" href="<?= e($adminUrl) ?>">Admin sign in</a>
      <a class="btn btn-primary btn-sm" href="<?= e($clockUrl) ?>">Clock in &rarr;</a>
    </div>
  </nav>

  <section class="hero">
    <span class="pill">
      <span class="live" aria-hidden="true"></span>
      <?php if ($onClock): ?><?= (int)$onClock ?> on the clock right now<?php else: ?>Ready when your crew is<?php endif; ?>
    </span>
    <h1>Site Timeclock</h1>
    <p class="lede"><?= e($tagline) ?> Clock in, track tasks by the hour, and export payroll-ready hours — without the paperwork.</p>
    <div class="hero-cta">
      <a class="btn btn-primary btn-lg" href="<?= e($clockUrl) ?>">Open the clock app</a>
      <a class="btn btn-lg" href="#features">See features</a>
    </div>

    <div class="stats">
      <div class="stat"><div class="n"><?= (int)$empCount ?></div><div class="l">Crew members</div></div>
      <div class="stat"><div class="n"><?= (int)$siteCount ?></div><div class="l">Active sites</div></div>
      <div class="stat"><div class="n"><?= (int)$onClock ?></div><div class="l">On the clock</div></div>
    </div>
  </section>

  <h2 class="section-title" id="features">Everything the crew needs</h2>
  <p class="section-sub">A focused tool that does the time-tracking job well — and gets out of the way.</p>
  <div class="features">
    <?php foreach ($features as [$icon, $title, $desc]): ?>
      <div class="feature">
        <div class="ic"><?= tc_land_icon($icon) ?></div>
        <h3><?= e($title) ?></h3>
        <p><?= e($desc) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <h2 class="section-title">How it works</h2>
  <p class="section-sub">Three taps on site, automatic records for the office.</p>
  <div class="steps">
    <div class="step"><div class="num">01</div><h4>Tap your name</h4><p>Pick yourself from the avatar grid and choose the site you’re working today.</p></div>
    <div class="step"><div class="num">02</div><h4>Work the day</h4><p>A live timer runs while you’re on site. Nothing to remember, nothing to start.</p></div>
    <div class="step"><div class="num">03</div><h4>Assign & clock out</h4><p>Drag tasks across your hours, then clock out. The entry lands in the office instantly.</p></div>
  </div>

  <section class="access">
    <h2>Choose your entrance</h2>
    <p>One tool, three doors — pick the one that fits how you’re signing in.</p>
    <div class="access-cards">
      <div class="access-card">
        <h3>Crew clock</h3>
        <p>For workers on site. No password — just tap your name.</p>
        <a class="btn btn-primary" href="<?= e($clockUrl) ?>">Clock in / out</a>
      </div>
      <div class="access-card">
        <h3>Admin</h3>
        <p>Managers: entries, employees, sites, tasks, reports &amp; exports.</p>
        <a class="btn" href="<?= e($adminUrl) ?>">Admin sign in</a>
      </div>
      <div class="access-card">
        <h3>Staff portal</h3>
        <p>Account holders sign in to the wider workspace portal.</p>
        <a class="btn" href="<?= e($portalUrl) ?>">Portal login</a>
      </div>
    </div>
  </section>

  <footer>
    <p><?= e($brandName) ?> · Site Timeclock &middot;
      <a href="<?= e($clockUrl) ?>">Clock</a> &middot;
      <a href="<?= e($adminUrl) ?>">Admin</a> &middot;
      <a href="<?= e($portalUrl) ?>">Portal</a>
    </p>
  </footer>

</div>
</body>
</html>
