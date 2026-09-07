<?php
/** Hero — split (image+text) or banner (text over bg image).
 * @var string $heading @var string $subheading @var string $btnText @var string $btnHref
 * @var string $btn2Text @var string $btn2Href @var string $image @var string $layout
 * @var string $align @var string $overlay @var string $height @var string $mediaSide @var string $eyebrow
 */
$layout = $layout ?? 'banner'; $layout = in_array($layout, ['split','banner'], true) ? $layout : 'banner';
$overlay = $overlay ?? 'medium'; $overlay = in_array($overlay, ['light','medium','dark'], true) ? $overlay : 'medium';
$height = $height ?? 'normal'; $height = in_array($height, ['short','normal','tall'], true) ? $height : 'normal';
$mediaSide = ($mediaSide ?? 'left') === 'right' ? 'right' : 'left';
$hasImg  = !empty($image);
$pad = $pad ?? 'normal'; $pad = in_array($pad, ['compact', 'normal', 'spacious'], true) ? $pad : 'normal';

if ($layout === 'split' && $hasImg):
    $cls = 'cb-hero cb-hero-split cb-hero-media-'.$mediaSide;
?>
<section class="cb-pad-<?= $pad ?> <?= $cls ?>">
    <div class="cb-hero-inner">
        <div class="cb-hero-media"><img src="<?= e(ContentBuilderAPI::mediaUrl($image)) ?>" alt="<?= e($heading ?? '') ?>"></div>
        <div class="cb-hero-body">
            <?php if (!empty($eyebrow)): ?><span class="cb-eyebrow" style="text-align:left"><?= e($eyebrow) ?></span><?php endif; ?>
            <?php if (!empty($heading)): ?><h1 class="cb-hero-title"><?= e($heading) ?></h1><?php endif; ?>
            <?php if (!empty($subheading)): ?><p class="cb-hero-sub"><?= e($subheading) ?></p><?php endif; ?>
            <div class="cb-hero-actions">
                <?php if (!empty($btnText)): ?><a class="cb-btn cb-btn-primary" href="<?= e(slate_safe_url($btnHref ?? '#')) ?>"><?= e($btnText) ?></a><?php endif; ?>
                <?php if (!empty($btn2Text)): ?><a class="cb-btn cb-btn-outline" href="<?= e(slate_safe_url($btn2Href ?? '#')) ?>"><?= e($btn2Text) ?></a><?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php else:
    $cls = 'cb-hero cb-hero-banner cb-hero-h-'.$height;
    if ($hasImg) { $cls .= ' cb-overlay-'.$overlay; }
    else         { $cls .= ' cb-hero-plain'; }   // no image → readable dark text, not white-on-white
    $style = $hasImg ? ' style="background-image:url(\''.e(ContentBuilderAPI::mediaUrl($image)).'\')"' : '';
?>
<section class="cb-pad-<?= $pad ?> <?= $cls ?>"<?= $style ?>>
    <div class="cb-hero-inner">
        <?php if (!empty($eyebrow)): ?><span class="cb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h1 class="cb-hero-title"><?= e($heading) ?></h1><?php endif; ?>
        <?php if (!empty($subheading)): ?><p class="cb-hero-sub"><?= e($subheading) ?></p><?php endif; ?>
        <div class="cb-hero-actions">
            <?php if (!empty($btnText)): ?><a class="cb-btn cb-btn-primary" href="<?= e(slate_safe_url($btnHref ?? '#')) ?>"><?= e($btnText) ?></a><?php endif; ?>
            <?php if (!empty($btn2Text)): ?><a class="cb-btn cb-btn-outline" href="<?= e(slate_safe_url($btn2Href ?? '#')) ?>"<?= $hasImg ? ' style="color:#fff;box-shadow:inset 0 0 0 1.5px #fff"' : '' ?>><?= e($btn2Text) ?></a><?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
