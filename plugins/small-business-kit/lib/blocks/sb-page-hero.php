<?php
/** sb-page-hero — compact hero for sub-pages.
 *
 * Smaller height (~52vh), breadcrumb chip ("Home / X"), big heading,
 * lede, optional single CTA. Same dark overlay treatment as sb-hero
 * but no feature strip, no two-button row.
 *
 * @var string $crumb1    breadcrumb left text   (default "Home")
 * @var string $crumb1Url breadcrumb left href   (default /p/home)
 * @var string $crumb2    breadcrumb right text  (current page)
 * @var string $heading   big H1
 * @var string $lede      paragraph
 * @var string $image     bg photo
 * @var string $btnText, $btnHref
 */
$crumb1    = trim($crumb1 ?? 'Home');
$crumb1Url = trim($crumb1Url ?? '');
if ($crumb1Url === '') $crumb1Url = rtrim(SLATE_URL, '/') . '/p/home';
$crumb2    = trim($crumb2 ?? '');
$heading   = trim($heading ?? '');
$lede      = trim($lede ?? '');
$image     = trim($image ?? '');
$bg        = $image !== '' ? "style=\"background-image:url('" . e(ContentBuilderAPI::mediaUrl($image)) . "')\"" : '';
$arrow     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
?>
<section class="sb-page-hero"<?= SBKBlocks::styleAttr(['headingSize'=>$headingSize??'','headingWeight'=>$headingWeight??''], 'h1') ?>>
  <div class="sb-page-hero-bg" <?= $bg ?>></div>
  <div class="sb-page-hero-inner">
    <?php if ($crumb1 !== '' || $crumb2 !== ''): ?>
      <div class="sb-crumbs">
        <?php if ($crumb1 !== ''): ?>
          <a href="<?= e($crumb1Url) ?>"><?= e($crumb1) ?></a>
        <?php endif; ?>
        <?php if ($crumb2 !== ''): ?>
          <span class="sb-crumb-sep">/</span>
          <span class="sb-crumb-current"><?= e($crumb2) ?></span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($heading !== ''): ?><h1><?= e($heading) ?></h1><?php endif; ?>
    <?php if ($lede !== ''): ?><p class="sb-page-hero-lede"><?= e($lede) ?></p><?php endif; ?>
    <?php if (!empty($btnText)): ?>
      <a class="sb-btn sb-btn-primary sb-page-hero-cta" href="<?= e($btnHref ?? '#') ?>"><?= e($btnText) ?> <?= $arrow ?></a>
    <?php endif; ?>
  </div>
</section>
