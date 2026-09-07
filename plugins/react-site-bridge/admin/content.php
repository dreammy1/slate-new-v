<?php
/**
 * React Site Bridge — visual copy editor for existing route documents.
 *
 * This editor deliberately exposes only existing text fields. It preserves the
 * route schema, field names, array structure, and component-controlled layout
 * so a non-developer can update approved website copy safely.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
Auth::require();
Auth::requirePerm('react-site-bridge.manage');
ReactSiteBridgeAPI::ensureSchema();

function rsc_content_token(array $path): string {
    return rtrim(strtr(base64_encode(json_encode(array_values($path), JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
}

function rsc_content_path(string $token): array {
    $token = strtr($token, '-_', '+/');
    $token .= str_repeat('=', (4 - (strlen($token) % 4)) % 4);
    $path = json_decode((string)base64_decode($token, true), true);
    return is_array($path) ? $path : [];
}

function rsc_content_fields($value, array $path = []): array {
    if (is_string($value)) return [['path' => $path, 'value' => $value]];
    if (!is_array($value)) return [];
    $fields = [];
    foreach ($value as $key => $child) {
        foreach (rsc_content_fields($child, array_merge($path, [(string)$key])) as $field) $fields[] = $field;
    }
    return $fields;
}

function rsc_content_set(array &$document, array $path, string $value): void {
    if (!$path) return;
    $target =& $document;
    $last = array_pop($path);
    foreach ($path as $segment) {
        if (!isset($target[$segment]) || !is_array($target[$segment])) $target[$segment] = [];
        $target =& $target[$segment];
    }
    $target[$last] = $value;
}

function rsc_content_label(array $path): string {
    $parts = [];
    foreach ($path as $part) {
        if (ctype_digit((string)$part)) $parts[] = 'Item ' . ((int)$part + 1);
        else $parts[] = ucwords(str_replace(['_', '-'], ' ', (string)$part));
    }
    return implode(' · ', $parts);
}

function rsc_content_is_long(array $path, string $value): bool {
    $key = strtolower((string)end($path));
    return strlen($value) > 105 || in_array($key, ['intro', 'lede', 'description', 'body', 'copy', 'summary', 'details', 'address'], true);
}

$siteId = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$site = ReactSiteBridgeAPI::getSite($siteId);
if (!$site) { http_response_code(404); echo 'React site not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        header('Location: ' . SLATE_URL . '/plugins/react-site-bridge/admin/content.php?id=' . $siteId . '&error=csrf');
        exit;
    }
    try {
        $documentId = (int)($_POST['document_id'] ?? 0);
        $document = ReactSiteBridgeAPI::getDocument($siteId, $documentId);
        if (!$document) throw new InvalidArgumentException('Route document not found.');
        $data = is_array($document['document']) ? $document['document'] : [];
        foreach ((array)($_POST['field'] ?? []) as $token => $value) {
            $path = rsc_content_path((string)$token);
            if ($path) rsc_content_set($data, $path, mb_substr((string)$value, 0, 10000));
        }
        ReactSiteBridgeAPI::saveDocument($siteId, $documentId, (string)$document['route'], (string)$document['schema_key'], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (string)$document['locale']);
        AuditLog::record('react_site_bridge.content_editor_saved', 'site#' . $siteId . ' document#' . $documentId);
        $published = false;
        if (($_POST['_action'] ?? '') === 'save_publish_content') {
            if (!Auth::can('react-site-bridge.publish') && !Auth::isSuperAdmin()) throw new RuntimeException('You do not have permission to publish React site revisions.');
            $revision = ReactSiteBridgeAPI::publish($siteId, 'Published from the visual content editor.');
            AuditLog::record('react_site_bridge.content_editor_published', 'site#' . $siteId . ' v' . $revision['version']);
            $published = true;
        }
        header('Location: ' . SLATE_URL . '/plugins/react-site-bridge/admin/content.php?id=' . $siteId . '&document=' . $documentId . '&saved=1' . ($published ? '&published=1' : ''));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$documents = array_values(array_filter(ReactSiteBridgeAPI::listDocuments($siteId), static function (array $document): bool {
    return (string)($document['route'] ?? '') !== '/__visual-editor__' && (string)($document['schema_key'] ?? '') !== 'react-site-visual.v1';
}));
$selectedId = (int)($_GET['document'] ?? 0);
if (!$selectedId && $documents) $selectedId = (int)$documents[0]['id'];
$selected = $selectedId ? ReactSiteBridgeAPI::getDocument($siteId, $selectedId) : null;
$pageTitle = 'Content editor · ' . $site['name'];
$currentNav = 'react-site-bridge';
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => 'React Sites', 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/index.php'], ['label' => $site['name'], 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId], ['label' => 'Content editor']]); ?>
<div class="page-header"><div><h1>Content editor</h1><p class="page-header-sub">Edit approved website copy for <?= e($site['name']) ?> without touching React code.</p></div><div class="rsc-content-actions"><a class="btn btn-secondary" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId) ?>">Site management</a><a class="btn btn-primary" target="_blank" rel="noopener" href="<?= e(ReactSiteBridgeAPI::hostedUrl($siteId)) ?>">Open live site</a></div></div>
<?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($_GET['error'])): ?><div class="alert alert-error" role="alert">Security check failed. Please try again.</div><?php endif; ?>
<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success" role="status"><?= !empty($_GET['published']) ? 'Changes saved and published. Reload the live site to view the update.' : 'Changes saved as a draft. Select “Save & publish” when you are ready to make them live.' ?></div><?php endif; ?>
<div class="rsc-content-layout">
  <aside class="card rsc-content-routes"><div class="card-header"><h2>Website pages</h2><p>Choose a page to update its existing text fields.</p></div>
    <?php foreach ($documents as $document): $active = (int)$document['id'] === $selectedId; ?>
      <a class="rsc-content-route<?= $active ? ' is-active' : '' ?>" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/content.php?id=' . $siteId . '&document=' . (int)$document['id']) ?>"><strong><?= e($document['route']) ?></strong><span><?= e($document['schema_key'] ?: 'Route document') ?></span></a>
    <?php endforeach; ?>
  </aside>
  <main class="card rsc-content-form">
    <?php if (!$selected): ?><div class="card-header"><h2>No editable pages yet</h2><p>Add route documents from Site management before using this editor.</p></div>
    <?php else: $fields = rsc_content_fields($selected['document']); ?>
      <div class="card-header"><h2><?= e($selected['route']) ?></h2><p>Only text is editable here. The page design, navigation, and data structure remain protected.</p></div>
      <form method="post"><input type="hidden" name="site_id" value="<?= $siteId ?>"><input type="hidden" name="document_id" value="<?= (int)$selected['id'] ?>"><?= csrf_field() ?>
        <div class="rsc-content-notice"><strong>How publishing works</strong><span>Save draft keeps changes private. Save &amp; publish updates the public manifest used by the live Slate-hosted Kaimana site.</span></div>
        <div class="rsc-content-fields">
          <?php foreach ($fields as $field): $path = $field['path']; $value = $field['value']; $long = rsc_content_is_long($path, $value); ?>
            <div class="field rsc-content-field<?= $long ? ' is-long' : '' ?>"><label class="field-label" for="field-<?= e(rsc_content_token($path)) ?>"><?= e(rsc_content_label($path)) ?></label>
              <?php if ($long): ?><textarea id="field-<?= e(rsc_content_token($path)) ?>" name="field[<?= e(rsc_content_token($path)) ?>]" rows="<?= strlen($value) > 420 ? 7 : 4 ?>"><?= e($value) ?></textarea><?php else: ?><input id="field-<?= e(rsc_content_token($path)) ?>" name="field[<?= e(rsc_content_token($path)) ?>]" value="<?= e($value) ?>"><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="rsc-content-submit"><button class="btn btn-secondary" type="submit" name="_action" value="save_content">Save draft</button><?php if (Auth::can('react-site-bridge.publish') || Auth::isSuperAdmin()): ?><button class="btn btn-primary" type="submit" name="_action" value="save_publish_content">Save &amp; publish</button><?php endif; ?></div>
      </form>
    <?php endif; ?>
  </main>
</div>
<style>
.rsc-content-actions{display:flex;gap:10px;flex-wrap:wrap}.rsc-content-layout{display:grid;grid-template-columns:minmax(220px,.52fr) minmax(0,1.48fr);gap:18px;align-items:start}.rsc-content-routes{padding-bottom:10px}.rsc-content-routes .card-header p,.rsc-content-form .card-header p{margin:5px 0 0;color:var(--muted,#6b7280);font-size:.9rem}.rsc-content-route{display:block;padding:13px 18px;border-top:1px solid var(--border,#e5e7eb);text-decoration:none;color:inherit}.rsc-content-route:hover,.rsc-content-route.is-active{background:color-mix(in srgb,var(--primary,#0f56a6) 8%,transparent)}.rsc-content-route.is-active{border-left:3px solid var(--primary,#0f56a6);padding-left:15px}.rsc-content-route strong,.rsc-content-route span{display:block}.rsc-content-route span{margin-top:3px;font-size:.78rem;color:var(--muted,#6b7280)}.rsc-content-form{padding-bottom:22px}.rsc-content-form .card-header{padding-bottom:8px}.rsc-content-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;margin:0 0 20px;border-left:3px solid var(--primary,#0f56a6);background:color-mix(in srgb,var(--primary,#0f56a6) 6%,transparent);font-size:.88rem}.rsc-content-notice span{color:var(--muted,#6b7280)}.rsc-content-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.rsc-content-field.is-long{grid-column:1/-1}.rsc-content-field textarea{min-height:96px;resize:vertical}.rsc-content-submit{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;padding-top:18px;border-top:1px solid var(--border,#e5e7eb)}@media(max-width:850px){.rsc-content-layout{grid-template-columns:1fr}.rsc-content-routes{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr)}.rsc-content-routes .card-header{grid-column:1/-1}.rsc-content-route{border-top:1px solid var(--border,#e5e7eb);border-left:0}.rsc-content-route.is-active{border-left:0;border-bottom:3px solid var(--primary,#0f56a6);padding-left:18px}}@media(max-width:620px){.rsc-content-fields{grid-template-columns:1fr}.rsc-content-actions{margin-top:12px}}
</style>
<?php require $root . '/admin/partials/footer.php'; ?>
