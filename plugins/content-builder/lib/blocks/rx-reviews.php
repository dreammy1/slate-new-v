<?php
/** rx-reviews — testimonial cards. Each: rating (1-5), quote, name, meta, avatar. */
$tone = ($tone ?? 'surface') === 'dark' ? 'dark' : 'surface';
$revs = is_array($items ?? null) ? $items : [];
?>
<section class="rx rx-reviews rx-tone-<?= $tone ?>">
  <div class="rx-inner">
    <div class="rx-head rx-head-center">
      <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
    </div>
    <div class="rx-reviews-grid">
      <?php foreach ($revs as $r):
        $n = (int)($r['rating'] ?? 5); $n = max(1, min(5, $n));
        $name = trim((string)($r['name'] ?? ''));
        $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '★'; ?>
        <blockquote class="rx-review">
          <span class="rx-stars"><?= str_repeat('★', $n) . str_repeat('☆', 5 - $n) ?></span>
          <?php if (!empty($r['quote'])): ?><p><?= e($r['quote']) ?></p><?php endif; ?>
          <footer class="rx-review-by">
            <span class="rx-avatar"<?= !empty($r['avatar']) ? ' style="background-image:url(\'' . e(ContentBuilderAPI::mediaUrl($r['avatar'])) . '\')"' : '' ?>><?= empty($r['avatar']) ? e($initial) : '' ?></span>
            <span>
              <?php if ($name !== ''): ?><b><?= e($name) ?></b><?php endif; ?>
              <?php if (!empty($r['meta'])): ?><small><?= e($r['meta']) ?></small><?php endif; ?>
            </span>
          </footer>
        </blockquote>
      <?php endforeach; ?>
    </div>
  </div>
</section>
