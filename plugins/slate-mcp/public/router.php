<?php
/** Streamable HTTP-style MCP endpoint: POST /slate-mcp/mcp */
if (!defined('SLATE_ROOT')) require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('slate-mcp');
require_once dirname(__DIR__) . '/SlateMcpAPI.php';
SlateMcpAPI::ensureSchema();

$route = trim((string)($_GET['_route_path'] ?? ''), '/');
if ($route !== 'mcp') { http_response_code(404); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'MCP endpoint not found.']); return; }
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) === 'OPTIONS') { header('Allow: POST, OPTIONS'); http_response_code(204); return; }
$authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if ($authorization === '' && function_exists('getallheaders')) {
    $h = getallheaders();
    $authorization = trim((string)($h['Authorization'] ?? $h['authorization'] ?? ''));
}
$token = preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) ? $matches[1] : '';
$payload = json_decode((string)file_get_contents('php://input'), true);
$response = SlateMcpAPI::handle(is_array($payload) ? $payload : [], $token);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
