<?php
/**
 * Shop storefront — category listing.
 *
 * URL: /shop/category/<slug>
 */

$root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(dirname(__DIR__));
if (!file_exists($root . '/config.php')) {
    $dir = __DIR__;
    while ($dir !== '/' && !file_exists($dir . '/config.php')) $dir = dirname($dir);
    $root = $dir;
}
require $root . '/config.php';

if (!class_exists('ShopAPI') || !PluginLoader::isActive('shop')) {
    http_response_code(503);
    echo '<h1>Shop unavailable</h1>';
    exit;
}

require __DIR__ . '/includes/layout.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$category = $slug !== '' ? ShopAPI::categoryBySlug($slug) : null;

if (!$category) {
    http_response_code(404);
    sf_header('Category not found');
    echo '<h1 class="sf-h1">Category not found</h1>';
    echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Browse all products</a>';
    sf_footer(); exit;
}

$sort = (string)($_GET['sort'] ?? 'newest');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

$filters = ['category_id' => (int)$category['id'], 'sort' => $sort];
$total = ShopAPI::publishedProductCount($filters);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$products = ShopAPI::publishedProducts($filters, $perPage, ($page - 1) * $perPage);

sf_header($category['name']);
?>

<div style="font-size:13px; color:var(--text-muted); margin-bottom:16px;">
    <a href="<?= e(SLATE_URL) ?>/shop/">Shop</a> / <span><?= e($category['name']) ?></span>
</div>

<h1 class="sf-h1"><?= e($category['name']) ?></h1>
<p class="sf-sub">
    <?php if (!empty($category['description'])): ?>
        <?= e($category['description']) ?>
    <?php else: ?>
        <?= $total ?> product<?= $total === 1 ? '' : 's' ?> in this category
    <?php endif; ?>
</p>

<form method="get" style="margin-bottom:24px;">
    <select name="sort" class="sf-select" onchange="this.form.submit()"
            style="max-width:240px; display:inline-block;">
        <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Sort: Newest</option>
        <option value="name"       <?= $sort === 'name'       ? 'selected' : '' ?>>Sort: Name (A–Z)</option>
        <option value="price-asc"  <?= $sort === 'price-asc'  ? 'selected' : '' ?>>Sort: Price (low to high)</option>
        <option value="price-desc" <?= $sort === 'price-desc' ? 'selected' : '' ?>>Sort: Price (high to low)</option>
    </select>
</form>

<?php if (empty($products)): ?>
    <div class="sf-card" style="text-align:center; padding:60px 20px;">
        <p>No products in this category yet.</p>
    </div>
<?php else: ?>
    <div class="sf-grid">
        <?php foreach ($products as $p) sf_product_card($p); ?>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex; justify-content:center; gap:8px; margin-top:32px;">
        <?php $qs = $_GET;
        for ($i = 1; $i <= $pages; $i++):
            $qs['page'] = $i; $active = $i === $page; ?>
            <a href="?<?= e(http_build_query($qs)) ?>"
               class="sf-btn <?= $active ? 'sf-btn-primary' : '' ?>"
               style="padding:6px 14px;"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php sf_footer(); ?>
