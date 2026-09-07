<?php
/**
 * Content Builder — front-end resolver.
 *
 * Maps an incoming slug to a rendered page. Wire your router/rewrite so
 * /<slug> (pages) and /<type>/<slug> (posts/CPTs) reach this file. See the
 * .htaccess snippet in the README.
 *
 * Fires 'content_head_tags' so the SEO plugin (or any plugin) can inject
 * <meta> tags, and 'content_footer' for trailing scripts.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('content-builder');

$type = preg_replace('/[^a-z0-9_-]/', '', $_GET['type'] ?? 'page');
$slug = preg_replace('/[^a-z0-9_\-\/]/', '', $_GET['slug'] ?? 'home');

$post = ContentBuilderAPI::getPostBySlug($type, $slug);

if (!$post || $post['status'] !== 'published') {
    http_response_code(404);
    // Fire the head filter even on 404 so plugins (SBK) can still inject
    // their theme CSS, fonts, and meta tags — without it the 404 page
    // renders raw HTML without any chrome.
    $headTags = Hook::applyFilters('content_head_tags', '<title>404 — Not found</title>', null);
    $home = rtrim(SLATE_URL, '/') . '/home';
    $bodyHtml = '<main class="cb-page"><section class="sb-page-hero">'
              . '<div class="sb-page-hero-bg"></div>'
              . '<div class="sb-page-hero-inner">'
              .   '<div class="sb-crumbs"><a href="' . e($home) . '">Home</a> <span class="sb-crumb-sep">/</span> <span class="sb-crumb-current">Page not found</span></div>'
              .   '<h1>That page could not be found.</h1>'
              .   '<p class="sb-page-hero-lede">The link may be broken or the page may have been moved. Check the URL or head back to the homepage.</p>'
              .   '<a class="sb-btn sb-btn-primary sb-page-hero-cta" href="' . e($home) . '">Back to home '
              .     '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
              .   '</a>'
              . '</div></section></main>';
} else {
    // Let plugins (SEO) contribute <head> tags. Default to a <title>.
    $default  = '<title>' . e($post['title'] ?: 'Untitled') . '</title>';
    $headTags = Hook::applyFilters('content_head_tags', $default, $post);
    $bodyHtml = '<main class="cb-page">'
              . ContentBuilderAPI::renderLayout($post['layout'])
              . '</main>';
}

$footer = Hook::applyFilters('content_footer', '', $post ?? null);

/*
 * Minimal standalone shell. If your platform has a public theme, replace
 * everything below with:  include SLATE_ROOT . '/public/theme/page.php';
 * passing $headTags and $bodyHtml.
 */
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $headTags ?>
    <?php $faviconPath = (string) Database::setting('brand_favicon_path'); if ($faviconPath !== ''): ?>
        <link rel="icon" href="<?= e(SLATE_URL . '/' . ltrim($faviconPath, '/')) ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= e(SLATE_URL) ?>/plugins/content-builder/admin/assets/builder.css">
</head>
<body class="cb-public">
    <?= $bodyHtml ?>
    <?= $footer ?>
</body>
</html>
