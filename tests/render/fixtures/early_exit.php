<?php
/**
 * Negative control: a page that exits before rendering.
 *
 * This is the failure mode a naive harness scores as a pass — the process
 * ends cleanly, exit code 0, no error raised, and no page. Exists so
 * tests/render/run.php can prove the shutdown guard still catches it.
 */

declare(strict_types=1);

echo '<!doctype html><html><body>';
exit;
