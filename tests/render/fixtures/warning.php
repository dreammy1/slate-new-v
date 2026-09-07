<?php
/**
 * Negative control: a page that renders fully but raises a warning.
 *
 * The markup closes and the process exits 0, so only the diagnostic capture
 * distinguishes this from a healthy page. Exists so tests/render/run.php can
 * prove that capture still works.
 */

declare(strict_types=1);

$empty = [];
$value = $empty['key_that_is_not_there'];

echo '<!doctype html><html><body>' . (string) $value . '</body></html>';
