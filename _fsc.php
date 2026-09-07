<?php
require __DIR__ . '/config.php';
$pdo = Database::get();
$out = "";
foreach (['forms_definitions','forms_submissions','forms_webhooks','forms_webhook_log'] as $t) {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_COLUMN);
        $out .= $t . ":\n  " . implode(', ', $cols) . "\n";
    } catch (\Throwable $e) {
        $out .= $t . " ERROR: " . $e->getMessage() . "\n";
    }
}
file_put_contents(__DIR__ . '/_fsc_out.txt', $out);
echo "WROTE\n";
