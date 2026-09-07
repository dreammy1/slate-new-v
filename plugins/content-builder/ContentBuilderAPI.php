<?php
/**
 * Content Builder — Public API.
 *
 * Other plugins call ContentBuilderAPI::* and NEVER read/write
 * contentbuilder_* tables directly. This class is the contract.
 *
 * Usage from another plugin:
 *
 *   if (PluginLoader::isActive('content-builder') && class_exists('ContentBuilderAPI')) {
 *       $page = ContentBuilderAPI::getPostBySlug('page', 'home');
 *   }
 */

class ContentBuilderAPI {

    protected static string $pluginDir = '';
    protected static bool $schemaChecked = false;

    public static function setPluginDir(string $dir): void {
        self::$pluginDir = rtrim($dir, '/');
    }
    public static function pluginDir(): string {
        return self::$pluginDir;
    }

    /**
     * Normalise a stored media path into a browser-usable URL.
     *
     * The media library stores root-relative paths like '/uploads/branding/x.png'.
     * When Slate is installed in a sub-directory (e.g. https://site.com/slate),
     * a bare '/uploads/...' resolves against the DOMAIN root and 404s. This
     * prepends the install's base path so images load wherever Slate lives.
     *
     * Absolute URLs (http(s):// or //), data: URIs, and paths that already
     * include the base prefix are returned unchanged.
     */
    public static function mediaUrl(string $v): string {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('#^(?:https?:)?//#i', $v) || str_starts_with($v, 'data:')) {
            return $v;
        }
        if ($v[0] === '/') {
            $base = defined('SLATE_URL') ? rtrim((string)parse_url(SLATE_URL, PHP_URL_PATH), '/') : '';
            if ($base !== '' && strncmp($v, $base . '/', strlen($base) + 1) !== 0) {
                return $base . $v;
            }
        }
        return $v;
    }

    /**
     * Create any missing tables. Runs once per request (cheap, guarded).
     * This makes upgrades self-healing: dropping a newer build over an
     * existing install adds new tables (e.g. menus) without needing the
     * admin to deactivate/reactivate so install.sql re-runs.
     */
    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;
        try {
            $pdo = Database::get();
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `contentbuilder_menus` (
                    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tenant_id`  INT UNSIGNED NOT NULL DEFAULT 1,
                    `name`       VARCHAR(128) NOT NULL,
                    `location`   VARCHAR(32)  NOT NULL DEFAULT '',
                    `items`      JSON         NULL,
                    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `tenant_loc` (`tenant_id`,`location`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS `contentbuilder_post_meta` (
                    `post_id`  INT UNSIGNED NOT NULL,
                    `meta_key` VARCHAR(128) NOT NULL,
                    `meta_val` TEXT NULL,
                    PRIMARY KEY (`post_id`, `meta_key`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $ex) {
            // Don't fatal the whole request if the DB user lacks CREATE;
            // the admin can deactivate/reactivate to run install.sql instead.
            if (function_exists('slate_log')) {
                slate_log('ContentBuilder ensureSchema failed: ' . $ex->getMessage(), 'warning');
            }
        }

        self::seedDemoPage();
    }

    /** Demo seed version — bump when demo-landing.json changes to re-apply. */
    const DEMO_SEED_VERSION = '1.5.0';

    /** Create/refresh a polished demo landing page so users see a finished example. */
    protected static function seedDemoPage(): void {
        try {
            $tenantId = current_tenant_id();
            $existing = Database::row(
                "SELECT id FROM contentbuilder_posts WHERE tenant_id = ? AND type = 'page' AND slug = ?",
                [$tenantId, 'demo']);

            $seededVer = (string)self::getSiteSetting('demo_seed_version', '');

            // Already current — nothing to do.
            if ($existing && $seededVer === self::DEMO_SEED_VERSION) return;

            $file = self::$pluginDir . '/lib/demo-landing.json';
            $layout = is_file($file) ? (string)file_get_contents($file) : '[]';
            json_decode($layout); // validate
            if (json_last_error() !== JSON_ERROR_NONE) return;

            if ($existing) {
                // Refresh the auto-seeded demo content to the new design.
                Database::update('contentbuilder_posts',
                    ['layout' => $layout, 'status' => 'published'],
                    'id = ? AND tenant_id = ?', [$existing['id'], $tenantId]);
            } else {
                Database::insert('contentbuilder_posts', [
                    'tenant_id'    => $tenantId,
                    'type'         => 'page',
                    'title'        => 'Demo landing',
                    'slug'         => 'demo',
                    'status'       => 'published',
                    'layout'       => $layout,
                    'published_at' => slate_db_now(),
                ]);
            }
            self::setSiteSetting('demo_seed_version', self::DEMO_SEED_VERSION);
        } catch (\Throwable $ex) {
            // best-effort seed; ignore failures
        }
    }

    /* ──────────────────────────── Post types ─────────────────────────── */

    public static function getPostTypes(?int $tenantId = null): array {
        return PostType::all($tenantId);
    }

    public static function getPostType(string $slug, ?int $tenantId = null): ?array {
        return PostType::get($slug, $tenantId);
    }

    public static function registerPostType(array $def, ?int $tenantId = null): int {
        $tenantId = $tenantId ?? current_tenant_id();
        $slug = self::slugify($def['slug'] ?? '');
        if ($slug === '') throw new InvalidArgumentException('Post type slug required.');

        $existing = PostType::get($slug, $tenantId);
        $row = [
            'tenant_id'    => $tenantId,
            'slug'         => $slug,
            'label'        => trim($def['label'] ?? ucfirst($slug)),
            'singular'     => trim($def['singular'] ?? ucfirst($slug)),
            'hierarchical' => !empty($def['hierarchical']) ? 1 : 0,
            'is_builtin'   => !empty($def['is_builtin']) ? 1 : 0,
            'supports'     => json_encode($def['supports'] ?? ['title', 'editor']),
        ];
        if ($existing) {
            Database::update('contentbuilder_post_types', $row,
                'id = ?', [$existing['id']]);
            return (int)$existing['id'];
        }
        return Database::insert('contentbuilder_post_types', $row);
    }

    public static function deletePostType(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        $pt = Database::row(
            "SELECT * FROM contentbuilder_post_types WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]);
        if (!$pt || (int)$pt['is_builtin'] === 1) return; // never delete built-ins
        Database::query("DELETE FROM contentbuilder_post_types WHERE id = ?", [$id]);
    }

    /* ──────────────────────────────── Posts ──────────────────────────── */

    public static function getPost(int $id, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]);
        return $row ? self::hydrate($row) : null;
    }

    public static function getPostBySlug(string $type, string $slug, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_posts
             WHERE tenant_id = ? AND type = ? AND slug = ?",
            [$tenantId, $type, $slug]);
        return $row ? self::hydrate($row) : null;
    }

    public static function listPosts(string $type, array $args = [], ?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $where  = ['tenant_id = ?', 'type = ?'];
        $params = [$tenantId, $type];

        $status = $args['status'] ?? 'published';
        if ($status === 'any') {
            $where[] = "status != 'trash'"; // "All" lists live items; Trash has its own tab
        } else {
            $where[] = 'status = ?'; $params[] = $status;
        }

        $sql = "SELECT * FROM contentbuilder_posts WHERE " . implode(' AND ', $where);

        // Optional term filter (join through relations).
        if (!empty($args['term_id'])) {
            $sql = "SELECT p.* FROM contentbuilder_posts p
                    JOIN contentbuilder_term_relations r ON r.post_id = p.id
                    WHERE " . str_replace('tenant_id', 'p.tenant_id',
                                str_replace('type', 'p.type',
                                str_replace('status', 'p.status', implode(' AND ', $where))))
                  . " AND r.term_id = ?";
            $params[] = (int)$args['term_id'];
        }

        $sql .= " ORDER BY " . (($args['orderby'] ?? 'created_at') === 'title' ? 'title ASC' : 'created_at DESC');
        // Both are cast to int, so they cannot inject — but unbounded they can
        // still hurt: a huge limit exhausts memory and a negative offset is a
        // SQL syntax error, i.e. a 500 from a hand-edited pagination parameter.
        $limit  = max(1, min(500, (int)($args['limit'] ?? 20)));
        $offset = max(0, (int)($args['offset'] ?? 0));
        $sql .= " LIMIT $offset, $limit";

        $rows = Database::rows($sql, $params);
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * Drop blocks the caller is not permitted to author, recursively.
     *
     * BlockRegistry entries may declare a 'perm'. That declaration existed but
     * nothing ever read it, so the html block's content.publish gate was
     * decorative and any content.edit user could inject script. Filtering in the
     * editor UI alone would not fix it — the layout arrives as a JSON POST field
     * and can be crafted by hand — so the check has to live here, on the way in.
     *
     * $mayUsePrivileged is passed explicitly rather than read from Auth so this
     * stays callable (and testable) outside a request.
     */
    /** Stable identity for a block, used to recognise one that already existed. */
    private static function blockFingerprint(array $block): string {
        return md5(json_encode([
            'type'  => (string)($block['type'] ?? ''),
            'props' => $block['props'] ?? [],
        ]));
    }

    /** Fingerprints of every privileged block in a stored layout, recursively. */
    private static function privilegedFingerprints(array $layout, array &$acc = []): array {
        foreach ($layout as $block) {
            if (!is_array($block)) continue;
            $def = BlockRegistry::get((string)($block['type'] ?? ''));
            if ($def && ($def['perm'] ?? '') !== '') {
                $acc[self::blockFingerprint($block)] = true;
            }
            foreach (['children', 'blocks'] as $key) {
                if (!empty($block[$key]) && is_array($block[$key])) {
                    self::privilegedFingerprints($block[$key], $acc);
                }
            }
            if (!empty($block['props']['blocks']) && is_array($block['props']['blocks'])) {
                self::privilegedFingerprints($block['props']['blocks'], $acc);
            }
        }
        return $acc;
    }

    /**
     * True when every entry looks like a block: an array carrying a `type`.
     * Deliberately strict — misidentifying an arbitrary list as blocks would
     * silently drop content.
     */
    private static function looksLikeBlockList(array $value): bool {
        if ($value === [] || !array_is_list($value)) return false;
        foreach ($value as $entry) {
            if (!is_array($entry) || !isset($entry['type']) || !is_string($entry['type'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Walk anything below a block and sanitise every nested block list found,
     * regardless of which key holds it.
     *
     * $isBlock marks the top call, whose own `type` must not be treated as a
     * nested list.
     */
    private static function sanitizeNested(
        $value,
        bool $mayUsePrivileged,
        array $grandfathered,
        bool $isBlock = false
    ) {
        if (!is_array($value)) return $value;

        if (!$isBlock && self::looksLikeBlockList($value)) {
            return self::sanitizeLayout($value, $mayUsePrivileged, [], $grandfathered);
        }

        foreach ($value as $key => $inner) {
            if ($isBlock && $key === 'type') continue;
            $value[$key] = self::sanitizeNested($inner, $mayUsePrivileged, $grandfathered);
        }
        return $value;
    }

    public static function sanitizeLayout(
        array $layout,
        bool $mayUsePrivileged,
        array $existing = [],
        ?array $grandfathered = null
    ): array {
        // A caller without the permission may not ADD a privileged block, but
        // must not silently DELETE one that is already published either — an
        // editor opening an admin-authored page and saving a typo fix would
        // otherwise wipe its Raw HTML. Blocks carried through unchanged are
        // recognised by fingerprint and kept; anything new is dropped.
        $grandfathered ??= self::privilegedFingerprints($existing);

        $out = [];
        foreach ($layout as $block) {
            if (!is_array($block)) continue;
            $type = (string)($block['type'] ?? '');
            if ($type === '') continue;

            $def = BlockRegistry::get($type);
            if (!$def) continue;                     // unknown block: drop, don't store

            $perm = (string)($def['perm'] ?? '');
            if ($perm !== '' && !$mayUsePrivileged
                && !isset($grandfathered[self::blockFingerprint($block)])) {
                continue;   // privileged AND not already published: drop
            }

            // Containers nest child blocks, and they do not agree on where.
            // `columns` uses props.cols[].blocks; others use children or
            // props.blocks. A hardcoded list of those keys missed the columns
            // shape, which let a privileged block ride inside a column past this
            // gate — the escalation #6 was written to close. So the walk is
            // generic: anything anywhere below this block that looks like a list
            // of blocks is sanitised, whatever key holds it.
            $block = self::sanitizeNested($block, $mayUsePrivileged, $grandfathered, true);

            $out[] = $block;
        }
        return $out;
    }

    public static function savePost(array $data, ?int $tenantId = null): int {
        $tenantId = $tenantId ?? current_tenant_id();
        $id = (int)($data['id'] ?? 0) ?: null;

        // Enforce per-block permissions on the way in. Done here rather than in
        // the editor so a hand-crafted POST cannot smuggle a privileged block.
        if (isset($data['layout']) && is_array($data['layout'])) {
            $mayUsePrivileged = !class_exists('Auth') || Auth::can('content.publish');
            $existingLayout   = [];
            if (!$mayUsePrivileged && $id) {
                // Only needed to grandfather already-published privileged blocks.
                $prior = self::getPost($id, $tenantId);
                $existingLayout = is_array($prior['layout'] ?? null) ? $prior['layout'] : [];
            }
            $data['layout'] = self::sanitizeLayout($data['layout'], $mayUsePrivileged, $existingLayout);
        }
        $type = $data['type'] ?? 'page';
        $slug = self::slugify($data['slug'] ?? ($data['title'] ?? ''));
        if ($slug === '') $slug = 'untitled-' . substr(md5(uniqid('', true)), 0, 6);
        $slug = self::uniqueSlug($type, $slug, $id, $tenantId);

        $row = [
            'tenant_id' => $tenantId,
            'type'      => $type,
            'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            'title'     => mb_substr(trim($data['title'] ?? ''), 0, 255),
            'slug'      => $slug,
            // The `?? 'draft'` has to be on the VALUE, not only the condition:
            // with status absent the check passed on the defaulted string and
            // then wrote the raw (missing) key, i.e. NULL, into a NOT NULL
            // column. Any caller that did not pass a status could not save.
            'status'    => in_array($data['status'] ?? '', ['draft','published','trash'], true)
                           ? (string) $data['status'] : 'draft',
            'layout'    => json_encode($data['layout'] ?? []),
            'excerpt'   => $data['excerpt'] ?? null,
            'author_id' => $data['author_id'] ?? Auth::userId(),
        ];
        if (($row['status']) === 'published') {
            $row['published_at'] = slate_db_now();
        }

        if ($id) {
            Database::update('contentbuilder_posts', $row, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
            $savedId = $id;
        } else {
            $savedId = Database::insert('contentbuilder_posts', $row);
        }
        // Additive extension point (Phase 3A): revision snapshots + future hooks.
        Hook::doAction('content_post_saved', $savedId);
        return $savedId;
    }

    public static function publish(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::update('contentbuilder_posts',
            ['status' => 'published', 'published_at' => slate_db_now()],
            'id = ? AND tenant_id = ?', [$id, $tenantId]);
        Hook::doAction('content_post_saved', $id);
    }

    public static function trash(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::update('contentbuilder_posts', ['status' => 'trash'],
            'id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    public static function restore(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::update('contentbuilder_posts', ['status' => 'draft'],
            'id = ? AND tenant_id = ?', [$id, $tenantId]);
    }

    /* ───────────────── Draft / Publish workflow (v2) ────────────────── */
    /*                                                                       */
    /* The visual editor writes to draft_data on every autosave, leaving     */
    /* the live `layout` and `published_data` untouched. Publishing copies   */
    /* draft_data → layout + published_data atomically, and reverting copies */
    /* published_data → draft_data to discard unpublished changes.           */

    /**
     * Save layout to draft_data only — does NOT touch the live layout column.
     * Safe for autosave; visitors never see draft_data.
     */
    public static function saveDraft(int $postId, array $layout, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();

        // Enforce per-block permissions exactly as savePost does.
        $mayUsePrivileged = !class_exists('Auth') || Auth::can('content.publish');
        $existingLayout   = [];
        if (!$mayUsePrivileged) {
            $prior = self::getPost($postId, $tenantId);
            $existingLayout = is_array($prior['layout'] ?? null) ? $prior['layout'] : [];
        }
        $layout = self::sanitizeLayout($layout, $mayUsePrivileged, $existingLayout);

        Database::update('contentbuilder_posts',
            ['draft_data' => json_encode($layout)],
            'id = ? AND tenant_id = ?', [$postId, $tenantId]);

        Hook::doAction('content_draft_saved', $postId);
    }

    /**
     * Promote draft_data to live: copies draft_data → layout + published_data,
     * sets status = published, stamps published_at, fires revision snapshot.
     *
     * Returns false if the post does not exist or has no draft_data.
     */
    public static function publishDraft(int $postId, ?int $tenantId = null): bool {
        $tenantId = $tenantId ?? current_tenant_id();
        $post = Database::row(
            "SELECT id, draft_data, status FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$postId, $tenantId]);
        if (!$post) return false;

        $draftJson = $post['draft_data'] ?? null;
        if ($draftJson === null || $draftJson === '') {
            // Nothing in draft_data — fall back to promoting current layout.
            Database::update('contentbuilder_posts', [
                'status'       => 'published',
                'published_at' => slate_db_now(),
            ], 'id = ? AND tenant_id = ?', [$postId, $tenantId]);
        } else {
            Database::update('contentbuilder_posts', [
                'layout'         => $draftJson,
                'published_data' => $draftJson,
                'status'         => 'published',
                'published_at'   => slate_db_now(),
            ], 'id = ? AND tenant_id = ?', [$postId, $tenantId]);
        }

        Hook::doAction('content_post_saved', $postId);
        return true;
    }

    /**
     * Discard unpublished changes: copies published_data → draft_data.
     * Returns false if there is no published snapshot to revert to.
     */
    public static function revertToPublished(int $postId, ?int $tenantId = null): bool {
        $tenantId = $tenantId ?? current_tenant_id();
        $post = Database::row(
            "SELECT id, published_data FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$postId, $tenantId]);
        if (!$post || ($post['published_data'] ?? null) === null) return false;

        Database::update('contentbuilder_posts',
            ['draft_data' => $post['published_data']],
            'id = ? AND tenant_id = ?', [$postId, $tenantId]);
        return true;
    }

    /**
     * Get the draft layout for the editor canvas.
     * Falls back to the live layout if no draft_data exists yet.
     *
     * @return array{layout: array, isDraft: bool}
     */
    public static function getDraft(int $postId, ?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT layout, draft_data FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$postId, $tenantId]);
        if (!$row) return ['layout' => [], 'isDraft' => false];

        $draftJson = $row['draft_data'] ?? null;
        if ($draftJson !== null && $draftJson !== '') {
            $layout = json_decode($draftJson, true);
            return ['layout' => is_array($layout) ? $layout : [], 'isDraft' => true];
        }
        $layout = json_decode($row['layout'] ?? '[]', true);
        return ['layout' => is_array($layout) ? $layout : [], 'isDraft' => false];
    }

    /**
     * Check whether a post has unsaved draft changes (draft_data differs from layout).
     */
    public static function hasDraftChanges(int $postId, ?int $tenantId = null): bool {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT layout, draft_data FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$postId, $tenantId]);
        if (!$row) return false;
        $d = $row['draft_data'] ?? null;
        if ($d === null || $d === '') return false;
        return $d !== $row['layout'];
    }

    public static function deletePost(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();

        // Confirm the post is ours BEFORE touching anything, the way
        // deletePostType() does. The child rows carry no tenant_id of their own,
        // so deleting them by post_id alone destroyed another tenant's meta and
        // term relations whenever an id from outside this tenant was passed —
        // the post itself survived, which made it silent.
        $owned = Database::row(
            "SELECT id FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]
        );
        if (!$owned) return;   // not ours: fail closed

        Database::query("DELETE FROM contentbuilder_posts WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        Database::query("DELETE FROM contentbuilder_post_meta WHERE post_id = ?", [$id]);
        Database::query("DELETE FROM contentbuilder_term_relations WHERE post_id = ?", [$id]);
    }

    public static function countsByType(?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $rows = Database::rows(
            "SELECT type, COUNT(*) AS n FROM contentbuilder_posts
             WHERE tenant_id = ? AND status <> 'trash' GROUP BY type",
            [$tenantId]);
        $out = [];
        foreach ($rows as $r) $out[$r['type']] = (int)$r['n'];
        return $out;
    }

    /* ─────────────────────────────── Post meta ───────────────────────── */
    /* Generic per-post key/value store. Any plugin (SEO, etc.) uses this   */
    /* instead of creating its own join table.                              */

    public static function getMeta(int $postId, string $key, $default = null) {
        $val = Database::value(
            "SELECT meta_val FROM contentbuilder_post_meta WHERE post_id = ? AND meta_key = ?",
            [$postId, $key]);
        return $val === null ? $default : $val;
    }

    public static function getAllMeta(int $postId): array {
        $rows = Database::rows(
            "SELECT meta_key, meta_val FROM contentbuilder_post_meta WHERE post_id = ?",
            [$postId]);
        $out = [];
        foreach ($rows as $r) $out[$r['meta_key']] = $r['meta_val'];
        return $out;
    }

    public static function setMeta(int $postId, string $key, ?string $val): void {
        // upsert
        Database::query(
            "INSERT INTO contentbuilder_post_meta (post_id, meta_key, meta_val)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE meta_val = VALUES(meta_val)",
            [$postId, mb_substr($key, 0, 128), $val]);
    }

    public static function deleteMeta(int $postId, string $key): void {
        Database::query(
            "DELETE FROM contentbuilder_post_meta WHERE post_id = ? AND meta_key = ?",
            [$postId, $key]);
    }

    /* ───────────────────────── Taxonomies & terms ────────────────────── */

    public static function getTaxonomies(?string $postType = null, ?int $tenantId = null): array {
        return Taxonomy::all($postType, $tenantId);
    }

    public static function registerTaxonomy(array $def, ?int $tenantId = null): int {
        $tenantId = $tenantId ?? current_tenant_id();
        $slug = self::slugify($def['slug'] ?? '');
        if ($slug === '') throw new InvalidArgumentException('Taxonomy slug required.');
        $existing = Database::row(
            "SELECT * FROM contentbuilder_taxonomies WHERE tenant_id = ? AND slug = ?",
            [$tenantId, $slug]);
        $row = [
            'tenant_id'    => $tenantId,
            'slug'         => $slug,
            'label'        => trim($def['label'] ?? ucfirst($slug)),
            'hierarchical' => !empty($def['hierarchical']) ? 1 : 0,
            'post_types'   => json_encode($def['post_types'] ?? ['post']),
        ];
        if ($existing) {
            Database::update('contentbuilder_taxonomies', $row, 'id = ?', [$existing['id']]);
            return (int)$existing['id'];
        }
        return Database::insert('contentbuilder_taxonomies', $row);
    }

    public static function getTerms(string $taxonomy, ?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        return Database::rows(
            "SELECT * FROM contentbuilder_terms
             WHERE tenant_id = ? AND taxonomy = ? ORDER BY name ASC",
            [$tenantId, $taxonomy]);
    }

    public static function getTermBySlug(string $taxonomy, string $slug, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        return Database::row(
            "SELECT * FROM contentbuilder_terms
             WHERE tenant_id = ? AND taxonomy = ? AND slug = ?",
            [$tenantId, $taxonomy, $slug]);
    }

    public static function addTerm(string $taxonomy, string $name, ?int $parent = null, ?int $tenantId = null): int {
        $tenantId = $tenantId ?? current_tenant_id();
        $slug = self::slugify($name);
        $existing = self::getTermBySlug($taxonomy, $slug, $tenantId);
        if ($existing) return (int)$existing['id'];
        return Database::insert('contentbuilder_terms', [
            'tenant_id' => $tenantId,
            'taxonomy'  => $taxonomy,
            'parent_id' => $parent ?: null,
            'name'      => mb_substr(trim($name), 0, 128),
            'slug'      => $slug,
        ]);
    }

    public static function deleteTerm(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::query("DELETE FROM contentbuilder_terms WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
        Database::query("DELETE FROM contentbuilder_term_relations WHERE term_id = ?", [$id]);
    }

    public static function setPostTerms(int $postId, array $termIds): void {
        Database::query("DELETE FROM contentbuilder_term_relations WHERE post_id = ?", [$postId]);
        foreach (array_unique(array_map('intval', $termIds)) as $tid) {
            if ($tid <= 0) continue;
            Database::query(
                "INSERT IGNORE INTO contentbuilder_term_relations (post_id, term_id) VALUES (?, ?)",
                [$postId, $tid]);
        }
    }

    public static function getPostTerms(int $postId, ?string $taxonomy = null): array {
        $sql = "SELECT t.* FROM contentbuilder_terms t
                JOIN contentbuilder_term_relations r ON r.term_id = t.id
                WHERE r.post_id = ?";
        $params = [$postId];
        if ($taxonomy !== null) { $sql .= " AND t.taxonomy = ?"; $params[] = $taxonomy; }
        return Database::rows($sql, $params);
    }

    /* ─────────────────────────────── Rendering ───────────────────────── */

    public static function renderLayout($layout): string {
        if (is_string($layout)) $layout = json_decode($layout, true);
        return Renderer::render(is_array($layout) ? $layout : []);
    }

    /**
     * Clean public URL for a post: pages → /p/<slug>, others → /<type>/<slug>.
     * Pass $preview=true to append the draft-preview flag.
     */
    public static function publicUrl(string $type, string $slug, bool $preview = false): string {
        $base = defined('SLATE_URL') ? SLATE_URL : '';
        $prefix = ($type === 'page') ? 'p' : $type;
        $url = $base . '/' . rawurlencode($prefix) . '/' . rawurlencode($slug);
        return $preview ? $url . '?preview=1' : $url;
    }

    /* ─────────────────────────────── Helpers ─────────────────────────── */

    protected static function hydrate(array $row): array {
        $row['layout'] = $row['layout'] ? (json_decode($row['layout'], true) ?: []) : [];
        return $row;
    }

    public static function slugify(string $s): string {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }

    protected static function uniqueSlug(string $type, string $slug, ?int $id, int $tenantId): string {
        $base = $slug; $i = 2;
        while (true) {
            $clash = Database::row(
                "SELECT id FROM contentbuilder_posts
                 WHERE tenant_id = ? AND type = ? AND slug = ? AND id <> ?",
                [$tenantId, $type, $slug, $id ?? 0]);
            if (!$clash) return $slug;
            $slug = $base . '-' . $i++;
        }
    }

    /* ─────────────────────────────── Permalinks ──────────────────────── */

    /**
     * Public URL for a post. Pages → /p/<slug>, other types → /<type>/<slug>.
     * Returns an absolute URL rooted at SLATE_URL.
     */
    public static function permalink(array $post): string {
        $type = $post['type'] ?? 'page';
        $slug = $post['slug'] ?? '';
        $prefix = ($type === 'page') ? 'p' : rawurlencode($type);
        return rtrim(SLATE_URL, '/') . '/' . $prefix . '/' . rawurlencode($slug);
    }

    /* ───────────────────────── Site settings (header/footer) ─────────── */
    /* Stored in the core settings table, prefixed 'content-builder.' so they */
    /* never collide. Used by the public theme for global chrome.            */

    public static function getSiteSetting(string $key, $default = null) {
        $val = Database::setting('content-builder.' . $key);
        return $val !== null ? $val : $default;
    }

    /* ─────────────────────────────── Content API ─────────────────────── */
    /*                                                                       */
    /* ADR-0013 §3. The API returns an ENVELOPE, not a bare document:        */
    /* addressing is echoed so a consumer never parses it back out of the    */
    /* URL, the document is passed through verbatim so both renderers        */
    /* provably read the same bytes, and the theme travels with it so a page */
    /* is one round trip rather than two that can disagree.                  */

    /**
     * Resolve blocks whose output depends on the database, keyed by position.
     *
     * ADR-0013 addendum. `post-list` queries published posts; the React renderer
     * has no database and must not get one, so the API resolves and carries the
     * result beside the document rather than inside it — embedding it would mean
     * the document no longer travels verbatim.
     *
     * The key is "<sectionId>.<blockIndex>". Position identifies a block because
     * normalisation is deterministic: ids are positional and re-normalising is
     * byte-stable, so the same document always yields the same keys.
     *
     * @return array<string, array> empty when the document has no dynamic blocks
     */
    public static function resolveDynamicBlocks(array $document, ?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $resolved = [];

        foreach (($document['sections'] ?? []) as $section) {
            $sid = (string)($section['id'] ?? '');
            foreach (($section['blocks'] ?? []) as $i => $block) {
                if (($block['type'] ?? '') !== 'post-list') continue;

                $props = is_array($block['props'] ?? null) ? $block['props'] : [];
                $type  = trim((string)($props['postType'] ?? 'post')) ?: 'post';
                $limit = max(1, min(50, (int)($props['limit'] ?? 6)));

                // Mirrors the PHP template exactly, including the published-only
                // filter. The API must not surface what the public renderer
                // refuses to.
                $args = ['status' => 'published', 'limit' => $limit];
                if (!empty($props['taxonomy']) && !empty($props['term'])) {
                    $term = self::getTermBySlug(
                        (string)$props['taxonomy'],
                        self::slugify((string)$props['term'])
                    );
                    if ($term) $args['term_id'] = (int)$term['id'];
                }

                $items = [];
                foreach (self::listPosts($type, $args, $tenantId) as $post) {
                    $items[] = [
                        'title'   => (string)($post['title'] ?? ''),
                        'slug'    => (string)($post['slug'] ?? ''),
                        'url'     => self::publicUrl($type, (string)($post['slug'] ?? '')),
                        'excerpt' => (string)($post['excerpt'] ?? ''),
                    ];
                }

                $resolved[$sid . '.' . $i] = ['items' => $items];
            }
        }

        return $resolved;
    }

    /**
     * Build the content envelope for a route.
     *
     * Returns null when nothing is published there, so the caller decides the
     * status code rather than this inventing one.
     *
     * @return array{address:array,document:array,theme:array,meta:array}|null
     */
    public static function contentEnvelope(
        string $route,
        string $locale = 'en',
        ?int $tenantId = null
    ): ?array {
        $tenantId = $tenantId ?? current_tenant_id();

        $slug = trim($route, '/');
        if ($slug === '') $slug = 'home';
        if (str_contains($slug, '/')) {
            $slug = substr($slug, strrpos($slug, '/') + 1);
        }

        $post = self::getPostBySlug('page', $slug, $tenantId);
        if (!$post || ($post['status'] ?? '') !== 'published') return null;

        // Verbatim: normalized through the same schema the HTML renderer reads,
        // never re-serialized differently for this consumer.
        $document = \Slate\Presentation\DocumentSchema::normalize(
            $post['layout'] ?? [],
            (string)($post['type'] ?? 'page')
        );

        return [
            'address' => [
                'site'   => $tenantId,
                'route'  => '/' . $slug,
                'locale' => $locale,
                'type'   => (string)($post['type'] ?? 'page'),
                'slug'   => $slug,
                'status' => (string)($post['status'] ?? ''),
            ],
            'document' => $document,
            'theme'    => self::themeTokens(),
            // Dynamic blocks resolved server-side, keyed by position. Absent
            // members simply render their empty state, so a consumer written
            // against the four-member envelope still works.
            'resolved' => self::resolveDynamicBlocks($document, $tenantId),
            'meta'     => [
                // Slate can live under a sub-path, so a stored '/uploads/x'
                // must render as '/slate/uploads/x'. A consumer on another
                // origin cannot infer that, so the envelope states it.
                'base'       => rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/'),
                'revision'   => $post['updated_at'] ?? null,
                'updated_at' => $post['updated_at'] ?? null,
                'schema'     => \Slate\Presentation\DocumentSchema::VERSION,
            ],
        ];
    }

    /**
     * Theme values as the --slate-* vocabulary both renderers consume
     * (ADR-0008). React reads these as CSS custom properties, which is what
     * keeps one theme description driving both outputs.
     */
    public static function themeTokens(): array {
        $tokens = [];
        try {
            $emitted = Hook::applyFilters('content_theme_tokens', []);
            if (is_array($emitted)) $tokens = $emitted;
        } catch (\Throwable $e) {
            // A theme that cannot resolve must not take the API down; the
            // consumer falls back to its own defaults.
        }
        return ['slug' => (string) Hook::applyFilters('content_active_theme', ''), 'tokens' => $tokens];
    }

    /* ─────────────────────────────── Media references ────────────────── */
    /*                                                                       */
    /* ADR-0013 point 4: an image-bearing block references media by a stable  */
    /* logical key and the renderer resolves it. URLs move — a CDN changes,   */
    /* the install moves under a sub-path, a React host serves assets the PHP */
    /* renderer never sees — and a key does not. That is what lets one        */
    /* document render correctly under two renderers on two hosts.           */

    /**
     * Resolve a block's media reference to a URL, alt text and focal point.
     *
     * The engine owns the CONTRACT, not the mapping. Whoever holds the
     * key -> media relationship answers `content_resolve_media_key`; that keeps
     * the content engine from depending on any one plugin's storage (today
     * react-site-bridge's logical keys, tomorrow something else).
     *
     * A filter may return a URL string, or an array with 'url' and optionally
     * 'alt' and 'focal'. Alt on the block wins over alt from the mapping: the
     * author wrote it for this placement.
     *
     * @param mixed  $media      the block's `media` prop, when present
     * @param string $legacySrc  deprecated raw-URL prop, still read
     * @param string $legacyAlt  alt that accompanied the raw URL
     * @return array{url:string,alt:string,focal:?array,key:string}
     */
    public static function resolveMedia($media, string $legacySrc = '', string $legacyAlt = ''): array {
        $key = is_array($media) ? trim((string)($media['key'] ?? '')) : '';

        if ($key !== '') {
            $resolved = Hook::applyFilters('content_resolve_media_key', null, $key);

            $url = '';
            $mappedAlt = '';
            if (is_string($resolved)) {
                $url = $resolved;
            } elseif (is_array($resolved)) {
                $url       = (string)($resolved['url'] ?? '');
                $mappedAlt = (string)($resolved['alt'] ?? '');
            }

            $focal = (is_array($media['focal'] ?? null) && count($media['focal']) === 2)
                ? array_map('floatval', $media['focal'])
                : null;

            return [
                'url'   => $url !== '' ? self::mediaUrl($url) : '',
                'alt'   => (string)($media['alt'] ?? '') !== '' ? (string)$media['alt'] : $mappedAlt,
                'focal' => $focal,
                'key'   => $key,
            ];
        }

        // Deprecated raw-URL form. Still read so existing documents render;
        // never written by new code.
        return [
            'url'   => $legacySrc !== '' ? self::mediaUrl($legacySrc) : '',
            'alt'   => $legacyAlt,
            'focal' => null,
            'key'   => '',
        ];
    }

    /**
     * key => URL for every resolvable media reference in a layout.
     *
     * The editor holds a KEY, not a URL, so without this a saved image has no
     * thumbnail after a reload — the author would see an empty box and
     * reasonably conclude the image was lost. Resolution has to happen server
     * side because that is where the mapping lives.
     *
     * Keys that resolve to nothing are simply absent from the map rather than
     * present with an empty value: the editor then renders its "unresolved"
     * state, which is the honest thing to show for a key nothing answers.
     */
    /**
     * Does this layout contain a block of $type, anywhere?
     *
     * Lives here rather than in each plugin because more than one now needs it:
     * a block that renders inline has assets, and those assets belong in the host
     * page's head only when the page actually uses the block.
     *
     * A generic walk, for the same reason sanitizeNested() and collectMediaKeys()
     * walk generically — container blocks disagree about which prop holds their
     * children, and a hardcoded list of places to look has already been wrong
     * once here, in a way that let a privileged block ride inside a column.
     */
    public static function layoutHasBlock($layout, string $type): bool {
        if (!is_array($layout)) return false;
        foreach ($layout as $key => $value) {
            if ($key === 'type' && is_string($value) && $value === $type) return true;
            if (is_array($value) && self::layoutHasBlock($value, $type)) return true;
        }
        return false;
    }

    public static function mediaPreviewMap($layout): array {
        $keys = [];
        self::collectMediaKeys($layout, $keys);

        $out = [];
        foreach ($keys as $key) {
            $m = self::resolveMedia(['key' => $key]);
            if ($m['url'] !== '') {
                $out[$key] = $m['url'];
            }
        }
        return $out;
    }

    /**
     * Collect every `key` that looks like a media reference, wherever it sits.
     *
     * A generic walk rather than a per-block-type lookup, for the same reason
     * sanitizeNested() walks generically: container blocks disagree about which
     * prop holds their children, and a hardcoded list of places to look has
     * already been wrong once. Anything that does not resolve is dropped later,
     * so over-collecting is harmless.
     */
    private static function collectMediaKeys($value, array &$keys): void {
        if (!is_array($value)) return;

        $k = $value['key'] ?? null;
        if (is_string($k) && trim($k) !== '' && !in_array($k, $keys, true)) {
            $keys[] = $k;
        }
        foreach ($value as $inner) {
            self::collectMediaKeys($inner, $keys);
        }
    }

    public static function setSiteSetting(string $key, $value): void {
        Database::setSetting('content-builder.' . $key, $value);
    }

    /* ─────────────────────────────── Site theme ──────────────────────── */
    /*                                                                       */
    /* The content engine owns which theme is active; plugins own the themes */
    /* themselves and register them on the `content_register_themes` filter. */
    /* That keeps a theme's tokens next to the blocks they style (e.g. the   */
    /* --sb-* vocabulary stays in small-business-kit) while giving "what is  */
    /* this site's look" a single owner and a single settings screen.        */
    /*                                                                       */
    /* Phase A of the content-engine consolidation. Deliberately NO data     */
    /* migration: the previous owner's key is still read as a fallback, so   */
    /* an install that never opens the new screen keeps the theme it had.    */

    /** Setting key owned by the engine. */
    public const THEME_KEY = 'active_theme';

    /** The key small-business-kit used before the engine took ownership. */
    private const LEGACY_THEME_KEY = 'small-business-kit.active_theme';

    /**
     * Every registered theme, keyed by slug.
     *
     * Each entry: ['label' => string, 'blurb' => string, 'tokens' => [css var => value]].
     */
    public static function themes(): array {
        $themes = Hook::applyFilters('content_register_themes', []);
        return is_array($themes) ? $themes : [];
    }

    public static function getTheme(string $slug): ?array {
        $all = self::themes();
        return $all[$slug] ?? null;
    }

    /**
     * Active theme slug. Falls back to the legacy key, then to the first
     * registered theme, so a fresh install is never themeless.
     */
    public static function activeTheme(): string {
        $slug = (string) self::getSiteSetting(self::THEME_KEY, '');
        if ($slug === '') {
            $slug = (string) (Database::setting(self::LEGACY_THEME_KEY) ?? '');
        }
        if ($slug !== '' && self::getTheme($slug)) return $slug;

        $all = self::themes();
        return $all === [] ? '' : (string) array_key_first($all);
    }

    /** Returns false when the slug is not registered; never stores garbage. */
    public static function setActiveTheme(string $slug): bool {
        if (!self::getTheme($slug)) return false;
        self::setSiteSetting(self::THEME_KEY, $slug);
        // Keep the legacy key in step so a rollback to the old screen is clean.
        Database::setSetting(self::LEGACY_THEME_KEY, $slug);
        return true;
    }

    /* ──────────────────────────── Navigation menus ───────────────────── */
    /*                                                                       */
    /* A menu's `items` is an ordered array of:                              */
    /*   { "label": "...", "url": "/p/about", "type": "custom|page|post" }   */

    public static function getMenus(?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $rows = Database::rows(
            "SELECT * FROM contentbuilder_menus WHERE tenant_id = ? ORDER BY name ASC",
            [$tenantId]);
        return array_map([self::class, 'hydrateMenu'], $rows);
    }

    public static function getMenu(int $id, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_menus WHERE id = ? AND tenant_id = ?",
            [$id, $tenantId]);
        return $row ? self::hydrateMenu($row) : null;
    }

    /** Menu assigned to a location ('header'|'footer'), or null. */
    public static function getMenuByLocation(string $location, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_menus
             WHERE tenant_id = ? AND location = ? ORDER BY id ASC LIMIT 1",
            [$tenantId, $location]);
        return $row ? self::hydrateMenu($row) : null;
    }

    public static function saveMenu(array $data, ?int $tenantId = null): int {
        $tenantId = $tenantId ?? current_tenant_id();
        $id = (int)($data['id'] ?? 0) ?: null;
        $location = in_array($data['location'] ?? '', ['', 'header', 'footer'], true)
                    ? ($data['location'] ?? '') : '';

        // Enforce one menu per location: clear the location off any other menu.
        if ($location !== '') {
            Database::query(
                "UPDATE contentbuilder_menus SET location = '' WHERE tenant_id = ? AND location = ? AND id <> ?",
                [$tenantId, $location, $id ?? 0]);
        }

        $row = [
            'tenant_id' => $tenantId,
            'name'      => mb_substr(trim($data['name'] ?? 'Menu'), 0, 128),
            'location'  => $location,
            'items'     => json_encode(array_values($data['items'] ?? [])),
        ];
        if ($id) {
            Database::update('contentbuilder_menus', $row, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
            return $id;
        }
        return Database::insert('contentbuilder_menus', $row);
    }

    public static function deleteMenu(int $id, ?int $tenantId = null): void {
        $tenantId = $tenantId ?? current_tenant_id();
        Database::query("DELETE FROM contentbuilder_menus WHERE id = ? AND tenant_id = ?", [$id, $tenantId]);
    }

    protected static function hydrateMenu(array $row): array {
        $row['items'] = $row['items'] ? (json_decode($row['items'], true) ?: []) : [];
        return $row;
    }

    /* ──────────────────────────── Page templates ─────────────────────── */
    /*                                                                       */
    /* Premade block layouts a user can start a page from. Pure data — the   */
    /* editor copies the blocks into a new post.                             */

    public static function templates(): array {
        $base = [
            'blank' => [
                'label'  => 'Blank',
                'blocks' => [],
            ],
            'landing' => [
                'label'  => 'Landing page',
                'blocks' => [
                    ['type'=>'hero','props'=>['layout'=>'split','mediaSide'=>'left','eyebrow'=>'Welcome',
                        'heading'=>'Your big headline','subheading'=>'A short supporting sentence that sells the idea.',
                        'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'Learn more','btn2Href'=>'#',
                        'image'=>'','overlay'=>'medium','height'=>'normal']],
                    ['type'=>'icon-grid','props'=>['eyebrow'=>'Why us','heading'=>'Why choose us','bg'=>'white','cols'=>'3','items'=>[
                        ['icon'=>'bolt','title'=>'Fast','text'=>'Describe benefit one.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'shield','title'=>'Reliable','text'=>'Describe benefit two.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'Loved','text'=>'Describe benefit three.','linkText'=>'','linkHref'=>''],
                    ]]],
                    ['type'=>'testimonial','props'=>['quote'=>'This product changed how we work.','author'=>'Jane Doe','role'=>'CEO, Example Co','avatar'=>'']],
                    ['type'=>'cta','props'=>['eyebrow'=>'','heading'=>'Ready to start?','text'=>'Join us today.','btnText'=>'Sign up','btnHref'=>'#']],
                ],
            ],
            'about' => [
                'label'  => 'About',
                'blocks' => [
                    ['type'=>'hero','props'=>['layout'=>'split','mediaSide'=>'right','eyebrow'=>'About us',
                        'heading'=>'Who we are','subheading'=>'A short line about what drives you.',
                        'btnText'=>'','btnHref'=>'','btn2Text'=>'','btn2Href'=>'','image'=>'','height'=>'normal']],
                    ['type'=>'icon-grid','props'=>['eyebrow'=>'Our values','heading'=>'What we stand for','bg'=>'tint','cols'=>'3','items'=>[
                        ['icon'=>'check','title'=>'Integrity','text'=>'Describe this value.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'star','title'=>'Quality','text'=>'Describe this value.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'clock','title'=>'Commitment','text'=>'Describe this value.','linkText'=>'','linkHref'=>''],
                    ]]],
                ],
            ],
            'services' => [
                'label'  => 'Services',
                'blocks' => [
                    ['type'=>'hero','props'=>['layout'=>'banner','eyebrow'=>'Services',
                        'heading'=>'What we offer','subheading'=>'Everything we can do for you.',
                        'btnText'=>'Get a quote','btnHref'=>'/p/contact','btn2Text'=>'','btn2Href'=>'',
                        'image'=>'','overlay'=>'medium','height'=>'normal']],
                    ['type'=>'image-grid','props'=>['eyebrow'=>'','heading'=>'Our services','bg'=>'tint','cols'=>'3','items'=>[
                        ['image'=>'','title'=>'Service one','text'=>'Short description.','href'=>''],
                        ['image'=>'','title'=>'Service two','text'=>'Short description.','href'=>''],
                        ['image'=>'','title'=>'Service three','text'=>'Short description.','href'=>''],
                    ]]],
                    ['type'=>'cta','props'=>['eyebrow'=>'','heading'=>'Want to know more?','text'=>'Get in touch and we will help.','btnText'=>'Contact us','btnHref'=>'/p/contact']],
                ],
            ],
            'contact' => [
                'label'  => 'Contact',
                'blocks' => [
                    ['type'=>'hero','props'=>['layout'=>'banner','eyebrow'=>'Contact',
                        'heading'=>'Get in touch','subheading'=>'We would love to hear from you.',
                        'btnText'=>'','btnHref'=>'','btn2Text'=>'','btn2Href'=>'','image'=>'','overlay'=>'medium','height'=>'short']],
                    ['type'=>'icon-grid','props'=>['eyebrow'=>'','heading'=>'How to reach us','bg'=>'white','cols'=>'3','items'=>[
                        ['icon'=>'heart','title'=>'Email','text'=>'hello@example.com','linkText'=>'','linkHref'=>''],
                        ['icon'=>'clock','title'=>'Hours','text'=>'Mon–Fri, 9am–6pm','linkText'=>'','linkHref'=>''],
                        ['icon'=>'shield','title'=>'Phone','text'=>'(555) 123-4567','linkText'=>'','linkHref'=>''],
                    ]]],
                ],
            ],

            /* ---------- Richer, multi-section "kit" templates ---------- */

            'restaurant-home' => [
                'label'  => 'Restaurant home',
                'blocks' => [
                    ['type'=>'hero','props'=>['pad'=>'spacious','layout'=>'banner','height'=>'tall','overlay'=>'dark',
                        'eyebrow'=>'Welcome','heading'=>'Slow-smoked, done right',
                        'subheading'=>'Hand-crafted barbecue and seasonal sides, served fresh every day.',
                        'btnText'=>'View the menu','btnHref'=>'#','btn2Text'=>'Reserve a table','btn2Href'=>'/p/contact','image'=>'']],
                    ['type'=>'icon-grid','props'=>['pad'=>'normal','eyebrow'=>'Why us','heading'=>'A cut above','bg'=>'white','cols'=>'3','items'=>[
                        ['icon'=>'leaf','title'=>'Fresh daily','text'=>'Locally sourced, prepped in-house each morning.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'star','title'=>'Award-winning','text'=>'Recognised for the best ribs in the county.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'Family recipes','text'=>'Three generations of smokehouse tradition.','linkText'=>'','linkHref'=>''],
                    ]]],
                    ['type'=>'image-grid','props'=>['pad'=>'normal','eyebrow'=>'On the menu','heading'=>'Guest favourites','bg'=>'tint','cols'=>'3','items'=>[
                        ['image'=>'','title'=>'Brisket plate','text'=>'14-hour smoked, sliced to order.','href'=>''],
                        ['image'=>'','title'=>'Baby back ribs','text'=>'Fall-off-the-bone, house rub.','href'=>''],
                        ['image'=>'','title'=>'Pulled pork','text'=>'Piled high with slaw and pickles.','href'=>''],
                    ]]],
                    ['type'=>'testimonial','props'=>['pad'=>'spacious','quote'=>'Best barbecue I have had outside of Texas. We drive an hour just for the brisket.','author'=>'Marcus T.','role'=>'Regular since 2019','avatar'=>'']],
                    ['type'=>'cta','props'=>['pad'=>'spacious','eyebrow'=>'Hungry?','heading'=>'Book your table tonight','text'=>'Walk-ins welcome, reservations recommended on weekends.','btnText'=>'Reserve now','btnHref'=>'/p/contact']],
                ],
            ],

            'business-home' => [
                'label'  => 'Business home',
                'blocks' => [
                    ['type'=>'hero','props'=>['pad'=>'spacious','layout'=>'split','mediaSide'=>'right',
                        'eyebrow'=>'Welcome','heading'=>'Grow your business with confidence',
                        'subheading'=>'Everything you need to launch, manage and scale — in one place.',
                        'btnText'=>'Get started','btnHref'=>'#','btn2Text'=>'Talk to us','btn2Href'=>'/p/contact','image'=>'','height'=>'normal']],
                    ['type'=>'icon-grid','props'=>['pad'=>'normal','eyebrow'=>'Features','heading'=>'Built for results','bg'=>'white','cols'=>'4','items'=>[
                        ['icon'=>'bolt','title'=>'Fast setup','text'=>'Be up and running in minutes.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'shield','title'=>'Secure','text'=>'Bank-grade security by default.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'check','title'=>'Reliable','text'=>'99.9% uptime, every month.','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'Loved','text'=>'Thousands of happy customers.','linkText'=>'','linkHref'=>''],
                    ]]],
                    ['type'=>'icon-grid','props'=>['pad'=>'spacious','eyebrow'=>'By the numbers','heading'=>'Results that speak','bg'=>'dark','cols'=>'3','items'=>[
                        ['icon'=>'star','title'=>'10,000+','text'=>'Active customers','linkText'=>'','linkHref'=>''],
                        ['icon'=>'bolt','title'=>'99.9%','text'=>'Uptime guarantee','linkText'=>'','linkHref'=>''],
                        ['icon'=>'heart','title'=>'4.9/5','text'=>'Average rating','linkText'=>'','linkHref'=>''],
                    ]]],
                    ['type'=>'testimonial','props'=>['pad'=>'spacious','quote'=>'This is the single best decision we made this year.','author'=>'Jane Doe','role'=>'CEO, Example Co','avatar'=>'']],
                    ['type'=>'cta','props'=>['pad'=>'spacious','eyebrow'=>'','heading'=>'Ready to get started?','text'=>'Join thousands who already made the switch.','btnText'=>'Start free trial','btnHref'=>'#']],
                ],
            ],

            'portfolio' => [
                'label'  => 'Portfolio / gallery',
                'blocks' => [
                    ['type'=>'hero','props'=>['pad'=>'spacious','layout'=>'banner','height'=>'normal','overlay'=>'medium',
                        'eyebrow'=>'Portfolio','heading'=>'Selected work',
                        'subheading'=>'A look at recent projects and the results they delivered.',
                        'btnText'=>'','btnHref'=>'','btn2Text'=>'','btn2Href'=>'','image'=>'']],
                    ['type'=>'image-grid','props'=>['pad'=>'normal','eyebrow'=>'','heading'=>'Recent projects','bg'=>'page','cols'=>'3','items'=>[
                        ['image'=>'','title'=>'Project one','text'=>'Brand identity & web design.','href'=>''],
                        ['image'=>'','title'=>'Project two','text'=>'Campaign & art direction.','href'=>''],
                        ['image'=>'','title'=>'Project three','text'=>'Product photography.','href'=>''],
                        ['image'=>'','title'=>'Project four','text'=>'Packaging design.','href'=>''],
                        ['image'=>'','title'=>'Project five','text'=>'Editorial layout.','href'=>''],
                        ['image'=>'','title'=>'Project six','text'=>'Motion & video.','href'=>''],
                    ]]],
                    ['type'=>'cta','props'=>['pad'=>'spacious','eyebrow'=>'','heading'=>'Have a project in mind?','text'=>'Let’s talk about how we can help.','btnText'=>'Get in touch','btnHref'=>'/p/contact']],
                ],
            ],
        ];

        // Editable modern restaurant page — assembled from the rx-* blocks.
        // Every section is fully field-driven; content seeds from each block's
        // registered defaults so there's a single source of truth.
        if (class_exists('BlockRegistry')) {
            $rxOrder  = ['rx-hero','rx-marquee','rx-story','rx-menu','rx-gallery','rx-reviews','rx-visit'];
            $rxBlocks = [];
            foreach ($rxOrder as $t) {
                $def = BlockRegistry::get($t);
                if ($def) $rxBlocks[] = ['type' => $t, 'props' => $def['defaults']];
            }
            if ($rxBlocks) {
                $base['restaurant-modern'] = [
                    'label'    => 'Restaurant — modern (editable)',
                    'category' => 'Full pages',
                    'blocks'   => $rxBlocks,
                ];
            }
        }

        // Designer pages: full, ready-made HTML designs from lib/designs/*.html.
        // These switch the page to 'full_html' render mode when applied.
        return array_merge($base, self::designTemplates());
    }

    /**
     * Design collection — complete, pixel-finished page designs shipped as
     * standalone HTML documents in lib/designs/*.html. Drop a new .html file
     * in that folder (optionally with a header marker) and it appears in the
     * editor's "Page templates" library automatically.
     *
     * Header marker (first line, optional):
     *   <!-- cb-design: label="My design"; category="Designer pages" -->
     *
     * Each design becomes a single Raw HTML block and is tagged mode=full_html
     * so the front-end outputs the document verbatim (no theme shell).
     *
     * @return array<string,array>
     */
    public static function designTemplates(): array {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        $dir = __DIR__ . '/lib/designs';
        if (!is_dir($dir)) return $cache;

        foreach (glob($dir . '/*.html') as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') continue;

            $name     = pathinfo($file, PATHINFO_FILENAME);
            $key      = 'design-' . preg_replace('/[^a-z0-9\-]+/', '-', strtolower($name));
            $label    = ucwords(trim(str_replace(['-', '_'], ' ', $name)));
            $category = 'Designer pages';

            // Optional metadata marker on the first line, then strip it out.
            if (preg_match('/<!--\s*cb-design:(.*?)-->/is', $raw, $m)) {
                if (preg_match('/label\s*=\s*"([^"]*)"/i', $m[1], $mm))    $label    = trim($mm[1]) ?: $label;
                if (preg_match('/category\s*=\s*"([^"]*)"/i', $m[1], $mm)) $category = trim($mm[1]) ?: $category;
                $raw = preg_replace('/^\s*<!--\s*cb-design:.*?-->\s*/is', '', $raw, 1);
            }

            $cache[$key] = [
                'label'    => $label,
                'category' => $category,
                'mode'     => 'full_html',
                'blocks'   => [['type' => 'html', 'props' => ['html' => $raw]]],
            ];
        }
        return $cache;
    }

    public static function template(string $key): ?array {
        return self::templates()[$key] ?? null;
    }

    /** Full-page templates as a JSON payload for the editor's template library. */
    public static function templatesJson(): string {
        $cats = [
            'blank'           => 'Basic',
            'restaurant-home' => 'Full pages',
            'business-home'   => 'Full pages',
            'portfolio'       => 'Full pages',
            'landing'         => 'Marketing',
            'about'           => 'Company',
            'services'        => 'Company',
            'contact'         => 'Company',
        ];
        $out = [];
        foreach (self::templates() as $key => $tpl) {
            $mode = $tpl['mode'] ?? 'builder';

            // A real, rendered HTML preview for the visual picker (iframe srcdoc).
            $preview = '';
            if ($mode === 'full_html') {
                foreach ($tpl['blocks'] as $b) {
                    if (($b['type'] ?? '') === 'html') $preview .= (string)($b['props']['html'] ?? '');
                }
            } elseif (!empty($tpl['blocks']) && class_exists('Theme')) {
                $preview = Theme::previewDoc(self::renderLayout($tpl['blocks']));
            }

            $out[$key] = [
                'label'    => $tpl['label'],
                'category' => $tpl['category'] ?? $cats[$key] ?? 'Pages',
                'mode'     => $mode,
                'preview'  => $preview,
                'blocks'   => $tpl['blocks'],
            ];
        }
        return json_encode($out);
    }

    /* ──────────────────────── Section patterns ────────────────────────── */
    /*                                                                       */
    /* Premade, pre-filled SECTIONS (built from the standard blocks) that    */
    /* the editor can insert into any page. See lib/PatternLibrary.php.      */

    public static function patterns(): array {
        return class_exists('PatternLibrary') ? PatternLibrary::all() : [];
    }

    public static function pattern(string $key): ?array {
        return class_exists('PatternLibrary') ? PatternLibrary::get($key) : null;
    }
}
