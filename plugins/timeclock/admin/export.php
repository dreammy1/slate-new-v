<?php
/**
 * Site Timeclock — CSV export of completed entries.
 * Optional query filters: employee_id, from, to.
 */
require_once dirname(__DIR__, 3) . '/config.php';
Auth::require();
Auth::requirePerm('timeclock.view');
require_once dirname(__DIR__) . '/TimeclockAPI.php';

$filters = [];
if (!empty($_GET['employee_id'])) $filters['employee_id'] = (int)$_GET['employee_id'];
if (!empty($_GET['from']))        $filters['from']        = preg_replace('/[^0-9\-]/', '', $_GET['from']);
if (!empty($_GET['to']))          $filters['to']          = preg_replace('/[^0-9\-]/', '', $_GET['to']);

$csv = TimeclockAPI::exportCsv($filters);

$fname = 'timeclock-' . date('Ymd-His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . strlen($csv));
echo $csv;
exit;
