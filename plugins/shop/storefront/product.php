<?php
/**
 * Shop storefront — product detail.
 *
 * URL: /shop/product/<slug>
 * Served via .htaccess RewriteRule into this file.
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
if ($slug === '') {
    http_response_code(404);
    sf_header('Not found');
    echo '<h1 class="sf-h1">Not found</h1><p class="sf-sub">No product specified.</p>';
    sf_footer(); exit;
}

$product = ShopAPI::productBySlug($slug);
if (!$product) {
    http_response_code(404);
    sf_header('Not found');
    echo '<h1 class="sf-h1">Product not found</h1>';
    echo '<p class="sf-sub">The product you\'re looking for doesn\'t exist or has been removed.</p>';
    echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Browse products</a>';
    sf_footer(); exit;
}

$variants = ShopAPI::variantsFor((int)$product['id']);

// Group variants by attribute
$variantsByAttr = [];
foreach ($variants as $v) {
    $variantsByAttr[$v['attribute']][] = $v;
}

// Handle "add to cart" POST
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'add_to_cart') {
    if (!sf_csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please try again.'];
    } else {
        $sid = sf_session_id();
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        $variantId = !empty($_POST['variant_id']) ? (int)$_POST['variant_id'] : null;
        $res = ShopAPI::addToCart($sid, (int)$product['id'], $qty, $variantId);
        if ($res['ok']) {
            $flash = ['type' => 'success', 'msg' => 'Added to cart.'];
        } else {
            $flash = ['type' => 'error', 'msg' => $res['error']];
        }
    }
}

$hasSale = !empty($product['sale_price']) && (float)$product['sale_price'] > 0
        && (float)$product['sale_price'] < (float)$product['price'];
$inStock = !$product['manage_stock'] || (int)$product['stock_qty'] > 0;
$imgUrl = sf_image_url($product['image_url'] ?? null);

$category = !empty($product['category_id']) ? ShopAPI::category((int)$product['category_id']) : null;

// Compute savings % for the "Save N%" badge
$savePct = null;
if ($hasSale) {
    $diff = (float)$product['price'] - (float)$product['sale_price'];
    $savePct = (int)round(($diff / (float)$product['price']) * 100);
}

// Smart short_desc: skip rendering if it's a truncation of description
// (so we don't show "...words wafts through the air..." next to the full
// text right below it in the Description tab). Detection works by:
//   1. short_desc is significantly shorter than description, OR
//   2. short_desc starts with the same content as description
// Either signal means short_desc adds no information.
$lede = sf_clean_blurb((string)($product['short_desc'] ?? ''));
$descFull = (string)($product['description'] ?? '');
if ($lede !== '' && $descFull !== '') {
    // Compare the first 100 chars of both, stripped of trailing ellipsis-like
    // characters. Use a generous comparison window so we catch the case where
    // short_desc is the first sentence of description.
    $cleanLede = rtrim($lede, " \t\n\r\0\x0B.…\x85");
    $cleanDesc = rtrim($descFull, " \t\n\r\0\x0B.…\x85");
    if (strlen($cleanLede) > 20 && strlen($cleanDesc) > strlen($cleanLede)) {
        // Compare the first N bytes (whichever is shorter). If the lede
        // matches the start of the description, hide it.
        $compareLen = min(strlen($cleanLede), 200);
        if (strncmp($cleanDesc, $cleanLede, $compareLen) === 0) {
            $lede = '';
        }
    }
}

// Stock pill state
$stockManaged = !empty($product['manage_stock']);
$stockN       = (int)$product['stock_qty'];
$stockState   = !$inStock ? 'out'
              : ($stockManaged && $stockN <= 5 ? 'low' : 'in');
$stockLabel   = !$inStock ? 'Out of stock'
              : ($stockManaged && $stockN <= 5
                  ? "Only $stockN left — order soon"
                  : ($stockManaged
                      ? "In stock — ready to ship"
                      : 'In stock — ready to ship'));

sf_header(sf_clean_name($product['name']));
?>

<nav class="sf-breadcrumb" aria-label="Breadcrumb">
    <a href="<?= e(SLATE_URL) ?>/shop/">Shop</a>
    <span class="sf-breadcrumb-sep">/</span>
    <?php if ($category): ?>
        <a href="<?= e(SLATE_URL) ?>/shop/category/<?= e($category['slug']) ?>"><?= e($category['name']) ?></a>
        <span class="sf-breadcrumb-sep">/</span>
    <?php endif; ?>
    <span><?= e(sf_clean_name($product['name'])) ?></span>
</nav>

<?php
// Assemble the full image list: main + gallery
$galleryImages = [];
if ($imgUrl) $galleryImages[] = $imgUrl;
$galleryStored = (string)($product['gallery_urls'] ?? '');
if ($galleryStored !== '') {
    $decoded = json_decode($galleryStored, true);
    $items = is_array($decoded) ? $decoded
           : array_filter(array_map('trim', explode("\n", $galleryStored)));
    foreach ($items as $g) {
        $u = sf_image_url($g);
        if ($u && !in_array($u, $galleryImages, true)) {
            $galleryImages[] = $u;
        }
    }
}
?>

<div class="sf-product-detail">

    <!-- Gallery: main image + thumbnail strip -->
    <div class="sf-gallery-wrap">
        <div class="sf-product-gallery">
            <?php if (!empty($galleryImages)): ?>
                <img id="sf-gallery-main"
                     src="<?= e($galleryImages[0]) ?>"
                     alt="<?= e(sf_clean_name($product['name'])) ?>">
            <?php else: ?>
                <span class="sf-placeholder" style="width:80px;height:80px;border-radius:18px;background:var(--bg-2);display:grid;place-items:center;color:var(--subtle);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round" style="width:38px;height:38px;">
                        <path d="M16.5 9.4L7.5 4.21"/>
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <path d="M3.27 6.96L12 12l8.73-5.04M12 22V12"/>
                    </svg>
                </span>
            <?php endif; ?>
        </div>
        <?php if (count($galleryImages) > 1): ?>
            <div class="sf-gallery-thumbs" role="tablist" aria-label="Product images">
                <?php foreach ($galleryImages as $idx => $g): ?>
                    <button type="button"
                            class="sf-gallery-thumb<?= $idx === 0 ? ' is-active' : '' ?>"
                            data-img="<?= e($g) ?>"
                            aria-label="View image <?= $idx + 1 ?>">
                        <img src="<?= e($g) ?>" alt=""
                             onerror="this.closest('.sf-gallery-thumb').style.display='none'">
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Buy box -->
    <div class="sf-buybox">
        <?php if ($category): ?>
            <div class="sf-eyebrow"><?= e($category['name']) ?></div>
        <?php endif; ?>

        <h1 class="sf-h1" style="margin-bottom:8px;"><?= e(sf_clean_name($product['name'])) ?></h1>

        <!-- Rating placeholder (real reviews coming in a later release) -->
        <div class="sf-rating" aria-label="Rating: new product, no reviews yet">
            <span class="sf-rating-stars">
                <?php for ($i = 0; $i < 5; $i++): ?>
                    <svg class="empty" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <path d="M12 2l2.7 6.4L22 9l-5.4 4.5L18 22l-6-3.5L6 22l1.4-8.5L2 9l7.3-.6L12 2z" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linejoin="round"/>
                    </svg>
                <?php endfor; ?>
            </span>
            <span class="sf-rating-text is-new">New product</span>
        </div>

        <!-- Price -->
        <div class="sf-pdp-price">
            <?php if ($hasSale): ?>
                <span class="price-now is-sale"><?= e(sf_money((float)$product['sale_price'])) ?></span>
                <span class="price-was"><?= e(sf_money((float)$product['price'])) ?></span>
                <?php if ($savePct !== null && $savePct > 0): ?>
                    <span class="price-save">Save <?= $savePct ?>%</span>
                <?php endif; ?>
            <?php else: ?>
                <span class="price-now"><?= e(sf_money((float)$product['price'])) ?></span>
            <?php endif; ?>
        </div>

        <!-- Stock pill -->
        <div class="sf-stock-pill is-<?= e($stockState) ?>">
            <span class="dot" aria-hidden="true"></span>
            <span><?= e($stockLabel) ?></span>
        </div>

        <!-- Lede (only if it isn't just a truncation of description) -->
        <?php if ($lede !== ''): ?>
            <p class="sf-product-lede"><?= e($lede) ?></p>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="sf-flash sf-flash-<?= e($flash['type']) ?>" style="margin-top:18px;"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if ($inStock): ?>
            <form method="post" id="buyform">
                <?= sf_csrf_field() ?>
                <input type="hidden" name="_action" value="add_to_cart">

                <?php foreach ($variantsByAttr as $attribute => $opts): ?>
                <div class="sf-field" style="margin-top:18px;">
                    <label class="sf-label"><?= e($attribute) ?></label>
                    <select name="variant_id" class="sf-select" required>
                        <option value="">— Select <?= e(strtolower($attribute)) ?> —</option>
                        <?php
                        // A variant is "out of stock" only when:
                        //   1. The parent product manages stock, AND
                        //   2. The variant's own stock_qty is <= 0
                        // When manage_stock is off on the parent, variants
                        // inherit unlimited availability — otherwise a CSV
                        // import that sets variants with stock_qty=0 would
                        // make every variant appear out of stock, even on
                        // unlimited-stock products.
                        $parentManagesStock = !empty($product['manage_stock']);
                        foreach ($opts as $v):
                            $vPrice = ShopAPI::variantPrice($product, $v);
                            $vDiff = (float)$v['price_diff'];
                            $outV = $parentManagesStock && (int)$v['stock_qty'] <= 0;
                            $vImg = sf_image_url($v['image_url'] ?? null);
                        ?>
                            <option value="<?= (int)$v['id'] ?>"
                                    data-variant-image="<?= e($vImg) ?>"
                                    data-variant-desc="<?= e((string)($v['description'] ?? '')) ?>"
                                    <?= $outV ? 'disabled' : '' ?>>
                                <?= e($v['value']) ?>
                                <?php if ($vDiff != 0): ?>
                                    (<?= $vDiff > 0 ? '+' : '' ?><?= e(sf_money($vDiff)) ?>)
                                <?php endif; ?>
                                <?= $outV ? '— out of stock' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endforeach; ?>

                <!-- Quantity stepper + Add to cart side by side -->
                <div class="sf-buy-row">
                    <div class="sf-qty-stepper" role="group" aria-label="Quantity">
                        <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                        <input type="number" id="qty" name="qty" value="1" min="1"
                               max="<?= !empty($product['manage_stock']) ? (int)$product['stock_qty'] : 99 ?>"
                               aria-label="Quantity">
                        <button type="button" data-step="+1" aria-label="Increase quantity">+</button>
                    </div>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                             stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;">
                            <circle cx="9" cy="21" r="1"/>
                            <circle cx="20" cy="21" r="1"/>
                            <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>
                        </svg>
                        Add to cart — <?= e(sf_money(ShopAPI::effectivePrice($product))) ?>
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div class="sf-buy-row" style="margin-top:22px;">
                <button type="button" class="sf-btn sf-btn-primary" disabled
                        style="flex:1;cursor:not-allowed;opacity:0.5;">
                    Out of stock
                </button>
            </div>
        <?php endif; ?>

        <!-- Trust strip -->
        <div class="sf-trust-strip">
            <div class="sf-trust-item">
                <div class="sf-trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13" rx="1"/>
                        <path d="M16 8h4l3 3v5h-7z"/>
                        <circle cx="6" cy="19" r="2"/>
                        <circle cx="18" cy="19" r="2"/>
                    </svg>
                </div>
                <div class="sf-trust-text">
                    <div class="sf-trust-title">Free shipping</div>
                    <div class="sf-trust-sub">On orders over $75</div>
                </div>
            </div>
            <div class="sf-trust-item">
                <div class="sf-trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M3 12a9 9 0 0 1 15-6.7L21 8"/>
                        <path d="M21 3v5h-5"/>
                        <path d="M21 12a9 9 0 0 1-15 6.7L3 16"/>
                        <path d="M3 21v-5h5"/>
                    </svg>
                </div>
                <div class="sf-trust-text">
                    <div class="sf-trust-title">30-day returns</div>
                    <div class="sf-trust-sub">Easy & hassle-free</div>
                </div>
            </div>
            <div class="sf-trust-item">
                <div class="sf-trust-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                         stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <div class="sf-trust-text">
                    <div class="sf-trust-title">Secure checkout</div>
                    <div class="sf-trust-sub">SSL-encrypted payments</div>
                </div>
            </div>
        </div>

        <!-- SKU + meta -->
        <div class="sf-pdp-meta">
            <?php if (!empty($product['sku']) && strpos($product['sku'], 'auto-') !== 0): ?>
                <div><span class="meta-label">SKU</span><span class="meta-value"><?= e($product['sku']) ?></span></div>
            <?php endif; ?>
            <?php if ($category): ?>
                <div><span class="meta-label">Category</span><span class="meta-value" style="font-family:inherit;font-size:12px;color:var(--text-2);"><?= e($category['name']) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($product['weight']) && (float)$product['weight'] > 0):
                $wfmt = rtrim(rtrim(number_format((float)$product['weight'], 2), '0'), '.');
                $wunit = Database::setting('weight_unit') ?: 'oz';
            ?>
                <div><span class="meta-label">Weight</span><span class="meta-value"><?= e($wfmt . ' ' . $wunit) ?></span></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Lower tabbed section -->
<?php $hasDescription = !empty($product['description']); ?>
<div class="sf-tabs-card">
    <div class="sf-tab-strip" role="tablist">
        <?php if ($hasDescription): ?>
            <button type="button" class="sf-tab-btn is-active" role="tab" aria-selected="true" data-panel="desc">Description</button>
        <?php endif; ?>
        <button type="button" class="sf-tab-btn <?= !$hasDescription ? 'is-active' : '' ?>" role="tab" data-panel="details">Details</button>
        <button type="button" class="sf-tab-btn" role="tab" data-panel="reviews">Reviews</button>
    </div>

    <?php if ($hasDescription): ?>
    <div class="sf-tab-panel is-active" id="panel-desc" role="tabpanel">
        <div class="prose">
            <?php
            // Strip any HTML tags admins may have pasted from a rich
            // editor (e.g. <span style="..."> showing up as literal
            // text). Preserve newlines so nl2br can render them.
            ?>
            <?= nl2br(e(strip_tags((string)$product['description']))) ?>
        </div>
        <!-- Per-variant description — shown when a variant with its own
             description is selected. Hidden by default; populated by the
             variant <select>'s change handler below. -->
        <div id="sf-variant-desc" class="sf-variant-desc" hidden
             style="margin-top:1.25rem; padding:1rem; border-radius:8px; background:var(--bg-2); border-left:3px solid var(--accent);">
            <div class="sf-variant-desc-label" style="font-size:12px; font-weight:600; letter-spacing:0.04em; text-transform:uppercase; color:var(--text-2, #5a6b7e); margin-bottom:0.4rem;">
                <span id="sf-variant-desc-attr">About this variant</span>
            </div>
            <div id="sf-variant-desc-body" class="prose"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sf-tab-panel <?= !$hasDescription ? 'is-active' : '' ?>" id="panel-details" role="tabpanel">
        <h3>Product details</h3>
        <dl class="sf-details-list">
            <?php
            $weightUnit = Database::setting('weight_unit') ?: 'oz';
            $dimUnit    = Database::setting('dimension_unit') ?: 'in';
            $hasDim = !empty($product['dimensions_l']) || !empty($product['dimensions_w']) || !empty($product['dimensions_h']);
            ?>
            <?php if (!empty($product['sku']) && strpos($product['sku'], 'auto-') !== 0): ?>
                <dt>SKU</dt><dd><?= e($product['sku']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($product['gtin'])): ?>
                <dt>GTIN / UPC</dt><dd><?= e($product['gtin']) ?></dd>
            <?php endif; ?>
            <?php if ($category): ?>
                <dt>Category</dt><dd><?= e($category['name']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($product['weight']) && (float)$product['weight'] > 0): ?>
                <dt>Weight</dt><dd><?= e(rtrim(rtrim(number_format((float)$product['weight'], 2), '0'), '.')) ?> <?= e($weightUnit) ?></dd>
            <?php endif; ?>
            <?php if ($hasDim): ?>
                <dt>Dimensions</dt>
                <dd>
                    <?php
                    $parts = [];
                    foreach (['dimensions_l', 'dimensions_w', 'dimensions_h'] as $k) {
                        if (!empty($product[$k]) && (float)$product[$k] > 0) {
                            $parts[] = rtrim(rtrim(number_format((float)$product[$k], 2), '0'), '.');
                        }
                    }
                    echo e(implode(' × ', $parts) . ' ' . $dimUnit);
                    ?>
                </dd>
            <?php endif; ?>
            <?php if (!empty($product['tags'])): ?>
                <dt>Tags</dt><dd><?= e($product['tags']) ?></dd>
            <?php endif; ?>
            <dt>Availability</dt>
            <dd><?= $inStock ? 'In stock' : 'Out of stock' ?></dd>
        </dl>
    </div>

    <div class="sf-tab-panel" id="panel-reviews" role="tabpanel">
        <div class="sf-reviews-empty">
            <div class="sf-reviews-empty-stars" aria-hidden="true">
                <?php for ($i = 0; $i < 5; $i++): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"
                         stroke-linejoin="round">
                        <path d="M12 2l2.7 6.4L22 9l-5.4 4.5L18 22l-6-3.5L6 22l1.4-8.5L2 9l7.3-.6L12 2z"/>
                    </svg>
                <?php endfor; ?>
            </div>
            <div class="sf-reviews-empty-title">No reviews yet</div>
            <div class="sf-reviews-empty-sub">Be the first to share your experience with this product.</div>
            <button type="button" class="sf-btn" disabled style="opacity:0.5;cursor:not-allowed;">
                Write a review
            </button>
        </div>
    </div>
</div>

<script>
(function() {
    // Gallery thumbnail-click swap
    var galleryMain = document.getElementById('sf-gallery-main');
    if (galleryMain) {
        document.querySelectorAll('.sf-gallery-thumb').forEach(function(t) {
            t.addEventListener('click', function() {
                var src = t.getAttribute('data-img');
                if (!src) return;
                galleryMain.src = src;
                document.querySelectorAll('.sf-gallery-thumb').forEach(function(x) {
                    x.classList.remove('is-active');
                });
                t.classList.add('is-active');
            });
        });
    }

    // Quantity stepper
    var stepper = document.querySelector('.sf-qty-stepper');
    if (stepper) {
        var input = stepper.querySelector('input');
        var minV = parseInt(input.getAttribute('min') || '1', 10);
        var maxV = parseInt(input.getAttribute('max') || '99', 10);
        stepper.querySelectorAll('button[data-step]').forEach(function(b) {
            b.addEventListener('click', function() {
                var d = parseInt(b.getAttribute('data-step'), 10);
                var v = (parseInt(input.value, 10) || 1) + d;
                if (v < minV) v = minV;
                if (v > maxV) v = maxV;
                input.value = v;
            });
        });
    }

    // Tabs
    var tabs   = document.querySelectorAll('.sf-tab-btn');
    var panels = document.querySelectorAll('.sf-tab-panel');
    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            tabs.forEach(function(x) {
                x.classList.remove('is-active');
                x.setAttribute('aria-selected', 'false');
            });
            panels.forEach(function(p) { p.classList.remove('is-active'); });
            t.classList.add('is-active');
            t.setAttribute('aria-selected', 'true');
            var panel = document.getElementById('panel-' + t.getAttribute('data-panel'));
            if (panel) panel.classList.add('is-active');
        });
    });

    // Per-variant image + description swap.
    // When a <select name="variant_id"> changes, look at the picked
    // option's data-variant-image and data-variant-desc. If image is
    // present, swap the main gallery image (remembering the original
    // so we can restore on "no selection"). If description is present,
    // show the sf-variant-desc panel; otherwise hide it.
    var variantSelects = document.querySelectorAll('select[name="variant_id"]');
    var galleryMainImg = document.getElementById('sf-gallery-main');
    var originalGalleryImg = galleryMainImg ? galleryMainImg.src : null;
    var variantDescBox = document.getElementById('sf-variant-desc');
    var variantDescBody = document.getElementById('sf-variant-desc-body');
    var variantDescAttr = document.getElementById('sf-variant-desc-attr');

    variantSelects.forEach(function(sel) {
        sel.addEventListener('change', function() {
            var opt = sel.options[sel.selectedIndex];
            var img = opt ? (opt.getAttribute('data-variant-image') || '') : '';
            var desc = opt ? (opt.getAttribute('data-variant-desc') || '') : '';

            if (galleryMainImg) {
                if (img) {
                    galleryMainImg.src = img;
                } else if (originalGalleryImg) {
                    galleryMainImg.src = originalGalleryImg;
                }
            }
            if (variantDescBox && variantDescBody) {
                if (desc) {
                    // textContent on the body keeps it safe — server-rendered
                    // text was already HTML-escaped before reaching the
                    // data-variant-desc attribute, but using textContent here
                    // means a defense-in-depth pass: no chance of HTML
                    // injection from a careless content edit.
                    variantDescBody.textContent = desc;
                    if (variantDescAttr && opt && opt.text) {
                        // Strip "(+$X)" and "— out of stock" markers from the
                        // option's display text for the label
                        var clean = opt.text.replace(/\s*\([^)]*\)\s*/g, '').replace(/\s*—\s*out of stock\s*$/i, '').trim();
                        variantDescAttr.textContent = 'About: ' + clean;
                    }
                    variantDescBox.hidden = false;
                } else {
                    variantDescBox.hidden = true;
                    variantDescBody.textContent = '';
                }
            }
        });
    });
})();
</script>

<?php sf_footer(); ?>

