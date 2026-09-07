<?php
/**
 * Client Desk — admin: support tickets.
 * List tickets, open a thread, reply, change status.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.handle_support');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$pageTitle  = __('cd_support', 'Support');
$currentNav = 'clientdesk-tickets';
$flash = null;
$me    = Auth::user();

$openId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $ticket = ClientDeskAPI::ticket($tid);

        if ($ticket && $action === 'reply') {
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body !== '') {
                ClientDeskAPI::addTicketMessage($tid, 'staff', $me['name'] ?? 'Staff', $body);
                if ($ticket['status'] === 'open') {
                    Database::update('clientdesk_tickets', ['status' => 'in_progress'], 'id = ? AND tenant_id = ?', [$tid, current_tenant_id()]);
                }
                // Notify the client by email if we can.
                $client = ClientDeskAPI::client((int)$ticket['client_id']);
                if ($client && !empty($client['email'])) {
                    Mailer::send($client['email'], 'Re: ' . $ticket['subject'],
                        '<p>' . e(__('cd_ticket_reply_email', 'You have a new reply on your support ticket.')) . '</p>'
                        . '<p><a href="' . e(SLATE_URL . '/portal/' . $client['access_token']) . '">'
                        . e(__('cd_view_portal', 'View it in your portal')) . '</a></p>',
                        $client['name'] ?? '');
                }
                AuditLog::record('clientdesk.ticket_replied', "ticket#$tid");
                $flash = ['type' => 'success', 'msg' => __('cd_reply_sent', 'Reply posted.')];
            }
            $openId = $tid;
        } elseif ($ticket && $action === 'set_status') {
            $status = in_array($_POST['status'] ?? '', ['open','in_progress','resolved','closed'], true) ? $_POST['status'] : $ticket['status'];
            Database::update('clientdesk_tickets',
                ['status' => $status, 'updated_at' => slate_db_now()],
                'id = ? AND tenant_id = ?', [$tid, current_tenant_id()]);
            AuditLog::record('clientdesk.ticket_status', "ticket#$tid", ['status' => $status]);
            $flash = ['type' => 'success', 'msg' => __('cd_status_updated', 'Status updated.')];
            $openId = $tid;
        }
    }
}

$tickets = Database::rows(
    "SELECT t.*, c.name AS client_name FROM clientdesk_tickets t
       JOIN clientdesk_clients c ON c.id = t.client_id
      WHERE t.tenant_id = ? ORDER BY t.updated_at DESC",
    [current_tenant_id()]
);
$open = $openId ? ClientDeskAPI::ticket($openId) : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_support', 'Support')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_support', 'Support') ?></h1>
        <p class="page-header-sub"><?= __('cd_support_sub', 'Client support tickets.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($open):
    $msgs = ClientDeskAPI::ticketMessages((int)$open['id']);
    $client = ClientDeskAPI::client((int)$open['client_id']);
?>
    <div class="card">
        <div class="card-header">
            <h2><?= e($open['subject']) ?></h2>
            <a href="?" class="btn btn-sm"><?= __('cd_back', '← Back') ?></a>
        </div>
        <p class="text-sm text-muted mb-3">
            <?= e($client['name'] ?? '') ?> · <?= e(ucfirst($open['priority'])) ?> ·
            <span class="badge badge-active"><?= e(ucfirst(str_replace('_',' ',$open['status']))) ?></span>
        </p>

        <?php foreach ($msgs as $m): ?>
            <div class="card mb-2" style="background:var(--surface-2)">
                <p class="text-sm text-muted" style="margin-bottom:4px">
                    <strong><?= e($m['author_name'] ?? ($m['author_type'] === 'staff' ? 'Staff' : 'Client')) ?></strong>
                    · <?= e($m['author_type']) ?> · <?= e(date('j M Y H:i', strtotime($m['created_at']))) ?>
                </p>
                <div><?= nl2br(e($m['body'])) ?></div>
            </div>
        <?php endforeach; ?>

        <form method="post" class="mt-3">
            <?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="reply">
            <input type="hidden" name="ticket_id" value="<?= (int)$open['id'] ?>">
            <div class="field">
                <label class="field-label" for="body"><?= __('cd_reply', 'Reply') ?></label>
                <textarea id="body" name="body" rows="3" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary"><?= __('cd_send_reply', 'Send reply') ?></button>
        </form>

        <form method="post" class="flex gap-2 items-center mt-3">
            <?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="set_status">
            <input type="hidden" name="ticket_id" value="<?= (int)$open['id'] ?>">
            <select name="status">
                <?php foreach (['open','in_progress','resolved','closed'] as $s): ?>
                    <option value="<?= $s ?>" <?= $open['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_',' ',$s))) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn"><?= __('cd_update_status', 'Update status') ?></button>
        </form>
    </div>
<?php elseif (empty($tickets)): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= __('cd_no_tickets', 'No tickets') ?></div>
        <p><?= __('cd_no_tickets_sub', 'Clients raise tickets from their portal.') ?></p></div></div>
<?php else: ?>
    <div class="data-list" data-single-open>
        <?php foreach ($tickets as $t):
            $color = ['open' => 'warning', 'in_progress' => 'accent', 'resolved' => 'success', 'closed' => 'muted'][$t['status']] ?? 'muted';
            ob_start(); ?>
            <a href="?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_open', 'Open') ?></a>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'       => 'TK',
                'avatar_color' => $color,
                'title'        => $t['subject'],
                'meta'         => $t['client_name'] . ' · ' . ucfirst($t['priority']),
                'badge'        => [ucfirst(str_replace('_',' ',$t['status'])), $color],
                'detail'       => [
                    'Client'   => $t['client_name'],
                    'Priority' => ucfirst($t['priority']),
                    'Updated'  => date('j M Y H:i', strtotime($t['updated_at'])),
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
