<?php
/**
 * React Site Bridge — direct Slate-hosted React release router.
 *
 * URL: /react-sites/<public-id>/[route-or-asset]
 * The release itself is stored below Slate uploads and served through this
 * router, preventing arbitrary filesystem paths and uploaded PHP execution.
 */

if (!defined('SLATE_ROOT')) require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('react-site-bridge');
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';

$route = trim((string)($_GET['_route_path'] ?? ''), '/');
[$publicId, $remainder] = array_pad(explode('/', $route, 2), 2, '');
if (!preg_match('/^[a-f0-9]{24,64}$/', $publicId)) {
    http_response_code(404);
    echo 'Hosted React site not found.';
    return;
}
ReactSiteBridgeAPI::serveHostedRelease($publicId, $remainder);
