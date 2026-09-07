<?php
/**
 * Negative control: a page that dies fatally.
 *
 * Exists so tests/render/run.php can prove it still detects a fatal. Not a
 * real page and never linked from anywhere.
 */

declare(strict_types=1);

echo '<!doctype html><html><body>';
/** @phpstan-ignore-next-line — the undefined call is the point. */
slate_render_fixture_function_that_does_not_exist();
echo '</body></html>';
