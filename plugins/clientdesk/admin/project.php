<?php
/**
 * Client Desk — admin: single project (v2, tabbed).
 * Tabs: Overview (progress, stepper, milestones, questionnaire, activity),
 * Files (assets + deliverables, upload), Comments (two-way thread). Plus a
 * Team rail and template application.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.view');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';

$id      = (int)($_GET['id'] ?? 0);
$project = ClientDeskAPI::project($id);
if (!$project) {
    http_response_code(404);
    $pageTitle = __('cd_not_found', 'Project not found');
    require SLATE_ROOT . '/admin/partials/header.php';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('cd_not_found', 'Project not found') . '</div></div></div>';
    require SLATE_ROOT . '/admin/partials/footer.php';
    exit;
}
$client = ClientDeskAPI::client((int)$project['client_id']);

$pageTitle  = $project['title'];
$currentNav = 'clientdesk-projects';
$flash = null;
$me    = Auth::user();
$canManage = Auth::can('clientdesk.manage_projects');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('cd_csrf', 'Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type' => 'warning', 'msg' => __('cd_dup_submit', 'That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'update_progress' && $canManage) {
            $phase    = in_array($_POST['phase'] ?? '', array_keys(ClientDeskAPI::phases()), true) ? $_POST['phase'] : $project['phase'];
            $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
            Database::update('clientdesk_projects', [
                'phase' => $phase, 'progress' => $progress,
                'staging_url' => trim((string)($_POST['staging_url'] ?? '')) ?: null,
                'agreement'   => trim((string)($_POST['agreement'] ?? '')) ?: null,
            ], 'id = ? AND tenant_id = ?', [$id, current_tenant_id()]);
            ClientDeskAPI::logActivity($id, 'Status: ' . ClientDeskAPI::phaseLabel($phase) . " ({$progress}%).", $me['name'] ?? null);
            AuditLog::record('clientdesk.progress_updated', "project#$id", ['phase'=>$phase,'progress'=>$progress]);
            $flash = ['type'=>'success','msg'=>__('cd_progress_saved','Progress updated.')];
            $project = ClientDeskAPI::project($id);

        } elseif ($action === 'add_milestone' && $canManage) {
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label !== '') {
                Database::insert('clientdesk_milestones', [
                    'tenant_id'=>current_tenant_id(),'project_id'=>$id,'label'=>mb_substr($label,0,190),
                    'sort_order'=>(int)Database::value("SELECT COALESCE(MAX(sort_order),0)+1 FROM clientdesk_milestones WHERE project_id=? AND tenant_id=?", [$id, current_tenant_id()]),
                ]);
                $flash = ['type'=>'success','msg'=>__('cd_milestone_added','Milestone added.')];
            }
        } elseif ($action === 'apply_template' && $canManage) {
            $n = ClientDeskAPI::applyTemplate($id, (int)($_POST['template_id'] ?? 0));
            $flash = ['type'=>'success','msg'=>sprintf(__('cd_tpl_applied','Added %d milestones from template.'), $n)];
        } elseif ($action === 'toggle_milestone' && $canManage) {
            $mid = (int)($_POST['milestone_id'] ?? 0);
            $m = Database::row("SELECT * FROM clientdesk_milestones WHERE id=? AND project_id=? AND tenant_id=?", [$mid, $id, current_tenant_id()]);
            if ($m) Database::update('clientdesk_milestones', ['done'=>$m['done']?0:1,'done_at'=>$m['done']?null:slate_db_now()], 'id=? AND tenant_id=?', [$mid, current_tenant_id()]);
        } elseif ($action === 'post_activity' && $canManage) {
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body !== '') { ClientDeskAPI::logActivity($id, $body, $me['name'] ?? null); $flash = ['type'=>'success','msg'=>__('cd_update_posted','Update posted.')]; }
        } elseif ($action === 'comment' && $canManage) {
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body !== '') { ClientDeskAPI::addComment($id, 'staff', $me['name'] ?? 'Staff', $body); $flash = ['type'=>'success','msg'=>__('cd_comment_added','Comment added.')]; }
        } elseif ($action === 'assign' && Auth::can('clientdesk.manage_team')) {
            $uid = (int)($_POST['user_id'] ?? 0);
            if (Database::row("SELECT id FROM users WHERE id=? AND tenant_id=?", [$uid, current_tenant_id()])) {
                Database::query("INSERT IGNORE INTO clientdesk_assignments (tenant_id, project_id, user_id, role_label) VALUES (?,?,?,?)",
                    [current_tenant_id(), $id, $uid, trim((string)($_POST['role_label'] ?? '')) ?: null]);
                AuditLog::record('clientdesk.member_assigned', "project#$id", ['user_id'=>$uid]);
                $flash = ['type'=>'success','msg'=>__('cd_assigned','Team member assigned.')];
            }
        } elseif ($action === 'unassign' && Auth::can('clientdesk.manage_team')) {
            Database::delete('clientdesk_assignments', 'project_id=? AND user_id=? AND tenant_id=?', [$id, (int)($_POST['user_id'] ?? 0), current_tenant_id()]);
            $flash = ['type'=>'success','msg'=>__('cd_unassigned','Removed.')];
        } elseif ($action === 'save_intake' && $canManage) {
            $answers = [];
            foreach (ClientDeskAPI::intakeFields() as $key => $def) $answers[$key] = trim((string)($_POST['intake'][$key] ?? ''));
            ClientDeskAPI::saveIntake($id, $answers, true);
            $flash = ['type'=>'success','msg'=>__('cd_intake_saved','Questionnaire saved.')];
        } elseif ($action === 'upload_file' && $canManage) {
            $res = Uploads::handle('file', 'clientdesk/' . $id, ClientDeskAPI::uploadOpts());
            if ($res['ok']) {
                Database::insert('clientdesk_files', [
                    'tenant_id'=>current_tenant_id(),'project_id'=>$id,
                    'label'=>trim((string)($_POST['label'] ?? '')) ?: null,
                    'path'=>$res['path'],'original'=>mb_substr($res['original'],0,190),
                    'mime'=>$res['mime'],'size_bytes'=>$res['size'],
                    'kind'=>($_POST['kind'] ?? 'asset') === 'deliverable' ? 'deliverable' : 'asset',
                    'uploaded_by'=>'staff',
                    'visible_to_client'=>isset($_POST['visible']) ? 1 : 0,
                ]);
                $flash = ['type'=>'success','msg'=>__('cd_file_uploaded','File uploaded.')];
            } else {
                $flash = ['type'=>'error','msg'=>e($res['error'])];
            }
        } elseif ($action === 'delete_file' && $canManage) {
            $f = ClientDeskAPI::file((int)($_POST['file_id'] ?? 0));
            if ($f) { Uploads::remove($f['path']); Database::delete('clientdesk_files', 'id=? AND tenant_id=?', [$f['id'], current_tenant_id()]); $flash = ['type'=>'success','msg'=>__('cd_file_deleted','File removed.')]; }
        }
    }
}

$milestones  = ClientDeskAPI::milestones($id);
$activity    = ClientDeskAPI::activity($id);
$assignments = ClientDeskAPI::assignments($id);
$intake      = ClientDeskAPI::intake($id);
$intakeAns   = $intake['answers_decoded'] ?? [];
$assignedIds = array_map(fn($a) => (int)$a['user_id'], $assignments);
$allUsers    = Database::rows("SELECT id, name FROM users WHERE tenant_id = ? AND status = 'active' ORDER BY name", [current_tenant_id()]);
$files       = ClientDeskAPI::files($id);
$comments    = ClientDeskAPI::comments($id);
$templates   = ClientDeskAPI::templates();
$doneCount   = count(array_filter($milestones, fn($m) => $m['done']));

require SLATE_ROOT . '/admin/partials/header.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_clients', 'Clients'), 'href' => plugin_url('clientdesk', 'admin/index.php')],
    ['label' => $client['name'] ?? '—', 'href' => plugin_url('clientdesk', 'admin/client.php') . '?id=' . (int)$project['client_id']],
    ['label' => $project['title']],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e($project['title']) ?></h1>
        <p class="page-header-sub"><?= e($client['name'] ?? '') ?> · <?= e(ClientDeskAPI::phaseLabel($project['phase'])) ?> · <?= (int)$project['progress'] ?>%</p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= $flash['msg'] ?></div>
<?php endif; ?>

<div class="card cd-fade">
    <?= cd_phase_stepper($project['phase'], ClientDeskAPI::phases()) ?>
</div>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">

        <div class="cd-tabs" role="tablist">
            <button class="cd-tab is-active" data-tab="overview"><?= __('cd_tab_overview', 'Overview') ?></button>
            <button class="cd-tab" data-tab="files"><?= __('cd_tab_files', 'Files') ?> <span class="text-muted">(<?= count($files) ?>)</span></button>
            <button class="cd-tab" data-tab="comments"><?= __('cd_tab_comments', 'Comments') ?> <span class="text-muted">(<?= count($comments) ?>)</span></button>
        </div>

        <!-- OVERVIEW -->
        <div class="cd-panel is-active" data-panel="overview">
            <?php if ($canManage): ?>
            <div class="card">
                <div class="card-header"><h2><?= __('cd_progress', 'Progress') ?></h2><?= cd_progress_ring((int)$project['progress'], 56) ?></div>
                <form method="post">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="update_progress">
                    <div class="field-row field-row-2">
                        <div class="field">
                            <label class="field-label" for="phase"><?= __('cd_phase', 'Phase') ?></label>
                            <select id="phase" name="phase">
                                <?php foreach (ClientDeskAPI::phases() as $k => $label): ?>
                                    <option value="<?= e($k) ?>" <?= $project['phase'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label" for="progress"><?= __('cd_percent', 'Progress %') ?></label>
                            <input type="number" id="progress" name="progress" min="0" max="100" value="<?= (int)$project['progress'] ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="staging_url"><?= __('cd_staging', 'Staging / preview URL') ?></label>
                        <input type="url" id="staging_url" name="staging_url" value="<?= e($project['staging_url'] ?? '') ?>" placeholder="https://staging.example.com">
                    </div>
                    <div class="field">
                        <label class="field-label" for="agreement"><?= __('cd_agreement', 'Agreement copy') ?></label>
                        <textarea id="agreement" name="agreement" rows="3"><?= e($project['agreement'] ?? '') ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= __('cd_save', 'Save') ?></button>
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h2><?= __('cd_milestones', 'Milestones') ?> <span class="text-muted text-sm"><?= $doneCount ?>/<?= count($milestones) ?></span></h2></div>
                <?php if ($milestones): ?>
                    <?= cd_progress_bar(count($milestones) ? (int)round($doneCount / count($milestones) * 100) : 0) ?>
                    <ul class="kv-list mt-3 mb-3">
                        <?php foreach ($milestones as $m): ?>
                            <li class="kv-row">
                                <span class="kv-label"><?= $m['done'] ? '✓ ' : '○ ' ?><?= e($m['label']) ?></span>
                                <span class="kv-value">
                                    <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                                        <input type="hidden" name="_action" value="toggle_milestone">
                                        <input type="hidden" name="milestone_id" value="<?= (int)$m['id'] ?>">
                                        <button class="btn btn-sm btn-ghost"><?= $m['done'] ? __('cd_undo','Undo') : __('cd_done','Done') ?></button>
                                    </form>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form method="post" class="flex gap-2 items-center mb-2"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="add_milestone">
                    <input type="text" name="label" maxlength="190" placeholder="<?= e(__('cd_milestone_ph','New milestone')) ?>" style="flex:1">
                    <button class="btn"><?= __('cd_add','Add') ?></button>
                </form>
                <?php if ($templates): ?>
                <form method="post" class="flex gap-2 items-center"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="apply_template">
                    <select name="template_id"><?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?></select>
                    <button class="btn btn-sm"><?= __('cd_apply_template','Apply template') ?></button>
                </form>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header"><h2><?= __('cd_questionnaire', 'Requirements form (onboarding questionnaire)') ?></h2>
                    <?php if (!empty($intake['submitted_at'])): ?><span class="badge badge-active"><?= __('cd_submitted','Submitted') ?></span><?php endif; ?></div>
                <p class="text-sm text-muted" style="margin-top:-6px;margin-bottom:12px"><?= __('cd_req_help', 'This is the requirements form. Your client fills it from their portal, or you can complete it here. It feeds the requirements brief attached to invoices.') ?></p>
                <form method="post">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="save_intake">
                    <?php foreach (ClientDeskAPI::intakeFields() as $key => $def): ?>
                        <div class="field">
                            <label class="field-label" for="intake_<?= e($key) ?>"><?= e($def['label']) ?></label>
                            <?php if ($def['type'] === 'textarea'): ?>
                                <textarea id="intake_<?= e($key) ?>" name="intake[<?= e($key) ?>]" rows="2"><?= e($intakeAns[$key] ?? '') ?></textarea>
                            <?php else: ?>
                                <input type="text" id="intake_<?= e($key) ?>" name="intake[<?= e($key) ?>]" value="<?= e($intakeAns[$key] ?? '') ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary"><?= __('cd_save_brief', 'Save brief') ?></button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h2><?= __('cd_activity', 'Activity') ?></h2></div>
                <?php if ($canManage): ?>
                <form method="post" class="flex gap-2 items-center mb-3"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="post_activity">
                    <input type="text" name="body" maxlength="500" placeholder="<?= e(__('cd_activity_ph','Post an update the client will see…')) ?>" style="flex:1">
                    <button class="btn"><?= __('cd_post','Post') ?></button>
                </form>
                <?php endif; ?>
                <?php if ($activity): ?>
                    <ol class="audit-trail">
                        <?php foreach ($activity as $i => $a): ?>
                            <li class="audit-trail-item <?= $i > 0 ? 'is-muted' : '' ?>">
                                <div class="audit-trail-action"><?= e($a['body']) ?></div>
                                <div class="audit-trail-meta"><?= e($a['author'] ?? '—') ?> · <?= e(date('j M Y H:i', strtotime($a['created_at']))) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?><p class="text-muted text-sm"><?= __('cd_no_activity','No updates yet.') ?></p><?php endif; ?>
            </div>
        </div>

        <!-- FILES -->
        <div class="cd-panel" data-panel="files">
            <?php if ($canManage): ?>
            <div class="card">
                <div class="card-header"><h2><?= __('cd_upload', 'Upload file') ?></h2></div>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="upload_file">
                    <div class="field-row field-row-2">
                        <div class="field">
                            <label class="field-label" for="file"><?= __('cd_file', 'File') ?></label>
                            <input type="file" id="file" name="file" required>
                        </div>
                        <div class="field">
                            <label class="field-label" for="label"><?= __('cd_label', 'Label (optional)') ?></label>
                            <input type="text" id="label" name="label" maxlength="190">
                        </div>
                    </div>
                    <div class="field-row field-row-2">
                        <div class="field">
                            <label class="field-label" for="kind"><?= __('cd_kind', 'Kind') ?></label>
                            <select id="kind" name="kind">
                                <option value="asset"><?= __('cd_asset', 'Client asset (logo, content)') ?></option>
                                <option value="deliverable"><?= __('cd_deliverable', 'Deliverable (final file)') ?></option>
                            </select>
                        </div>
                        <div class="field" style="display:flex;align-items:flex-end">
                            <label class="field-label" style="display:flex;align-items:center;gap:8px">
                                <input type="checkbox" name="visible" checked> <?= __('cd_visible_client', 'Visible to client') ?>
                            </label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= __('cd_upload', 'Upload') ?></button>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header"><h2><?= __('cd_files', 'Files') ?></h2></div>
                <?php if (empty($files)): ?>
                    <div class="empty"><div class="empty-title"><?= __('cd_no_files', 'No files yet') ?></div></div>
                <?php else: ?>
                    <div class="cd-files">
                        <?php foreach ($files as $f): ?>
                            <div class="cd-file">
                                <a href="<?= e(SLATE_URL . $f['path']) ?>" target="_blank" rel="noopener" class="cd-file-thumb">
                                    <?php if (ClientDeskAPI::isImage($f['mime'])): ?>
                                        <img src="<?= e(SLATE_URL . $f['path']) ?>" alt="">
                                    <?php else: ?>
                                        <span class="text-muted text-sm"><?= e(strtoupper(pathinfo($f['original'] ?? '', PATHINFO_EXTENSION) ?: 'FILE')) ?></span>
                                    <?php endif; ?>
                                </a>
                                <span class="cd-file-name"><?= e($f['label'] ?: $f['original']) ?></span>
                                <span class="cd-file-meta">
                                    <?= e(ucfirst($f['kind'])) ?> · <?= e(cd_human_size((int)$f['size_bytes'])) ?>
                                    <?= $f['visible_to_client'] ? '' : ' · ' . __('cd_internal', 'internal') ?>
                                </span>
                                <?php if ($canManage): ?>
                                <form method="post" onsubmit="return confirm(<?= e(json_encode(__('cd_confirm_del_file','Delete this file?'))) ?>);">
                                    <?= csrf_field() ?><?= submit_token_field() ?><input type="hidden" name="_action" value="delete_file"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                                    <button class="btn btn-sm btn-ghost btn-block"><?= __('cd_delete','Delete') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- COMMENTS -->
        <div class="cd-panel" data-panel="comments">
            <div class="card">
                <div class="card-header"><h2><?= __('cd_comments', 'Comments') ?></h2></div>
                <?php if ($comments): foreach ($comments as $cm): ?>
                    <div class="cd-msg cd-msg-<?= $cm['author_type'] === 'staff' ? 'staff' : 'client' ?>">
                        <div class="cd-msg-head"><?= e($cm['author_name'] ?? ucfirst($cm['author_type'])) ?> · <?= e(date('j M H:i', strtotime($cm['created_at']))) ?></div>
                        <div class="cd-msg-body"><?= e($cm['body']) ?></div>
                    </div>
                <?php endforeach; else: ?>
                    <p class="text-muted text-sm"><?= __('cd_no_comments', 'No comments yet. Start the conversation.') ?></p>
                <?php endif; ?>
                <?php if ($canManage): ?>
                <form method="post" class="mt-3"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="comment">
                    <div class="field"><textarea name="body" rows="2" placeholder="<?= e(__('cd_comment_ph','Write a comment…')) ?>" required></textarea></div>
                    <button class="btn btn-primary"><?= __('cd_post_comment','Post comment') ?></button>
                </form>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_team', 'Team') ?></div>
            <?php if ($assignments): ?>
                <ul class="kv-list mb-2">
                    <?php foreach ($assignments as $a): ?>
                        <li class="kv-row">
                            <span class="kv-label"><?= e($a['user_name']) ?><?= $a['role_label'] ? ' · ' . e($a['role_label']) : '' ?></span>
                            <span class="kv-value">
                                <?php if (Auth::can('clientdesk.manage_team')): ?>
                                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                                    <input type="hidden" name="_action" value="unassign"><input type="hidden" name="user_id" value="<?= (int)$a['user_id'] ?>">
                                    <button class="btn btn-sm btn-ghost">×</button></form>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?><p class="text-muted text-sm mb-2"><?= __('cd_no_team','No one assigned.') ?></p><?php endif; ?>
            <?php if (Auth::can('clientdesk.manage_team')): ?>
            <form method="post"><?= csrf_field() ?><?= submit_token_field() ?>
                <input type="hidden" name="_action" value="assign">
                <div class="field">
                    <label class="field-label" for="user_id"><?= __('cd_assign_member','Assign member') ?></label>
                    <select id="user_id" name="user_id">
                        <?php foreach ($allUsers as $u): if (in_array((int)$u['id'], $assignedIds, true)) continue; ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="role_label"><?= __('cd_role_label','Role (optional)') ?></label>
                    <input type="text" id="role_label" name="role_label" maxlength="80" placeholder="Designer, Developer…">
                </div>
                <button class="btn btn-block"><?= __('cd_assign','Assign') ?></button>
            </form>
            <?php endif; ?>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_at_a_glance', 'At a glance') ?></div>
            <ul class="kv-list">
                <li class="kv-row"><span class="kv-label"><?= __('cd_files','Files') ?></span><span class="kv-value"><?= count($files) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= __('cd_comments','Comments') ?></span><span class="kv-value"><?= count($comments) ?></span></li>
                <li class="kv-row"><span class="kv-label"><?= __('cd_deadline','Deadline') ?></span><span class="kv-value"><?= $project['deadline'] ? e(date('j M Y', strtotime($project['deadline']))) : '—' ?></span></li>
            </ul>
        </div>
    </aside>

<?php slate_page_layout_end(); ?>

<script>
(function(){
  var tabs = document.querySelectorAll('.cd-tab');
  var panels = document.querySelectorAll('.cd-panel');
  tabs.forEach(function(t){
    t.addEventListener('click', function(){
      tabs.forEach(x=>x.classList.remove('is-active'));
      panels.forEach(p=>p.classList.remove('is-active'));
      t.classList.add('is-active');
      var target = document.querySelector('.cd-panel[data-panel="'+t.dataset.tab+'"]');
      if(target) target.classList.add('is-active');
    });
  });
})();
</script>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
