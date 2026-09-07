<?php
/**
 * Client Desk — admin: invoices.
 * Create invoices with line items, attach the agreement copy + the
 * requirements brief (auto-pulled from the project's intake), send by
 * email, mark paid, or take payment via Stripe if that plugin is active.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.manage_invoices');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$pageTitle  = __('cd_invoices', 'Invoices');
$currentNav = 'clientdesk-invoices';
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
            $descs = $_POST['item_desc']  ?? [];
            $amts  = $_POST['item_amount'] ?? [];
            $items = [];
            $total = 0;
            foreach ($descs as $i => $d) {
                $d = trim((string)$d);
                $a = round((float)($amts[$i] ?? 0) * 100);
                if ($d === '' || $a <= 0) continue;
                $items[] = ['desc' => mb_substr($d, 0, 190), 'amount_cents' => (int)$a];
                $total += (int)$a;
            }
            if (!$client) {
                $flash = ['type' => 'error', 'msg' => __('cd_pick_client', 'Pick a client.')];
            } elseif (!$items) {
                $flash = ['type' => 'error', 'msg' => __('cd_need_item', 'Add at least one line item.')];
            } else {
                $projectId = (int)($_POST['project_id'] ?? 0) ?: null;
                // Auto-attach agreement + brief snapshots from the project.
                $agreement = null; $brief = null;
                if ($projectId) {
                    $proj = ClientDeskAPI::project($projectId);
                    $agreement = $proj['agreement'] ?? null;
                    $intake = ClientDeskAPI::intake($projectId);
                    if ($intake && !empty($intake['answers_decoded'])) {
                        $lines = [];
                        foreach (ClientDeskAPI::intakeFields() as $k => $def) {
                            $v = $intake['answers_decoded'][$k] ?? '';
                            if ($v !== '') $lines[] = $def['label'] . ': ' . $v;
                        }
                        $brief = implode("\n", $lines);
                    }
                }
                $id = Database::insert('clientdesk_invoices', [
                    'tenant_id'          => current_tenant_id(),
                    'client_id'          => $clientId,
                    'project_id'         => $projectId,
                    'number'             => ClientDeskAPI::nextInvoiceNumber(),
                    'currency'           => in_array($_POST['currency'] ?? 'USD', ['USD','EUR','GBP','BDT'], true) ? $_POST['currency'] : 'USD',
                    'line_items'         => json_encode($items),
                    'total_cents'        => $total,
                    'agreement_copy'     => $agreement,
                    'requirements_brief' => $brief,
                    'due_date'           => ($_POST['due_date'] ?? '') !== '' ? $_POST['due_date'] : null,
                ]);
                AuditLog::record('clientdesk.invoice_created', "invoice#$id", ['total' => $total]);
                $flash = ['type' => 'success', 'msg' => __('cd_invoice_created', 'Invoice created as draft.')];
            }
        } elseif ($action === 'send') {
            $invId = (int)($_POST['id'] ?? 0);
            $inv = ClientDeskAPI::invoice($invId);
            if ($inv) {
                $client = ClientDeskAPI::client((int)$inv['client_id']);
                Database::update('clientdesk_invoices',
                    ['status' => 'sent', 'sent_at' => slate_db_now()],
                    'id = ? AND tenant_id = ?', [$invId, current_tenant_id()]);
                if ($client && !empty($client['email'])) {
                    $body = '<p>' . e(__('cd_inv_email_intro', 'A new invoice is ready for you.')) . '</p>'
                          . '<p><strong>' . e($inv['number']) . '</strong> — '
                          . e(ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency'])) . '</p>'
                          . '<p><a href="' . e(SLATE_URL . '/portal/' . $client['access_token']) . '">'
                          . e(__('cd_view_portal', 'View it in your portal')) . '</a></p>';
                    Mailer::send($client['email'], 'Invoice ' . $inv['number'], $body, $client['name'] ?? '');
                }
                AuditLog::record('clientdesk.invoice_sent', "invoice#$invId");
                $flash = ['type' => 'success', 'msg' => __('cd_invoice_sent', 'Invoice marked sent (email sent if address on file).')];
            }
        } elseif ($action === 'mark_paid') {
            $invId = (int)($_POST['id'] ?? 0);
            if (ClientDeskAPI::invoice($invId)) {
                Database::update('clientdesk_invoices',
                    ['status' => 'paid', 'paid_at' => slate_db_now()],
                    'id = ? AND tenant_id = ?', [$invId, current_tenant_id()]);
                AuditLog::record('clientdesk.invoice_paid', "invoice#$invId");
                $flash = ['type' => 'success', 'msg' => __('cd_invoice_paid', 'Marked paid.')];
            }
        }
    }
}

$preselectClient = (int)($_GET['client'] ?? 0);
$clients  = ClientDeskAPI::clients();
$invoices = Database::rows(
    "SELECT i.*, c.name AS client_name FROM clientdesk_invoices i
       JOIN clientdesk_clients c ON c.id = i.client_id
      WHERE i.tenant_id = ? ORDER BY i.created_at DESC",
    [current_tenant_id()]
);

require SLATE_ROOT . '/admin/partials/header.php';
require_once dirname(__DIR__) . '/includes/ui.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_invoices', 'Invoices')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_invoices', 'Invoices') ?></h1>
        <p class="page-header-sub"><?= __('cd_invoices_sub', 'Bill clients; the agreement + requirements brief attach automatically.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h2><?= __('cd_new_invoice', 'New invoice') ?></h2></div>
    <form method="post" id="invForm">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="_action" value="create">
        <div class="field-row field-row-3">
            <div class="field">
                <label class="field-label" for="client_id"><?= __('cd_client', 'Client') ?></label>
                <select id="client_id" name="client_id" required>
                    <option value=""><?= __('cd_select', 'Select…') ?></option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $preselectClient === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e(ucfirst($c['source'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="project_id"><?= __('cd_project_optional', 'Project (optional — pulls agreement + brief)') ?></label>
                <input type="number" id="project_id" name="project_id" min="0" placeholder="<?= e(__('cd_project_id_ph', 'Project ID')) ?>">
            </div>
            <div class="field">
                <label class="field-label" for="currency"><?= __('cd_currency', 'Currency') ?></label>
                <select id="currency" name="currency">
                    <option>USD</option><option>EUR</option><option>GBP</option><option>BDT</option>
                </select>
            </div>
        </div>

        <div class="field">
            <label class="field-label"><?= __('cd_line_items', 'Line items') ?></label>
            <div id="items">
                <div class="field-row field-row-2 mb-2">
                    <input type="text" name="item_desc[]" placeholder="<?= e(__('cd_item_desc', 'Description')) ?>" maxlength="190">
                    <input type="number" name="item_amount[]" placeholder="<?= e(__('cd_amount', 'Amount')) ?>" step="0.01" min="0">
                </div>
            </div>
            <button type="button" class="btn btn-sm" onclick="cdAddItem()"><?= __('cd_add_line', '+ Add line') ?></button>
        </div>

        <div class="field">
            <label class="field-label" for="due_date"><?= __('cd_due', 'Due date') ?></label>
            <input type="date" id="due_date" name="due_date">
        </div>
        <button type="submit" class="btn btn-primary"><?= __('cd_create_invoice', 'Create invoice') ?></button>
    </form>
</div>

<?php if ($invoices): ?>
    <div class="card-header"><h2><?= __('cd_all_invoices', 'All invoices') ?></h2><span class="text-muted text-sm"><?= count($invoices) ?></span></div>
    <div class="data-list" data-single-open>
        <?php foreach ($invoices as $inv):
            $color = ['draft' => 'muted', 'sent' => 'warning', 'paid' => 'success', 'overdue' => 'danger'][$inv['status']] ?? 'muted';
            ob_start(); ?>
            <div class="flex gap-2">
                <a href="<?= e(plugin_url('clientdesk','admin/invoice.php')) ?>?id=<?= (int)$inv['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_view','View') ?></a>
                <?php if ($inv['status'] === 'draft'): ?>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="send">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <button type="submit" class="btn btn-sm"><?= __('cd_send', 'Send') ?></button>
                </form>
                <?php endif; ?>
                <?php if ($inv['status'] !== 'paid'): ?>
                <form method="post" style="margin:0">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="mark_paid">
                    <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                    <button type="submit" class="btn btn-sm"><?= __('cd_mark_paid', 'Mark paid') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php $actions = ob_get_clean();

            $detail = [
                'Client'   => $inv['client_name'],
                'Number'   => $inv['number'],
                'Total'    => ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency']),
                'Due'      => $inv['due_date'] ? date('j M Y', strtotime($inv['due_date'])) : '—',
            ];
            if ($inv['status'] === 'paid') {
                $detail['Paid'] = $inv['paid_at'] ? date('j M Y', strtotime($inv['paid_at'])) : '—';
                $method = !empty($inv['payment_method']) ? ucfirst($inv['payment_method']) : __('cd_manual', 'Manual');
                $detail['Method'] = !empty($inv['payment_ref'])
                    ? ['label' => 'Method', 'value' => $method . ' · ' . $inv['payment_ref'], 'muted' => true]
                    : $method;
            }
            if (!empty($inv['agreement_copy']))     $detail['Agreement'] = ['label' => 'Agreement', 'value' => mb_substr($inv['agreement_copy'], 0, 400), 'muted' => true];
            if (!empty($inv['requirements_brief'])) $detail['Brief']     = ['label' => 'Requirements brief', 'value' => mb_substr($inv['requirements_brief'], 0, 400), 'muted' => true];

            slate_data_row([
                'avatar'       => 'IN',
                'avatar_color' => $color,
                'title'        => $inv['number'] . ' · ' . $inv['client_name'],
                'meta'         => ucfirst($inv['status']),
                'value'        => ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency']),
                'badge'        => [ucfirst($inv['status']), $color],
                'detail'       => $detail,
                'actions'      => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<script>
function cdAddItem() {
    var wrap = document.getElementById('items');
    var row = document.createElement('div');
    row.className = 'field-row field-row-2 mb-2';
    row.innerHTML = '<input type="text" name="item_desc[]" placeholder="<?= e(__('cd_item_desc', 'Description')) ?>" maxlength="190">'
                  + '<input type="number" name="item_amount[]" placeholder="<?= e(__('cd_amount', 'Amount')) ?>" step="0.01" min="0">';
    wrap.appendChild(row);
}
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
