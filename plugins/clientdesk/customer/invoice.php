<?php
/**
 * Client Desk — customer-facing invoice view.
 * The signed-in client opens their own invoice as a branded, printable
 * document (Save as PDF). Strictly scoped: a client can only view an
 * invoice that belongs to their own linked client record, and only if
 * it has been sent (drafts stay hidden).
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::requireCustomer();

require_once SLATE_ROOT . '/includes/portal_shell.php';
require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';
require_once dirname(__DIR__) . '/includes/document.php';

$cust   = Auth::customer();
$client = ClientDeskAPI::clientForCustomer((int)$cust['id'], $cust['email'] ?? null);

$id  = (int)($_GET['id'] ?? 0);
$inv = ClientDeskAPI::invoice($id);

$pageTitle           = $inv['number'] ?? __('cd_invoice', 'Invoice');
$currentPortalNav = 'clientdesk';   // highlights the shared portal tab

// Authorization: must be the owning client, and not a draft.
$authorized = $client && $inv
    && (int)$inv['client_id'] === (int)$client['id']
    && $inv['status'] !== 'draft';

slate_portal_shell_head(__('cd_invoice', 'Invoice')); slate_portal_shell_open();
cd_styles();

if (!$authorized) {
    echo '<div class="card"><div class="empty"><div class="empty-title">'
       . __('cd_inv_unavailable', 'This invoice is not available.') . '</div>'
       . '<p><a href="' . e(SLATE_URL) . '/plugins/clientdesk/customer/dashboard.php">'
       . __('cd_back_portal', '← Back to portal') . '</a></p></div></div>';
    slate_portal_shell_close();
    exit;
}

$canPay = ClientDeskAPI::stripeReady() && in_array($inv['status'], ['sent','overdue'], true);

// Meta rows for the document header.
$meta = [];
$meta[] = ['Issued', $inv['sent_at'] ? date('j M Y', strtotime($inv['sent_at'])) : date('j M Y', strtotime($inv['created_at']))];
if ($inv['due_date']) $meta[] = ['Due', date('j M Y', strtotime($inv['due_date']))];
if ($inv['status'] === 'paid' && $inv['paid_at']) $meta[] = ['Paid', date('j M Y', strtotime($inv['paid_at']))];
?>

<div class="page-header cd-doc-actions">
    <div>
        <h1><?= e($inv['number']) ?></h1>
        <p class="page-header-sub"><?= e(ucfirst($inv['status'])) ?> · <?= e(ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency'])) ?></p>
    </div>
    <div class="flex gap-2" style="flex-wrap:wrap">
        <a href="<?= e(SLATE_URL) ?>/plugins/clientdesk/customer/dashboard.php" class="btn btn-sm"><?= __('cd_back', '← Back') ?></a>
        <button onclick="window.print()" class="btn btn-sm"><?= __('cd_download_pdf', 'Download PDF') ?></button>
        <?php if ($canPay): ?>
        <form method="post" action="<?= e(SLATE_URL) ?>/plugins/clientdesk/customer/dashboard.php" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="pay_invoice">
            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
            <button class="btn btn-sm btn-primary"><?= __('cd_pay_now', 'Pay now') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php
cd_document([
    'kind'        => 'INVOICE',
    'number'      => $inv['number'],
    'status'      => $inv['status'],
    'currency'    => $inv['currency'],
    'items'       => $inv['line_items_decoded'],
    'total_cents' => (int)$inv['total_cents'],
    'client'      => $client,
    'meta'        => $meta,
    'brief'       => $inv['requirements_brief'] ?? null,
    'agreement'   => $inv['agreement_copy'] ?? null,
]);
?>

<?php slate_portal_shell_close(); ?>
