<?php
/**
 * Small Business Kit — Plugin bootstrap.
 *
 * Wires the kit into Content Builder:
 *   - Registers sb-* blocks
 *   - Injects active theme tokens + sb.css into every CB page
 *   - Adds an admin nav item for the theme picker
 *
 * No tables of its own — settings live in core; page content lives in
 * Content Builder's standard tables.
 */

require_once __DIR__ . '/SBKitAPI.php';
require_once __DIR__ . '/lib/Themes.php';
require_once __DIR__ . '/lib/Blocks.php';
require_once __DIR__ . '/lib/Templates.php';
require_once __DIR__ . '/lib/Header.php';
require_once __DIR__ . '/lib/Footer.php';

class SmallBusinessKit extends Plugin {

    public function boot(): void {
        // 1. Register sb-* blocks (order-independent).
        SBKBlocks::register();

        // 2. Inject theme variables + stylesheet + meta tags into every CB
        //    page. `$post` lets us emit a meaningful <meta description> +
        //    OG tags and preload the hero image for LCP.
        Hook::addFilter('content_head_tags', function ($tags, $post) {
            return $tags . SBKitAPI::headInjection($post);
        }, 20, 2);

        // 3. Render our global chrome at end of body. CSS positions the
        //    header fixed at the top; the footer renders inline at the
        //    bottom of the page.
        Hook::addFilter('content_footer', function ($extra, $post) {
            return $extra . SBKFooter::render() . SBKHeader::render();
        }, 20, 2);

        // 4. Pretty URLs: register /<slug> as an alias for /p/<slug> for every
        //    published page. Skips any slug that would collide with an
        //    existing prefix (forms, shop, book, p, etc.) so plugin routes
        //    always win. The /p/<slug> form still works — this just adds
        //    a shorter alias for sharing/SEO.
        Hook::addFilter('public_routes', [$this, 'addPrettyUrls']);

        // 5. Publish our themes to the content engine, which owns "which theme
        //    is active" and renders the single picker inside Site Settings.
        //    The tokens stay here, next to the sb-* blocks they style.
        Hook::addFilter('content_register_themes', function (array $themes): array {
            return $themes + SBKThemes::all();
        });

        // 6. No admin nav of our own any more. The theme picker moved into
        //    Site Settings (Phase A of the content-engine consolidation), so
        //    there is exactly one place to change how the site looks.
        //    admin/theme.php still exists and redirects there, for bookmarks.
    }

    /** Register /<slug> aliases for every published CB page. */
    public function addPrettyUrls(array $routes): array {
        if (!class_exists('ContentBuilderAPI')) return $routes;
        $handler = $this->dir('public/page-shim.php');
        if (!is_file($handler)) return $routes;
        try {
            $pages = ContentBuilderAPI::listPosts('page', ['status' => 'published', 'limit' => 500]);
        } catch (\Throwable $e) { return $routes; }
        foreach ($pages as $p) {
            $slug = (string)($p['slug'] ?? '');
            // Never override an existing prefix (forms, shop, book, p, admin
            // is served as a real dir not a route, etc.).
            if ($slug === '' || isset($routes[$slug])) continue;
            $routes[$slug] = ['handler' => $handler, 'methods' => ['GET']];
        }
        return $routes;
    }

}
