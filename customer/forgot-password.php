<?php
/**
 * Slate — customer "forgot password" form.
 * Always claims success regardless of whether the email exists,
 * to avoid leaking which addresses are registered.
 */
require_once dirname(__DIR__) . '/config.php';

$flash = null;
$email = '';
$sent  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        Auth::sendCustomerPasswordReset($email);
        $sent = true;  // always show the same confirmation page
    }
}

$pageTitle = 'Reset your password';
$customerPageVariant = 'auth';
require __DIR__ . '/partials/header.php';
?>

<div class="auth-card">
    <?php if ($sent): ?>
        <h1>Check your inbox</h1>
        <p class="auth-card-sub">
            If <strong><?= e($email) ?></strong> is registered, we've sent a reset link.
        </p>
        <div class="alert alert-info" role="status">
            The link is valid for 2 hours. If it doesn't arrive, check your spam folder.
        </div>
        <a href="<?= e(SLATE_URL) ?>/customer/login.php" class="btn btn-primary btn-block">Back to sign in</a>
    <?php else: ?>
        <h1>Reset your password</h1>
        <p class="auth-card-sub">Enter your account email — we'll send you a link.</p>

        <?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <div class="field">
                <label class="field-label" for="email">Email</label>
                <input type="email" id="email" name="email" required autocomplete="email"
                       value="<?= e($email) ?>" autofocus maxlength="190">
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Send reset link</button>
        </form>
    <?php endif; ?>
</div>

<div class="auth-footer">
    Remembered it? <a href="<?= e(SLATE_URL) ?>/customer/login.php">Sign in</a>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
