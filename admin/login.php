<?php
/**
 * Slate — admin login.
 */
require_once dirname(__DIR__) . '/config.php';

// If already logged in, send straight to dashboard
if (Auth::check()) {
    header('Location: ' . SLATE_URL . '/admin/');
    exit;
}

$error     = '';
$installed = isset($_GET['installed']);
$next      = $_GET['next'] ?? (SLATE_URL . '/admin/');
$mfaPending = Auth::mfaPending();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = __('csrf_failed', 'Security check failed. Please try again.');
    } elseif ($mfaPending) {
        $code = trim((string)($_POST['mfa_code'] ?? ''));
        $recovery = trim((string)($_POST['recovery_code'] ?? ''));
        if (Auth::completeMfa($code, $recovery)) {
            AuditLog::record('login.mfa_success', Auth::user()['email']);
            $target = slate_safe_redirect_target($next, SLATE_URL . '/admin/');
            header('Location: ' . $target);
            exit;
        }
        $error = __('login_mfa_invalid', 'That verification code was invalid or has already been used.');
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = __('login_required', 'Email and password are required.');
        } elseif (!Auth::attemptLogin($email, $password)) {
            if (Auth::lastLoginNeedsMfa()) {
                $mfaPending = true;
            } elseif (Auth::lastLoginWasThrottled()) {
                $error = __('login_throttled', 'Too many login attempts. Please wait a few minutes and try again.');
                AuditLog::record('login.throttled', $email);
            } else {
                $error = __('login_invalid', 'Invalid email or password.');
                AuditLog::record('login.failed', $email);
            }
        } else {
            AuditLog::record('login.success', Auth::user()['email']);
            $target = slate_safe_redirect_target($next, SLATE_URL . '/admin/');
            header('Location: ' . $target);
            exit;
        }
    }
}

$siteName  = Database::setting('site_name') ?: 'Slate';
$brandSub  = Database::setting('brand_sublabel') ?: __('admin_login', 'Admin login');

// ── Dynamic branding (admin-managed via Settings → Branding) ──
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)Database::setting('brand_accent_color'))
    ? Database::setting('brand_accent_color') : '#2563EB';

$logoPath = (string)Database::setting('brand_logo_path');
$logoUrl  = $logoPath !== '' ? SLATE_URL . '/' . ltrim($logoPath, '/') : '';

$heroPath = (string)Database::setting('brand_login_image_path');
$heroUrl  = $heroPath !== '' ? SLATE_URL . '/' . ltrim($heroPath, '/') : '';

$tagline  = trim((string)Database::setting('brand_login_tagline'));

$siteHome = rtrim(SLATE_URL, '/');
// Drop a trailing /admin so "Back to website" points at the public root.
$siteHome = preg_replace('#/admin/?$#', '', $siteHome) ?: SLATE_URL;

$initial  = e(mb_strtoupper(mb_substr($siteName, 0, 1)));
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1117">
    <title><?= __('login', 'Log in') ?> — <?= e($siteName) ?></title>
    <?php slate_ui_emit_css(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <style>
    /* ── Dynamic brand accent (admin-chosen). Overrides the theme default
          so buttons, focus rings and accents track the configured color. ── */
    :root {
        --accent:       <?= e($accent) ?>;
        --accent-deep:  color-mix(in srgb, <?= e($accent) ?> 82%, #000);
        --accent-hover: color-mix(in srgb, <?= e($accent) ?> 82%, #000);
        --on-accent:    #FFFFFF;
        --ring:         color-mix(in srgb, <?= e($accent) ?> 24%, transparent);
    }

    html, body { height: 100%; }
    body { background: #0E1117; }

    /* ── Split layout ─────────────────────────────────────── */
    .auth-split {
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        min-height: 100vh;
        min-height: 100dvh;
    }

    /* ── Left: brand / image panel ────────────────────────── */
    .auth-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 40px;
        color: #fff;
        background: linear-gradient(155deg,
            var(--accent),
            color-mix(in srgb, var(--accent) 45%, #0E1117));
    }
    <?php if ($heroUrl !== ''): ?>
    .auth-hero {
        background-image: url("<?= e($heroUrl) ?>");
        background-size: cover;
        background-position: center;
    }
    <?php endif; ?>
    /* Scrim for legibility of overlaid text regardless of the image. */
    .auth-hero::after {
        content: "";
        position: absolute; inset: 0;
        background: linear-gradient(180deg,
            rgba(14,17,23,0.45) 0%,
            rgba(14,17,23,0.15) 35%,
            rgba(14,17,23,0.70) 100%);
        pointer-events: none;
    }
    .auth-hero-top,
    .auth-hero-bottom { position: relative; z-index: 1; }
    .auth-hero-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .auth-hero-brand {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        color: #fff;
        text-decoration: none;
        font-family: var(--font-display);
        font-weight: 700;
        font-size: 19px;
        letter-spacing: -0.02em;
    }
    .auth-hero-brand img {
        max-height: 40px;
        max-width: 180px;
        width: auto;
        display: block;
        /* Logos are usually dark; lift them to white on the photo. */
        filter: brightness(0) invert(1);
    }
    .auth-hero-mark {
        width: 38px; height: 38px;
        border-radius: 11px;
        display: grid; place-items: center;
        background: rgba(255,255,255,0.16);
        backdrop-filter: blur(6px);
        font-size: 18px; font-weight: 700;
        border: 1px solid rgba(255,255,255,0.25);
    }
    .auth-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 16px;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.22);
        backdrop-filter: blur(8px);
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: background 0.15s ease;
        white-space: nowrap;
    }
    .auth-back:hover { background: rgba(255,255,255,0.26); color: #fff; text-decoration: none; }
    .auth-hero-tagline {
        margin: 0;
        max-width: 460px;
        font-family: var(--font-display);
        font-size: clamp(26px, 3vw, 38px);
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -0.02em;
        text-shadow: 0 2px 18px rgba(0,0,0,0.35);
    }

    /* ── Right: form panel ────────────────────────────────── */
    .auth-form-panel {
        background: var(--bg);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px;
    }
    .auth-form-inner {
        width: 100%;
        max-width: 380px;
    }
    .auth-logo {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 28px;
    }
    .auth-logo img { max-height: 44px; max-width: 200px; width: auto; display: block; }
    .auth-logo-mark {
        width: 42px; height: 42px;
        border-radius: 12px;
        background: var(--accent);
        color: var(--on-accent);
        display: grid; place-items: center;
        font-size: 19px; font-weight: 700;
        letter-spacing: -0.02em;
        box-shadow: var(--shadow-md);
    }
    .auth-logo-name {
        font-family: var(--font-display);
        font-size: 18px; font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--text);
    }
    .auth-title {
        font-family: var(--font-display);
        font-size: 30px;
        font-weight: 700;
        letter-spacing: -0.03em;
        margin: 0;
        color: var(--text);
    }
    .auth-sub {
        color: var(--muted);
        margin: 8px 0 28px;
        font-size: 14.5px;
    }
    .auth-credit {
        margin: 30px 0 0;
        padding-top: 18px;
        border-top: 1px solid var(--border, #e5e7eb);
        text-align: center;
        font-size: 12px;
        color: var(--muted, #6b7280);
    }
    .auth-credit a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .auth-credit a:hover { text-decoration: underline; }
    .auth-pass-wrap { position: relative; }
    .auth-pass-wrap input { padding-right: 44px; }
    .auth-pass-toggle {
        position: absolute;
        top: 50%; right: 8px;
        transform: translateY(-50%);
        width: 32px; height: 32px;
        border: 0; background: transparent;
        color: var(--muted);
        display: grid; place-items: center;
        cursor: pointer;
        border-radius: var(--radius-sm);
    }
    .auth-pass-toggle:hover { color: var(--text); background: var(--surface-2); }

    @media (max-width: 860px) {
        .auth-split { grid-template-columns: 1fr; }
        .auth-hero {
            min-height: 200px;
            padding: 24px;
        }
        .auth-hero-tagline { font-size: 22px; }
        .auth-form-panel { padding: 32px 22px; }
    }
    @media (max-width: 520px) {
        .auth-hero-tagline { display: none; }
        .auth-hero { min-height: 150px; }
    }
    </style>
</head>
<body>
<div class="auth-split">

    <!-- ── Brand / image panel ── -->
    <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-top">
            <a href="<?= e($siteHome) ?>/" class="auth-hero-brand">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
                <?php else: ?>
                    <span class="auth-hero-mark"><?= $initial ?></span>
                    <span><?= e($siteName) ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= e($siteHome) ?>/" class="auth-back">
                <?= __('back_to_website', 'Back to website') ?>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                </svg>
            </a>
        </div>
        <div class="auth-hero-bottom">
            <?php if ($tagline !== ''): ?>
                <p class="auth-hero-tagline"><?= nl2br(e($tagline)) ?></p>
            <?php endif; ?>
        </div>
    </aside>

    <!-- ── Form panel ── -->
    <main class="auth-form-panel">
        <div class="auth-form-inner">
            <div class="auth-logo">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
                <?php else: ?>
                    <span class="auth-logo-mark"><?= $initial ?></span>
                    <span class="auth-logo-name"><?= e($siteName) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="auth-title"><?= __('welcome_back', 'Welcome back') ?></h1>
            <p class="auth-sub">
                <?= __('admin_login_sub', 'Log in to your dashboard.') ?>
            </p>

            <?php if ($installed): ?>
                <div class="alert alert-success" role="status">
                    <?= __('install_success', 'Slate is installed. Log in with the account you just created.') ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

                        <?php if ($mfaPending): ?>
                <div class="alert alert-success" role="status">A verification step is required to finish signing in.</div>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="field-label" for="mfa_code">Verification code</label>
                        <input type="text" id="mfa_code" name="mfa_code" required autofocus
                               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
                    </div>
                    <div class="field">
                        <label class="field-label" for="recovery_code">Recovery code <span class="text-muted">(optional)</span></label>
                        <input type="text" id="recovery_code" name="recovery_code" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-2">Verify and continue</button>
                </form>
            <?php else: ?>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
                        <input type="email" id="email" name="email" required autofocus
                               value="<?= e($_POST['email'] ?? '') ?>"
                               autocomplete="username" inputmode="email"
                               placeholder="<?= __('email', 'Email') ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="password"><?= __('password', 'Password') ?></label>
                        <div class="auth-pass-wrap">
                            <input type="password" id="password" name="password" required
                                   autocomplete="current-password"
                                   placeholder="<?= __('password', 'Password') ?>">
                            <button type="button" class="auth-pass-toggle" id="auth-pass-toggle"
                                    aria-label="<?= __('show_password', 'Show password') ?>">
                                <svg id="auth-eye" width="19" height="19" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-2">
                        <?= __('login', 'Log in') ?>
                    </button>
                </form>
            <?php endif; ?>


            <?php
            $loginOwner    = Database::setting('brand_owner')    ?: 'Rakib Hasan';
            $loginOwnerUrl = Database::setting('brand_owner_url') ?: 'https://rakibhasaan.com';
            if (!preg_match('#^https?://#i', $loginOwnerUrl)) $loginOwnerUrl = 'https://' . ltrim($loginOwnerUrl, '/');
            ?>
            <p class="auth-credit">
                © <?= e(date('Y')) ?> <?= e($siteName) ?> ·
                <?= __('built_by', 'Built &amp; maintained by') ?>
                <a href="<?= e($loginOwnerUrl) ?>" target="_blank" rel="noopener"><?= e($loginOwner) ?></a>
            </p>
        </div>
    </main>
</div>

<script>
(function () {
    var btn = document.getElementById('auth-pass-toggle');
    var pwd = document.getElementById('password');
    var eye = document.getElementById('auth-eye');
    if (!btn || !pwd) return;
    var OPEN = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
    var SHUT = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    btn.addEventListener('click', function () {
        var show = pwd.type === 'password';
        pwd.type = show ? 'text' : 'password';
        eye.innerHTML = show ? SHUT : OPEN;
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>
</body>
</html>
