<?php
/**
 * Block: Columns / Grid (Elementor Style)
 * Supports 1 to 4 columns, customizable ratios, alignment, and child widgets.
 *
 * @var array $cols
 * @var callable $renderBlock
 * @var bool $stackOnMobile
 * @var string $ratio
 * @var int $gap
 * @var string $align
 * @var int $minHeight
 */
$cols = is_array($cols ?? null) ? $cols : [];
if (empty($cols)) {
    $cols = [['blocks' => []], ['blocks' => []]];
}
$numCols = count($cols);
$ratio   = $ratio ?? ($numCols === 3 ? '33-33-33' : ($numCols === 4 ? '25-25-25-25' : '50-50'));
$gap     = isset($gap) && $gap !== '' ? (int)$gap : 24;
$align   = $align ?? 'stretch';
$minHeight = !empty($minHeight) ? (int)$minHeight : 0;
$stackOnMobile = $stackOnMobile ?? true;

$classes = ['cb-columns', 'cb-cols-' . $numCols, 'cb-ratio-' . $ratio, 'cb-valign-' . $align];
if ($stackOnMobile) $classes[] = 'cb-cols-stack';

$styleStr = "gap: {$gap}px;";
if ($minHeight > 0) $styleStr .= "min-height: {$minHeight}px;";

$isEditor = defined('SLATE_EDITOR_MODE') || !empty($GLOBALS['SLATE_EDITOR_MODE']);
?>
<div class="<?= implode(' ', $classes) ?>" style="<?= $styleStr ?>">
    <?php foreach ($cols as $colIdx => $col): 
        $bList = (array)($col['blocks'] ?? []);
    ?>
        <div class="cb-col" data-col-index="<?= $colIdx ?>">
            <?php if (!empty($bList)): ?>
                <?php foreach ($bList as $b): ?>
                    <?php if (is_array($b)): ?>
                        <?= isset($renderBlock) ? $renderBlock($b) : (class_exists('Renderer') ? Renderer::renderBlock($b) : '') ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($isEditor): ?>
                    <div class="ve-slot-add-bar">
                        <button type="button" class="ve-slot-mini-add-btn" data-slot-action="add-to-col" data-col="<?= $colIdx ?>" title="Add widget to Column <?= $colIdx + 1 ?>">+ Add Widget</button>
                    </div>
                <?php endif; ?>
            <?php elseif ($isEditor): ?>
                <div class="ve-empty-col-placeholder" data-slot-action="add-to-col" data-col="<?= $colIdx ?>">
                    <div class="ve-slot-icon">+</div>
                    <div class="ve-slot-text">Column <?= $colIdx + 1 ?></div>
                    <div class="ve-slot-sub">Click to add widget</div>
                </div>
            <?php else: ?>
                <div class="cb-col-empty"></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
