<?php
/**
 * React Site Bridge — Slate plugin bootstrap.
 *
 * Coastal Precision implementation note: This file is platform integration
 * only. Its visual design is intentionally delegated to each connected React
 * site and Slate's shared admin shell.
 */

require_once __DIR__ . '/ReactSiteBridgeAPI.php';
require_once __DIR__ . '/ReactSiteBridgeContentBuilder.php';

class ReactSiteBridge extends Plugin {
    public function boot(): void {
        ReactSiteBridgeAPI::ensureSchema();
        ReactSiteBridgeContentBuilder::register();
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('react-site-bridge.manage') && !Auth::isSuperAdmin()) return $items;
        $items[] = [
            'slug'  => 'react-site-bridge',
            'label' => __('react_site_bridge', 'React Sites'),
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'layout',
            'perm'  => 'react-site-bridge.manage',
            'order' => 270,
            'group' => 'content',
        ];
        return $items;
    }

    public function addPublicRoutes(array $routes): array {
        $routes['site-data'] = [
            'handler' => $this->dir('public/router.php'),
            'methods' => ['GET', 'OPTIONS'],
        ];
        $routes['react-preview'] = [
            'handler' => $this->dir('public/preview.php'),
            'methods' => ['GET'],
        ];
        $routes['react-sites'] = [
            'handler' => $this->dir('public/host.php'),
            'methods' => ['GET', 'HEAD'],
        ];
        // Direct-hosted releases are also available from their tenant-scoped
        // site key (for example, /slate/kaimana/electric). This removes the
        // opaque public identifier from visitor-facing URLs while retaining
        // /react-sites/<public-id>/ as a stable backward-compatible route.
        foreach (ReactSiteBridgeAPI::hostedRouteKeys() as $siteKey) {
            if (isset($routes[$siteKey])) continue;
            $routes[$siteKey] = [
                'handler' => $this->dir('public/host-alias.php'),
                'methods' => ['GET', 'HEAD'],
            ];
        }
        return $routes;
    }
}
