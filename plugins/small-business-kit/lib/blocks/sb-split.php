<?php
/** sb-split — image + text side-by-side.
 * @var string $eyebrow @var string $heading @var string $accentLine
 * @var string $body @var string $image @var string $mediaSide
 * @var string $btnText @var string $btnHref @var string $btnStyle
 * @var string $bg
 */
$bg        = $bg ?? 'page';
$bg        = in_array($bg, ['page','surface','tint','dark'], true) ? $bg : 'page';
$mediaSide = ($mediaSide ?? 'left') === 'right' ? 'sb-split-r' : '';
$img       = trim($image ?? '');
$btnStyle  = $btnStyle ?? 'dark';
$btnStyle  = in_array($btnStyle, ['dark','primary','outline'], true) ? $btnStyle : 'dark';
$arrow     = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<section class="sb sb-bg-<?= e($bg) ?>"<?= SBKBlocks::styleAttr(['headingSize'=>$headingSize??'','headingWeight'=>$headingWeight??'','subSize'=>$subSize??'']) ?>>
  <div class="sb-inner">
    <div class="sb-split <?= $mediaSide ?>">
      <div class="sb-split-media">
        <?php if ($img !== ''): ?>
          <img src="<?= e(ContentBuilderAPI::mediaUrl($img)) ?>"
               alt="<?= e($heading ?? '') ?>"
               width="1100" height="720" loading="lazy" decoding="async">
        <?php endif; ?>
      </div>
      <div class="sb-split-body">
        <?php if (!empty($eyebrow)): ?><span class="sb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading) || !empty($accentLine)): ?>
          <h2><?= e($heading ?? '') ?> <?php if (!empty($accentLine)): ?><em><?= e($accentLine) ?></em><?php endif; ?></h2>
        <?php endif; ?>
        <?php
          $bodyText = (string)($body ?? '');
          foreach (preg_split('/\r?\n\r?\n+/', $bodyText) as $para):
              $para = trim($para); if ($para === '') continue;
        ?>
          <p><?= nl2br(e($para)) ?></p>
        <?php endforeach; ?>
        <?php if (!empty($btnText)): ?>
          <a class="sb-btn sb-btn-<?= e($btnStyle) ?>" href="<?= e($btnHref ?? '#') ?>"><?= e($btnText) ?> <?= $arrow ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
