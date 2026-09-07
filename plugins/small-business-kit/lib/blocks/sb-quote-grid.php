<?php
/** sb-quote-grid — optional stats strip + N testimonial cards.
 * @var string $eyebrow @var string $heading @var string $accentLine @var string $sub
 * @var string $cols @var string $bg
 * @var array  $stats (each: ['big'=>'30+','label'=>'Years','isText'=>'0|1'])
 * @var array  $items (each: ['quote'=>'','name'=>'','meta'=>'','stars'=>'5'])
 */
$bg    = in_array($bg ?? 'page', ['page','surface','tint','dark'], true) ? $bg : 'page';
$cols  = in_array((string)($cols ?? '3'), ['2','3'], true) ? (string)$cols : '3';
$items = is_array($items ?? null) ? $items : [];
$stats = is_array($stats ?? null) ? $stats : [];

$initials = static function (string $name): string {
    $p = preg_split('/\s+/', trim($name));
    return strtoupper(mb_substr($p[0] ?? '', 0, 1) . (isset($p[1]) ? mb_substr($p[1], 0, 1) : ''));
};
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

    <?php if ($stats): ?>
      <div class="sb-stats">
        <?php foreach ($stats as $s):
            $cls = !empty($s['isText']) ? ' sb-stat-text' : '';
        ?>
          <div class="sb-stat">
            <strong class="<?= $cls ?>"><?= e($s['big'] ?? '') ?></strong>
            <span><?= e($s['label'] ?? '') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="sb-grid sb-grid-<?= $cols ?>">
      <?php foreach ($items as $r):
          $stars = (int)($r['stars'] ?? 5); $stars = max(3, min(5, $stars));
      ?>
        <div class="sb-quote">
          <div class="sb-quote-stars"><?= str_repeat('★', $stars) ?></div>
          <p><?= e($r['quote'] ?? '') ?></p>
          <div class="sb-quote-author">
            <div class="sb-quote-avatar"><?= e($initials($r['name'] ?? '')) ?></div>
            <div>
              <div class="sb-quote-name"><?= e($r['name'] ?? '') ?></div>
              <?php if (!empty($r['meta'])): ?><div class="sb-quote-meta"><?= e($r['meta']) ?></div><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
