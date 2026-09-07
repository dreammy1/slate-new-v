<?php
/**
 * Client Desk — admin: quotes / proposals.
 * Create a quote with line items + a description, send it; the client
 * approves or declines from their portal. Approved quotes can be turned
 * into an invoice in one click.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.manage_quotes');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';

$pageTitle  = __('cd_quotes', 'Quotes');
$currentNav = 'clientdesk-quotes';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'create') {
            $clientId = (int)($_POST['client_id'] ?? 0);
            $client   = ClientDeskAPI::client($clientId);
            $title    = trim((string)($_POST['title'] ?? ''));
            $descs = $_POST['item_desc'] ?? []; $amts = $_POST['item_amount'] ?? [];
            $items = []; $total = 0;
            foreach ($descs as $i => $d) {
                $d = trim((string)$d); $a = (int)round((float)($amts[$i] ?? 0) * 100);
                if ($d === '' || $a <= 0) continue;
                $items[] = ['desc' => mb_substr($d, 0, 190), 'amount_cents' => $a]; $total += $a;
            }
            if (!$client || $title === '') {
                $flash = ['type' => 'error', 'msg' => __('cd_quote_need', 'Pick a client and give the quote a title.')];
            } elseif (!$items) {
                $flash = ['type' => 'error', 'msg' => __('cd_need_item', 'Add at least one line item.')];
            } else {
                $id = Database::insert('clientdesk_quotes', [
                    'tenant_id'   => current_tenant_id(),
                    'client_id'   => $clientId,
                    'project_id'  => (int)($_POST['project_id'] ?? 0) ?: null,
                    'number'      => ClientDeskAPI::nextQuoteNumber(),
                    'title'       => mb_substr($title, 0, 190),
                    'currency'    => in_array($_POST['currency'] ?? 'USD', ['USD','EUR','GBP','BDT'], true) ? $_POST['currency'] : 'USD',
                    'line_items'  => json_encode($items),
                    'total_cents' => $total,
                    'body'        => trim((string)($_POST['body'] ?? '')) ?: null,
                    'valid_until' => ($_POST['valid_until'] ?? '') !== '' ? $_POST['valid_until'] : null,
                ]);
                AuditLog::record('clientdesk.quote_created', "quote#$id");
                $flash = ['type' => 'success', 'msg' => __('cd_quote_created', 'Quote created as draft.')];
            }
        } elseif ($action === 'send') {
            $qid = (int)($_POST['id'] ?? 0); $q = ClientDeskAPI::quote($qid);
            if ($q) {
                Database::update('clientdesk_quotes', ['status'=>'sent','sent_at'=>slate_db_now()], 'id=? AND tenant_id=?', [$qid, current_tenant_id()]);
                $client = ClientDeskAPI::client((int)$q['client_id']);
                if ($client && !empty($client['email'])) {
                    Mailer::send($client['email'], 'Quote ' . $q['number'] . ' — ' . $q['title'],
                        '<p>' . e(__('cd_quote_email', 'A new quote is ready for your review.')) . '</p>'
                        . '<p><a href="' . e(SLATE_URL . '/portal/' . $client['access_token']) . '">'
                        . e(__('cd_view_portal', 'Review it in your portal')) . '</a></p>', $client['name'] ?? '');
                }
                AuditLog::record('clientdesk.quote_sent', "quote#$qid");
                $flash = ['type' => 'success', 'msg' => __('cd_quote_sent', 'Quote sent.')];
            }
        } elseif ($action === 'to_invoice') {
            $qid = (int)($_POST['id'] ?? 0); $q = ClientDeskAPI::quote($qid);
            if ($q && $q['status'] === 'approved') {
                $invId = Database::insert('clientdesk_invoices', [
                    'tenant_id'   => current_tenant_id(),
                    'client_id'   => (int)$q['client_id'],
                    'project_id'  => $q['project_id'] ? (int)$q['project_id'] : null,
                    'number'      => ClientDeskAPI::nextInvoiceNumber(),
                    'currency'    => $q['currency'],
                    'line_items'  => $q['line_items'],
                    'total_cents' => (int)$q['total_cents'],
                ]);
                AuditLog::record('clientdesk.quote_to_invoice', "quote#$qid", ['invoice' => $invId]);
                $flash = ['type' => 'success', 'msg' => __('cd_quote_invoiced', 'Invoice created from quote (as draft).')];
            }
        }
    }
}

$clients = ClientDeskAPI::clients();
$quotes  = Database::rows(
    "SELECT q.*, c.name AS client_name FROM clientdesk_quotes q
       JOIN clientdesk_clients c ON c.id = q.client_id
      WHERE q.tenant_id = ? ORDER BY q.created_at DESC", [current_tenant_id()]);

require SLATE_ROOT . '/admin/partials/header.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_quotes', 'Quotes')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_quotes', 'Quotes') ?></h1>
        <p class="page-header-sub"><?= __('cd_quotes_sub', 'Send proposals clients approve online before work begins.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><?= __('cd_new_quote', 'New quote') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="_action" value="create">
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="client_id"><?= __('cd_client', 'Client') ?></label>
                <select id="client_id" name="client_id" required>
                    <option value=""><?= __('cd_select', 'Select…') ?></option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e(ucfirst($c['source'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="title"><?= __('cd_quote_title', 'Quote title') ?></label>
                <input type="text" id="title" name="title" maxlength="190" required placeholder="e.g. 5-page restaurant website">
            </div>
        </div>

        <div class="field">
            <label class="field-label"><?= __('cd_line_items', 'Line items') ?></label>
            <div id="qitems">
                <div class="field-row field-row-2 mb-2">
                    <input type="text" name="item_desc[]" placeholder="<?= e(__('cd_item_desc', 'Description')) ?>" maxlength="190">
                    <input type="number" name="item_amount[]" placeholder="<?= e(__('cd_amount', 'Amount')) ?>" step="0.01" min="0">
                </div>
            </div>
            <button type="button" class="btn btn-sm" onclick="cdAddQ()"><?= __('cd_add_line', '+ Add line') ?></button>
        </div>

        <div class="field">
            <label class="field-label" for="body"><?= __('cd_scope_notes', 'Scope / notes (shown to client)') ?></label>
            <textarea id="body" name="body" rows="3"></textarea>
        </div>
        <div class="field-row field-row-3">
            <div class="field">
                <label class="field-label" for="currency"><?= __('cd_currency', 'Currency') ?></label>
                <select id="currency" name="currency"><option>USD</option><option>EUR</option><option>GBP</option><option>BDT</option></select>
            </div>
            <div class="field">
                <label class="field-label" for="valid_until"><?= __('cd_valid_until', 'Valid until') ?></label>
                <input type="date" id="valid_until" name="valid_until">
            </div>
            <div class="field">
                <label class="field-label" for="project_id"><?= __('cd_project_id_opt', 'Project ID (optional)') ?></label>
                <input type="number" id="project_id" name="project_id" min="0">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('cd_create_quote', 'Create quote') ?></button>
    </form>
</div>

<?php if ($quotes): ?>
    <div class="card-header"><h2><?= __('cd_all_quotes', 'All quotes') ?></h2><span class="text-muted text-sm"><?= count($quotes) ?></span></div>
    <div class="data-list" data-single-open>
        <?php foreach ($quotes as $q):
            $color = ['draft'=>'muted','sent'=>'warning','approved'=>'success','declined'=>'danger','expired'=>'muted'][$q['status']] ?? 'muted';
            ob_start(); ?>
            <div class="flex gap-2">
                <a href="<?= e(plugin_url('clientdesk','admin/quote.php')) ?>?id=<?= (int)$q['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_view','View') ?></a>
                <?php if ($q['status'] === 'draft'): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="send"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                    <button class="btn btn-sm"><?= __('cd_send', 'Send') ?></button></form>
                <?php endif; ?>
                <?php if ($q['status'] === 'approved'): ?>
                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="to_invoice"><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                    <button class="btn btn-sm"><?= __('cd_convert_invoice', 'Convert to invoice') ?></button></form>
                <?php endif; ?>
            </div>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'=>'QT','avatar_color'=>$color,
                'title'=>$q['number'] . ' · ' . $q['title'],
                'meta'=>$q['client_name'] . ' · ' . ucfirst($q['status']),
                'value'=>ClientDeskAPI::formatMoney((int)$q['total_cents'], $q['currency']),
                'badge'=>[ucfirst($q['status']), $color],
                'detail'=>[
                    'Client'=>$q['client_name'],
                    'Total'=>ClientDeskAPI::formatMoney((int)$q['total_cents'], $q['currency']),
                    'Valid until'=>$q['valid_until'] ? date('j M Y', strtotime($q['valid_until'])) : '—',
                    'Decided'=>$q['decided_at'] ? date('j M Y', strtotime($q['decided_at'])) : '—',
                ],
                'actions'=>$actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<script>
function cdAddQ(){var w=document.getElementById('qitems');var r=document.createElement('div');r.className='field-row field-row-2 mb-2';
r.innerHTML='<input type="text" name="item_desc[]" placeholder="<?= e(__('cd_item_desc','Description')) ?>" maxlength="190"><input type="number" name="item_amount[]" placeholder="<?= e(__('cd_amount','Amount')) ?>" step="0.01" min="0">';w.appendChild(r);}
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
