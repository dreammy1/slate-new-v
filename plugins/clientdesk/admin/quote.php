<?php
/**
 * Client Desk — admin: quote detail / view.
 * Branded, print-ready quote document. Send, or (when approved) convert
 * to an invoice. Print/PDF outputs the document only.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.manage_quotes');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';
require_once dirname(__DIR__) . '/includes/document.php';

$id = (int)($_GET['id'] ?? 0);
$q  = ClientDeskAPI::quote($id);
if (!$q) {
    http_response_code(404);
    $pageTitle = __('cd_not_found', 'Quote not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('cd_quote_not_found', 'Quote not found') . '</div></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}
$client  = ClientDeskAPI::client((int)$q['client_id']);
$project = $q['project_id'] ? ClientDeskAPI::project((int)$q['project_id']) : null;
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>__('cd_csrf','Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type'=>'warning','msg'=>__('cd_dup_submit','That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'send' && $q['status'] === 'draft') {
            Database::update('clientdesk_quotes', ['status'=>'sent','sent_at'=>slate_db_now()], 'id=? AND tenant_id=?', [$id, current_tenant_id()]);
            if ($client && !empty($client['email'])) {
                Mailer::send($client['email'], 'Quote ' . $q['number'] . ' — ' . $q['title'],
                    '<p>' . e(__('cd_quote_email','A new quote is ready for your review.')) . '</p>'
                    . '<p><a href="' . e(SLATE_URL . '/portal/' . $client['access_token']) . '">' . e(__('cd_view_portal','Review it in your portal')) . '</a></p>',
                    $client['name'] ?? '');
            }
            AuditLog::record('clientdesk.quote_sent', "quote#$id");
            $flash = ['type'=>'success','msg'=>__('cd_quote_sent','Quote sent.')];
            $q = ClientDeskAPI::quote($id);
        } elseif ($action === 'to_invoice' && $q['status'] === 'approved') {
            $invId = Database::insert('clientdesk_invoices', [
                'tenant_id'=>current_tenant_id(),'client_id'=>(int)$q['client_id'],
                'project_id'=>$q['project_id'] ? (int)$q['project_id'] : null,
                'number'=>ClientDeskAPI::nextInvoiceNumber(),'currency'=>$q['currency'],
                'line_items'=>$q['line_items'],'total_cents'=>(int)$q['total_cents'],
            ]);
            AuditLog::record('clientdesk.quote_to_invoice', "quote#$id", ['invoice'=>$invId]);
            header('Location: ' . plugin_url('clientdesk','admin/invoice.php') . '?id=' . $invId);
            exit;
        }
    }
}

$meta = [];
$meta[] = ['Issued', $q['sent_at'] ? date('j M Y', strtotime($q['sent_at'])) : date('j M Y', strtotime($q['created_at']))];
if ($q['valid_until']) $meta[] = ['Valid until', date('j M Y', strtotime($q['valid_until']))];
if ($q['decided_at']) $meta[] = [ucfirst($q['status']), date('j M Y', strtotime($q['decided_at']))];

$pageTitle  = $q['number'];
$currentNav = 'clientdesk-quotes';
require SLATE_ROOT . '/admin/partials/header.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label'=>__('dashboard','Dashboard'),'href'=>SLATE_URL.'/admin/'],
    ['label'=>__('cd_quotes','Quotes'),'href'=>plugin_url('clientdesk','admin/quotes.php')],
    ['label'=>$q['number']],
]); ?>

<div class="page-header cd-doc-actions">
    <div>
        <h1><?= e($q['number']) ?></h1>
        <p class="page-header-sub"><?= e($q['title']) ?> · <?= e($client['name'] ?? '') ?></p>
    </div>
    <div class="flex gap-2">
        <button onclick="window.print()" class="btn btn-sm"><?= __('cd_print','Print / PDF') ?></button>
        <?php if ($q['status'] === 'draft'): ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?><input type="hidden" name="_action" value="send"><button class="btn btn-sm btn-primary"><?= __('cd_send','Send') ?></button></form>
        <?php endif; ?>
        <?php if ($q['status'] === 'approved'): ?>
        <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?><input type="hidden" name="_action" value="to_invoice"><button class="btn btn-sm btn-primary"><?= __('cd_convert_invoice','Convert to invoice') ?></button></form>
        <?php endif; ?>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?> cd-doc-actions" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php
cd_document([
    'kind'        => 'QUOTE',
    'number'      => $q['number'],
    'status'      => $q['status'],
    'currency'    => $q['currency'],
    'items'       => $q['line_items_decoded'],
    'total_cents' => (int)$q['total_cents'],
    'client'      => $client ?: [],
    'meta'        => $meta,
    'intro'       => $q['body'] ?? null,
]);
?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
