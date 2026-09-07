<?php
/**
 * Client Desk — admin: invoice detail / view.
 * Renders a branded, print-ready invoice document. Print/PDF outputs the
 * document only (the shell is hidden via the print stylesheet).
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.manage_invoices');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';
require_once dirname(__DIR__) . '/includes/document.php';

$id  = (int)($_GET['id'] ?? 0);
$inv = ClientDeskAPI::invoice($id);
if (!$inv) {
    http_response_code(404);
    $pageTitle = __('cd_not_found', 'Invoice not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('cd_inv_not_found', 'Invoice not found') . '</div></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}
$client  = ClientDeskAPI::client((int)$inv['client_id']);
$project = $inv['project_id'] ? ClientDeskAPI::project((int)$inv['project_id']) : null;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>__('cd_csrf','Security check failed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'send') {
            Database::update('clientdesk_invoices', ['status'=>'sent','sent_at'=>slate_db_now()], 'id=? AND tenant_id=?', [$id, current_tenant_id()]);
            if ($client && !empty($client['email'])) {
                Mailer::send($client['email'], 'Invoice ' . $inv['number'],
                    '<p>' . e(__('cd_inv_email_intro','A new invoice is ready for you.')) . '</p>'
                    . '<p><strong>' . e($inv['number']) . '</strong> — ' . e(ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency'])) . '</p>'
                    . '<p><a href="' . e(SLATE_URL . '/portal/' . $client['access_token']) . '">' . e(__('cd_view_portal','View it in your portal')) . '</a></p>',
                    $client['name'] ?? '');
            }
            AuditLog::record('clientdesk.invoice_sent', "invoice#$id");
            $flash = ['type'=>'success','msg'=>__('cd_invoice_sent','Invoice sent.')];
            $inv = ClientDeskAPI::invoice($id);
        } elseif ($action === 'mark_paid') {
            Database::update('clientdesk_invoices', ['status'=>'paid','paid_at'=>slate_db_now()], 'id=? AND tenant_id=?', [$id, current_tenant_id()]);
            AuditLog::record('clientdesk.invoice_paid', "invoice#$id");
            $flash = ['type'=>'success','msg'=>__('cd_invoice_paid','Marked paid.')];
            $inv = ClientDeskAPI::invoice($id);
        }
    }
}

// Build the meta rows shown top-right of the document.
$meta = [];
$meta[] = ['Issued', $inv['sent_at'] ? date('j M Y', strtotime($inv['sent_at'])) : date('j M Y', strtotime($inv['created_at']))];
if ($inv['due_date']) $meta[] = ['Due', date('j M Y', strtotime($inv['due_date']))];
if ($inv['status'] === 'paid' && $inv['paid_at']) $meta[] = ['Paid', date('j M Y', strtotime($inv['paid_at']))];
if (!empty($inv['payment_method'])) $meta[] = ['Method', ucfirst($inv['payment_method'])];

$pageTitle  = $inv['number'];
$currentNav = 'clientdesk-invoices';
require SLATE_ROOT . '/admin/partials/header.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label'=>__('dashboard','Dashboard'),'href'=>SLATE_URL.'/admin/'],
    ['label'=>__('cd_invoices','Invoices'),'href'=>plugin_url('clientdesk','admin/invoices.php')],
    ['label'=>$inv['number']],
]); ?>

<div class="page-header cd-doc-actions">
    <div>
        <h1><?= e($inv['number']) ?></h1>
        <p class="page-header-sub"><?= e($client['name'] ?? '') ?>
            <?php if ($project): ?> · <a href="<?= e(plugin_url('clientdesk','admin/project.php')) ?>?id=<?= (int)$project['id'] ?>"><?= e($project['title']) ?></a><?php endif; ?></p>
    </div>
    <div class="flex gap-2">
        <button onclick="window.print()" class="btn btn-sm"><?= __('cd_print','Print / PDF') ?></button>
        <?php if ($inv['status'] === 'draft'): ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="_action" value="send"><button class="btn btn-sm btn-primary"><?= __('cd_send','Send') ?></button></form>
        <?php endif; ?>
        <?php if ($inv['status'] !== 'paid'): ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><input type="hidden" name="_action" value="mark_paid"><button class="btn btn-sm"><?= __('cd_mark_paid','Mark paid') ?></button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> cd-doc-actions" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php
cd_document([
    'kind'        => 'INVOICE',
    'number'      => $inv['number'],
    'status'      => $inv['status'],
    'currency'    => $inv['currency'],
    'items'       => $inv['line_items_decoded'],
    'total_cents' => (int)$inv['total_cents'],
    'client'      => $client ?: [],
    'meta'        => $meta,
    'brief'       => $inv['requirements_brief'] ?? null,
    'agreement'   => $inv['agreement_copy'] ?? null,
]);
?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
