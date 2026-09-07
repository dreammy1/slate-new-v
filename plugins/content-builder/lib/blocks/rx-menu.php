<?php
/** rx-menu — menu grid. Each dish is a repeater row (tag, name, price, text, image). */
$tone  = ($tone ?? 'surface') === 'dark' ? 'dark' : 'surface';
$cols  = (string)($cols ?? '3'); $cols = in_array($cols, ['2','3'], true) ? $cols : '3';
$dishes = is_array($items ?? null) ? $items : [];
?>
<section class="rx rx-menu rx-tone-<?= $tone ?>">
  <div class="rx-inner">
    <div class="rx-head rx-head-center">
      <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
      <?php if (!empty($intro)): ?><p class="rx-head-sub"><?= e($intro) ?></p><?php endif; ?>
    </div>
    <div class="rx-menu-grid rx-cols-<?= $cols ?>">
      <?php foreach ($dishes as $d):
        $img = trim((string)($d['image'] ?? '')); ?>
        <article class="rx-dish">
          <div class="rx-dish-pic"<?= $img !== '' ? ' style="background-image:url(\'' . e(ContentBuilderAPI::mediaUrl($img)) . '\')"' : '' ?>>
            <?php if (!empty($d['tag'])): ?><span class="rx-dish-tag"><?= e($d['tag']) ?></span><?php endif; ?>
          </div>
          <div class="rx-dish-body">
            <div class="rx-dish-row">
              <h3><?= e($d['name'] ?? '') ?></h3>
              <?php if (isset($d['price']) && $d['price'] !== ''): ?><span class="rx-dish-price"><?= e($d['price']) ?></span><?php endif; ?>
            </div>
            <?php if (!empty($d['text'])): ?><p><?= e($d['text']) ?></p><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
