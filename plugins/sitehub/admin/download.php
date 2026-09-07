<?php
/**
 * SiteHub — stream a stored backup zip to an authorised user.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
Auth::require();
Auth::requirePerm('sitehub.manage');

$id = (int)($_GET['id'] ?? 0);
$row = Database::row(
    "SELECT * FROM sitehub_backups WHERE id = ? AND tenant_id = ?",
    [$id, current_tenant_id()]
);
if (!$row) { http_response_code(404); exit('Not found'); }

$path = SitehubAPI::backupDir() . basename($row['filename']);
if (!is_file($path)) { http_response_code(404); exit('File missing'); }

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($row['filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;
