<?php
/**
 * Taxonomy — thin read registry over contentbuilder_taxonomies.
 * Writes go through ContentBuilderAPI::registerTaxonomy().
 */
class Taxonomy {

    public static function all(?string $postType = null, ?int $tenantId = null): array {
        $tenantId = $tenantId ?? current_tenant_id();
        $rows = Database::rows(
            "SELECT * FROM contentbuilder_taxonomies WHERE tenant_id = ? ORDER BY label ASC",
            [$tenantId]);
        $rows = array_map([self::class, 'hydrate'], $rows);
        if ($postType === null) return $rows;
        return array_values(array_filter($rows, fn($t) => in_array($postType, $t['post_types'], true)));
    }

    public static function get(string $slug, ?int $tenantId = null): ?array {
        $tenantId = $tenantId ?? current_tenant_id();
        $row = Database::row(
            "SELECT * FROM contentbuilder_taxonomies WHERE tenant_id = ? AND slug = ?",
            [$tenantId, $slug]);
        return $row ? self::hydrate($row) : null;
    }

    protected static function hydrate(array $row): array {
        $row['hierarchical'] = (int)$row['hierarchical'];
        $row['post_types']   = $row['post_types'] ? (json_decode($row['post_types'], true) ?: []) : [];
        return $row;
    }
}
