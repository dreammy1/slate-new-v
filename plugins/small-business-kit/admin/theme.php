<?php
/**
 * Site theme — moved.
 *
 * The theme picker now lives inside the content engine's Site Settings, next to
 * branding, so there is one screen that owns how the site looks instead of two
 * in different plugins (Phase A of the content-engine consolidation).
 *
 * This file stays as a redirect: the page was linked from the admin nav for
 * months and people will have it bookmarked. It intentionally keeps the same
 * permission check, so an unauthorised visitor gets the same answer as before
 * rather than being bounced to a page they cannot use.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('sbk.theme');

header('Location: ' . rtrim(SLATE_URL, '/') . '/plugins/content-builder/admin/site.php#theme', true, 302);
exit;
