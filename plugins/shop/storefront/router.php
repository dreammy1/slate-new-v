<?php
/**
 * Shop storefront router.
 *
 * Apache rewrites /shop/(.*) → /plugins/shop/storefront/router.php
 * with $_GET['_path'] set to the original suffix. This file inspects
 * that path and includes the matching storefront page.
 *
 * Routes:
 *   /shop/                    → index.php   (catalog)
 *   /shop/product/<slug>      → product.php with ?slug=<slug>
 *   /shop/category/<slug>     → category.php with ?slug=<slug>
 *   /shop/cart                → cart.php
 *   /shop/checkout            → checkout.php
 *   /shop/order               → order.php
 *   /shop/order?key=<token>   → order.php (same; key is passed through)
 */

// Resolve the requested path. Prefer the explicit _path param set by
// the rewrite rule. Fall back to parsing REQUEST_URI for safety.
$path = '';
if (isset($_GET['_path'])) {
    $path = trim((string)$_GET['_path'], '/');
} elseif (!empty($_SERVER['REQUEST_URI'])) {
    $uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (preg_match('#/shop(?:/(.*))?$#', $uri, $m)) {
        $path = trim($m[1] ?? '', '/');
    }
}

// Sanitize: reject anything with .. or backslashes
if (strpos($path, '..') !== false || strpos($path, '\\') !== false) {
    http_response_code(400);
    echo 'Bad request';
    exit;
}

// Decompose into segments
$segments = $path === '' ? [] : explode('/', $path);
$top = $segments[0] ?? '';

// Dispatch
switch ($top) {
    case '':
        require __DIR__ . '/index.php';
        break;

    case 'product':
        $_GET['slug'] = $segments[1] ?? '';
        require __DIR__ . '/product.php';
        break;

    case 'category':
        $_GET['slug'] = $segments[1] ?? '';
        require __DIR__ . '/category.php';
        break;

    case 'cart':
        require __DIR__ . '/cart.php';
        break;

    case 'checkout':
        require __DIR__ . '/checkout.php';
        break;

    case 'order':
        require __DIR__ . '/order.php';
        break;

    case 'embed':
        require __DIR__ . '/embed.php';
        break;

    default:
        http_response_code(404);
        // Try to render the storefront's 404 layout
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if (!file_exists($root . '/config.php')) {
            $dir = __DIR__;
            while ($dir !== '/' && !file_exists($dir . '/config.php')) {
                $dir = dirname($dir);
            }
            $root = $dir;
        }
        require $root . '/config.php';
        require __DIR__ . '/includes/layout.php';
        sf_header('Not found');
        echo '<h1 class="sf-h1">Page not found</h1>';
        echo '<p class="sf-sub">The page you\'re looking for doesn\'t exist.</p>';
        echo '<a href="' . e(SLATE_URL) . '/shop/" class="sf-btn sf-btn-primary">Back to shop</a>';
        sf_footer();
        break;
}
