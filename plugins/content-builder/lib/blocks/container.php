<?php
/**
 * Block: Flex Container (Elementor Style)
 * Supports flex direction, alignment, gap, wrapping, and child widgets.
 *
 * @var string $direction
 * @var string $align
 * @var string $justify
 * @var int $gap
 * @var bool $wrap
 * @var bool $fullwidth
 * @var int $minHeight
 * @var bool $stackOnMobile
 * @var array $children
 * @var callable $renderBlock
 */
$direction = in_array($direction ?? 'row', ['row','column','row-reverse','column-reverse']) ? $direction : 'row';
$align     = in_array($align ?? 'stretch', ['stretch','center','flex-start','flex-end']) ? $align : 'stretch';
$justify   = in_array($justify ?? 'flex-start', ['flex-start','center','flex-end','space-between','space-around','space-evenly']) ? $justify : 'flex-start';
$gap       = max(0, min(100, (int)($gap ?? 16)));
$wrap      = !empty($wrap) ? 'wrap' : 'nowrap';
$minHeight = !empty($minHeight) ? (int)$minHeight : 0;
$stackOnMobile = $stackOnMobile ?? true;
$children  = is_array($children ?? null) ? $children : [];

$style = "display:flex;flex-direction:{$direction};align-items:{$align};justify-content:{$justify};gap:{$gap}px;flex-wrap:{$wrap};";
if (!empty($fullwidth)) $style .= 'width:100%;';
if ($minHeight > 0) $style .= "min-height:{$minHeight}px;";

$classes = ['cb-block', 'cb-container'];
if (!empty($stackOnMobile)) $classes[] = 'cb-stack-mobile';

$isEditor = defined('SLATE_EDITOR_MODE') || !empty($GLOBALS['SLATE_EDITOR_MODE']);
?>
<div class="<?= implode(' ', $classes) ?>" style="<?= htmlspecialchars($style, ENT_QUOTES) ?>">
    <?php if (!empty($children)): ?>
        <?php foreach ($children as $b): ?>
            <?php if (is_array($b)): ?>
                <?= isset($renderBlock) ? $renderBlock($b) : (class_exists('Renderer') ? Renderer::renderBlock($b) : '') ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($isEditor): ?>
            <div class="ve-slot-add-bar" style="width:100%;">
                <button type="button" class="ve-slot-mini-add-btn" data-slot-action="add-to-container" title="Add widget to Container">+ Add Widget</button>
            </div>
        <?php endif; ?>
    <?php elseif ($isEditor): ?>
        <div class="ve-empty-container-placeholder" data-slot-action="add-to-container">
            <div class="ve-slot-icon">+</div>
            <div class="ve-slot-text">Flex Container</div>
            <div class="ve-slot-sub">Click to add widgets side by side</div>
        </div>
    <?php endif; ?>
</div>
