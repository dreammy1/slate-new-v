<?php
/**
 * Block: Spacer
 * Adds configurable vertical whitespace between blocks.
 */
$height = (int)($props['height'] ?? 48);
$height = max(8, min(400, $height));
?>
<div class="cb-block cb-spacer" style="height:<?= $height ?>px;" aria-hidden="true"></div>
