<?php
/** sb-cta-band — dark gradient band with heading + sub + primary CTA.
 * @var string $heading @var string $text @var string $btnText @var string $btnHref @var string $btnColor @var string $btnTextColor
 */
$arrow = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<section class="sb-ctaband">
  <div class="sb-ctaband-inner">
    <?php if (!empty($heading)): ?><h2><?= e($heading) ?></h2><?php endif; ?>
    <?php if (!empty($text)): ?><p><?= e($text) ?></p><?php endif; ?>
    <?php if (!empty($btnText)): 
        $btnStyle = '';
        if (!empty($btnColor)) $btnStyle .= 'background-color:' . e($btnColor) . ';border-color:' . e($btnColor) . ';';
        if (!empty($btnTextColor)) $btnStyle .= 'color:' . e($btnTextColor) . ';';
    ?>
      <a class="sb-btn sb-btn-primary" href="<?= e($btnHref ?? '#') ?>" <?= $btnStyle ? 'style="'.$btnStyle.'"' : '' ?>><?= e($btnText) ?> <?= $arrow ?></a>
    <?php endif; ?>
  </div>
</section>
