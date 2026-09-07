<?php
/** rx-gallery — masonry-ish image grid. Each tile: image, label, size. */
$tone  = ($tone ?? 'light') === 'dark' ? 'dark' : 'light';
$tiles = is_array($items ?? null) ? $items : [];
?>
<section class="rx rx-gallery rx-tone-<?= $tone ?>">
  <div class="rx-inner">
    <div class="rx-head">
      <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
    </div>
    <div class="rx-gallery-grid">
      <?php foreach ($tiles as $t):
        $img  = trim((string)($t['image'] ?? ''));
        $size = ($t['size'] ?? 'normal');
        $cls  = $size === 'wide' ? ' rx-tile-wide' : ($size === 'tall' ? ' rx-tile-tall' : ''); ?>
        <figure class="rx-tile<?= $cls ?>"<?= $img !== '' ? ' style="background-image:url(\'' . e(ContentBuilderAPI::mediaUrl($img)) . '\')"' : '' ?>>
          <?php if (!empty($t['label'])): ?><figcaption class="rx-tile-label"><?= e($t['label']) ?></figcaption><?php endif; ?>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
