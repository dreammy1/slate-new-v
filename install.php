<?php
/**
 * Slate — install wizard (minimal, two-step).
 *
 * Step 1: collect DB credentials, verify connection, write .env
 * Step 2: run db/schema.sql, create the first admin user, finish
 *
 * Stops itself once .installed marker exists.
 *
 * The polished 7-step install wizard with requirements check, branding,
 * and plugin picker is Stage 6.
 */

define('SLATE_ROOT', __DIR__);
define('SLATE_VERSION', '1.0.0');
$installMarker = SLATE_ROOT . '/.installed';

// ── Already installed ───────────────────────────────────────
if (file_exists($installMarker)) {
    require __DIR__ . '/includes/helpers.php';
    ?>
    <!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Slate is already installed</title>
        <?php require_once __DIR__ . '/includes/ui_components.php'; slate_ui_emit_css(); ?>
    </head><body>
    <div style="max-width:520px;margin:60px auto;padding:0 20px">
        <h1>Slate is already installed</h1>
        <div class="alert alert-warning">
            To re-install, delete <code>.installed</code> from the project root
            and visit this page again.
        </div>
        <p><a href="admin/" class="btn btn-primary mt-3">Go to admin →</a></p>
    </div>
    </body></html>
    <?php
    exit;
}

require __DIR__ . '/includes/helpers.php';

$step  = (int)($_GET['step'] ?? 1);
$error = '';

// ── Step 1 submit: write .env, redirect to step 2 ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

    if ($dbName === '' || $dbUser === '' || $appUrl === '') {
        $error = 'Please fill in all required fields.';
    } else {
        try {
            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $error = 'Could not connect to the database: ' . htmlspecialchars($e->getMessage());
            $pdo = null;
        }

        if ($pdo ?? null) {
            $appSecret  = bin2hex(random_bytes(32));
            $cronSecret = bin2hex(random_bytes(32));

            $envBody  = "APP_URL=$appUrl\n";
            $envBody .= "APP_SECRET=$appSecret\n";
            $envBody .= "CRON_SECRET=$cronSecret\n";
            $envBody .= "TENANT_ID=1\n";
            $envBody .= "DB_HOST=$dbHost\nDB_NAME=$dbName\nDB_USER=$dbUser\nDB_PASS=$dbPass\nDB_CHARSET=utf8mb4\n";

            if (@file_put_contents(SLATE_ROOT . '/.env', $envBody) === false) {
                $error = 'Could not write .env. Make the project root writable temporarily, '
                       . 'or paste this into .env manually:<br><pre style="background:#f5f1e8;padding:12px;border-radius:8px;font-size:12px;overflow:auto">'
                       . htmlspecialchars($envBody) . '</pre>';
            } else {
                @chmod(SLATE_ROOT . '/.env', 0640);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?step=2');
                exit;
            }
        }
    }
}

// ── Step 2 submit: run schema, create admin, write marker ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    require __DIR__ . '/config.php';

    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || strlen($password) < 8) {
        $error = 'Name, email, and a password of at least 8 characters are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $schema = file_get_contents(SLATE_ROOT . '/db/schema.sql');
            $stmts  = array_filter(array_map('trim', explode(';', preg_replace('/--[^\n]*\n/', "\n", $schema))));
            $pdo    = Database::get();
            foreach ($stmts as $stmt) {
                if ($stmt !== '') $pdo->exec($stmt);
            }

            Database::insert('users', [
                'tenant_id'     => 1,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name'          => $name,
                'role_id'       => 1,
                'status'        => 'active',
            ]);

            file_put_contents($installMarker, "Installed: " . date('Y-m-d H:i:s') . " | Slate " . SLATE_VERSION . "\n");
            @chmod($installMarker, 0640);

            header('Location: ' . SLATE_URL . '/admin/login.php?installed=1');
            exit;
        } catch (\Throwable $e) {
            $error = 'Installation failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FBF8F2">
    <title>Install Slate</title>
    <?php require_once __DIR__ . '/includes/ui_components.php'; slate_ui_emit_css(); ?>
    <?php require __DIR__ . '/includes/a11y_head.php'; ?>
    <style>
    /* Match the admin's gradient glass canvas: a soft off-white field lit by
       two accent-tinted radial glows, with a frosted card floating on top. */
    body {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        min-height: 100vh;
        min-height: 100dvh;
        padding: var(--space-5) var(--space-4);
        background:
            radial-gradient(60% 50% at 12% 0%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 70%),
            radial-gradient(55% 45% at 100% 100%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 65%),
            var(--bg, #f6f7f9);
        background-attachment: fixed;
    }
    .install-wrap { width: 100%; max-width: 480px; padding-top: var(--space-6); }
    .install-brand {
        text-align: center;
        margin-bottom: var(--space-6);
    }
    .install-brand-mark {
        width: 66px; height: 66px;
        margin: 0 auto var(--space-3);
        background: linear-gradient(150deg, color-mix(in srgb, var(--accent) 86%, #fff), var(--accent));
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--on-accent, #fff);
        font-size: 30px;
        font-weight: 700;
        box-shadow: var(--glow-accent), inset 0 1px 0 rgba(255,255,255,.4);
        letter-spacing: -0.02em;
    }
    .install-title { font-size: 27px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
    .install-sub   { color: var(--muted); margin: 6px 0 0; font-size: 15px; }
    .step-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 13px;
        background: var(--glass-bg-strong, rgba(255,255,255,.72));
        -webkit-backdrop-filter: blur(var(--glass-blur, 18px));
        backdrop-filter: blur(var(--glass-blur, 18px));
        border: 1px solid var(--glass-border, rgba(255,255,255,.7));
        color: var(--accent);
        border-radius: var(--radius-full);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        margin-bottom: var(--space-3);
    }
    .step-dots { display: inline-flex; gap: 4px; margin-left: 6px; }
    .step-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); opacity: 0.3; transition: opacity .2s; }
    .step-dot.is-on { opacity: 1; }
    .install-card {
        background: var(--glass-bg-strong, rgba(255,255,255,.72));
        -webkit-backdrop-filter: blur(var(--glass-blur, 18px)) saturate(150%);
        backdrop-filter: blur(var(--glass-blur, 18px)) saturate(150%);
        border: 1px solid var(--glass-border, rgba(255,255,255,.7));
        border-radius: var(--radius-lg);
        padding: var(--space-6);
        box-shadow: var(--glass-shadow-lg, 0 14px 44px rgba(31,41,75,.16));
    }
    .install-card h2 { letter-spacing: -0.01em; }
    @media (max-width: 480px) {
        .install-card { padding: var(--space-5) var(--space-4); }
    }
    </style>
</head>
<body>
<div class="install-wrap">
    <div class="install-brand">
        <div class="install-brand-mark" aria-hidden="true">S</div>
        <h1 class="install-title">Install Slate</h1>
        <p class="install-sub">A lean, modular platform for small businesses.</p>
    </div>

    <div class="text-center mb-4">
        <span class="step-pill">
            Step <?= $step ?> of 2
            <span class="step-dots">
                <span class="step-dot is-on"></span>
                <span class="step-dot <?= $step >= 2 ? 'is-on' : '' ?>"></span>
            </span>
        </span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= $error ?></div>
    <?php endif; ?>

    <div class="install-card">
    <?php if ($step === 1): ?>
        <h2 style="margin-bottom:var(--space-4)">Database &amp; site URL</h2>
        <form method="post">
            <div class="field">
                <label class="field-label" for="app_url">
                    Application URL <span class="field-required">*</span>
                </label>
                <input type="url" id="app_url" name="app_url" required
                       placeholder="https://your-domain.example"
                       value="<?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['PHP_SELF']), '/')) ?>">
                <div class="field-hint">The public URL where Slate will be reached. No trailing slash.</div>
            </div>

            <div class="field">
                <label class="field-label" for="db_host">Database host</label>
                <input type="text" id="db_host" name="db_host" value="localhost">
            </div>

            <div class="field">
                <label class="field-label" for="db_name">
                    Database name <span class="field-required">*</span>
                </label>
                <input type="text" id="db_name" name="db_name" required autocomplete="off">
            </div>

            <div class="field-row field-row-2">
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_user">
                        Database user <span class="field-required">*</span>
                    </label>
                    <input type="text" id="db_user" name="db_user" required autocomplete="off">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_pass">Database password</label>
                    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                Connect &amp; continue →
            </button>
        </form>

    <?php elseif ($step === 2): ?>
        <h2 style="margin-bottom:var(--space-4)">Create your admin account</h2>
        <form method="post">
            <div class="field">
                <label class="field-label" for="name">
                    Your name <span class="field-required">*</span>
                </label>
                <input type="text" id="name" name="name" required autofocus>
            </div>

            <div class="field">
                <label class="field-label" for="email">
                    Email <span class="field-required">*</span>
                </label>
                <input type="email" id="email" name="email" required
                       autocomplete="username" inputmode="email">
            </div>

            <div class="field">
                <label class="field-label" for="password">
                    Password <span class="field-required">*</span>
                </label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password">
                <div class="field-hint">At least 8 characters.</div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                Install Slate →
            </button>
        </form>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
