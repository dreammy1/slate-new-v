<?php /** @var string $text @var string $level */
$lvl = max(1, min(6, (int)($level ?? 2))); ?>
<h<?= $lvl ?> class="cb-heading"><?= e($text ?? '') ?></h<?= $lvl ?>>
