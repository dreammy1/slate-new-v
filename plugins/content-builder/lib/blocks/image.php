<?php
/**
 * @var array  $media  keyed reference: ['key'=>…, 'alt'=>…, 'focal'=>[x,y]]
 * @var string $src    deprecated raw URL — still read, never newly written
 * @var string $alt    alt that accompanied the raw URL
 * @var string $width
 *
 * ADR-0013 point 4: media is referenced by logical key and resolved at render
 * time, so a document renders correctly under either renderer on either host.
 */
$m = ContentBuilderAPI::resolveMedia($media ?? null, (string)($src ?? ''), (string)($alt ?? ''));
if ($m['url'] === '') return;

$wv = $width ?? 'full'; $w = in_array($wv, ['full','wide','normal'], true) ? $wv : 'full';

// A focal point is only meaningful once something crops the image; emitting it
// as object-position costs nothing and makes the value usable by CSS today.
$style = $m['focal'] !== null
    ? sprintf(' style="object-position:%.2f%% %.2f%%"', $m['focal'][0] * 100, $m['focal'][1] * 100)
    : '';
?>
<figure class="cb-image cb-image-<?= e($w) ?>">
    <img src="<?= e(slate_safe_url($m['url'])) ?>" alt="<?= e($m['alt']) ?>" loading="lazy"<?= $style ?>>
</figure>
