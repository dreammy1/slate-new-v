<?php
/**
 * Client Desk — public portal entry.
 *
 * URL: /portal/<40-hex access token>
 *
 * The token identifies the client. The visitor authenticates as a Slate
 * customer (email + password); the token alone is NOT a login. When a
 * signed-in customer's email matches an unlinked client, we bind them
 * automatically (self-service linking) so a freshly-registered client
 * lands straight in their portal.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('clientdesk');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$token = trim((string)($_GET['_route_path'] ?? ''), '/');

// No token → portal landing page (login + request access). If already
// signed in, go straight to the dashboard.
if ($token === '') {
    if (Auth::customerId() !== null) {
        header('Location: ' . SLATE_URL . '/plugins/clientdesk/customer/dashboard.php');
        exit;
    }
    require __DIR__ . '/landing.php';
    exit;
}

$client = ClientDeskAPI::clientByToken($token);

// Helper to render a minimal portal message page.
$render = function (string $title, string $bodyHtml) {
    http_response_code(200);
    $GLOBALS['pageTitle'] = $title;
    $GLOBALS['customerPageVariant'] = 'auth';
    $pageTitle = $title; $customerPageVariant = 'auth';
    require SLATE_ROOT . '/customer/partials/header.php';
    echo '<div class="card"><div class="empty">' . $bodyHtml . '</div></div>';
    require SLATE_ROOT . '/customer/partials/footer.php';
    exit;
};

if (!$client) {
    http_response_code(404);
    $render(
        __('cd_invalid_link', 'Invalid link'),
        '<div class="empty-title">' . __('cd_invalid_link', 'This portal link is not valid.') . '</div>'
        . '<p>' . __('cd_invalid_link_sub', 'Please ask your project contact for an up-to-date link.') . '</p>'
    );
}

// Remember which client this link is for, so the dashboard can scope.
$_SESSION['clientdesk_portal_token'] = $token;

// Not signed in → send to the standard customer login, returning here after.
if (Auth::customerId() === null) {
    $return = SLATE_URL . '/portal/' . $token;
    header('Location: ' . SLATE_URL . '/customer/login?next=' . rawurlencode($return));
    exit;
}

$customerId = (int) Auth::customerId();
$cust       = Auth::customer();
$custEmail  = $cust['email'] ?? null;

// If this client is unlinked but the emails match, auto-link now.
if (empty($client['customer_id']) && $custEmail !== null
    && !empty($client['email'])
    && mb_strtolower($client['email']) === mb_strtolower($custEmail)) {
    Database::update('clientdesk_clients', ['customer_id' => $customerId],
        'id = ? AND tenant_id = ?', [(int)$client['id'], current_tenant_id()]);
    $client['customer_id'] = $customerId;
    AuditLog::record('clientdesk.portal_self_linked', 'client#' . $client['id'], ['customer_id' => $customerId]);
}

// Correct account (or just-linked) → go to the dashboard.
if ((int)$client['customer_id'] === $customerId) {
    header('Location: ' . SLATE_URL . '/plugins/clientdesk/customer/dashboard.php');
    exit;
}

// This customer already owns a (different) client portal — send them there
// rather than dead-ending. Common when one person manages several brands.
$own = ClientDeskAPI::clientForCustomer($customerId, $custEmail);
if ($own) {
    header('Location: ' . SLATE_URL . '/plugins/clientdesk/customer/dashboard.php');
    exit;
}

// Truly a different account: the client is linked to someone else and this
// customer has no client of their own. Offer a clear path, not a dead end.
http_response_code(403);
$render(
    __('cd_wrong_account', 'Wrong account'),
    '<div class="empty-title">' . __('cd_wrong_account', 'This portal belongs to a different account.') . '</div>'
    . '<p>' . __('cd_wrong_account_help', 'You are signed in as') . ' <strong>' . e($custEmail ?? '') . '</strong>. '
    . __('cd_wrong_account_help2', 'If this is your project, ask your contact to link this email, or sign in with the account this link was issued to.') . '</p>'
    . '<p class="flex gap-2" style="justify-content:center;margin-top:12px">'
    . '<a class="btn" href="' . e(SLATE_URL) . '/customer/logout">' . __('cd_sign_out', 'Sign out') . '</a>'
    . '</p>'
);
