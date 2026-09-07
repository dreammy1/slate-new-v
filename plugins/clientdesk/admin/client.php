<?php
/**
 * Client Desk — admin: single client.
 * Edit client, create projects, link/create the portal customer account
 * (Custom Login), and see invoices + tickets at a glance.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.view');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$id     = (int)($_GET['id'] ?? 0);
$client = ClientDeskAPI::client($id);
if (!$client) {
    http_response_code(404);
    $pageTitle = __('cd_not_found', 'Client not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('cd_not_found', 'Client not found') . '</div></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}

$pageTitle  = $client['name'];
$currentNav = 'clientdesk-clients';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'new_project' && Auth::can('clientdesk.manage_projects')) {
            $title = trim((string)($_POST['title'] ?? ''));
            if ($title !== '') {
                $pid = Database::insert('clientdesk_projects', [
                    'tenant_id'    => current_tenant_id(),
                    'client_id'    => $id,
                    'title'        => mb_substr($title, 0, 190),
                    'project_type' => trim((string)($_POST['project_type'] ?? '')) ?: null,
                    'deadline'     => ($_POST['deadline'] ?? '') !== '' ? $_POST['deadline'] : null,
                ]);
                ClientDeskAPI::logActivity($pid, 'Project created.', Auth::user()['name'] ?? null);
                AuditLog::record('clientdesk.project_created', "project#$pid", ['client' => $id]);
                $flash = ['type' => 'success', 'msg' => __('cd_project_added', 'Project created.')];
            } else {
                $flash = ['type' => 'error', 'msg' => __('cd_title_required', 'Project title is required.')];
            }
        } elseif ($action === 'link_portal' && Auth::can('clientdesk.manage_clients')) {
            // Create or link a customer account so the client can log in.
            $email = trim((string)($_POST['portal_email'] ?? ''));
            $pass  = (string)($_POST['portal_password'] ?? '');
            if ($email === '' || strlen($pass) < 8) {
                $flash = ['type' => 'error', 'msg' => __('cd_portal_invalid', 'A valid email and an 8+ character password are required.')];
            } else {
                $reg = Auth::registerCustomer($email, $pass, $client['name'], $client['phone'] ?? '');
                if ($reg['ok']) {
                    Database::update('clientdesk_clients', ['customer_id' => $reg['customer_id']],
                        'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                    AuditLog::record('clientdesk.portal_linked', "client#$id", ['customer_id' => $reg['customer_id']]);
                    $flash = ['type' => 'success', 'msg' => __('cd_portal_created', 'Portal account created and linked.')];
                    $client = ClientDeskAPI::client($id);
                } else {
                    // Email may already exist — try to find and link it.
                    $existing = Database::row("SELECT id FROM customers WHERE email = ? AND tenant_id = ?", [$email, current_tenant_id()]);
                    if ($existing) {
                        Database::update('clientdesk_clients', ['customer_id' => $existing['id']],
                            'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                        $flash = ['type' => 'success', 'msg' => __('cd_portal_linked', 'Existing portal account linked.')];
                        $client = ClientDeskAPI::client($id);
                    } else {
                        $flash = ['type' => 'error', 'msg' => e($reg['error'] ?? __('cd_portal_fail', 'Could not create the portal account.'))];
                    }
                }
            }
        } elseif ($action === 'link_existing' && Auth::can('clientdesk.manage_clients')) {
            // Link an already-registered customer account by email (no password needed).
            $email = trim((string)($_POST['existing_email'] ?? ''));
            if ($email === '') {
                $flash = ['type' => 'error', 'msg' => __('cd_email_required', 'Enter the customer email to link.')];
            } else {
                $existing = Database::row("SELECT id FROM customers WHERE LOWER(email) = LOWER(?) AND tenant_id = ?", [$email, current_tenant_id()]);
                if ($existing) {
                    Database::update('clientdesk_clients', ['customer_id' => $existing['id'], 'email' => $email],
                        'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
                    AuditLog::record('clientdesk.portal_linked', "client#$id", ['customer_id' => $existing['id']]);
                    $flash = ['type' => 'success', 'msg' => __('cd_portal_linked', 'Existing portal account linked.')];
                    $client = ClientDeskAPI::client($id);
                } else {
                    $flash = ['type' => 'error', 'msg' => __('cd_no_such_customer', 'No registered customer with that email. Use “Create portal account” instead.')];
                }
            }
        } elseif ($action === 'unlink_portal' && Auth::can('clientdesk.manage_clients')) {
            Database::update('clientdesk_clients', ['customer_id' => null], 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
            AuditLog::record('clientdesk.portal_unlinked', "client#$id");
            $flash = ['type' => 'success', 'msg' => __('cd_portal_unlinked', 'Portal account unlinked.')];
            $client = ClientDeskAPI::client($id);
        }
    }
}

$projects = ClientDeskAPI::projectsForClient($id);
$invoices = ClientDeskAPI::invoicesForClient($id);
$tickets  = ClientDeskAPI::ticketsForClient($id);
$quotes   = ClientDeskAPI::quotesForClient($id);
$portalUrl = SLATE_URL . '/portal/' . $client['access_token'];

require SLATE_ROOT . '/admin/partials/header.php';
require_once dirname(__DIR__) . '/includes/ui.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_clients', 'Clients'), 'href' => plugin_url('clientdesk', 'admin/index.php')],
    ['label' => $client['name']],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e($client['name']) ?></h1>
        <p class="page-header-sub"><?= e(ucfirst($client['source'])) ?><?= $client['company'] ? ' · ' . e($client['company']) : '' ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= $flash['msg'] ?></div>
<?php endif; ?>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">

        <?php if (Auth::can('clientdesk.manage_projects')): ?>
        <div class="card">
            <div class="card-header"><h2><?= __('cd_new_project', 'New project') ?></h2></div>
            <form method="post">
                <?= csrf_field() ?><?= submit_token_field() ?>
                <input type="hidden" name="_action" value="new_project">
                <div class="field">
                    <label class="field-label" for="title"><?= __('cd_project_title', 'Project title') ?> <span class="field-required">*</span></label>
                    <input type="text" id="title" name="title" required maxlength="190">
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label" for="project_type"><?= __('cd_project_type', 'Type') ?></label>
                        <input type="text" id="project_type" name="project_type" maxlength="80" placeholder="e.g. Restaurant, Portfolio">
                    </div>
                    <div class="field">
                        <label class="field-label" for="deadline"><?= __('cd_deadline', 'Deadline') ?></label>
                        <input type="date" id="deadline" name="deadline">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><?= __('cd_create_project', 'Create project') ?></button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card-header"><h2><?= __('cd_projects', 'Projects') ?></h2><span class="text-muted text-sm"><?= count($projects) ?></span></div>
        <?php if (empty($projects)): ?>
            <div class="card"><div class="empty"><div class="empty-title"><?= __('cd_no_projects', 'No projects yet') ?></div></div></div>
        <?php else: ?>
            <div class="data-list" data-single-open>
                <?php foreach ($projects as $p):
                    ob_start(); ?>
                    <a href="<?= e(plugin_url('clientdesk', 'admin/project.php')) ?>?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_manage', 'Manage') ?></a>
                    <?php $actions = ob_get_clean();
                    slate_data_row([
                        'avatar'       => mb_strtoupper(mb_substr($p['title'], 0, 2)),
                        'avatar_color' => $p['phase'] === 'complete' ? 'success' : 'accent',
                        'title'        => $p['title'],
                        'meta'         => ClientDeskAPI::phaseLabel($p['phase']) . ' · ' . (int)$p['progress'] . '%',
                        'value'        => $p['deadline'] ? date('j M Y', strtotime($p['deadline'])) : null,
                        'detail'       => [
                            'Type'     => $p['project_type'] ?: '—',
                            'Phase'    => ClientDeskAPI::phaseLabel($p['phase']),
                            'Progress' => (int)$p['progress'] . '%',
                            'Staging'  => $p['staging_url'] ? ['label' => 'Staging', 'html' => '<a href="' . e($p['staging_url']) . '" target="_blank" rel="noopener">' . e($p['staging_url']) . '</a>'] : '—',
                        ],
                        'actions' => $actions,
                    ]);
                endforeach; ?>
            </div>
            <?php slate_data_list_script(); ?>
        <?php endif; ?>

    </div>

    <aside class="page-aside">

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_custom_login', 'Custom login link') ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= __('cd_portal_url', 'Portal URL') ?></span></li>
            </ul>
            <p class="text-sm" style="word-break:break-all"><code><?= e($portalUrl) ?></code></p>
            <?php
            $linkedEmail = null;
            if ($client['customer_id']) {
                $linkedEmail = Database::value("SELECT email FROM customers WHERE id = ? AND tenant_id = ?", [(int)$client['customer_id'], current_tenant_id()]);
            }
            ?>
            <?php if ($client['customer_id']): ?>
                <p class="field-hint"><?= __('cd_portal_active', 'Linked. The client signs in with their email + password.') ?></p>
                <ul class="kv-list">
                    <li class="kv-row"><span class="kv-label"><?= __('cd_linked_as', 'Linked account') ?></span>
                        <span class="kv-value" style="word-break:break-all"><?= e($linkedEmail ?: ('#' . $client['customer_id'])) ?></span></li>
                </ul>
                <?php if (Auth::can('clientdesk.manage_clients')): ?>
                <form method="post" class="mt-2" onsubmit="return confirm(<?= e(json_encode(__('cd_confirm_unlink', 'Unlink this portal account? The client will lose access until re-linked.'))) ?>);">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="unlink_portal">
                    <button class="btn btn-sm btn-ghost btn-block"><?= __('cd_unlink', 'Unlink account') ?></button>
                </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="field-hint"><?= __('cd_portal_none', 'No portal account linked yet. Link an existing account by email, or create one below.') ?></p>
            <?php endif; ?>
        </div>

        <?php if (Auth::can('clientdesk.manage_clients') && !$client['customer_id']): ?>
        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_link_existing', 'Link existing account') ?></div>
            <p class="field-hint"><?= __('cd_link_existing_hint', 'If the client already registered on the portal, link them by email — no password needed.') ?></p>
            <form method="post">
                <?= csrf_field() ?><?= submit_token_field() ?>
                <input type="hidden" name="_action" value="link_existing">
                <div class="field">
                    <label class="field-label" for="existing_email"><?= __('cd_email', 'Email') ?></label>
                    <input type="email" id="existing_email" name="existing_email" value="<?= e($client['email'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-block"><?= __('cd_link_account', 'Link account') ?></button>
            </form>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_create_portal', 'Create portal account') ?></div>
            <p class="field-hint"><?= __('cd_create_hint', 'Or create a brand-new account and share the password.') ?></p>
            <form method="post">
                <?= csrf_field() ?><?= submit_token_field() ?>
                <input type="hidden" name="_action" value="link_portal">
                <div class="field">
                    <label class="field-label" for="portal_email"><?= __('cd_email', 'Email') ?></label>
                    <input type="email" id="portal_email" name="portal_email" value="<?= e($client['email'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label class="field-label" for="portal_password"><?= __('cd_temp_password', 'Temporary password') ?></label>
                    <input type="text" id="portal_password" name="portal_password" minlength="8" required>
                    <span class="field-hint"><?= __('cd_pw_hint', 'Share securely; the client can reset it later.') ?></span>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><?= __('cd_create_account', 'Create account') ?></button>
            </form>
        </div>
        <?php endif; ?>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_invoices', 'Invoices') ?></div>
            <ul class="kv-list">
                <?php if (empty($invoices)): ?>
                    <li class="kv-row"><span class="kv-label text-muted"><?= __('cd_none', 'None') ?></span></li>
                <?php else: foreach ($invoices as $inv): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= e($inv['number']) ?></span>
                        <span class="kv-value kv-mono"><?= e(ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency'])) ?></span>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
            <a href="<?= e(plugin_url('clientdesk', 'admin/invoices.php')) ?>?client=<?= (int)$id ?>" class="btn btn-sm btn-block mt-2"><?= __('cd_manage_invoices', 'Manage invoices') ?></a>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_quotes', 'Quotes') ?></div>
            <ul class="kv-list">
                <?php if (empty($quotes)): ?>
                    <li class="kv-row"><span class="kv-label text-muted"><?= __('cd_none', 'None') ?></span></li>
                <?php else: foreach ($quotes as $q):
                    $qc = ['draft'=>'inactive','sent'=>'warning','approved'=>'active','declined'=>'danger','expired'=>'inactive'][$q['status']] ?? 'inactive'; ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= e($q['number']) ?> <span class="badge badge-<?= $qc ?>"><?= e(ucfirst($q['status'])) ?></span></span>
                        <span class="kv-value kv-mono"><?= e(ClientDeskAPI::formatMoney((int)$q['total_cents'], $q['currency'])) ?></span>
                    </li>
                <?php endforeach; endif; ?>
            </ul>
            <a href="<?= e(plugin_url('clientdesk', 'admin/quotes.php')) ?>" class="btn btn-sm btn-block mt-2"><?= __('cd_manage_quotes', 'Manage quotes') ?></a>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_support', 'Support') ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= __('cd_open_tickets', 'Open tickets') ?></span>
                    <span class="kv-value"><?= count(array_filter($tickets, fn($t) => in_array($t['status'], ['open','in_progress']))) ?></span></li>
            </ul>
        </div>

    </aside>

<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
