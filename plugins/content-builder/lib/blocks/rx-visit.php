<?php
/** rx-visit — closing CTA with address/phone + an editable hours table. */
$tone  = ($tone ?? 'surface') === 'dark' ? 'dark' : 'surface';
$hours = is_array($items ?? null) ? $items : [];
?>
<section class="rx rx-visit rx-tone-<?= $tone ?>">
  <div class="rx-inner">
    <div class="rx-visit-card">
      <div class="rx-visit-copy">
        <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h2 class="rx-h2"><?= e($heading) ?></h2><?php endif; ?>
        <?php if (!empty($text)): ?><p class="rx-head-sub"><?= nl2br(e($text)) ?></p><?php endif; ?>
        <div class="rx-actions">
          <?php if (!empty($btnText)): ?><a class="rx-btn rx-btn-primary" href="<?= e(slate_safe_url($btnHref ?? '#')) ?>"><?= e($btnText) ?></a><?php endif; ?>
          <?php if (!empty($btn2Text)): ?><a class="rx-btn rx-btn-ghost" href="<?= e(slate_safe_url($btn2Href ?? '#')) ?>"><?= e($btn2Text) ?></a><?php endif; ?>
        </div>
        <?php if (!empty($address) || !empty($phone)): ?>
          <p class="rx-visit-meta">
            <?php if (!empty($address)): ?><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <?= e($address) ?><?php endif; ?>
            <?php if (!empty($address) && !empty($phone)): ?> &nbsp;·&nbsp; <?php endif; ?>
            <?php if (!empty($phone)): ?><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M6.6 3.6L9.4 8l-2 2.2a12.8 12.8 0 0 0 6.4 6.4l2.2-2 4.4 2.8v3a1.6 1.6 0 0 1-1.75 1.6A17.6 17.6 0 0 1 3 5.35 1.6 1.6 0 0 1 4.6 3.6z"/></svg> <?= e($phone) ?><?php endif; ?>
          </p>
        <?php endif; ?>
      </div>
      <?php if ($hours): ?>
      <ul class="rx-hours">
        <?php foreach ($hours as $h):
          $hi = !empty($h['highlight']) && $h['highlight'] !== 'false'; ?>
          <li<?= $hi ? ' class="rx-hours-open"' : '' ?>>
            <span><?= e($h['label'] ?? '') ?></span>
            <b><?= e($h['value'] ?? '') ?></b>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
