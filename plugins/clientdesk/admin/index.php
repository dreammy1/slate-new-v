<?php
/**
 * Client Desk — admin: client directory.
 * List + create + edit clients, separate direct vs Fiverr, generate
 * the per-client custom login link, link a portal customer account.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.view');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$pageTitle  = __('cd_clients', 'Clients');
$currentNav = 'clientdesk-clients';

$flash = null;
$canManage = Auth::can('clientdesk.manage_clients');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } elseif (!$canManage) {
        $flash = ['type' => 'error', 'msg' => __('cd_no_perm', 'You do not have permission to do that.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $name   = trim((string)($_POST['name'] ?? ''));
            $fields = [
                'company'    => trim((string)($_POST['company'] ?? '')) ?: null,
                'email'      => trim((string)($_POST['email'] ?? '')) ?: null,
                'phone'      => trim((string)($_POST['phone'] ?? '')) ?: null,
                'source'     => in_array($_POST['source'] ?? '', ['direct','fiverr','referral','other'], true) ? $_POST['source'] : 'direct',
                'source_ref' => trim((string)($_POST['source_ref'] ?? '')) ?: null,
                'status'     => in_array($_POST['status'] ?? '', ['active','completed','on_hold','archived'], true) ? $_POST['status'] : 'active',
                'tags'       => trim((string)($_POST['tags'] ?? '')) ?: null,
                'notes'      => trim((string)($_POST['notes'] ?? '')) ?: null,
            ];
            if ($name === '') {
                $flash = ['type' => 'error', 'msg' => __('cd_name_required', 'Client name is required.')];
            } elseif ($action === 'create') {
                $fields['tenant_id']    = current_tenant_id();
                $fields['name']         = mb_substr($name, 0, 160);
                $fields['access_token'] = ClientDeskAPI::newAccessToken();
                $id = Database::insert('clientdesk_clients', $fields);
                AuditLog::record('clientdesk.client_created', "client#$id", ['name' => $name]);
                $flash = ['type' => 'success', 'msg' => __('cd_client_added', 'Client added.')];
            } else {
                $id = (int)($_POST['id'] ?? 0);
                if (ClientDeskAPI::client($id)) {
                    $fields['name'] = mb_substr($name, 0, 160);
                    Database::update('clientdesk_clients', $fields, 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                    AuditLog::record('clientdesk.client_updated', "client#$id");
                    $flash = ['type' => 'success', 'msg' => __('cd_client_saved', 'Client updated.')];
                }
            }
        } elseif ($action === 'regen_token') {
            $id = (int)($_POST['id'] ?? 0);
            if (ClientDeskAPI::client($id)) {
                Database::update('clientdesk_clients',
                    ['access_token' => ClientDeskAPI::newAccessToken()],
                    'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                AuditLog::record('clientdesk.token_regenerated', "client#$id");
                $flash = ['type' => 'success', 'msg' => __('cd_link_regen', 'Access link regenerated.')];
            }
        } elseif ($action === 'request_status') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $st  = in_array($_POST['status'] ?? '', ['handled','dismissed'], true) ? $_POST['status'] : 'dismissed';
            Database::update('clientdesk_access_requests', ['status' => $st], 'id = ? AND tenant_id = ?', [$rid, current_tenant_id()]);
            $flash = ['type' => 'success', 'msg' => __('cd_request_updated', 'Request updated.')];
        }
    }
}

$filterSource = $_GET['source'] ?? '';
$searchQ = trim((string)($_GET['q'] ?? ''));
$clients = ClientDeskAPI::clients(
    in_array($filterSource, ['direct','fiverr','referral','other'], true) ? ['source' => $filterSource] : []
);
if ($searchQ !== '') {
    $needle = mb_strtolower($searchQ);
    $clients = array_values(array_filter($clients, function ($c) use ($needle) {
        return strpos(mb_strtolower(($c['name'] ?? '') . ' ' . ($c['company'] ?? '') . ' ' . ($c['email'] ?? '') . ' ' . ($c['tags'] ?? '')), $needle) !== false;
    }));
}

require SLATE_ROOT . '/admin/partials/header.php';
require_once dirname(__DIR__) . '/includes/ui.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_clients', 'Clients')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_clients', 'Clients') ?></h1>
        <p class="page-header-sub"><?= __('cd_clients_sub', 'Your client directory — direct and Fiverr.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php
$pendingRequests = $canManage ? ClientDeskAPI::accessRequests('new') : [];
if ($pendingRequests): ?>
<div class="card" style="border-color:var(--warning)">
    <div class="card-header">
        <h2><?= __('cd_access_requests', 'Access requests') ?></h2>
        <span class="badge badge-warning"><?= count($pendingRequests) ?> <?= __('cd_new', 'new') ?></span>
    </div>
    <p class="text-sm text-muted" style="margin-top:-4px"><?= __('cd_access_requests_sub', 'People who asked for portal access from the landing page. Add them as a client, then create or link their account.') ?></p>
    <div class="data-list" data-single-open>
        <?php foreach ($pendingRequests as $r):
            ob_start(); ?>
            <div class="flex gap-2" style="flex-wrap:wrap">
                <a href="#new-client" class="btn btn-sm btn-primary"
                   onclick="document.getElementById('name').value=<?= e(json_encode($r['name'])) ?>;document.getElementById('email').value=<?= e(json_encode($r['email'])) ?>;document.getElementById('name').scrollIntoView({behavior:'smooth'});return false;">
                    <?= __('cd_add_as_client', 'Add as client') ?>
                </a>
                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="request_status">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="status" value="handled">
                    <button class="btn btn-sm"><?= __('cd_mark_handled', 'Mark handled') ?></button>
                </form>
                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="request_status">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="status" value="dismissed">
                    <button class="btn btn-sm btn-ghost"><?= __('cd_dismiss', 'Dismiss') ?></button>
                </form>
            </div>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($r['name'], 0, 2)),
                'avatar_color' => 'warning',
                'title'        => $r['name'],
                'meta'         => $r['email'] . ' · ' . date('j M Y', strtotime($r['created_at'])),
                'detail'       => [
                    'Email'   => $r['email'],
                    'Message' => $r['message'] ? ['label' => 'Message', 'value' => $r['message'], 'muted' => true] : '—',
                    'When'    => date('j M Y H:i', strtotime($r['created_at'])),
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
</div>
<?php endif; ?>

<div class="flex gap-2 mb-3" style="flex-wrap:wrap">
    <a href="?" class="btn btn-sm <?= $filterSource === '' ? 'btn-primary' : '' ?>"><?= __('cd_all', 'All') ?></a>
    <a href="?source=direct" class="btn btn-sm <?= $filterSource === 'direct' ? 'btn-primary' : '' ?>"><?= __('cd_direct', 'Direct') ?></a>
    <a href="?source=fiverr" class="btn btn-sm <?= $filterSource === 'fiverr' ? 'btn-primary' : '' ?>">Fiverr</a>
    <a href="?source=referral" class="btn btn-sm <?= $filterSource === 'referral' ? 'btn-primary' : '' ?>"><?= __('cd_referral', 'Referral') ?></a>
    <form method="get" class="flex gap-2 items-center" style="margin-left:auto">
        <?php if ($filterSource): ?><input type="hidden" name="source" value="<?= e($filterSource) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($searchQ) ?>" placeholder="<?= e(__('cd_search', 'Search name, email, tag…')) ?>" style="min-width:200px">
        <button class="btn btn-sm"><?= __('cd_search_btn', 'Search') ?></button>
    </form>
</div>

<?php if ($canManage): ?>
<div class="card" id="new-client">
    <div class="card-header"><h2><?= __('cd_new_client', 'New client') ?></h2></div>
    <form method="post">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="_action" value="create">
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="name"><?= __('cd_name', 'Name') ?> <span class="field-required">*</span></label>
                <input type="text" id="name" name="name" required maxlength="160">
            </div>
            <div class="field">
                <label class="field-label" for="company"><?= __('cd_company', 'Company') ?></label>
                <input type="text" id="company" name="company" maxlength="160">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="email"><?= __('cd_email', 'Email') ?></label>
                <input type="email" id="email" name="email" maxlength="190">
            </div>
            <div class="field">
                <label class="field-label" for="phone"><?= __('cd_phone', 'Phone') ?></label>
                <input type="text" id="phone" name="phone" maxlength="40">
            </div>
        </div>
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="source"><?= __('cd_source', 'Source') ?></label>
                <select id="source" name="source">
                    <option value="direct"><?= __('cd_direct', 'Direct') ?></option>
                    <option value="fiverr">Fiverr</option>
                    <option value="referral"><?= __('cd_referral', 'Referral') ?></option>
                    <option value="other"><?= __('cd_other', 'Other') ?></option>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="source_ref"><?= __('cd_source_ref', 'Source reference (e.g. Fiverr username/order)') ?></label>
                <input type="text" id="source_ref" name="source_ref" maxlength="120">
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="tags"><?= __('cd_tags', 'Tags (comma-separated)') ?></label>
            <input type="text" id="tags" name="tags" maxlength="255" placeholder="restaurant, retainer, vip">
            <span class="field-hint"><?= __('cd_tags_hint', 'Used for search and quick filtering.') ?></span>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('cd_add_client', 'Add client') ?></button>
    </form>
</div>
<?php endif; ?>

<?php if (empty($clients)): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title"><?= __('cd_no_clients', 'No clients yet') ?></div>
            <p><?= __('cd_no_clients_sub', 'Add your first client above.') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="card-header"><h2><?= __('cd_directory', 'Directory') ?></h2><span class="text-muted text-sm"><?= count($clients) ?></span></div>
    <div class="data-list" data-single-open>
        <?php foreach ($clients as $c):
            $portalUrl = SLATE_URL . '/portal/' . $c['access_token'];
            $sourceColor = ['direct' => 'success', 'fiverr' => 'accent', 'referral' => 'info', 'other' => 'muted'][$c['source']] ?? 'muted';
            $projects = ClientDeskAPI::projectsForClient((int)$c['id']);

            ob_start(); ?>
            <div class="flex gap-2">
                <a href="<?= e(plugin_url('clientdesk', 'admin/client.php')) ?>?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_open', 'Open') ?></a>
                <?php if ($canManage): ?>
                <form method="post" style="margin:0" onsubmit="return confirm(<?= e(json_encode(__('cd_confirm_regen', 'Regenerate the login link? The old link stops working.'))) ?>);">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="regen_token">
                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                    <button type="submit" class="btn btn-sm"><?= __('cd_regen_link', 'Regenerate link') ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php $actions = ob_get_clean();

            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($c['name'], 0, 2)),
                'avatar_color' => $sourceColor,
                'title'        => $c['name'] . ($c['company'] ? ' · ' . $c['company'] : ''),
                'meta'         => ($c['email'] ?: '—') . ' · ' . count($projects) . ' ' . __('cd_projects_l', 'project(s)'),
                'badge'        => [ucfirst($c['source']), $sourceColor],
                'detail'       => [
                    'Email'       => $c['email'] ?: '—',
                    'Phone'       => $c['phone'] ?: '—',
                    'Source'      => ucfirst($c['source']) . ($c['source_ref'] ? ' (' . $c['source_ref'] . ')' : ''),
                    'Status'      => ['label' => 'Status', 'html' => '<span class="badge badge-active">' . e(ucfirst(str_replace('_',' ',$c['status']))) . '</span>'],
                    'Login link'  => ['label' => 'Login link', 'html' => '<code class="kv-mono">' . e($portalUrl) . '</code>'],
                    'Tags'        => $c['tags'] ? e($c['tags']) : '—',
                    'Notes'       => $c['notes'] ? ['label' => 'Notes', 'value' => $c['notes'], 'muted' => true] : '—',
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
