<?php
/**
 * Client Desk — customer dashboard (v2).
 * Progress with rings + stepper, onboarding questionnaire, quotes to
 * approve, invoices (with pay-by-card), deliverable downloads, asset
 * uploads, project comments, and support tickets.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::requireCustomer();

require_once SLATE_ROOT . '/includes/portal_shell.php';
require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';

$cust   = Auth::customer();
$client = ClientDeskAPI::clientForCustomer((int)$cust['id'], $cust['email'] ?? null);

$pageTitle           = __('cd_my_portal', 'My Portal');
$currentPortalNav = 'clientdesk';   // highlights the shared portal tab
$flash = null;

if (!$client) {
    slate_portal_shell_head(__('cd_my_portal', 'My Portal')); slate_portal_shell_open();
    cd_styles();
    echo '<div class="page-header"><div><h1>' . __('cd_my_portal','My Portal') . '</h1></div></div>';
    echo '<div class="card"><div class="empty"><div class="empty-title">' . __('cd_no_client','No project account is linked to you yet.') . '</div></div></div>';
    slate_portal_shell_close();
    exit;
}
$clientId = (int)$client['id'];

// Stripe return banners
if (($_GET['paid'] ?? '') === '1')      $flash = ['type'=>'success','msg'=>__('cd_payment_ok','Payment received — thank you!')];
if (($_GET['cancelled'] ?? '') === '1') $flash = ['type'=>'info','msg'=>__('cd_payment_cancel','Payment cancelled. You can try again anytime.')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>__('cd_csrf','Security check failed.')];
    } elseif (!submit_token_consume()) {
        $flash = ['type'=>'warning','msg'=>__('cd_dup_submit','That looks like a duplicate submission — nothing was changed.')];
    } else {
        $action = $_POST['_action'] ?? '';

        if ($action === 'submit_intake') {
            $pid = (int)($_POST['project_id'] ?? 0); $proj = ClientDeskAPI::project($pid);
            if ($proj && (int)$proj['client_id'] === $clientId) {
                $answers = [];
                foreach (ClientDeskAPI::intakeFields() as $key => $def) $answers[$key] = trim((string)($_POST['intake'][$key] ?? ''));
                ClientDeskAPI::saveIntake($pid, $answers, true);
                ClientDeskAPI::logActivity($pid, 'Client submitted the onboarding questionnaire.', $cust['name'] ?? 'Client');
                $flash = ['type'=>'success','msg'=>__('cd_thanks_intake','Thanks — your answers were saved.')];
            }
        } elseif ($action === 'quote_decision') {
            $qid = (int)($_POST['quote_id'] ?? 0); $q = ClientDeskAPI::quote($qid);
            $decision = $_POST['decision'] ?? '';
            if ($q && (int)$q['client_id'] === $clientId && $q['status'] === 'sent' && in_array($decision, ['approved','declined'], true)) {
                Database::update('clientdesk_quotes', ['status'=>$decision,'decided_at'=>slate_db_now()], 'id=? AND tenant_id=?', [$qid, current_tenant_id()]);
                if (class_exists('Notifications')) Notifications::add('Quote ' . $q['number'] . ' ' . $decision . ' by client',
                    ['icon'=>'clipboard-list','url'=>SLATE_URL.'/plugins/clientdesk/admin/quotes.php']);
                $flash = ['type'=>'success','msg'=>$decision==='approved'?__('cd_quote_approved','Quote approved — thank you!'):__('cd_quote_declined','Quote declined.')];
            }
        } elseif ($action === 'pay_invoice') {
            $invId = (int)($_POST['invoice_id'] ?? 0); $inv = ClientDeskAPI::invoice($invId);
            if ($inv && (int)$inv['client_id'] === $clientId && in_array($inv['status'], ['sent','overdue'], true)) {
                $return = SLATE_URL . '/plugins/clientdesk/customer/dashboard.php';
                $url = ClientDeskAPI::invoiceCheckoutUrl($inv, $client, $return);
                if ($url) { header('Location: ' . $url); exit; }
                $flash = ['type'=>'error','msg'=>__('cd_pay_unavailable','Online payment is not available right now.')];
            }
        } elseif ($action === 'new_ticket') {
            $subject = trim((string)($_POST['subject'] ?? '')); $body = trim((string)($_POST['body'] ?? ''));
            if ($subject !== '' && $body !== '') {
                $tid = Database::insert('clientdesk_tickets', [
                    'tenant_id'=>current_tenant_id(),'client_id'=>$clientId,
                    'project_id'=>(int)($_POST['project_id'] ?? 0) ?: null,
                    'subject'=>mb_substr($subject,0,190),
                    'priority'=>in_array($_POST['priority'] ?? 'normal', ['low','normal','high'], true) ? $_POST['priority'] : 'normal',
                ]);
                ClientDeskAPI::addTicketMessage($tid, 'client', $cust['name'] ?? 'Client', $body);
                if (class_exists('Notifications')) Notifications::add('New support ticket: ' . $subject, ['icon'=>'mail','url'=>SLATE_URL.'/plugins/clientdesk/admin/tickets.php?id='.$tid]);
                $flash = ['type'=>'success','msg'=>__('cd_ticket_created','Support ticket created.')];
            } else { $flash = ['type'=>'error','msg'=>__('cd_ticket_incomplete','Subject and message are required.')]; }
        } elseif ($action === 'ticket_reply') {
            $tid = (int)($_POST['ticket_id'] ?? 0); $ticket = ClientDeskAPI::ticket($tid); $body = trim((string)($_POST['body'] ?? ''));
            if ($ticket && (int)$ticket['client_id'] === $clientId && $body !== '') {
                ClientDeskAPI::addTicketMessage($tid, 'client', $cust['name'] ?? 'Client', $body);
                $flash = ['type'=>'success','msg'=>__('cd_reply_sent','Reply posted.')];
            }
        } elseif ($action === 'comment') {
            $pid = (int)($_POST['project_id'] ?? 0); $proj = ClientDeskAPI::project($pid); $body = trim((string)($_POST['body'] ?? ''));
            if ($proj && (int)$proj['client_id'] === $clientId && $body !== '') {
                ClientDeskAPI::addComment($pid, 'client', $cust['name'] ?? 'Client', $body);
                $flash = ['type'=>'success','msg'=>__('cd_comment_added','Comment added.')];
            }
        } elseif ($action === 'client_upload') {
            $pid = (int)($_POST['project_id'] ?? 0); $proj = ClientDeskAPI::project($pid);
            if ($proj && (int)$proj['client_id'] === $clientId) {
                $res = Uploads::handle('file', 'clientdesk/' . $pid, ClientDeskAPI::uploadOpts());
                if ($res['ok']) {
                    Database::insert('clientdesk_files', [
                        'tenant_id'=>current_tenant_id(),'project_id'=>$pid,
                        'label'=>trim((string)($_POST['label'] ?? '')) ?: null,
                        'path'=>$res['path'],'original'=>mb_substr($res['original'],0,190),
                        'mime'=>$res['mime'],'size_bytes'=>$res['size'],
                        'kind'=>'asset','uploaded_by'=>'client','visible_to_client'=>1,
                    ]);
                    ClientDeskAPI::logActivity($pid, 'Client uploaded a file: ' . $res['original'], $cust['name'] ?? 'Client');
                    $flash = ['type'=>'success','msg'=>__('cd_file_uploaded','File uploaded.')];
                } else { $flash = ['type'=>'error','msg'=>e($res['error'])]; }
            }
        }
    }
}

$projects = ClientDeskAPI::projectsForClient($clientId);
$invoices = ClientDeskAPI::invoicesForClient($clientId);
$tickets  = ClientDeskAPI::ticketsForClient($clientId);
$quotes   = array_filter(ClientDeskAPI::quotesForClient($clientId), fn($q) => in_array($q['status'], ['sent','approved','declined'], true));
$canPay   = ClientDeskAPI::stripeReady();


// Summary figures for the hero.
$activeCount   = count(array_filter($projects, fn($p) => $p['phase'] !== 'complete'));
$pendingQuotes = array_filter($quotes, fn($q) => $q['status'] === 'sent');
$visibleInv    = array_filter($invoices, fn($i) => $i['status'] !== 'draft');
$dueInv        = array_filter($visibleInv, fn($i) => in_array($i['status'], ['sent','overdue'], true));
$openTickets   = count(array_filter($tickets, fn($t) => !in_array($t['status'], ['closed'], true)));
$avgProgress   = $projects ? (int) round(array_sum(array_map(fn($p) => (int)$p['progress'], $projects)) / count($projects)) : 0;

slate_portal_shell_head(__('cd_my_portal', 'My Portal')); slate_portal_shell_open();
cd_styles();
?>

<!-- HERO -->
<div class="cdp-hero cd-fade">
    <h1><?= __('cd_welcome', 'Welcome back') ?>, <?= e($client['name']) ?></h1>
    <p><?= $client['company'] ? e($client['company']) . ' · ' : '' ?><?= __('cd_hero_sub', 'Here is everything happening with your projects.') ?></p>
    <div class="cdp-hero-meta">
        <div class="cdp-hero-stat"><b><?= $activeCount ?></b><span><?= __('cd_active', 'Active') ?></span></div>
        <div class="cdp-hero-stat"><b><?= $avgProgress ?>%</b><span><?= __('cd_avg_progress', 'Avg progress') ?></span></div>
        <?php if (count($pendingQuotes)): ?><div class="cdp-hero-stat"><b><?= count($pendingQuotes) ?></b><span><?= __('cd_quotes', 'Quotes') ?></span></div><?php endif; ?>
        <?php if (count($dueInv)): ?><div class="cdp-hero-stat"><b><?= count($dueInv) ?></b><span><?= __('cd_due', 'Due') ?></span></div><?php endif; ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> cd-fade" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<!-- SECTION NAV -->
<nav class="cdp-nav">
    <?php if (count($pendingQuotes)): ?><a href="#quotes"><?= __('cd_quotes','Quotes') ?> <span class="cdp-nav-count"><?= count($pendingQuotes) ?></span></a><?php endif; ?>
    <a href="#projects"><?= __('cd_projects','Projects') ?> <span class="cdp-nav-count"><?= count($projects) ?></span></a>
    <a href="#invoices"><?= __('cd_invoices','Invoices') ?> <span class="cdp-nav-count"><?= count($visibleInv) ?></span></a>
    <a href="#support"><?= __('cd_support','Support') ?> <span class="cdp-nav-count"><?= $openTickets ?></span></a>
</nav>

<!-- QUOTES TO APPROVE -->
<?php if ($pendingQuotes): ?>
    <div id="quotes" class="cdp-anchor"></div>
    <div class="cdp-section-title"><?= __('cd_quotes_to_review', 'Quotes to review') ?></div>
    <?php foreach ($pendingQuotes as $qrow): $q = ClientDeskAPI::quote((int)$qrow['id']); ?>
        <div class="card cdp-quote cd-fade">
            <div class="card-header">
                <h2 style="font-size:1.05rem;min-width:0;overflow-wrap:anywhere"><?= e($q['number']) ?> · <?= e($q['title']) ?></h2>
                <span class="badge badge-warning"><?= __('cd_awaiting','Awaiting approval') ?></span>
            </div>
            <?php if (!empty($q['body'])): ?><p class="text-sm" style="white-space:pre-wrap"><?= e($q['body']) ?></p><?php endif; ?>
            <div class="mt-2">
                <?php foreach ($q['line_items_decoded'] as $li): ?>
                    <div class="cdp-line">
                        <span class="cdp-line-label"><?= e($li['desc']) ?></span>
                        <span class="cdp-line-value kv-mono"><?= e(ClientDeskAPI::formatMoney((int)$li['amount_cents'], $q['currency'])) ?></span>
                    </div>
                <?php endforeach; ?>
                <div class="cdp-line">
                    <span class="cdp-line-label"><strong><?= __('cd_total','Total') ?></strong></span>
                    <span class="cdp-line-value kv-mono"><strong><?= e(ClientDeskAPI::formatMoney((int)$q['total_cents'], $q['currency'])) ?></strong></span>
                </div>
            </div>
            <?php if ($q['valid_until']): ?><p class="field-hint mt-2"><?= __('cd_valid_until','Valid until') ?> <?= e(date('j M Y', strtotime($q['valid_until']))) ?></p><?php endif; ?>
            <div class="cd-quote-actions">
                <form method="post" style="margin:0"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="quote_decision"><input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="decision" value="approved">
                    <button class="btn btn-primary"><?= __('cd_approve','Approve') ?></button></form>
                <form method="post" style="margin:0" onsubmit="return confirm(<?= e(json_encode(__('cd_confirm_decline','Decline this quote?'))) ?>);"><?= csrf_field() ?><?= submit_token_field() ?>
                    <input type="hidden" name="_action" value="quote_decision"><input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>"><input type="hidden" name="decision" value="declined">
                    <button class="btn btn-danger"><?= __('cd_decline','Decline') ?></button></form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- PROJECTS -->
<div id="projects" class="cdp-anchor"></div>
<div class="cdp-section-title"><?= __('cd_your_projects','Your projects') ?></div>
<?php if (empty($projects)): ?>
    <div class="card"><div class="cdp-empty">
        <div class="cdp-empty-icon">✦</div>
        <div class="empty-title"><?= __('cd_no_projects_yet','No projects yet') ?></div>
        <p class="text-muted text-sm"><?= __('cd_no_projects_client','Your project will appear here once it kicks off.') ?></p>
    </div></div>
<?php else: foreach ($projects as $p):
    $ms = ClientDeskAPI::milestones((int)$p['id']);
    $acts = ClientDeskAPI::activity((int)$p['id'], 6);
    $intake = ClientDeskAPI::intake((int)$p['id']);
    $intakeAns = $intake['answers_decoded'] ?? [];
    $intakeDone = !empty($intake['submitted_at']);
    $deliverables = array_filter(ClientDeskAPI::files((int)$p['id'], true), fn($f) => $f['kind'] === 'deliverable');
    $pComments = ClientDeskAPI::comments((int)$p['id']);
    $pct = max(0, min(100, (int)$p['progress']));
?>
    <div class="card cdp-project cd-fade">
        <div class="cdp-project-head">
            <div style="min-width:0">
                <h2><?= e($p['title']) ?></h2>
                <p class="text-sm text-muted mt-1"><?= e(ClientDeskAPI::phaseLabel($p['phase'])) ?><?php if ($p['deadline']): ?> · <?= __('cd_due','Due') ?> <?= e(date('j M Y', strtotime($p['deadline']))) ?><?php endif; ?></p>
            </div>
            <?= cd_progress_ring($pct, 56) ?>
        </div>

        <div class="mt-3"><?= cd_phase_stepper($p['phase'], ClientDeskAPI::phases()) ?></div>

        <?php if (!empty($p['staging_url'])): ?>
            <p class="mt-3"><a href="<?= e($p['staging_url']) ?>" target="_blank" rel="noopener" class="btn btn-sm"><?= __('cd_view_preview','View live preview') ?> →</a></p>
        <?php endif; ?>

        <?php if ($ms): ?>
            <div class="section-divider"></div>
            <div class="aside-card-title"><?= __('cd_milestones','Milestones') ?></div>
            <?php foreach ($ms as $m): ?>
                <div class="cdp-line">
                    <span class="cdp-line-label"><?= $m['done'] ? '✓' : '○' ?> <?= e($m['label']) ?></span>
                    <span class="cdp-line-value text-sm text-muted"><?= $m['done'] && $m['done_at'] ? e(date('j M', strtotime($m['done_at']))) : '' ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($deliverables): ?>
            <div class="section-divider"></div>
            <div class="aside-card-title"><?= __('cd_deliverables','Deliverables') ?></div>
            <div class="cd-files">
                <?php foreach ($deliverables as $f): ?>
                    <a class="cd-file" href="<?= e(SLATE_URL . $f['path']) ?>" target="_blank" rel="noopener">
                        <span class="cd-file-thumb">
                            <?php if (ClientDeskAPI::isImage($f['mime'])): ?><img src="<?= e(SLATE_URL . $f['path']) ?>" alt="">
                            <?php else: ?><span class="text-muted text-sm"><?= e(strtoupper(pathinfo($f['original'] ?? '', PATHINFO_EXTENSION) ?: 'FILE')) ?></span><?php endif; ?>
                        </span>
                        <span class="cd-file-name"><?= e($f['label'] ?: $f['original']) ?></span>
                        <span class="cd-file-meta"><?= e(cd_human_size((int)$f['size_bytes'])) ?> · <?= __('cd_download','Download') ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($acts): ?>
            <div class="section-divider"></div>
            <div class="aside-card-title"><?= __('cd_recent_updates','Recent updates') ?></div>
            <ol class="audit-trail">
                <?php foreach ($acts as $i => $a): ?>
                    <li class="audit-trail-item <?= $i > 0 ? 'is-muted' : '' ?>">
                        <div class="audit-trail-action"><?= e($a['body']) ?></div>
                        <div class="audit-trail-meta"><?= e(date('j M Y H:i', strtotime($a['created_at']))) ?></div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <!-- share files -->
        <div class="section-divider"></div>
        <div class="aside-card-title"><?= __('cd_share_files','Share files (logo, content, photos)') ?></div>
        <form method="post" enctype="multipart/form-data" class="flex gap-2 items-center" style="flex-wrap:wrap">
            <?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="client_upload">
            <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
            <input type="file" name="file" required style="flex:1 1 180px;min-width:0">
            <button class="btn btn-sm"><?= __('cd_upload','Upload') ?></button>
        </form>

        <!-- requirements form (collapsible) -->
        <div class="section-divider"></div>
        <details <?= $intakeDone ? '' : 'open' ?>>
            <summary style="cursor:pointer;font:600 13px var(--font-sans);letter-spacing:.05em;text-transform:uppercase;color:var(--muted)">
                <?= __('cd_questionnaire','Requirements form') ?> <?= $intakeDone ? '· ' . __('cd_submitted','Submitted ✓') : '' ?>
            </summary>
            <p class="text-sm text-muted" style="margin:10px 0"><?= __('cd_req_help_client','Tell us what you need — goals, references, logo, domain, hosting, pages and more. The more detail, the better.') ?></p>
            <form method="post">
                <?= csrf_field() ?><?= submit_token_field() ?>
                <input type="hidden" name="_action" value="submit_intake">
                <input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
                <?php foreach (ClientDeskAPI::intakeFields() as $key => $def): ?>
                    <div class="field">
                        <label class="field-label" for="i_<?= (int)$p['id'] ?>_<?= e($key) ?>"><?= e($def['label']) ?></label>
                        <?php if ($def['type'] === 'textarea'): ?>
                            <textarea id="i_<?= (int)$p['id'] ?>_<?= e($key) ?>" name="intake[<?= e($key) ?>]" rows="2"><?= e($intakeAns[$key] ?? '') ?></textarea>
                        <?php else: ?>
                            <input type="text" id="i_<?= (int)$p['id'] ?>_<?= e($key) ?>" name="intake[<?= e($key) ?>]" value="<?= e($intakeAns[$key] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary"><?= $intakeDone ? __('cd_update_answers','Update answers') : __('cd_submit_answers','Submit answers') ?></button>
            </form>
        </details>

        <!-- comments -->
        <div class="section-divider"></div>
        <div class="aside-card-title"><?= __('cd_comments','Comments') ?></div>
        <?php foreach ($pComments as $cm): ?>
            <div class="cd-msg cd-msg-<?= $cm['author_type'] === 'staff' ? 'staff' : 'client' ?>">
                <div class="cd-msg-head"><?= e($cm['author_name'] ?? ucfirst($cm['author_type'])) ?> · <?= e(date('j M H:i', strtotime($cm['created_at']))) ?></div>
                <div class="cd-msg-body"><?= e($cm['body']) ?></div>
            </div>
        <?php endforeach; ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="comment"><input type="hidden" name="project_id" value="<?= (int)$p['id'] ?>">
            <div class="field"><textarea name="body" rows="2" placeholder="<?= e(__('cd_comment_ph','Write a comment…')) ?>" required></textarea></div>
            <button class="btn btn-sm"><?= __('cd_post_comment','Post comment') ?></button>
        </form>
    </div>
<?php endforeach; endif; ?>

<!-- INVOICES -->
<div id="invoices" class="cdp-anchor"></div>
<div class="cdp-section-title"><?= __('cd_invoices','Invoices') ?></div>
<div class="card">
    <?php if (empty($visibleInv)): ?>
        <p class="text-muted text-sm"><?= __('cd_no_invoices','No invoices yet.') ?></p>
    <?php else: ?>
        <?php foreach ($visibleInv as $inv):
            $isPaid = $inv['status'] === 'paid'; ?>
            <div class="cdp-line">
                <span class="cdp-line-label">
                    <strong><?= e($inv['number']) ?></strong>
                    <span class="badge badge-<?= $isPaid ? 'active' : 'warning' ?>"><?= e(ucfirst($inv['status'])) ?></span>
                </span>
                <span class="cdp-line-value">
                    <span class="kv-mono"><?= e(ClientDeskAPI::formatMoney((int)$inv['total_cents'], $inv['currency'])) ?></span>
                    <a href="<?= e(SLATE_URL) ?>/plugins/clientdesk/customer/invoice.php?id=<?= (int)$inv['id'] ?>" class="btn btn-sm" style="margin-left:8px"><?= __('cd_view','View') ?></a>
                    <?php if (!$isPaid && $canPay): ?>
                        <form method="post" style="display:inline;margin-left:8px"><?= csrf_field() ?><?= submit_token_field() ?>
                            <input type="hidden" name="_action" value="pay_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                            <button class="btn btn-sm btn-primary"><?= __('cd_pay_now','Pay now') ?></button></form>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        <?php if (!$canPay): ?><p class="field-hint mt-2"><?= __('cd_pay_contact','Contact us for payment instructions.') ?></p><?php endif; ?>
    <?php endif; ?>
</div>

<!-- SUPPORT -->
<div id="support" class="cdp-anchor"></div>
<div class="cdp-section-title"><?= __('cd_support','Support') ?></div>
<div class="card">
    <div class="aside-card-title"><?= __('cd_new_ticket','New ticket') ?></div>
    <form method="post">
        <?= csrf_field() ?><?= submit_token_field() ?>
        <input type="hidden" name="_action" value="new_ticket">
        <div class="field-row field-row-2">
            <div class="field"><label class="field-label" for="subject"><?= __('cd_subject','Subject') ?></label>
                <input type="text" id="subject" name="subject" maxlength="190" required></div>
            <div class="field"><label class="field-label" for="priority"><?= __('cd_priority','Priority') ?></label>
                <select id="priority" name="priority"><option value="low"><?= __('cd_low','Low') ?></option><option value="normal" selected><?= __('cd_normal','Normal') ?></option><option value="high"><?= __('cd_high','High') ?></option></select></div>
        </div>
        <div class="field"><label class="field-label" for="tbody"><?= __('cd_message','Message') ?></label>
            <textarea id="tbody" name="body" rows="3" required></textarea></div>
        <button type="submit" class="btn btn-primary"><?= __('cd_create_ticket','Create ticket') ?></button>
    </form>
</div>

<?php foreach ($tickets as $t): $msgs = ClientDeskAPI::ticketMessages((int)$t['id']);
    $tc = ['open'=>'warning','in_progress'=>'accent','resolved'=>'active','closed'=>'inactive'][$t['status']] ?? 'inactive'; ?>
    <div class="card">
        <div class="card-header"><h2 style="font-size:1rem;min-width:0;overflow-wrap:anywhere"><?= e($t['subject']) ?></h2>
            <span class="badge badge-<?= $tc ?>"><?= e(ucfirst(str_replace('_',' ',$t['status']))) ?></span></div>
        <?php foreach ($msgs as $m): ?>
            <div class="cd-msg cd-msg-<?= $m['author_type'] === 'staff' ? 'staff' : 'client' ?>">
                <div class="cd-msg-head"><?= e($m['author_name'] ?? ucfirst($m['author_type'])) ?> · <?= e(date('j M H:i', strtotime($m['created_at']))) ?></div>
                <div class="cd-msg-body"><?= e($m['body']) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if ($t['status'] !== 'closed'): ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><?= submit_token_field() ?>
            <input type="hidden" name="_action" value="ticket_reply"><input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
            <div class="field"><textarea name="body" rows="2" placeholder="<?= e(__('cd_reply_ph','Reply…')) ?>" required></textarea></div>
            <button class="btn btn-sm"><?= __('cd_reply','Reply') ?></button>
        </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<?php slate_portal_shell_close(); ?>
