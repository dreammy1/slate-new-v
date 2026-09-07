<?php
/**
 * Client Desk — admin: analytics overview.
 * KPI tiles, a 6-month revenue chart, a project-phase breakdown, and
 * shortcuts to things needing attention.
 */

require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('clientdesk.view');

require_once dirname(__DIR__) . '/ClientDeskAPI.php';
require_once dirname(__DIR__) . '/includes/ui.php';

$pageTitle  = __('cd_overview', 'Overview');
$currentNav = 'clientdesk-overview';

$kpis      = ClientDeskAPI::kpis();
$revenue   = ClientDeskAPI::revenueByMonth(6);
$breakdown = ClientDeskAPI::phaseBreakdown();

$attention = Database::rows(
    "SELECT i.id, i.number, i.total_cents, i.currency, c.name AS client_name
       FROM clientdesk_invoices i JOIN clientdesk_clients c ON c.id = i.client_id
      WHERE i.tenant_id = ? AND i.status = 'overdue' ORDER BY i.due_date LIMIT 5",
    [current_tenant_id()]);
$pendingQuotes = Database::rows(
    "SELECT q.id, q.number, q.title, c.name AS client_name
       FROM clientdesk_quotes q JOIN clientdesk_clients c ON c.id = q.client_id
      WHERE q.tenant_id = ? AND q.status = 'sent' ORDER BY q.sent_at DESC LIMIT 5",
    [current_tenant_id()]);

require SLATE_ROOT . '/admin/partials/header.php';
cd_styles();
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('cd_overview', 'Overview')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= __('cd_overview', 'Overview') ?></h1>
        <p class="page-header-sub"><?= __('cd_overview_sub', 'Your business at a glance.') ?></p>
    </div>
</div>

<div class="cd-tiles cd-fade">
    <?= cd_stat_tile(__('cd_active_clients', 'Active clients'), (string)$kpis['clients_active'], null, 'var(--accent)') ?>
    <?= cd_stat_tile(__('cd_active_projects', 'Active projects'), (string)$kpis['projects_active'], null, 'var(--info)') ?>
    <?= cd_stat_tile(__('cd_revenue_paid', 'Revenue (paid)'), '$' . number_format($kpis['paid_total'] / 100), null, 'var(--success)') ?>
    <?= cd_stat_tile(__('cd_outstanding', 'Outstanding'), '$' . number_format($kpis['outstanding'] / 100), $kpis['outstanding'] > 0 ? __('cd_awaiting', 'awaiting payment') : null, 'var(--warning)') ?>
    <?= cd_stat_tile(__('cd_open_tickets', 'Open tickets'), (string)$kpis['open_tickets'], null, 'var(--danger)') ?>
    <?= cd_stat_tile(__('cd_pending_quotes', 'Pending quotes'), (string)$kpis['pending_quotes'], null, 'var(--muted)') ?>
</div>

<?php slate_page_layout('with-aside'); ?>

    <div class="page-main">
        <div class="card cd-fade">
            <div class="card-header"><h2><?= __('cd_revenue_6mo', 'Revenue — last 6 months') ?></h2></div>
            <?= cd_bar_chart($revenue, '$') ?>
        </div>

        <div class="card cd-fade">
            <div class="card-header"><h2><?= __('cd_projects_by_phase', 'Projects by phase') ?></h2></div>
            <?php if ($breakdown): ?>
                <?= cd_segments($breakdown) ?>
            <?php else: ?>
                <p class="text-muted text-sm"><?= __('cd_no_projects', 'No projects yet.') ?></p>
            <?php endif; ?>
        </div>
    </div>

    <aside class="page-aside">
        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_needs_attention', 'Needs attention') ?></div>
            <?php if (empty($attention) && empty($pendingQuotes)): ?>
                <p class="text-muted text-sm"><?= __('cd_all_clear', 'All clear. Nothing overdue.') ?></p>
            <?php endif; ?>
            <?php foreach ($attention as $a): ?>
                <a class="card-link card mb-2" style="display:block" href="<?= e(plugin_url('clientdesk','admin/invoices.php')) ?>">
                    <span class="badge badge-danger"><?= __('cd_overdue', 'Overdue') ?></span>
                    <div class="text-sm mt-2"><strong><?= e($a['number']) ?></strong> · <?= e($a['client_name']) ?></div>
                    <div class="text-sm text-muted"><?= e(ClientDeskAPI::formatMoney((int)$a['total_cents'], $a['currency'])) ?></div>
                </a>
            <?php endforeach; ?>
            <?php foreach ($pendingQuotes as $q): ?>
                <a class="card-link card mb-2" style="display:block" href="<?= e(plugin_url('clientdesk','admin/quotes.php')) ?>">
                    <span class="badge badge-warning"><?= __('cd_awaiting_approval', 'Awaiting approval') ?></span>
                    <div class="text-sm mt-2"><strong><?= e($q['number']) ?></strong> · <?= e($q['client_name']) ?></div>
                    <div class="text-sm text-muted"><?= e($q['title']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_quick_actions', 'Quick actions') ?></div>
            <a href="<?= e(plugin_url('clientdesk','admin/index.php')) ?>" class="btn btn-sm btn-block mb-2"><?= __('cd_add_client', '+ Add client') ?></a>
            <a href="<?= e(plugin_url('clientdesk','admin/quotes.php')) ?>" class="btn btn-sm btn-block mb-2"><?= __('cd_new_quote', '+ New quote') ?></a>
            <a href="<?= e(plugin_url('clientdesk','admin/invoices.php')) ?>" class="btn btn-sm btn-block"><?= __('cd_new_invoice', '+ New invoice') ?></a>
        </div>

        <?php if (!ClientDeskAPI::stripeReady()): ?>
        <div class="aside-card">
            <div class="aside-card-title"><?= __('cd_payments', 'Online payments') ?></div>
            <p class="text-sm text-muted"><?= __('cd_stripe_off', 'Install + configure the Stripe Payment plugin to let clients pay invoices by card from their portal.') ?></p>
        </div>
        <?php endif; ?>
    </aside>

<?php slate_page_layout_end(); ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
