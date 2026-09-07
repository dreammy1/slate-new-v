<?php
/** sb-contact-grid — eyebrow + heading + three contact cards.
 * @var string $eyebrow @var string $heading @var string $accentLine
 * @var string $bg @var array $items
 *   item: ['icon'=>'phone','label'=>'Phone','value'=>'(866) 627-7878','href'=>'tel:...']
 */
require_once __DIR__ . '/../Icons.php';

$bg    = in_array($bg ?? 'page', ['page','surface','tint','dark'], true) ? $bg : 'page';
$items = is_array($items ?? null) ? $items : [];
?>
<section class="sb sb-bg-<?= e($bg) ?>"<?= SBKBlocks::styleAttr(['headingSize'=>$headingSize??'','headingWeight'=>$headingWeight??'','subSize'=>$subSize??'']) ?>>
  <div class="sb-inner">
    <?php if (!empty($eyebrow) || !empty($heading)): ?>
      <div class="sb-head">
        <?php if (!empty($eyebrow)): ?><span class="sb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading) || !empty($accentLine)): ?>
          <h2><?= e($heading ?? '') ?> <?php if (!empty($accentLine)): ?><em><?= e($accentLine) ?></em><?php endif; ?></h2>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="sb-contact-grid">
      <?php foreach ($items as $it): ?>
        <div class="sb-contact-card">
          <?php if (!empty($it['icon'])): ?><div class="sb-card-icon"><?= SBKIcons::svg($it['icon'], 24) ?></div><?php endif; ?>
          <?php if (!empty($it['label'])): ?><h3><?= e($it['label']) ?></h3><?php endif; ?>
          <?php if (!empty($it['value'])):
              if (!empty($it['href'])): ?>
                <p><a href="<?= e($it['href']) ?>"><?= nl2br(e($it['value'])) ?></a></p>
              <?php else: ?>
                <p><?= nl2br(e($it['value'])) ?></p>
              <?php endif;
          endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
