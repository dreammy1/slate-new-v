<?php
/** sb-hero — full-bleed hero photo + big copy. The header is GLOBAL
 *  (rendered by SBKHeader on every page), so this block focuses on
 *  the hero content + the optional bottom feature strip.
 *
 * @var string $align        center | left
 * @var string $tall         '1' = full viewport (100vh)
 * @var string $eyebrow      small pill above heading (optional)
 * @var string $heading      main h1 line
 * @var string $accentLine   word(s) in accent color
 * @var string $lede         paragraph below heading
 * @var string $image        background photo
 * @var string $btnText, $btnHref, $btn2Text, $btn2Href
 * @var array  $features     repeater [{text:''}] — feature strip pinned bottom
 */
$align    = ($align ?? 'left') === 'center' ? '' : 'sb-hero-left';
$tall     = !empty($tall) ? 'sb-hero-tall' : '';
$eyebrow  = trim($eyebrow ?? '');
$heading  = trim($heading ?? '');
$accent   = trim($accentLine ?? '');
$lede     = trim($lede ?? '');
$image    = trim($image ?? '');
$bg       = $image !== '' ? "style=\"background-image:url('" . e(ContentBuilderAPI::mediaUrl($image)) . "')\"" : '';
$arrow    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$star     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9z"/></svg>';
$features = is_array($features ?? null) ? $features : [];

// Per-element sizing/weight overrides (Style tab). Empty = theme default.
$h1sz = ['s'=>'clamp(30px,5vw,52px)','l'=>'clamp(40px,7.5vw,84px)','xl'=>'clamp(44px,8.5vw,104px)'];
$ldsz = ['s'=>'clamp(14px,1.3vw,16px)','l'=>'clamp(18px,1.9vw,23px)'];
$sv = '';
$hs = (string)($h1Size ?? '');   if ($hs !== '' && isset($h1sz[$hs])) $sv .= '--sbk-hero-h1-size:'.$h1sz[$hs].';';
$hw = preg_replace('/[^0-9]/','',(string)($h1Weight ?? '')); if ($hw !== '') $sv .= '--sbk-hero-h1-weight:'.$hw.';';
$ls = (string)($ledeSize ?? ''); if ($ls !== '' && isset($ldsz[$ls])) $sv .= '--sbk-hero-lede-size:'.$ldsz[$ls].';';
// Button shape + size overrides.
$brad = ['rounded'=>'14px','pill'=>'999px','square'=>'6px'];
$bpad = ['s'=>'11px 20px','l'=>'17px 32px']; $bfs = ['s'=>'14px','l'=>'17px'];
$bsh = (string)($btnShape ?? ''); if ($bsh !== '' && isset($brad[$bsh])) $sv .= '--sb-btn-radius:'.$brad[$bsh].';';
$bsz = (string)($btnSize ?? '');  if ($bsz !== '' && isset($bpad[$bsz])) $sv .= '--sbk-btn-pad:'.$bpad[$bsz].';--sbk-btn-fs:'.$bfs[$bsz].';';
$secStyle = $sv !== '' ? ' style="'.e($sv).'"' : '';
?>
<section class="sb-hero sb <?= $align ?> <?= $tall ?>"<?= $secStyle ?>>
  <div class="sb-hero-bg" <?= $bg ?>></div>
  <div class="sb-hero-inner">
    <?php if ($eyebrow !== ''): ?>
      <span class="sb-eyebrow-pill"><?= $star ?> <?= e($eyebrow) ?></span>
    <?php endif; ?>
    <?php if ($heading !== '' || $accent !== ''): ?>
      <h1><?= e($heading) ?> <?php if ($accent !== ''): ?><em><?= e($accent) ?></em><?php endif; ?></h1>
    <?php endif; ?>
    <?php if ($lede !== ''): ?><p class="sb-lede"><?= e($lede) ?></p><?php endif; ?>
    <?php if (!empty($btnText) || !empty($btn2Text)): ?>
      <div class="sb-hero-actions">
        <?php if (!empty($btnText)): ?>
          <a class="sb-btn sb-btn-primary" href="<?= e($btnHref ?? '#') ?>"><?= e($btnText) ?> <?= $arrow ?></a>
        <?php endif; ?>
        <?php if (!empty($btn2Text)): ?>
          <a class="sb-btn sb-btn-ghost" href="<?= e($btn2Href ?? '#') ?>"><?= e($btn2Text) ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($features): ?>
    <div class="sb-hero-features">
      <div class="sb-hero-features-inner">
        <?php foreach ($features as $f): if (empty($f['text'])) continue; ?>
          <div class="sb-hero-feature">
            <span class="sb-hero-feature-dot">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18l6-6-6-6"/></svg>
            </span>
            <span><?= e($f['text']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
</section>
