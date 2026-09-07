<?php
/** sb-feature-grid — eyebrow + heading + sub + N feature cards.
 * @var string $eyebrow @var string $heading @var string $accentLine @var string $sub
 * @var string $cols @var string $bg @var array  $items
 */
require_once __DIR__ . '/../Icons.php';

$bg     = in_array($bg ?? 'page', ['page','surface','tint','dark'], true) ? $bg : 'page';
$cols   = in_array((string)($cols ?? '3'), ['2','3','4'], true) ? (string)$cols : '3';
$items  = is_array($items ?? null) ? $items : [];
$arrow  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<section class="sb sb-bg-<?= e($bg) ?>"<?= SBKBlocks::styleAttr(['headingSize'=>$headingSize??'','headingWeight'=>$headingWeight??'','subSize'=>$subSize??'']) ?>>
  <div class="sb-inner">
    <?php if (!empty($eyebrow) || !empty($heading) || !empty($sub)): ?>
      <div class="sb-head">
        <?php if (!empty($eyebrow)): ?><span class="sb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading) || !empty($accentLine)): ?>
          <h2><?= e($heading ?? '') ?> <?php if (!empty($accentLine)): ?><em><?= e($accentLine) ?></em><?php endif; ?></h2>
        <?php endif; ?>
        <?php if (!empty($sub)): ?><p class="sb-section-sub"><?= e($sub) ?></p><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="sb-grid sb-grid-<?= $cols ?>">
      <?php foreach ($items as $i => $it): ?>
        <div class="sb-card">
          <?php if (!empty($it['num'])): ?><div class="sb-card-num"><?= e($it['num']) ?></div><?php endif; ?>
          <?php if (!empty($it['icon'])): ?><div class="sb-card-icon"><?= SBKIcons::svg($it['icon'], 26) ?></div><?php endif; ?>
          <?php if (!empty($it['title'])): ?><h3><?= e($it['title']) ?></h3><?php endif; ?>
          <?php if (!empty($it['text'])): ?><p><?= e($it['text']) ?></p><?php endif; ?>
          <?php if (!empty($it['linkText']) && !empty($it['linkHref'])): ?>
            <a class="sb-card-link" href="<?= e($it['linkHref']) ?>"><?= e($it['linkText']) ?> <?= $arrow ?></a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
