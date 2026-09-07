<?php
/**
 * Client Desk — portal landing page (/portal).
 *
 * Branded entry point for the client portal. Primary action is sign-in;
 * visitors without an account use "Request access", which records a
 * lead (clientdesk_access_requests) and notifies staff — there is no
 * open self-signup, by design (accounts are issued per client).
 *
 * Included by public/portal.php when the route has no token. Expects
 * config.php + ClientDeskAPI already loaded, and $token === ''.
 */

if (!defined('SLATE_ROOT')) { http_response_code(400); exit; }

$flash = null;
$mode  = 'login';           // 'login' | 'request'
$email = '';
$reqName = $reqEmail = $reqMsg = '';
$portalReturn = SLATE_URL . '/plugins/clientdesk/customer/dashboard.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'login';
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed. Please try again.')];
        $mode  = $action === 'request' ? 'request' : 'login';
    } elseif ($action === 'login') {
        $email    = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attemptCustomerLogin($email, $password)) {
            header('Location: ' . $portalReturn);
            exit;
        }
        $flash = Auth::lastLoginWasThrottled()
            ? ['type' => 'error', 'msg' => __('cd_login_throttled', 'Too many attempts. Please wait a few minutes and try again.')]
            : ['type' => 'error', 'msg' => __('cd_login_bad', 'Email or password is incorrect.')];
    } elseif ($action === 'request') {
        $mode     = 'request';
        $reqName  = trim((string)($_POST['req_name'] ?? ''));
        $reqEmail = trim((string)($_POST['req_email'] ?? ''));
        $reqMsg   = trim((string)($_POST['req_message'] ?? ''));
        if ($reqName === '' || !filter_var($reqEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'error', 'msg' => __('cd_req_invalid', 'Please enter your name and a valid email.')];
        } elseif (!submit_token_consume()) {
            // Duplicate submit (refresh / back / double-click): show the same
            // friendly confirmation but don't record a second lead.
            $flash = ['type' => 'success', 'msg' => __('cd_req_sent', 'Thanks — your request was sent. We will be in touch shortly.')];
            $reqName = $reqEmail = $reqMsg = '';
            $mode = 'login';
        } else {
            ClientDeskAPI::createAccessRequest($reqName, $reqEmail, $reqMsg);
            if (class_exists('Notifications')) {
                Notifications::add('Portal access requested by ' . $reqName,
                    ['body' => $reqEmail, 'icon' => 'users',
                     'url' => SLATE_URL . '/plugins/clientdesk/admin/index.php']);
            }
            // Best-effort notify the business inbox.
            $to = (string)(Database::setting('business_email') ?: Database::setting('smtp_from_email'));
            if ($to !== '') {
                Mailer::send($to, 'Portal access request — ' . $reqName,
                    '<p><strong>' . e($reqName) . '</strong> (' . e($reqEmail) . ') '
                    . e(__('cd_req_email_body', 'requested access to the client portal.')) . '</p>'
                    . ($reqMsg !== '' ? '<p>' . nl2br(e($reqMsg)) . '</p>' : ''),
                    '');
            }
            $flash = ['type' => 'success', 'msg' => __('cd_req_sent', 'Thanks — your request was sent. We will be in touch shortly.')];
            $reqName = $reqEmail = $reqMsg = '';
            $mode = 'login';
        }
    }
}

$brandName = (string)(Database::setting('business_name') ?: (Database::setting('site_name') ?: 'Client Portal'));

$pageTitle = __('cd_portal_title', 'Client Portal');
$customerPageVariant = 'auth';
require SLATE_ROOT . '/customer/partials/header.php';
?>

<style>
/* Scoped accents for the landing page; relies on Slate's .auth-card chrome. */
.cdl-card { text-align: left; }
.cdl-tabs { display: flex; gap: 4px; background: var(--surface-2); padding: 4px; border-radius: var(--radius); margin-bottom: 20px; }
.cdl-tab { flex: 1; text-align: center; padding: 9px 12px; border-radius: calc(var(--radius) - 2px); font: 600 13px var(--font-sans); color: var(--muted); background: none; border: 0; cursor: pointer; }
.cdl-tab.is-active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
.cdl-panel { display: none; }
.cdl-panel.is-active { display: block; }
.cdl-intro { font-size: 13px; color: var(--muted); margin: 0 0 16px; line-height: 1.5; }
.cdl-help { text-align: center; font-size: 12.5px; color: var(--subtle); margin-top: 16px; }
</style>

<div class="auth-card cdl-card">
    <h1><?= e($brandName) ?></h1>
    <p class="auth-card-sub"><?= __('cd_portal_sub', 'Sign in to view your projects, files and invoices.') ?></p>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="cdl-tabs" role="tablist">
        <button type="button" class="cdl-tab <?= $mode === 'login' ? 'is-active' : '' ?>" data-tab="login"><?= __('cd_sign_in', 'Sign in') ?></button>
        <button type="button" class="cdl-tab <?= $mode === 'request' ? 'is-active' : '' ?>" data-tab="request"><?= __('cd_request_access', 'Request access') ?></button>
    </div>

    <!-- LOGIN -->
    <div class="cdl-panel <?= $mode === 'login' ? 'is-active' : '' ?>" data-panel="login">
        <form method="post" autocomplete="on">
            <?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="login">
            <div class="field">
                <label class="field-label" for="email"><?= __('cd_email', 'Email') ?></label>
                <input type="email" id="email" name="email" required autocomplete="email" value="<?= e($email) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="password"><?= __('cd_password', 'Password') ?></label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
                <div class="field-hint" style="text-align:right">
                    <a href="<?= e(SLATE_URL) ?>/customer/forgot-password.php"><?= __('cd_forgot', 'Forgot password?') ?></a>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('cd_sign_in', 'Sign in') ?></button>
        </form>
    </div>

    <!-- REQUEST ACCESS -->
    <div class="cdl-panel <?= $mode === 'request' ? 'is-active' : '' ?>" data-panel="request">
        <p class="cdl-intro"><?= __('cd_request_intro', 'Accounts are set up by our team. Tell us who you are and we will send you an invite to your portal.') ?></p>
        <form method="post">
            <?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="request">
            <div class="field">
                <label class="field-label" for="req_name"><?= __('cd_name', 'Name') ?></label>
                <input type="text" id="req_name" name="req_name" required maxlength="160" value="<?= e($reqName) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="req_email"><?= __('cd_email', 'Email') ?></label>
                <input type="email" id="req_email" name="req_email" required maxlength="190" value="<?= e($reqEmail) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="req_message"><?= __('cd_message_opt', 'Message (optional)') ?></label>
                <textarea id="req_message" name="req_message" rows="3" maxlength="2000" placeholder="<?= e(__('cd_request_ph', 'Which project is this about?')) ?>"><?= e($reqMsg) ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg"><?= __('cd_send_request', 'Send request') ?></button>
        </form>
    </div>

    <p class="cdl-help"><?= __('cd_have_link', 'Have a portal link? Open it to sign in directly.') ?></p>
</div>

<script>
(function () {
    var tabs = document.querySelectorAll('.cdl-tab');
    var panels = document.querySelectorAll('.cdl-panel');
    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.remove('is-active'); });
            panels.forEach(function (p) { p.classList.remove('is-active'); });
            t.classList.add('is-active');
            var panel = document.querySelector('.cdl-panel[data-panel="' + t.dataset.tab + '"]');
            if (panel) panel.classList.add('is-active');
        });
    });
})();
</script>

<?php require SLATE_ROOT . '/customer/partials/footer.php'; ?>
