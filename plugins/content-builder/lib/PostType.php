<?php
/**
 * PostType — thin read registry over contentbuilder_post_types.
 * Writes go through ContentBuilderAPI::registerPostType().
 */
class PostType {

    public static function all(?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $rows = Database::rows(
            "SELECT * FROM contentbuilder_post_types
             WHERE tenant_id = ? ORDER BY is_builtin DESC, label ASC",
            [$tenantId]);
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function get(string $slug, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_post_types WHERE tenant_id = ? AND slug = ?",
            [$tenantId, $slug]);
        return $row ? self::hydrate($row) : null;
    }

    public static function supports(array $pt, string $feature): bool {
        return in_array($feature, $pt['supports'] ?? [], true);
    }

    protected static function hydrate(array $row): array {
        $row['hierarchical'] = (int)$row['hierarchical'];
        $row['is_builtin']   = (int)$row['is_builtin'];
        $row['supports']     = $row['supports'] ? (json_decode($row['supports'], true) ?: []) : [];
        return $row;
    }
}
