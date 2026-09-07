<?php
/**
 * Content Builder — Slate plugin bootstrap.
 *
 * Provides pages, posts, custom post types, taxonomies, and a block-based
 * page builder. Designed to be EXTENDED by other plugins via three hooks:
 *
 *   - 'content_register_blocks' (action)  : add blocks to the builder palette
 *   - 'content_edit_sidebar'    (action)  : add fields to the post editor
 *   - 'content_head_tags'       (filter)  : inject tags into the rendered <head>
 *
 * This plugin depends on NOTHING. Forms, Stripe, SEO, etc. depend on it.
 */

class ContentBuilder extends Plugin {

    /** Bump to bust the cached admin stylesheet. */
    public const ASSET_VER = '1.9.7';

    public function boot(): void {
        // Eagerly require API + lib classes (Slate has no autoloader).
        foreach ([
            'ContentBuilderAPI.php',
            'lib/PostType.php',
            'lib/Taxonomy.php',
            'lib/BlockRegistry.php',
            'lib/PatternLibrary.php',
            'lib/Renderer.php',
            'lib/Branding.php',
            'lib/Theme.php',
            'lib/SiteTemplate.php',
            'lib/PrecomposedBody.php',
            'lib/CoreBridge.php',
            'lib/MediaKeyResolver.php',
        ] as $f) {
            $path = $this->dir($f);
            if (file_exists($path)) require_once $path;
        }

        // Tell lib classes where the plugin lives (for block templates etc.)
        ContentBuilderAPI::setPluginDir($this->getDir());
        ContentBuilderAPI::ensureSchema();
        BlockRegistry::registerDefaults($this->dir('lib/blocks'));

        // Let other active plugins register their own blocks.
        Hook::doAction('content_register_blocks', BlockRegistry::class);

        // …and their own premade section patterns (drop-in section library).
        Hook::doAction('content_register_patterns', PatternLibrary::class);

        // Bridge to the core Slate\Presentation content spine (Phase 3A): revision
        // snapshots on save/publish + an opt-in, parity-gated core render path.
        // Wired after blocks register so the core registry sees the full palette.
        if (class_exists('ContentCoreBridge')) {
            ContentCoreBridge::register();
        }

        // Answer content_resolve_media_key for `media:<id>` keys. Without a
        // registered resolver the keyed media form is read-only in principle and
        // dead in practice: every key resolves to an empty URL.
        if (class_exists('ContentMediaKeyResolver')) {
            ContentMediaKeyResolver::register();
        }

        // Admin chrome.
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('admin_dashboard_widgets', [$this, 'addDashboardWidget']);

        // Single source of truth for the admin stylesheet — loaded once in
        // the <head> of every admin page instead of a per-page <link> tag.
        Hook::addAction('admin_head', [$this, 'adminHead']);

        // Clean public URLs: /p/<slug> for pages, /<type>/<slug> for posts/CPTs.
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
    }

    /** Expose the plugin base dir so the API/Renderer can resolve files. */
    protected function getDir(): string {
        return $this->dir('');
    }

    /** Emit the admin stylesheet once, in <head>, on every admin page. */
    public function adminHead(): void {
        $href = SLATE_URL . '/plugins/content-builder/admin/assets/builder.css?v=' . self::ASSET_VER;
        echo '<link rel="stylesheet" href="' . e($href) . '">' . "\n";
    }

    public function addAdminNav(array $items): array {
        $existingSlugs = array_column($items, 'slug');
        $order = 160;
        foreach (PostType::all() as $pt) {
            if (in_array($pt['slug'], ['page', 'post'], true) || in_array('content-' . $pt['slug'], $existingSlugs, true)) {
                continue;
            }
            $icon = $pt['hierarchical'] ? 'folder' : 'box';
            $items[] = [
                'slug'  => 'content-' . $pt['slug'],
                'label' => $pt['label'],
                'href'  => SLATE_URL . '/admin/posts.php?type=' . urlencode($pt['slug']),
                'icon'  => $icon,
                'perm'  => 'content.view',
                'order' => $order++,
                'group' => 'content',
            ];
        }
        if (!in_array('content-types', $existingSlugs, true)) {
            $items[] = [
                'slug'  => 'content-types',
                'label' => 'Post Types',
                'href'  => $this->url('admin/post-types.php'),
                'icon'  => 'box',
                'perm'  => 'content.manage_types',
                'order' => 190,
                'group' => 'content',
            ];
        }
        if (!in_array('content-tax', $existingSlugs, true)) {
            $items[] = [
                'slug'  => 'content-tax',
                'label' => 'Taxonomies',
                'href'  => $this->url('admin/taxonomies.php'),
                'icon'  => 'tag',
                'perm'  => 'content.manage_types',
                'order' => 191,
                'group' => 'content',
            ];
        }
        return $items;
    }

    /**
     * Register clean public URL prefixes. Pages get '/p', each non-page
     * post type gets its own slug as a prefix (e.g. '/post', '/product').
     */
    public function addPublicRoutes(array $routes): array {
        $handler = $this->dir('public/router.php');
        $routes['p'] = ['handler' => $handler, 'methods' => ['GET']];

        // Content API (ADR-0013 §3). Registered through the router rather than
        // dropped into the web root, so it inherits method handling and cannot
        // become a stray executable file.
        $routes['api'] = [
            'handler' => $this->dir('public/api.php'),
            'methods' => ['GET', 'OPTIONS'],
        ];
        foreach (PostType::all() as $pt) {
            if ($pt['slug'] === 'page') continue;
            if (!isset($routes[$pt['slug']])) {
                $routes[$pt['slug']] = ['handler' => $handler, 'methods' => ['GET']];
            }
        }
        return $routes;
    }

    public function addDashboardWidget(array $widgets): array {
        // Real KPI tiles (published / drafts / total) instead of a prose
        // paragraph -- matches the Forms/Membership/Coaching dashboard
        // widgets' own .dwidget-kpis strip, so every plugin's card on the
        // main dashboard reads the same way.
        $tid = current_tenant_id();
        try {
            $published = (int) Database::value(
                "SELECT COUNT(*) FROM contentbuilder_posts WHERE tenant_id = ? AND status = 'published'", [$tid]);
            $drafts = (int) Database::value(
                "SELECT COUNT(*) FROM contentbuilder_posts WHERE tenant_id = ? AND status = 'draft'", [$tid]);
            $counts = ContentBuilderAPI::countsByType($tid);
        } catch (\Throwable $e) {
            return $widgets;
        }
        $total = array_sum($counts);

        ob_start(); ?>
        <div class="card">
            <div class="card-header">
                <h2>Content</h2>
                <a href="<?= e($this->url('admin/posts.php?type=page')) ?>" class="dwidget-all">View all &rarr;</a>
            </div>
            <div class="dwidget-kpis">
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Published</div>
                    <div class="dwidget-kpi-v"><?= $published ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Drafts</div>
                    <div class="dwidget-kpi-v"><?= $drafts ?></div>
                </div>
                <div class="dwidget-kpi">
                    <div class="dwidget-kpi-k">Total items</div>
                    <div class="dwidget-kpi-v"><?= $total ?></div>
                </div>
            </div>
        </div>
        <?php
        $widgets[] = ob_get_clean();
        return $widgets;
    }
}
