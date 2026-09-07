<?php
/** Slate AI Gateway bootstrap. */
require_once __DIR__ . '/SlateMcpAPI.php';

class SlateMcp extends Plugin {
    public function boot(): void {
        SlateMcpAPI::ensureSchema();
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);
        Hook::addFilter('public_routes', [$this, 'addPublicRoutes']);
        Hook::addFilter('api_v1_authenticate', [$this, 'authenticateApiV1Token'], 10, 2);
    }

    public function authenticateApiV1Token($auth, string $token): ?array {
        if ($auth !== null) return $auth;
        $context = SlateMcpAPI::authenticate($token);
        if ($context) {
            return [
                'authenticated' => true,
                'type'          => 'mcp_token',
                'tenant_id'     => $context['tenant_id'],
                'scopes'        => $context['scopes'],
                'token_id'      => $context['token_id'],
            ];
        }
        return $auth;
    }

    public function addAdminNav(array $items): array {
        if (!Auth::can('slate-mcp.manage') && !Auth::isSuperAdmin()) return $items;
        $items[] = ['slug' => 'slate-mcp', 'label' => 'AI Access', 'href' => $this->url('admin/index.php'), 'icon' => 'shield', 'perm' => 'slate-mcp.manage', 'order' => 285, 'group' => 'settings'];
        return $items;
    }

    public function addPublicRoutes(array $routes): array {
        $routes['slate-mcp'] = ['handler' => $this->dir('public/router.php'), 'methods' => ['POST', 'OPTIONS']];
        return $routes;
    }
}
