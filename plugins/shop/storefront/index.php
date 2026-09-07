<?php
/**
 * Shop storefront — catalog (product listing).
 *
 * Lives at /shop/index.php (URL: /shop/).
 * Resolves Slate root by walking up the path.
 */

// Find Slate root
$root = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
if (!file_exists($root . '/config.php')) {
    // fallback: walk up
    $dir = __DIR__;
    while ($dir !== '/' && !file_exists($dir . '/config.php')) {
        $dir = dirname($dir);
    }
    $root = $dir;
}
require $root . '/config.php';

if (!class_exists('ShopAPI') || !PluginLoader::isActive('shop')) {
    http_response_code(503);
    echo '<h1>Shop unavailable</h1><p>The Shop plugin is not active.</p>';
    exit;
}

require __DIR__ . '/includes/layout.php';

$search = trim((string)($_GET['q'] ?? ''));
$sort   = (string)($_GET['sort'] ?? 'newest');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

$filters = [];
if ($search !== '') $filters['search'] = $search;
$filters['sort'] = $sort;

$total = ShopAPI::publishedProductCount($filters);
$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$products = ShopAPI::publishedProducts($filters, $perPage, $offset);
$featured = $page === 1 && $search === ''
    ? ShopAPI::publishedProducts(['featured' => 1, 'sort' => 'featured'], 4)
    : [];

sf_header('Shop');
?>

<?php // Hero — only shown on the catalog landing (no search, page 1)
if ($search === '' && $page === 1):
    // Pull a fun stat from settings if the admin filled it in;
    // otherwise show generic copy.
    $tagline = Database::setting('shop.tagline')
        ?: 'Small-batch peanut brittle, hand-stirred in small kettles. Over 20 flavors and counting.';
?>
<section class="sf-hero" aria-labelledby="hero-title">
    <div class="sf-hero-eyebrow">Hand-crafted · Small batch</div>
    <h1 class="sf-hero-title" id="hero-title">
        Sweet, savory, &amp; <em>seriously</em> good.
    </h1>
    <p class="sf-hero-sub"><?= e($tagline) ?></p>
    <div class="sf-hero-actions">
        <a href="#all-products" class="sf-btn sf-btn-primary">Shop the collection</a>
        <?php
        // If there's a "best sellers" or similar category, link to it
        // as a secondary CTA. Otherwise, link to the first non-empty
        // category. If neither, omit the secondary button.
        $featuredCat = null;
        foreach (ShopAPI::categories(true) as $c) {
            if ((int)$c['product_count'] === 0) continue;
            $featuredCat = $c;
            break;
        }
        if ($featuredCat): ?>
            <a href="<?= e(SLATE_URL) ?>/shop/category/<?= e($featuredCat['slug']) ?>"
               class="sf-btn"><?= e($featuredCat['name']) ?> →</a>
        <?php endif; ?>
    </div>
</section>
<?php else: ?>
    <h1 class="sf-h1">
        <?php if ($search !== ''): ?>
            Search results
        <?php else: ?>
            All products
        <?php endif; ?>
    </h1>
    <p class="sf-sub">
        <?php if ($search !== ''): ?>
            <?= $total ?> result<?= $total === 1 ? '' : 's' ?> for "<?= e($search) ?>"
        <?php else: ?>
            Page <?= (int)$page ?> of <?= (int)$pages ?>
        <?php endif; ?>
    </p>
<?php endif; ?>

<!-- Search + sort -->
<form method="get" class="sf-card" id="all-products"
      style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:24px;">
    <div class="sf-field" style="flex:2; min-width:200px; margin:0;">
        <label class="sf-label" for="q">Search</label>
        <input type="search" id="q" name="q" class="sf-input"
               value="<?= e($search) ?>" placeholder="Search products…">
    </div>
    <div class="sf-field" style="flex:1; min-width:160px; margin:0;">
        <label class="sf-label" for="sort">Sort by</label>
        <select id="sort" name="sort" class="sf-select">
            <option value="newest"     <?= $sort === 'newest'     ? 'selected' : '' ?>>Newest</option>
            <option value="featured"   <?= $sort === 'featured'   ? 'selected' : '' ?>>Featured</option>
            <option value="name"       <?= $sort === 'name'       ? 'selected' : '' ?>>Name (A–Z)</option>
            <option value="price-asc"  <?= $sort === 'price-asc'  ? 'selected' : '' ?>>Price (low to high)</option>
            <option value="price-desc" <?= $sort === 'price-desc' ? 'selected' : '' ?>>Price (high to low)</option>
        </select>
    </div>
    <button type="submit" class="sf-btn sf-btn-primary">Apply</button>
</form>

<?php if (!empty($featured)): ?>
    <h2 class="sf-section-heading">Featured</h2>
    <div class="sf-grid" style="margin-bottom: 36px;">
        <?php foreach ($featured as $p) sf_product_card($p); ?>
    </div>
    <h2 class="sf-section-heading">All products</h2>
<?php endif; ?>

<?php if (empty($products)): ?>
    <div class="sf-card" style="text-align:center; padding: 60px 20px;">
        <div class="sf-placeholder" style="width:56px;height:56px;border-radius:14px;background:var(--bg-2);display:grid;place-items:center;margin:0 auto 14px;color:var(--subtle);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" style="width:28px;height:28px;">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                <path d="M3.27 6.96L12 12l8.73-5.04M12 22V12"/>
            </svg>
        </div>
        <h2 class="sf-h2" style="margin:0;">No products found</h2>
        <p class="sf-sub" style="margin: 8px 0 0;">
            <?= $search !== '' ? 'Try a different search term.'
                : 'Check back soon — new products are coming!' ?>
        </p>
    </div>
<?php else: ?>
    <div class="sf-grid">
        <?php foreach ($products as $p) sf_product_card($p); ?>
    </div>

    <?php if ($pages > 1): ?>
    <div style="display:flex; justify-content:center; gap:8px; margin-top:32px;">
        <?php
        $qs = $_GET;
        for ($i = 1; $i <= $pages; $i++):
            $qs['page'] = $i;
            $active = $i === $page;
        ?>
            <a href="?<?= e(http_build_query($qs)) ?>"
               class="sf-btn <?= $active ? 'sf-btn-primary' : '' ?>"
               style="padding: 6px 14px;"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php sf_footer(); ?>
