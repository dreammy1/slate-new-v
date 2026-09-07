<?php
/**
 * Block: Divider
 * A horizontal rule with configurable style, color and spacing.
 */
$style  = in_array($props['style'] ?? 'solid', ['solid','dashed','dotted']) ? ($props['style'] ?? 'solid') : 'solid';
$color  = preg_match('/^#[0-9a-fA-F]{3,8}$/', $props['color'] ?? '') ? $props['color'] : 'var(--color-border, #e5e7eb)';
$space  = max(0, min(120, (int)($props['space'] ?? 24)));
?>
<div class="cb-block cb-divider" style="padding:<?= $space ?>px 0;">
    <hr style="border:0;border-top:1px <?= e($style) ?> <?= e($color) ?>;margin:0;">
</div>
