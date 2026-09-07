<?php
/** rx-story — split "our story": stat medallion + copy + feature list. */
$tone  = ($tone ?? 'light') === 'dark' ? 'dark' : 'light';
$feats = is_array($items ?? null) ? $items : [];
$img   = trim((string)($image ?? ''));
?>
<section class="rx rx-story rx-tone-<?= $tone ?>">
  <div class="rx-story-inner">
    <div class="rx-story-visual"<?= $img !== '' ? ' style="background-image:url(\'' . e(ContentBuilderAPI::mediaUrl($img)) . '\')"' : '' ?>>
      <?php if ($img === ''): ?>
        <div class="rx-story-medallion">
          <div class="rx-stat-big"><?= e($statBig ?? '') ?></div>
          <small><?= e($statLabel ?? '') ?></small>
        </div>
      <?php elseif (!empty($statBig)): ?>
        <div class="rx-story-chip"><b><?= e($statBig) ?></b> <span><?= e($statLabel ?? '') ?></span></div>
      <?php endif; ?>
    </div>
    <div class="rx-story-copy">
      <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
      <?php if (!empty($body)): ?><div class="rx-prose"><?= nl2br(e($body)) ?></div><?php endif; ?>
      <?php if ($feats): ?>
      <div class="rx-feat-grid">
        <?php foreach ($feats as $f): ?>
          <div class="rx-feat">
            <?php if (!empty($f['icon'])): ?><span class="rx-feat-ic"><?= e($f['icon']) ?></span><?php endif; ?>
            <?php if (!empty($f['title'])): ?><b><?= e($f['title']) ?></b><?php endif; ?>
            <?php if (!empty($f['text'])): ?><span class="rx-feat-text"><?= e($f['text']) ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
