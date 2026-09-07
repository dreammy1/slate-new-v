<?php /** @var string $heading @var string $text @var string $btnText @var string $btnHref @var string $eyebrow @var string $btnColor @var string $btnTextColor */ ?>
<?php $pad = $pad ?? "normal"; $pad = in_array($pad, ["compact","normal","spacious"], true) ? $pad : "normal"; ?>
<section class="cb-pad-<?= $pad ?> cb-cta">
    <div class="cb-cta-inner">
        <?php if (!empty($eyebrow)): ?><span class="cb-eyebrow" style="color:rgba(255,255,255,.85)"><?= e($eyebrow) ?></span><?php endif; ?>
        <?php if (!empty($heading)): ?><h2 class="cb-cta-title"><?= e($heading) ?></h2><?php endif; ?>
        <?php if (!empty($text)): ?><p class="cb-cta-text"><?= e($text) ?></p><?php endif; ?>
        <?php if (!empty($btnText)): 
            $btnStyle = '';
            if (!empty($btnColor)) $btnStyle .= 'background-color:' . e($btnColor) . ';border-color:' . e($btnColor) . ';';
            if (!empty($btnTextColor)) $btnStyle .= 'color:' . e($btnTextColor) . ';';
        ?>
            <a class="cb-btn cb-btn-primary" href="<?= e(slate_safe_url($btnHref ?? '#')) ?>"<?= $btnStyle ? ' style="'.$btnStyle.'"' : '' ?>><?= e($btnText) ?></a>
        <?php endif; ?>
    </div>
</section>

