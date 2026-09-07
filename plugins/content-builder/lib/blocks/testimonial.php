<?php /** @var string $quote @var string $author @var string $role @var string $avatar */ ?>
<?php $pad = $pad ?? "normal"; $pad = in_array($pad, ["compact","normal","spacious"], true) ? $pad : "normal"; ?>
<section class="cb-pad-<?= $pad ?> cb-testimonial">
    <figure class="cb-testimonial-inner">
        <blockquote class="cb-testimonial-quote">&ldquo;<?= e($quote ?? '') ?>&rdquo;</blockquote>
        <figcaption class="cb-testimonial-cite">
            <?php if (!empty($avatar)): ?><img class="cb-testimonial-avatar" src="<?= e(ContentBuilderAPI::mediaUrl($avatar)) ?>" alt=""><?php endif; ?>
            <span>
                <span class="cb-testimonial-author"><?= e($author ?? '') ?></span>
                <?php if (!empty($role)): ?><span class="cb-testimonial-role"><?= e($role) ?></span><?php endif; ?>
            </span>
        </figcaption>
    </figure>
</section>
