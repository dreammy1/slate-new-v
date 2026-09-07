<?php
require __DIR__ . '/config.php';
$rows = Database::rows("SELECT id, submitter_email, data_json FROM forms_submissions ORDER BY created_at DESC LIMIT 6", []);
foreach ($rows as $r) {
    $d = json_decode($r['data_json'], true) ?: [];
    echo "#" . $r['id'] . " email=" . $r['submitter_email'] . "\n";
    foreach ($d as $k => $v) {
        if (is_array($v)) $v = '[array]';
        echo "    " . $k . " = " . mb_substr((string)$v, 0, 40) . "\n";
    }
}
