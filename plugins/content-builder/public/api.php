<?php
/**
 * Content API — GET /api/content/{route}
 *
 * Returns the ADR-0013 envelope: addressing echoed, document verbatim, theme
 * alongside. One round trip, so a consumer cannot end up with a document and a
 * theme that disagree.
 *
 * Registered on `public_routes` under the 'api' prefix, so it inherits the
 * router's method handling and never becomes a loose executable file in the web
 * root.
 *
 * CORS is an explicit allowlist, empty by default. A content API is exactly the
 * thing someone reaches for a wildcard on, and a wildcard here would let any
 * origin read unpublished-adjacent metadata from a visitor's browser. Set
 * `content-builder.api_allowed_origins` to a comma-separated list to open it.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('content-builder');

$path = trim((string)($_GET['_route_path'] ?? ''), '/');

// Only /api/content/... belongs to this handler.
if (!str_starts_with($path, 'content')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found']);
    return;
}

$route  = trim(substr($path, strlen('content')), '/');
$locale = preg_match('/^[A-Za-z0-9_-]{1,16}$/', (string)($_GET['locale'] ?? ''))
    ? (string) $_GET['locale']
    : 'en';

// ── CORS, allowlist only ────────────────────────────────────
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $allowed = array_filter(array_map(
        'trim',
        explode(',', (string) ContentBuilderAPI::getSiteSetting('api_allowed_origins', ''))
    ));
    if (in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    return;
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

try {
    $envelope = ContentBuilderAPI::contentEnvelope($route, $locale);
} catch (\Throwable $e) {
    // Never leak an exception message to an unauthenticated caller.
    if (function_exists('slate_log')) {
        slate_log('content API failed for ' . $route . ': ' . $e->getMessage(), 'warning');
    }
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
    return;
}

if ($envelope === null) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found', 'route' => '/' . $route]);
    return;
}

echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
