<?php
/**
 * Slate — Customer Portal Booking Flow.
 *
 * Integrated booking interface inside the customer portal shell:
 * - Shows current / upcoming appointments for the logged-in customer
 * - Provides seamless inline booking widget with pre-filled customer details
 *
 * Route: /member/book
 */

declare(strict_types=1);

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__) . '/config.php';
}

require_once SLATE_ROOT . '/includes/portal_shell.php';
require_once SLATE_ROOT . '/includes/portal_ui.php';

use Slate\Data\Database;
use Slate\Services\Auth\Auth;

Auth::requireCustomer();

$cid = (int) Auth::customerId();
$tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;

// Fetch upcoming appointments
$upcoming = [];
try {
    $upcoming = Database::rows(
        "SELECT a.*, s.name AS service_name, p.name AS provider_name
           FROM booking_appointments a
           JOIN booking_services  s ON s.id = a.service_id
           JOIN booking_providers p ON p.id = a.provider_id
          WHERE a.customer_id = ? AND a.tenant_id = ? AND a.status = 'confirmed'
            AND a.starts_at >= NOW()
       ORDER BY a.starts_at ASC LIMIT 10",
        [$cid, $tid]
    );
} catch (\Throwable $e) {
    $upcoming = [];
}

$currentPortalNav = 'book';
slate_portal_shell_head(__('book_now', 'Book an appointment'));
slate_portal_shell_open(['active' => 'book', 'area' => 'booking']);
?>

<div class="phero" style="margin-bottom:24px;">
    <div class="phero-main">
        <div class="phero-eyebrow"><?= e(__('portal_overview', 'Overview')) ?></div>
        <h1 class="phero-title"><?= e(__('book_appointment', 'Book an appointment')) ?></h1>
        <p class="phero-sub"><?= e(__('book_appointment_sub', 'Choose a service, select an available time slot, and confirm your booking.')) ?></p>
    </div>
</div>

<?php if ($upcoming): ?>
    <div class="pcard" style="margin-bottom:24px;">
        <p class="pcard-eyebrow"><?= e(__('upcoming_appointments', 'Your upcoming appointments')) ?></p>
        <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($upcoming as $app): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;border-radius:10px;background:var(--m-surface-2,#F8FAFC);border:1px solid var(--m-line,#ECEEF1);flex-wrap:wrap;gap:10px;">
                    <div>
                        <strong style="font-size:15px;color:var(--m-ink,#15181E);display:block;">
                            <?= e($app['service_name']) ?>
                        </strong>
                        <span style="font-size:13px;color:var(--m-muted,#737886);">
                            with <?= e($app['provider_name']) ?> · <strong><?= e(date('D, j M Y \a\t g:i a', strtotime($app['starts_at']))) ?></strong>
                        </span>
                    </div>
                    <?php if (!empty($app['manage_token'])): ?>
                        <a href="<?= e(SLATE_URL) ?>/book/manage?token=<?= e($app['manage_token']) ?>" class="mbtn mbtn-sm mbtn-ghost" target="_blank">
                            <?= __('manage_booking', 'Manage / Reschedule') ?> →
                        </a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="pcard" style="padding:0;overflow:hidden;border:1px solid var(--m-line,#ECEEF1);">
    <?php /* &portal=1: this iframe is Slate's OWN customer portal embedding
     Slate's OWN booking widget — same-origin, and the portal topbar
     already shows a language switcher. That is different from a
     THIRD-PARTY site (Wix, WordPress...) embedding /book?embed=1 with
     no outer chrome of its own, where the widget's own switcher is the
     only control there is and must keep showing. */ ?>
    <iframe src="<?= e(SLATE_URL) ?>/book?embed=1&portal=1"
            title="<?= e(__('book_now', 'Book an appointment')) ?>"
            style="width:100%;min-height:720px;border:none;display:block;"
            loading="lazy"></iframe>
</div>

<?php slate_portal_shell_close(); ?>
