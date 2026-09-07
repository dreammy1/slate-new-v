<?php
/** rx-marquee — scrolling strip of short phrases. */
$items = is_array($items ?? null) ? $items : [];
$phrases = [];
foreach ($items as $it) { $t = trim((string)($it['text'] ?? '')); if ($t !== '') $phrases[] = $t; }
if (!$phrases) return;
$tone = ($tone ?? 'surface');
?>
<div class="rx rx-marquee rx-mq-<?= e($tone) ?>">
  <div class="rx-marquee-track">
    <?php for ($r = 0; $r < 2; $r++): foreach ($phrases as $p): ?>
      <span class="rx-marquee-item"><?= e($p) ?></span>
    <?php endforeach; endfor; ?>
  </div>
</div>
