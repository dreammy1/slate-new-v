<?php
/**
 * Client Desk — admin: all projects + manual create.
 * Managers see every project and can create one here (picking the client);
 * assigned-only team members see just theirs.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.view');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';

$pageTitle  = __('cd_projects', 'Projects');
$currentNav = 'clientdesk-projects';
$flash = null;
$canManage = Auth::can('clientdesk.manage_projects');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } elseif (($_POST['_action'] ?? '') === 'new_project') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? ''));
        $client   = ClientDeskAPI::client($clientId);
        if (!$client) {
            $flash = ['type' => 'error', 'msg' => __('cd_pick_client', 'Pick a client.')];
        } elseif ($title === '') {
            $flash = ['type' => 'error', 'msg' => __('cd_title_required', 'Project title is required.')];
        } else {
            $pid = Database::insert('clientdesk_projects', [
                'tenant_id'    => current_tenant_id(),
                'client_id'    => $clientId,
                'title'        => mb_substr($title, 0, 190),
                'project_type' => trim((string)($_POST['project_type'] ?? '')) ?: null,
                'deadline'     => ($_POST['deadline'] ?? '') !== '' ? $_POST['deadline'] : null,
            ]);
            // Optional: apply a milestone template immediately.
            $tplId = (int)($_POST['template_id'] ?? 0);
            if ($tplId > 0) ClientDeskAPI::applyTemplate($pid, $tplId);
            ClientDeskAPI::logActivity($pid, 'Project created.', Auth::user()['name'] ?? null);
            AuditLog::record('clientdesk.project_created', "project#$pid", ['client' => $clientId]);
            header('Location: ' . plugin_url('clientdesk', 'admin/project.php') . '?id=' . $pid);
            exit;
        }
    }
}

// Managers see all; otherwise restrict to assigned projects.
if ($canManage || Auth::isSuperAdmin()) {
    $projects = Database::rows(
        "SELECT p.*, c.name AS client_name, c.source AS client_source
           FROM clientdesk_projects p JOIN clientdesk_clients c ON c.id = p.client_id
          WHERE p.tenant_id = ? ORDER BY p.created_at DESC", [current_tenant_id()]);
} else {
    $projects = Database::rows(
        "SELECT p.*, c.name AS client_name, c.source AS client_source
           FROM clientdesk_projects p JOIN clientdesk_clients c ON c.id = p.client_id
           JOIN clientdesk_assignments a ON a.project_id = p.id AND a.user_id = ?
          WHERE p.tenant_id = ? ORDER BY p.created_at DESC",
        [(int)Auth::userId(), current_tenant_id()]);
}

$clients   = $canManage ? ClientDeskAPI::clients() : [];
$templates = $canManage ? ClientDeskAPI::templates() : [];

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_projects', 'Projects')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_projects', 'Projects') ?></h1>
        <p class="page-header-sub"><?= __('cd_projects_sub', 'Every active build and its status.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="card">
    <div class="card-header"><h2><?= __('cd_new_project', 'New project') ?></h2></div>
    <?php if (empty($clients)): ?>
        <p class="text-muted text-sm"><?= __('cd_need_client_first', 'Add a client first, then create a project for them.') ?>
            <a href="<?= e(plugin_url('clientdesk', 'admin/index.php')) ?>"><?= __('cd_go_clients', 'Go to Clients') ?> →</a></p>
    <?php else: ?>
    <form method="post">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="_action" value="new_project">
        <div class="field-row field-row-2">
            <div class="field">
                <label class="field-label" for="client_id"><?= __('cd_client', 'Client') ?> <span class="field-required">*</span></label>
                <select id="client_id" name="client_id" required>
                    <option value=""><?= __('cd_select', 'Select…') ?></option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= e(ucfirst($c['source'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="title"><?= __('cd_project_title', 'Project title') ?> <span class="field-required">*</span></label>
                <input type="text" id="title" name="title" required maxlength="190">
            </div>
        </div>
        <div class="field-row field-row-3">
            <div class="field">
                <label class="field-label" for="project_type"><?= __('cd_project_type', 'Type') ?></label>
                <input type="text" id="project_type" name="project_type" maxlength="80" placeholder="e.g. Restaurant, Portfolio">
            </div>
            <div class="field">
                <label class="field-label" for="deadline"><?= __('cd_deadline', 'Deadline') ?></label>
                <input type="date" id="deadline" name="deadline">
            </div>
            <div class="field">
                <label class="field-label" for="template_id"><?= __('cd_template', 'Milestone template') ?></label>
                <select id="template_id" name="template_id">
                    <option value="0"><?= __('cd_none_tpl', 'None') ?></option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><?= __('cd_create_project', 'Create project') ?></button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($projects)): ?>
    <div class="card"><div class="empty"><div class="empty-title"><?= __('cd_no_projects', 'No projects yet') ?></div>
        <p><?= $canManage ? __('cd_create_above', 'Create your first project above.') : __('cd_no_projects_sub2', 'Projects you are assigned to will appear here.') ?></p></div></div>
<?php else: ?>
    <div class="card-header"><h2><?= __('cd_all_projects', 'All projects') ?></h2><span class="text-muted text-sm"><?= count($projects) ?></span></div>
    <div class="data-list" data-single-open>
        <?php foreach ($projects as $p):
            ob_start(); ?>
            <a href="<?= e(plugin_url('clientdesk', 'admin/project.php')) ?>?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-primary"><?= __('cd_manage', 'Manage') ?></a>
            <?php $actions = ob_get_clean();
            slate_data_row([
                'avatar'       => mb_strtoupper(mb_substr($p['title'], 0, 2)),
                'avatar_color' => $p['phase'] === 'complete' ? 'success' : 'accent',
                'title'        => $p['title'],
                'meta'         => $p['client_name'] . ' · ' . ClientDeskAPI::phaseLabel($p['phase']),
                'value'        => (int)$p['progress'] . '%',
                'badge'        => [ucfirst($p['client_source']), $p['client_source'] === 'fiverr' ? 'accent' : 'success'],
                'detail'       => [
                    'Client'   => $p['client_name'],
                    'Phase'    => ClientDeskAPI::phaseLabel($p['phase']),
                    'Progress' => (int)$p['progress'] . '%',
                    'Deadline' => $p['deadline'] ? date('j M Y', strtotime($p['deadline'])) : '—',
                ],
                'actions' => $actions,
            ]);
        endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
