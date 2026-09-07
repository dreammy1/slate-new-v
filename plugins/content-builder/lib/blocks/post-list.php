<?php
/**
 * post-list block — renders a dynamic list of published posts.
 * @var string $postType @var string $taxonomy @var string $term @var string $limit
 */
$type  = trim($postType ?? 'post') ?: 'post';
$limit = max(1, min(50, (int)($limit ?? 6)));

$args = ['status' => 'published', 'limit' => $limit];
if (!empty($taxonomy) && !empty($term)) {
    $t = ContentBuilderAPI::getTermBySlug($taxonomy, ContentBuilderAPI::slugify($term));
    if ($t) $args['term_id'] = (int)$t['id'];
}
$posts = ContentBuilderAPI::listPosts($type, $args);
if (!$posts) {
    echo '<p class="cb-empty text-muted">No posts found.</p>';
    return;
}
?>
<ul class="cb-post-list">
    <?php foreach ($posts as $p): ?>
        <li class="cb-post-list-item">
            <a href="<?= e(ContentBuilderAPI::publicUrl($type, $p['slug'])) ?>">
                <?= e($p['title'] ?: '(untitled)') ?>
            </a>
            <?php if (!empty($p['excerpt'])): ?>
                <p class="cb-post-list-excerpt"><?= e($p['excerpt']) ?></p>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
</ul>
