<?php
/**
 * Booking+ — Messages inbox (glass UI).
 *
 * Landing page: pending human-message threads that still need a reply,
 * plus a KPI strip and quick links to Services + Settings.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/BookingPlusAPI.php';

Auth::require();
if (!Auth::can('bookingplus.reply_messages')
    && !Auth::can('bookingplus.manage_settings')
    && !Auth::isSuperAdmin()) {
    Auth::requirePerm('bookingplus.reply_messages');
}
BookingPlusAPI::ensureSchema();

$pageTitle  = 'Booking+ Messages';
$currentNav = 'bookingplus-messages';
$tid        = current_tenant_id();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['mark_replied'])) {
    if (csrf_verify()) {
        $mid = (int)$_POST['mark_replied'];
        if ($mid > 0) {
            Database::update('bookingplus_appointment_meta',
                ['therapist_replied_at' => slate_db_now()],   // CORE-1: DB clock
                'id = ? AND tenant_id = ?', [$mid, $tid]);
        }
    }
    header('Location: ' . plugin_url('booking-plus', 'admin/index.php'));
    exit;
}

$pending = Database::rows(
    "SELECT m.*, a.customer_name, a.customer_email, a.customer_phone, a.starts_at, a.ref,
            s.name AS service_name
       FROM bookingplus_appointment_meta m
       JOIN booking_appointments a ON a.id = m.appointment_id
       JOIN booking_services    s ON s.id = a.service_id
      WHERE m.tenant_id = ?
        AND m.client_message IS NOT NULL
        AND m.therapist_replied_at IS NULL
      ORDER BY m.client_message_at DESC
      LIMIT 100",
    [$tid]
);

$totalMsgs    = (int) Database::value("SELECT COUNT(*) FROM bookingplus_appointment_meta WHERE tenant_id = ? AND client_message IS NOT NULL", [$tid]);
$totalReplied = (int) Database::value("SELECT COUNT(*) FROM bookingplus_appointment_meta WHERE tenant_id = ? AND therapist_replied_at IS NOT NULL", [$tid]);

require SLATE_ROOT . '/admin/partials/header.php';

slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Booking+'],
]);
?>

<div class="page-header">
    <div>
        <h1>Booking+ Messages</h1>
        <p class="text-muted">Human-message threads routed from booking. Reply from your email, then click "Mark replied" here.</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(plugin_url('booking-plus', 'admin/services.php')) ?>" class="btn btn-ghost">Services</a>
        <a href="<?= e(plugin_url('booking-plus', 'admin/settings.php')) ?>" class="btn btn-ghost">Settings</a>
    </div>
</div>

<div class="kpi-strip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3);margin-bottom:var(--space-4);">
    <div class="card kpi-card"><div class="kpi-label">Waiting for reply</div><div class="kpi-value"><?= count($pending) ?></div></div>
    <div class="card kpi-card"><div class="kpi-label">Messages received</div><div class="kpi-value"><?= $totalMsgs ?></div></div>
    <div class="card kpi-card"><div class="kpi-label">Replied</div><div class="kpi-value"><?= $totalReplied ?></div></div>
</div>

<?php if (!$pending): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">You're caught up</div>
            <p class="text-sm">No client messages are waiting for a reply.</p>
        </div>
    </div>
<?php else: ?>
    <div class="data-list" data-single-open>
    <?php foreach ($pending as $m):
        $waitedH  = $m['client_message_at']
            ? (int) floor((time() - strtotime($m['client_message_at'])) / 3600)
            : null;
        $overdue  = $waitedH !== null && $waitedH >= BookingPlusAPI::globalNudgeHours();
        $badge    = $overdue ? ['Overdue · ' . $waitedH . 'h', 'inactive'] : ['New', 'active'];

        $detail = [
            'Service'   => (string)$m['service_name'],
            'Session'   => date('l, j F Y · H:i', strtotime($m['starts_at'])),
            'Reference' => ['label' => 'Reference', 'html' => '<code>' . e($m['ref']) . '</code>'],
            'Email'     => ['label' => 'Email',     'html' => '<a href="mailto:' . e($m['customer_email']) . '">' . e($m['customer_email']) . '</a>'],
            'Phone'     => $m['customer_phone'] ?: '—',
            'Received'  => $m['client_message_at'] ? date('j M Y, H:i', strtotime($m['client_message_at'])) : '—',
            'Message'   => ['label' => 'Message', 'html' => '<div style="white-space:pre-wrap;">' . e((string)$m['client_message']) . '</div>'],
        ];

        $actions = '<form method="post" style="display:inline;margin:0;">'
                 . csrf_field()
                 . '<input type="hidden" name="mark_replied" value="' . (int)$m['id'] . '">'
                 . '<button class="btn btn-sm btn-primary">Mark replied</button>'
                 . '</form>';

        slate_data_row([
            'avatar'       => mb_strtoupper(mb_substr($m['customer_name'], 0, 1)),
            'avatar_color' => $overdue ? 'muted' : 'info',
            'title'        => $m['customer_name'],
            'meta'         => e($m['service_name']) . ' · ' . e(date('j M, H:i', strtotime($m['starts_at']))),
            'badge'        => $badge,
            'detail'       => $detail,
            'actions'      => $actions,
        ]);
    endforeach; ?>
    </div>
    <?php slate_data_list_script(); ?>
<?php endif; ?>

<?php require SLATE_ROOT . '/admin/partials/footer.php';
