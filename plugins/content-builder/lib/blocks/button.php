<?php /** @var string $text @var string $href @var string $style */
$st = ($style ?? 'primary') === 'secondary' ? 'btn-secondary' : 'btn-primary'; ?>
<p class="cb-button">
    <a class="btn <?= $st ?>" href="<?= e(slate_safe_url($href ?? '#')) ?>"><?= e($text ?? 'Button') ?></a>
</p>
