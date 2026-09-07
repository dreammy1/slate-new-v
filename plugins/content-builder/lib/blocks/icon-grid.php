<?php
/** icon-box grid. @var string $heading @var string $eyebrow @var array $items @var string $bg @var string $cols */
$items = is_array($items ?? null) ? $items : [];
$bg = $bg ?? 'white'; $bg = in_array($bg, ['page','white','tint','tint2','dark'], true) ? $bg : 'white';
$cols = (string)($cols ?? '3'); $cols = in_array($cols, ['2','3','4'], true) ? $cols : '3';
$icons = [
    'star'=>'<path d="M12 2l3 7 7 .8-5.4 4.8L18 22l-6-3.5L6 22l1.4-7.4L2 9.8 9 9z"/>',
    'box'=>'<path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v9"/>',
    'truck'=>'<path d="M1 3h15v13H1z"/><path d="M16 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'check'=>'<path d="M20 6L9 17l-5-5"/>',
    'heart'=>'<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/>',
    'bolt'=>'<path d="M13 2L3 14h7l-1 8 10-12h-7z"/>',
    'shield'=>'<path d="M12 3l8 4v6c0 5-3.5 9-8 10-4.5-1-8-5-8-10V7z"/>',
    'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'leaf'=>'<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10z"/><path d="M2 21c0-3 1.85-5.36 5.08-6"/>',
    'gift'=>'<rect x="3" y="8" width="18" height="4"/><path d="M12 8v13M5 12v9h14v-9M12 8a3 3 0 1 0-3-3M12 8a3 3 0 1 1 3-3"/>',
];
?>
<?php $pad = $pad ?? "normal"; $pad = in_array($pad, ["compact","normal","spacious"], true) ? $pad : "normal"; ?>
<section class="cb-pad-<?= $pad ?> cb-section cb-icon-grid cb-bg-<?= $bg ?>">
    <div class="cb-section-inner">
        <?php if (!empty($eyebrow)): ?><span class="cb-eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h2 class="cb-section-title"><?= e($heading) ?></h2><div class="cb-divider"></div><?php endif; ?>
        <div class="cb-grid cb-grid-<?= $cols ?>">
            <?php foreach ($items as $it):
                $icon = $it['icon'] ?? 'star'; $path = $icons[$icon] ?? $icons['star']; ?>
                <div class="cb-card">
                    <span class="cb-card-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" width="26" height="26"><?= $path ?></svg>
                    </span>
                    <?php if (!empty($it['title'])): ?><h3 class="cb-card-title"><?= e($it['title']) ?></h3><?php endif; ?>
                    <?php if (!empty($it['text'])): ?><p class="cb-card-text"><?= e($it['text']) ?></p><?php endif; ?>
                    <?php if (!empty($it['linkText']) && !empty($it['linkHref'])): ?>
                        <a class="cb-card-link" href="<?= e(slate_safe_url($it['linkHref'] ?? '#')) ?>"><?= e($it['linkText']) ?> &rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
