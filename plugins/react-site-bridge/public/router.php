<?php
/**
 * React Site Bridge — public JSON manifest route.
 *
 * URL: /site-data/<public-id>/manifest
 * The public id is an opaque identifier. The route only exposes an immutable,
 * already-published revision; admin changes are not public until publish().
 */

if (!defined('SLATE_ROOT')) require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('react-site-bridge');
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
ReactSiteBridgeAPI::ensureSchema();

$route = trim((string)($_GET['_route_path'] ?? ''), '/');
$parts = $route === '' ? [] : explode('/', $route);
$publicId = (string)($parts[0] ?? '');
$resource = (string)($parts[1] ?? '');

if ($publicId === '' || $resource !== 'manifest' || count($parts) !== 2) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Manifest not found.']);
    return;
}

$result = ReactSiteBridgeAPI::activeManifestByPublicId($publicId);
if (!$result || !is_array($result['manifest'])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Published manifest not found.']);
    return;
}

$site = $result['site'];
$origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
$allowed = array_values(array_unique(array_filter([
    ReactSiteBridgeAPI::frontendCorsOrigin((string)($site['public_origin'] ?? '')),
    ReactSiteBridgeAPI::frontendCorsOrigin((string)($site['preview_origin'] ?? '')),
])));
if ($origin !== '' && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    http_response_code(204);
    return;
}

$json = json_encode($result['manifest'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$etag = '"' . hash('sha256', (string)$json) . '"';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    return;
}
echo $json;
