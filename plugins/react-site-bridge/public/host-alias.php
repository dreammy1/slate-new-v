<?php
/**
 * React Site Bridge — clean direct-hosting entrypoint.
 * The PublicRouter supplies the registered site key as _route_prefix and the
 * remainder as _route_path, so this handler never trusts a user-controlled
 * filesystem path or a tenant-external release identifier.
 */

require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('react-site-bridge');
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';

$siteKey = (string)($_GET['_route_prefix'] ?? '');
$path = (string)($_GET['_route_path'] ?? '');
ReactSiteBridgeAPI::serveHostedReleaseBySiteKey($siteKey, $path);
