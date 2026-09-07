<?php
/**
 * Booking+ settings — retired (CON-1).
 *
 * Booking+ no longer owns a settings screen. Its two fields are contributed
 * into Booking's own settings form through `booking_settings_cards` and saved
 * from `booking_settings_save`; see BookingPlusAPI::settingsCard() and
 * ::saveSettings().
 *
 * This file stays as a redirect rather than being deleted because the old page
 * is bookmarkable and was linked from the admin nav until this change. A 404
 * would look like a broken install; a redirect lands the operator on the screen
 * that now holds the same fields.
 *
 * 302, deliberately, not 301. A permanent redirect is cached by the browser
 * indefinitely and is not revalidated, so reverting this consolidation would
 * leave every operator who had visited the old URL still being bounced away
 * from a page that works again — with no server-side way to call it back. The
 * redirect is a migration aid, and a migration aid has to stay reversible.
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();

if (!PluginLoader::isActive('booking-plus')) {
    http_response_code(503);
    exit('Booking+ is not active.');
}

header('Location: ' . plugin_url('booking', 'admin/settings.php'), true, 302);
exit;
