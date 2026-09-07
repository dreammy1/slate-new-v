<?php
/** rx-hero — modern restaurant hero. All content is field-driven; colours
 * come from the site theme tokens (--cb-accent, --cb-ink, …). */
$tone = ($tone ?? 'dark') === 'light' ? 'light' : 'dark';
$bg   = trim((string)($image ?? ''));
$style = $bg !== '' ? ' style="--rx-hero-img:url(\'' . e(ContentBuilderAPI::mediaUrl($bg)) . '\')"' : '';
?>
<section class="rx rx-hero rx-tone-<?= $tone ?><?= $bg !== '' ? ' rx-hero-photo' : '' ?>"<?= $style ?>>
  <div class="rx-hero-inner">
    <div class="rx-hero-copy">
      <?php if (!empty($eyebrow)): ?><span class="rx-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
      <h1 class="rx-hero-title">
        <?= e($heading ?? '') ?>
        <?php if (!empty($accentLine)): ?><br><em><?= e($accentLine) ?></em><?php endif; ?>
        <?php if (!empty($headingEnd)): ?><br><?= e($headingEnd) ?><?php endif; ?>
      </h1>
      <?php if (!empty($lead)): ?><p class="rx-hero-lead"><?= nl2br(e($lead)) ?></p><?php endif; ?>
      <div class="rx-actions">
        <?php if (!empty($btnText)): ?><a class="rx-btn rx-btn-primary" href="<?= e(slate_safe_url($btnHref ?? '#')) ?>"><?= e($btnText) ?></a><?php endif; ?>
        <?php if (!empty($btn2Text)): ?><a class="rx-btn rx-btn-ghost" href="<?= e(slate_safe_url($btn2Href ?? '#')) ?>"><?= e($btn2Text) ?></a><?php endif; ?>
      </div>
      <?php if (!empty($rating)): ?>
        <div class="rx-trust"><span class="rx-stars">★★★★★</span><small><?= e($rating) ?></small></div>
      <?php endif; ?>
    </div>
    <?php if (!empty($cardTitle) || !empty($cardBadge)): ?>
    <aside class="rx-hero-card">
      <?php if (!empty($cardBadge)): ?><span class="rx-hero-badge"><?= e($cardBadge) ?></span><?php endif; ?>
      <div class="rx-hero-plate">
        <div>
          <?php if (!empty($cardTitle)): ?><b><?= e($cardTitle) ?></b><?php endif; ?>
          <?php if (!empty($cardNote)): ?><span><?= e($cardNote) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($cardPrice)): ?><div class="rx-hero-price"><?= e($cardPrice) ?></div><?php endif; ?>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</section>
