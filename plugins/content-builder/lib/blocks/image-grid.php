<?php
/** image-box grid. @var string $heading @var string $eyebrow @var array $items @var string $bg @var string $cols */
$items = is_array($items ?? null) ? $items : [];
$bg = $bg ?? 'tint'; $bg = in_array($bg, ['page','white','tint','tint2','dark'], true) ? $bg : 'tint';
$cols = (string)($cols ?? '3'); $cols = in_array($cols, ['2','3','4'], true) ? $cols : '3';
?>
<?php $pad = $pad ?? "normal"; $pad = in_array($pad, ["compact","normal","spacious"], true) ? $pad : "normal"; ?>
<section class="cb-pad-<?= $pad ?> cb-section cb-image-grid cb-bg-<?= $bg ?>">
    <div class="cb-section-inner">
        <?php if (!empty($eyebrow)): ?><span class="cb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h2 class="cb-section-title"><?= e($heading) ?></h2><div class="cb-divider"></div><?php endif; ?>
        <div class="cb-grid cb-grid-<?= $cols ?>">
            <?php foreach ($items as $it):
                $href = trim((string)($it['href'] ?? ''));
                $tag = $href !== '' ? 'a' : 'div'; $attr = $href !== '' ? ' href="'.e(slate_safe_url($href)).'"' : ''; ?>
                <<?= $tag ?> class="cb-image-card"<?= $attr ?>>
                    <?php if (!empty($it['image'])): ?><img class="cb-image-card-img" src="<?= e(ContentBuilderAPI::mediaUrl($it['image'])) ?>" alt="<?= e($it['title'] ?? '') ?>" loading="lazy"><?php else: ?><span class="cb-image-card-ph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" width="30" height="30"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.6"/><path d="M21 15l-5-5L5 21"/></svg></span><?php endif; ?>
                    <div class="cb-image-card-body">
                        <?php if (!empty($it['title'])): ?><h3 class="cb-image-card-title"><?= e($it['title']) ?></h3><?php endif; ?>
                        <?php if (!empty($it['text'])): ?><p class="cb-image-card-text"><?= e($it['text']) ?></p><?php endif; ?>
                    </div>
                </<?= $tag ?>>
            <?php endforeach; ?>
        </div>
    </div>
</section>
