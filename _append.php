<?php
$f = __DIR__ . '/plugins/small-business-kit/assets/css/sb.css';
$append = <<<CSS


/* ──────────────────────────────────────────────────────────
 * SUB-PAGE HERO (.sb-page-hero) — compact, breadcrumb-style
 * ────────────────────────────────────────────────────────── */
.sb-page-hero {
  position: relative; min-height: 52vh;
  padding: clamp(160px, 22vh, 200px) clamp(20px, 5vw, 48px) clamp(60px, 8vh, 96px);
  display: flex; align-items: center; color: #fff; overflow: hidden; isolation: isolate;
}
.sb-page-hero-bg { position: absolute; inset: 0; z-index: -2; background-size: cover; background-position: center; }
.sb-page-hero::before {
  content: ""; position: absolute; inset: 0; z-index: -1;
  background:
    linear-gradient(90deg, rgba(8,18,28,.84) 0%, rgba(8,18,28,.60) 65%, rgba(8,18,28,.40) 100%),
    linear-gradient(180deg, rgba(8,18,28,.45), rgba(8,18,28,.70));
}
.sb-page-hero-inner { max-width: 1240px; width: 100%; margin: 0 auto; text-align: left; }
.cb-public .sb-page-hero h1 {
  font-size: clamp(34px, 5.4vw, 60px); line-height: 1.05; font-weight: 800; letter-spacing: -.025em;
  margin: 0 0 18px; color: #fff !important; text-shadow: 0 2px 22px rgba(0,0,0,.32);
  text-wrap: balance;
}
.sb-page-hero-lede { font-size: clamp(15px, 1.4vw, 18px); color: rgba(255,255,255,.86); margin: 0 0 24px; max-width: 60ch; line-height: 1.6; }
.sb-page-hero-cta { margin-top: 8px; }
.sb-crumbs { display: inline-flex; align-items: center; gap: 14px; color: rgba(255,255,255,.85); font-weight: 600; font-size: 15px; margin-bottom: 18px; }
.sb-crumbs a { color: rgba(255,255,255,.85); text-decoration: none; transition: color .15s; }
.sb-crumbs a:hover { color: var(--sb-accent, #00c6ff); }
.sb-crumb-sep { color: var(--sb-accent, #00c6ff); font-weight: 800; }
.sb-crumb-current { color: #fff; }

/* Better alignment on the homepage hero heading */
.sb-hero h1 { text-wrap: balance; }
.sb-hero-left .sb-hero-inner { max-width: 820px; }
CSS;

file_put_contents($f, $append, FILE_APPEND);
echo "appended " . strlen($append) . " bytes to sb.css\n";
