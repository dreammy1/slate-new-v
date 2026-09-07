<?php
/**
 * Slate — customer-facing shell header.
 *
 * Renders one of two layouts depending on $customerPageVariant:
 *
 *   'auth' (default for auth pages)
 *     — full-width light canvas, centered card, brand mark + product
 *       name at top. Used by login/register/verify/forgot/reset.
 *
 *   'dashboard'
 *     — topbar with brand + signed-in user + Sign out. Main content
 *       area centered to --content-max. Used by /customer/index.php
 *       and any plugin that adds customer-side pages.
 *
 * Pages set $customerPageVariant before requiring this partial,
 * then must require partials/footer.php at the bottom.
 */

if (!defined('SLATE_ROOT')) exit;

$customerPageVariant = $customerPageVariant ?? 'auth';
$pageTitle           = $pageTitle           ?? 'Account';
$siteName            = Database::setting('site_name') ?: 'Slate';
$brandSublabel       = Database::setting('brand_sublabel') ?: __('customer_portal', 'Customer Portal');

// Branding for the two-column 'auth-split' variant (same settings the
// admin login uses — Settings → Branding).
$brandLogoPath  = (string)Database::setting('brand_logo_path');
$brandLogoUrl   = $brandLogoPath !== '' ? SLATE_URL . '/' . ltrim($brandLogoPath, '/') : '';
$brandHeroPath  = (string)Database::setting('brand_login_image_path');
$brandHeroUrl   = $brandHeroPath !== '' ? SLATE_URL . '/' . ltrim($brandHeroPath, '/') : '';
$brandTagline   = trim((string)Database::setting('brand_login_tagline'));
$brandAccentRaw = (string)Database::setting('brand_accent_color');
$brandAccent    = preg_match('/^#[0-9a-fA-F]{6}$/', $brandAccentRaw) ? $brandAccentRaw : '#2563EB';
$brandInitial   = e(mb_strtoupper(mb_substr($siteName, 0, 1)));
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FAFAFA">
    <title><?= e($pageTitle) ?> — <?= e($siteName) ?></title>
    <meta name="csrf" content="<?= e(csrf_token()) ?>">
    <?php slate_ui_emit_css(); ?>
    <?php slate_brand_accent_emit(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <style>
    body { background: var(--bg); min-height: 100vh; min-height: 100dvh; }

    /* ── Auth-page shell (centered card) ──────────────────── */
    .auth-shell {
        min-height: 100vh; min-height: 100dvh;
        display: flex; flex-direction: column;
        align-items: center; justify-content: center;
        padding: var(--space-5) var(--space-4);
        gap: var(--space-5);
    }
    .auth-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        color: var(--text);
    }
    .auth-brand:hover { color: var(--text); text-decoration: none; }
    .auth-brand-mark {
        width: 36px; height: 36px;
        border-radius: var(--radius);
        background: var(--accent);
        color: var(--on-accent);
        display: grid;
        place-items: center;
        font-family: var(--font-display);
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -0.04em;
        box-shadow: 0 1px 2px rgba(0,0,0,0.10);
    }
    .auth-brand-text { line-height: 1.2; }
    .auth-brand-name {
        font-family: var(--font-display);
        font-size: 17px;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    .auth-brand-sub {
        font-size: 11px;
        color: var(--subtle);
        letter-spacing: 0.06em;
        text-transform: uppercase;
        font-weight: 600;
        margin-top: 1px;
    }
    .auth-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 28px 28px 24px;
        width: 100%;
        max-width: 400px;
        box-shadow: 0 1px 2px rgba(15,17,23,0.04);
    }
    .auth-card h1 {
        font-family: var(--font-display);
        font-size: 22px;
        margin: 0 0 6px;
    }
    .auth-card .auth-card-sub {
        font-size: 13px;
        color: var(--muted);
        margin: 0 0 var(--space-5);
    }
    .auth-footer {
        font-size: 13px;
        color: var(--muted);
        text-align: center;
    }
    .auth-footer a { color: var(--accent); }
    .auth-footer a:hover { color: var(--accent-deep); text-decoration: underline; }

    /* Customer pages sit on a solid surface where the admin's translucent
       --glass-border is nearly invisible. Use the solid hairline so inputs read
       as real fields (not just an underline on focus). */
    .field input, .field select, .field textarea, .input,
    main.cust-content :is(input, select, textarea) {
        background: var(--surface);
        border: 1px solid var(--border);
    }
    .field input:hover, .field select:hover, .field textarea:hover,
    main.cust-content :is(input, select, textarea):hover { border-color: var(--border-stronger); }

    /* ── Two-column auth shell (hero image + form) ────────── */
    .auth-split {
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        min-height: 100vh; min-height: 100dvh;
    }
    .auth-hero {
        position: relative; overflow: hidden;
        display: flex; flex-direction: column; justify-content: space-between;
        padding: 40px; color: #fff;
        background: linear-gradient(155deg,
            var(--accent), color-mix(in srgb, var(--accent) 45%, #0E1117));
    }
    <?php if ($brandHeroUrl !== ''): ?>
    .auth-hero {
        background-image: url("<?= e($brandHeroUrl) ?>");
        background-size: cover; background-position: center;
    }
    <?php endif; ?>
    .auth-hero::after {
        content: ""; position: absolute; inset: 0; pointer-events: none;
        background: linear-gradient(180deg,
            rgba(14,17,23,0.45) 0%, rgba(14,17,23,0.15) 35%, rgba(14,17,23,0.70) 100%);
    }
    .auth-hero-top, .auth-hero-bottom { position: relative; z-index: 1; }
    .auth-hero-top { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
    .auth-hero-brand {
        display: inline-flex; align-items: center; gap: 10px;
        color: #fff; text-decoration: none;
        font-family: var(--font-display); font-weight: 700; font-size: 19px; letter-spacing: -0.02em;
    }
    .auth-hero-brand img { max-height: 40px; max-width: 180px; width: auto; display: block; filter: brightness(0) invert(1); }
    .auth-hero-mark {
        width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
        background: rgba(255,255,255,0.16); backdrop-filter: blur(6px);
        font-size: 18px; font-weight: 700; border: 1px solid rgba(255,255,255,0.25);
    }
    .auth-back {
        display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px;
        background: rgba(255,255,255,0.14); border: 1px solid rgba(255,255,255,0.22); backdrop-filter: blur(8px);
        color: #fff; text-decoration: none; font-size: 13px; font-weight: 600; white-space: nowrap;
        transition: background 0.15s ease;
    }
    .auth-back:hover { background: rgba(255,255,255,0.26); color: #fff; text-decoration: none; }
    .auth-hero-tagline {
        margin: 0; max-width: 460px; font-family: var(--font-display);
        font-size: clamp(26px, 3vw, 38px); font-weight: 700; line-height: 1.15; letter-spacing: -0.02em;
        text-shadow: 0 2px 18px rgba(0,0,0,0.35);
    }
    .auth-form-panel {
        background: var(--bg); display: flex; align-items: center; justify-content: center; padding: 40px;
    }
    .auth-form-inner { width: 100%; max-width: 400px; }
    .auth-form-inner .auth-brand { margin-bottom: 24px; }
    .auth-form-inner .auth-card { border: 0; box-shadow: none; padding: 0; max-width: none; background: transparent; }
    @media (max-width: 860px) {
        .auth-split { grid-template-columns: 1fr; }
        .auth-hero { min-height: 200px; padding: 24px; }
        .auth-hero-tagline { font-size: 22px; }
        .auth-form-panel { padding: 32px 22px; }
    }
    @media (max-width: 520px) {
        .auth-hero-tagline { display: none; }
        .auth-hero { min-height: 150px; }
    }

    /* ── Dashboard shell ──────────────────────────────────── */
    .cust-topbar {
        position: sticky;
        top: 0;
        z-index: 30;
        height: var(--topbar-height);
        background: rgba(245, 244, 241, 0.82);
        backdrop-filter: saturate(180%) blur(12px);
        -webkit-backdrop-filter: saturate(180%) blur(12px);
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 0 20px;
    }
    .cust-topbar .auth-brand-name { font-size: 15px; }
    .cust-topbar .auth-brand-sub  { display: none; }
    .cust-topbar .auth-brand-mark { width: 28px; height: 28px; font-size: 14px; }
    .cust-topbar-spacer { flex: 1; }
    .cust-topbar-user {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 13px;
        color: var(--text-2);
    }
    .cust-topbar-user .topbar-avatar { width: 28px; height: 28px; }
    .cust-topbar-user a { color: var(--muted); }
    .cust-topbar-user a:hover { color: var(--text); text-decoration: underline; }

    main.cust-content {
        max-width: 900px;
        width: 100%;
        margin: 0 auto;
        padding: 24px 20px 56px;
    }

    @media (max-width: 480px) {
        .cust-topbar-user-name { display: none; }
        .cust-topbar { padding: 0 14px; }
        main.cust-content { padding: 16px 14px 40px; }
        .auth-card { padding: 22px 20px 20px; }
    }
    </style>
    <?php
    // Plugin-injected head content. Coaching uses this to load customer-shell.css
    // (premium overlay) and customer.css (bento). Same pattern as admin_head.
    if (class_exists('Hook')) Hook::doAction('customer_head');
    if (class_exists('PluginLoader')) echo PluginLoader::renderQueuedStyles();
    ?>
</head>
<body>
    <a class="skip-link" href="#cust-content"><?= __('skip_to_content', 'Skip to main content') ?></a>

<?php if ($customerPageVariant === 'dashboard'):
    $cust = Auth::customer();
?>
    <header class="cust-topbar">
        <a href="<?= e(SLATE_URL) ?>/customer/" class="auth-brand">
            <span class="auth-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
            <span class="auth-brand-text">
                <span class="auth-brand-name"><?= e($siteName) ?></span>
                <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
            </span>
        </a>
        <div class="cust-topbar-spacer"></div>
        <?php if ($cust): ?>
            <div class="cust-topbar-user">
                <span class="topbar-avatar" aria-hidden="true">
                    <?= e(mb_strtoupper(mb_substr($cust['name'] ?? $cust['email'] ?? 'A', 0, 1))) ?>
                </span>
                <span class="cust-topbar-user-name"><?= e($cust['name'] ?? $cust['email']) ?></span>
                <a href="<?= e(SLATE_URL) ?>/customer/logout.php?csrf=<?= e(csrf_token()) ?>"><?= __('sign_out', 'Sign out') ?></a>
            </div>
        <?php endif; ?>
    </header>
    <main class="cust-content" id="cust-content">

<?php elseif ($customerPageVariant === 'auth-split'): /* two-column hero + form */ ?>

    <div class="auth-split" id="cust-content">
        <aside class="auth-hero" aria-hidden="true">
            <div class="auth-hero-top">
                <a href="<?= e(SLATE_URL) ?>/" class="auth-hero-brand">
                    <?php if ($brandLogoUrl !== ''): ?>
                        <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>">
                    <?php else: ?>
                        <span class="auth-hero-mark"><?= $brandInitial ?></span>
                        <span><?= e($siteName) ?></span>
                    <?php endif; ?>
                </a>
                <a href="<?= e(SLATE_URL) ?>/" class="auth-back">
                    <?= __('back_to_website', 'Back to website') ?>
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                    </svg>
                </a>
            </div>
            <div class="auth-hero-bottom">
                <?php if ($brandTagline !== ''): ?>
                    <p class="auth-hero-tagline"><?= nl2br(e($brandTagline)) ?></p>
                <?php endif; ?>
            </div>
        </aside>
        <main class="auth-form-panel">
            <div class="auth-form-inner">
                <a href="<?= e(SLATE_URL) ?>/customer/" class="auth-brand">
                    <?php if ($brandLogoUrl !== ''): ?>
                        <img src="<?= e($brandLogoUrl) ?>" alt="<?= e($siteName) ?>" style="max-height:40px;max-width:180px">
                    <?php else: ?>
                        <span class="auth-brand-mark" aria-hidden="true"><?= $brandInitial ?></span>
                        <span class="auth-brand-text">
                            <span class="auth-brand-name"><?= e($siteName) ?></span>
                            <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
                        </span>
                    <?php endif; ?>
                </a>

<?php else: /* 'auth' variant */ ?>

    <div class="auth-shell" id="cust-content">
        <a href="<?= e(SLATE_URL) ?>/" class="auth-brand">
            <span class="auth-brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1))) ?></span>
            <span class="auth-brand-text">
                <span class="auth-brand-name"><?= e($siteName) ?></span>
                <span class="auth-brand-sub"><?= e($brandSublabel) ?></span>
            </span>
        </a>

<?php endif; ?>
