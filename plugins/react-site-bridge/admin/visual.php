<?php
/**
 * Slate dark-workspace visual editor: precise, reversible live-canvas controls.
 *
 * The admin canvas speaks only to the same-origin React runtime bridge. It saves
 * a constrained visual document through ReactSiteBridgeAPI, preserving tenant,
 * revision, CSRF, permission, and audit-log behavior already used by the text editor.
 */
$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';
require_once dirname(__DIR__) . '/ReactSiteBridgeAPI.php';
Auth::require();
Auth::requirePerm('react-site-bridge.manage');
ReactSiteBridgeAPI::ensureSchema();

const RSC_VISUAL_ROUTE = '/__visual-editor__';
const RSC_VISUAL_SCHEMA = 'react-site-visual.v1';

function rsc_visual_valid_route(string $route): string {
    $route = '/' . trim($route, '/');
    return preg_match('#^/[A-Za-z0-9/_-]*$#', $route) ? ($route === '//' ? '/' : $route) : '/';
}

function rsc_visual_element_key(string $key): string {
    return preg_match('/^[a-z][a-z0-9._:-]{0,159}$/i', $key) ? $key : '';
}

function rsc_visual_string(string $value, int $limit = 120): string {
    return mb_substr(trim($value), 0, $limit);
}

function rsc_visual_valid_color(string $value): string {
    $value = trim($value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : '';
}

function rsc_visual_valid_length(string $value, int $max = 160): string {
    $value = trim($value);
    return preg_match('/^(?:0|[0-9]{1,3}(?:\.[0-9]{1,2})?)(?:px|rem|em|%|vw|vh)$/', $value) && strlen($value) <= $max ? $value : '';
}

function rsc_visual_valid_link(string $value): string {
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F<>"\']/', $value)) return '';
    if (preg_match('/^(?:#|\/)(?!\/)/', $value) || preg_match('/^(?:mailto:|tel:)/i', $value)) return $value;
    $parts = parse_url($value);
    return is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) ? $value : '';
}

function rsc_visual_valid_media_url(string $value): string {
    $value = trim($value);
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x1F<>"\']/', $value)) return '';
    if (preg_match('#^/(?!/)#', $value)) return $value;
    $parts = parse_url($value);
    $slate = parse_url(rtrim(SLATE_URL, '/'));
    if (!is_array($parts) || !is_array($slate) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return '';
    return strtolower((string)($parts['host'] ?? '')) === strtolower((string)($slate['host'] ?? '')) ? $value : '';
}

function rsc_visual_valid_dark_overlay(string $value): string {
    $value = trim($value);
    if (!preg_match('/^(?:0(?:\.\d{1,2})?|\.\d{1,2})$/', $value)) return '';
    $opacity = (float)$value;
    if ($opacity < 0 || $opacity > 0.9) return '';
    return rtrim(rtrim(number_format($opacity, 2, '.', ''), '0'), '.') ?: '0';
}

function rsc_visual_sanitize_media($media): array {
    if (!is_array($media)) return [];
    $result = [];
    if (isset($media['src']) && is_scalar($media['src'])) {
        $src = rsc_visual_valid_media_url((string)$media['src']);
        if ($src !== '') $result['src'] = $src;
    }
    if (isset($media['backgroundImage']) && is_scalar($media['backgroundImage'])) {
        $background = rsc_visual_valid_media_url((string)$media['backgroundImage']);
        if ($background !== '') $result['backgroundImage'] = $background;
    }
    if (isset($media['darkOverlay']) && is_scalar($media['darkOverlay'])) {
        $overlay = rsc_visual_valid_dark_overlay((string)$media['darkOverlay']);
        if ($overlay !== '') $result['darkOverlay'] = $overlay;
    }
    if (isset($media['focalX']) && is_scalar($media['focalX'])) {
        $fx = (int)$media['focalX'];
        if ($fx >= 0 && $fx <= 100) $result['focalX'] = (string)$fx;
    }
    if (isset($media['focalY']) && is_scalar($media['focalY'])) {
        $fy = (int)$media['focalY'];
        if ($fy >= 0 && $fy <= 100) $result['focalY'] = (string)$fy;
    }
    if (isset($media['alt']) && is_scalar($media['alt'])) $result['alt'] = mb_substr(trim((string)$media['alt']), 0, 500);
    return $result;
}

function rsc_visual_sanitize_style($style): array {
    if (!is_array($style)) return [];
    $result = [];
    foreach ($style as $property => $value) {
        $property = (string)$property;
        $value = is_scalar($value) ? (string)$value : '';
        if (in_array($property, ['color', 'backgroundColor', 'borderColor'], true)) {
            // Transparent is a deliberate and durable editor choice for text
            // backgrounds; an empty value still restores the approved baseline.
            $sanitized = $property === 'backgroundColor' && strtolower(trim($value)) === 'transparent'
                ? 'transparent'
                : rsc_visual_valid_color($value);
        } elseif (in_array($property, ['fontSize', 'lineHeight', 'letterSpacing', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'marginTop', 'marginBottom', 'borderWidth', 'borderRadius', 'maxWidth'], true)) {
            $sanitized = rsc_visual_valid_length($value);
        } elseif ($property === 'fontWeight') {
            $sanitized = in_array($value, ['400', '500', '600', '700'], true) ? $value : '';
        } elseif ($property === 'textAlign') {
            $sanitized = in_array($value, ['left', 'center', 'right'], true) ? $value : '';
        } elseif ($property === 'opacity') {
            $sanitized = preg_match('/^(?:0(?:\.[0-9]{1,2})?|1(?:\.0{1,2})?)$/', $value) ? $value : '';
        } else {
            $sanitized = '';
        }
        if ($sanitized !== '') $result[$property] = $sanitized;
    }
    return $result;
}

function rsc_visual_sanitize_payload($payload, array $allowedRoutes): array {
    $clean = ['version' => 1, 'routes' => []];
    if (!is_array($payload) || !is_array($payload['routes'] ?? null)) return $clean;
    $allowed = array_fill_keys($allowedRoutes, true);
    foreach ($payload['routes'] as $route => $elements) {
        $route = rsc_visual_valid_route((string)$route);
        if (!isset($allowed[$route]) || !is_array($elements)) continue;
        $cleanElements = [];
        foreach ($elements as $key => $override) {
            $key = rsc_visual_element_key((string)$key);
            if ($key === '' || !is_array($override)) continue;
            $item = [];
            if (isset($override['text']) && is_scalar($override['text'])) {
                $text = trim((string)$override['text']);
                if ($text !== '') $item['text'] = mb_substr($text, 0, 1000);
            }
            $style = rsc_visual_sanitize_style($override['style'] ?? []);
            if ($style) $item['style'] = $style;
            if (isset($override['link']) && is_scalar($override['link'])) {
                $link = rsc_visual_valid_link((string)$override['link']);
                if ($link !== '') $item['link'] = $link;
            }
            $media = rsc_visual_sanitize_media($override['media'] ?? []);
            if ($media) $item['media'] = $media;
            if ($item) $cleanElements[$key] = $item;
        }
        if ($cleanElements) $clean['routes'][$route] = $cleanElements;
    }
    // An empty PHP array serializes as JSON [], but routes is a keyed map. Keep
    // its empty form as {} so browser-side route assignment remains serializable.
    if ($clean['routes'] === []) $clean['routes'] = (object)[];
    return $clean;
}

$siteId = (int)($_GET['id'] ?? $_POST['site_id'] ?? 0);
$site = ReactSiteBridgeAPI::getSite($siteId);
if (!$site) { http_response_code(404); echo 'React site not found.'; exit; }
$documents = ReactSiteBridgeAPI::listDocuments($siteId);
$mediaManifest = ReactSiteBridgeAPI::buildManifest($siteId)['media'] ?? [];
$mediaFocalDefaults = [];
foreach ($mediaManifest as $logicalKey => $asset) {
    $x = $asset['focalX'] ?? ($asset['focal']['x'] ?? 50);
    $y = $asset['focalY'] ?? ($asset['focal']['y'] ?? 50);
    $mediaFocalDefaults[(string)$logicalKey] = [
        'x' => is_numeric($x) ? max(0, min(100, (int)round((float)$x))) : 50,
        'y' => is_numeric($y) ? max(0, min(100, (int)round((float)$y))) : 50,
    ];
}
$routes = [];
$visualDocument = null;
foreach ($documents as $document) {
    if ((string)$document['route'] === RSC_VISUAL_ROUTE || (string)$document['schema_key'] === RSC_VISUAL_SCHEMA) {
        $visualDocument = $document;
        continue;
    }
    $route = rsc_visual_valid_route((string)$document['route']);
    if (!in_array($route, $routes, true)) $routes[] = $route;
}
if (!in_array('/', $routes, true)) array_unshift($routes, '/');
sort($routes);
$selectedRoute = rsc_visual_valid_route((string)($_GET['route'] ?? $_POST['route'] ?? ($routes[0] ?? '/')));
if (!in_array($selectedRoute, $routes, true)) $selectedRoute = $routes[0] ?? '/';
$visualData = is_array($visualDocument['document'] ?? null) ? $visualDocument['document'] : ['version' => 1, 'routes' => (object)[]];
$visualRevisions = ReactSiteBridgeAPI::listVisualRevisions($siteId, 16);
$visualDraftInfo = [
    'updated_at' => (string)($visualDocument['updated_at'] ?? ''),
    'updated_by' => (int)($visualDocument['updated_by'] ?? 0),
];
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } else {
        try {
            $raw = json_decode((string)($_POST['visual_json'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            $visualData = rsc_visual_sanitize_payload($raw, $routes);
            $documentId = (int)($visualDocument['id'] ?? 0);
            $documentId = ReactSiteBridgeAPI::saveDocument(
                $siteId,
                $documentId,
                RSC_VISUAL_ROUTE,
                RSC_VISUAL_SCHEMA,
                json_encode($visualData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'en'
            );
            AuditLog::record('react_site_bridge.visual_editor_saved', 'site#' . $siteId . ' document#' . $documentId);
            $published = false;
            if (($_POST['_action'] ?? '') === 'save_publish_visual') {
                if (!Auth::can('react-site-bridge.publish') && !Auth::isSuperAdmin()) throw new RuntimeException('You do not have permission to publish React site revisions.');
                $revision = ReactSiteBridgeAPI::publish($siteId, 'Published from the live visual editor.');
                AuditLog::record('react_site_bridge.visual_editor_published', 'site#' . $siteId . ' v' . $revision['version']);
                $published = true;
            }
            header('Location: ' . SLATE_URL . '/plugins/react-site-bridge/admin/visual.php?id=' . $siteId . '&route=' . rawurlencode($selectedRoute) . '&saved=1' . ($published ? '&published=1' : ''));
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    }
}

$hostedUrl = rtrim((string)ReactSiteBridgeAPI::hostedUrl($siteId), '/');
if ($hostedUrl === '') {
    $flash = $flash ?: ['type' => 'error', 'msg' => 'Upload a hosted React release before using the visual editor.'];
}
$canvasUrl = $hostedUrl ? $hostedUrl . ($selectedRoute === '/' ? '/' : $selectedRoute) : '';
$pageTitle = 'Visual editor · ' . $site['name'];
$currentNav = 'react-site-bridge';
require $root . '/admin/partials/header.php';
?>
<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'], ['label' => 'React Sites', 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/index.php'], ['label' => $site['name'], 'href' => SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId], ['label' => 'Visual editor']]); ?>
<div class="rsc-visual-app" data-site-id="<?= (int)$siteId ?>">
  <header class="rsc-visual-topbar">
    <div class="rsc-visual-header-left"><a class="rsc-visual-back" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/site.php?id=' . $siteId) ?>" aria-label="Back to site management">←</a><div class="rsc-visual-brand"><span class="rsc-visual-dot"></span><strong><?= e($site['name']) ?></strong><span>Visual editor</span></div><div class="rsc-visual-change-status" id="rsc-change-status" aria-live="polite"><span></span><strong>Saved baseline</strong><em id="rsc-change-count">0 current-page changes</em></div></div>
    <div class="rsc-visual-header-center"><div class="rsc-visual-device-group" aria-label="Canvas size"><button type="button" class="is-active" data-device="desktop" title="Desktop">▣</button><button type="button" data-device="tablet" title="Tablet">▯</button><button type="button" data-device="mobile" title="Mobile">▯</button></div><form method="get" class="rsc-visual-route-form"><input type="hidden" name="id" value="<?= (int)$siteId ?>"><span class="rsc-visual-route-home" aria-hidden="true">⌂</span><span class="rsc-visual-route-mobile-label" aria-hidden="true"><b>⌂</b><strong><?= e($selectedRoute === '/' ? 'Home' : ltrim($selectedRoute, '/')) ?></strong><i>⌄</i></span><select name="route" aria-label="Canvas page" onchange="this.form.submit()"><?php foreach ($routes as $route): ?><option value="<?= e($route) ?>" <?= $route === $selectedRoute ? 'selected' : '' ?>><?= e($route === '/' ? '/' : $route) ?></option><?php endforeach; ?></select></form><div class="rsc-visual-header-actions"><a class="rsc-visual-icon" target="_blank" rel="noopener" href="<?= e($hostedUrl ?: '#') ?>" title="Open live site">↗</a><a class="rsc-visual-icon" href="<?= e(SLATE_URL . '/plugins/react-site-bridge/admin/photography.php?id=' . $siteId) ?>" title="Upload and map approved photography">▦</a><button class="rsc-visual-icon" type="button" id="rsc-open-canvas" title="Open canvas in a new tab">↗</button><button class="rsc-visual-icon" type="button" id="rsc-undo" title="Undo local change" disabled>↶</button><button class="rsc-visual-icon" type="button" id="rsc-redo" title="Redo local change" disabled>↷</button><button class="rsc-visual-icon" type="button" id="rsc-reload" title="Reload canvas">↻</button><button class="rsc-visual-icon" type="button" id="rsc-reset-preview" title="Discard local preview changes">⌫</button><button class="rsc-visual-icon" type="button" id="rsc-history-toggle" title="Open edit history" aria-controls="rsc-history-panel" aria-expanded="false">◷</button><button class="rsc-visual-icon rsc-focus-toggle" type="button" id="rsc-focus-toggle" title="Hide top controls" aria-pressed="false" aria-label="Hide top controls for focused editing">⛶</button></div></div>
    <div class="rsc-visual-header-right"><button type="button" class="rsc-visual-mode-chip is-edit" id="rsc-header-canvas-mode" aria-pressed="true"><span aria-hidden="true">✎</span><strong id="rsc-header-canvas-mode-label">Edit mode</strong></button><span class="rsc-visual-network-badge" title="Connection speed indicator"><i aria-hidden="true">●</i>15.8 Mbps</span><?php if (Auth::can('react-site-bridge.publish') || Auth::isSuperAdmin()): ?><button class="rsc-visual-publish" type="button" id="rsc-submit-publish">Review &amp; publish</button><?php endif; ?></div>
  </header>
  <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>
  <?php if (!empty($_GET['saved'])): ?><div class="alert alert-success" role="status"><?= !empty($_GET['published']) ? 'Visual changes saved and published. Reload the live site to view them.' : 'Visual changes saved as a draft. Publish when you are ready.' ?></div><?php endif; ?>
  <div class="rsc-visual-workspace">
    <main class="rsc-visual-stage"><button type="button" class="rsc-focus-exit" id="rsc-focus-exit" hidden>Show controls <span aria-hidden="true">⌃</span></button><div class="rsc-canvas-touchbar"><button type="button" id="rsc-canvas-mode" class="is-edit" aria-pressed="true"><strong>Edit mode</strong><span>Tap an element to edit</span></button></div><button type="button" class="rsc-show-inspector" id="rsc-show-inspector" aria-controls="rsc-visual-inspector" hidden><span aria-hidden="true">◫</span>Show editor panel</button><div id="rsc-canvas-wrap" class="rsc-canvas-wrap rsc-canvas--desktop" data-rsc-canvas-mode="edit"><?php if ($canvasUrl): ?><iframe id="rsc-canvas" title="<?= e($site['name']) ?> visual editing canvas" src="<?= e($canvasUrl) ?>"></iframe><?php else: ?><div class="rsc-visual-empty"><strong>Hosted React release required</strong><span>Upload a release from Site management, then return here to edit it visually.</span></div><?php endif; ?></div></main>
    <aside class="rsc-visual-inspector" id="rsc-visual-inspector" data-rsc-sheet-state="collapsed">
      <div class="rsc-inspector-head"><button type="button" class="rsc-sheet-toggle" id="rsc-sheet-toggle" aria-expanded="false"><span class="rsc-sheet-grip" aria-hidden="true"></span><span><small id="rsc-selection-tag">Select an element</small><strong id="rsc-selection-name">Tap an element to open its controls.</strong></span><i aria-hidden="true">⌃</i></button><button type="button" id="rsc-clear-selection" title="Clear selection" aria-label="Clear selection">↺</button><button type="button" class="rsc-inspector-visibility-toggle" id="rsc-hide-inspector" title="Hide editor panel" aria-label="Hide editor panel" aria-controls="rsc-visual-inspector">×</button></div>
      <div class="rsc-selection-actions"><button type="button" id="rsc-reset-selected" disabled>Reset selected element</button><span>Removes only this element’s local visual override.</span></div>
      <div class="rsc-inspector-tabs"><button class="is-active" type="button" data-tab="content">Content</button><button type="button" data-tab="style">Style</button><button type="button" data-tab="layout">Layout</button></div>
      <div class="rsc-inspector-scroll">
        <section class="rsc-inspector-panel is-active" data-panel="content"><label>Text content<textarea id="rsc-text" maxlength="1000" placeholder="Select a text element to edit its approved copy."></textarea></label><label>Button or link destination<input id="rsc-link" type="text" maxlength="2048" placeholder="/electric/contact, https://…, mailto:…, or tel:…"></label><label>Find approved media<input id="rsc-media-filter" type="search" placeholder="Search name, description, or file type"></label><section id="rsc-media-library" class="rsc-media-library" aria-label="Approved Slate Media library"><div class="rsc-media-library-head"><strong>Approved image library</strong><span>Click an image to apply it</span></div><div id="rsc-media-grid" class="rsc-media-grid" aria-live="polite"><?php foreach ($mediaManifest as $logicalKey => $asset): $url = (string)($asset['url'] ?? ''); if ($url === '') continue; $alt = (string)($asset['alt'] ?? ''); $mime = (string)($asset['mime'] ?? 'image'); $width = (int)($asset['width'] ?? 0); $height = (int)($asset['height'] ?? 0); $details = $width > 0 && $height > 0 ? $width . '×' . $height : $mime; $search = strtolower($logicalKey . ' ' . $alt . ' ' . $mime . ' ' . $details); ?><button type="button" class="rsc-media-card" data-rsc-media-key="<?= e((string)$logicalKey) ?>" data-rsc-media-url="<?= e($url) ?>" data-rsc-media-alt="<?= e($alt) ?>" data-rsc-media-search="<?= e($search) ?>" aria-pressed="false" title="Apply <?= e((string)$logicalKey) ?>"><img src="<?= e($url) ?>" alt="" loading="lazy"><span><strong><?= e((string)$logicalKey) ?></strong><small><?= e($details) ?></small></span><i aria-hidden="true">✓</i></button><?php endforeach; ?></div><p id="rsc-media-library-empty" class="rsc-media-library-empty" hidden>No approved Slate Media items match this search.</p></section><div id="rsc-media-preview" class="rsc-media-preview" aria-live="polite"><span>Select an image target to preview its approved source.</span></div><label>Image or background URL<input id="rsc-media-source" type="url" maxlength="2048" placeholder="Choose a library image or enter a Slate-hosted URL"></label><label>Alternative text<input id="rsc-media-alt" type="text" maxlength="500" placeholder="Describe the selected image"></label><p class="rsc-control-note">The library shows only this React site’s approved Slate Media mappings. Selecting a card updates the live preview only; save a draft or publish separately. Images must come from Slate-hosted media or the packaged site.</p></section>
        <section id="rsc-focal-controls" class="rsc-inspector-panel is-active rsc-focal-controls" data-panel="content" hidden aria-label="Background crop focal point"><div class="rsc-focal-heading"><strong>Background crop focus</strong><span>Click or drag the marker</span></div><div id="rsc-focal-preview" class="rsc-focal-preview" role="img" aria-label="Background crop preview. Use the focal-point sliders below for precise control." tabindex="0"><span class="rsc-focal-preview-hint">Drag to retain this area</span></div><label>Focal point X <div class="rsc-range"><input id="rsc-focal-x" type="range" min="0" max="100" step="1" value="50"><output id="rsc-focal-x-output">50%</output></div></label><label>Focal point Y <div class="rsc-range"><input id="rsc-focal-y" type="range" min="0" max="100" step="1" value="50"><output id="rsc-focal-y-output">50%</output></div></label><p class="rsc-control-note">Choose the part of the background that remains visible when the panel crops on smaller screens. The crop preview updates before anything is applied.</p></section>
        <section class="rsc-inspector-panel" data-panel="style">
          <div class="rsc-control-group"><button type="button" class="rsc-control-heading">Image contrast <span>⌃</span></button><div class="rsc-control-body"><label>Additional dark overlay <div class="rsc-range"><input id="rsc-dark-overlay" type="range" min="0" max="90" step="5" value="0"><output id="rsc-dark-overlay-output">0%</output></div></label><p class="rsc-control-note">Applies only to a selected prepared background-image panel. It adds a dark Navy overlay behind text without modifying the source image.</p></div></div>
          <div class="rsc-control-group"><button type="button" class="rsc-control-heading">Colors <span>⌃</span></button><div class="rsc-control-body"><label>Text color <input id="rsc-color" type="color" value="#102832"></label><label>Background <input id="rsc-background" type="color" value="#FFFFFF"></label><button type="button" class="rsc-remove-background" id="rsc-remove-text-background" aria-pressed="false" disabled>Remove text background</button><p class="rsc-control-note rsc-remove-background-note">For text targets only. Choose a color again or reset the selected element to restore the approved fill.</p></div></div>
          <div class="rsc-control-group"><button type="button" class="rsc-control-heading">Typography <span>⌃</span></button><div class="rsc-control-body"><label>Font size <div class="rsc-range"><input id="rsc-font-size" type="range" min="12" max="104" step="1"><output id="rsc-font-size-output">—</output></div></label><label>Weight <select id="rsc-font-weight"><option value="">Use site style</option><option value="400">Regular</option><option value="500">Medium</option><option value="600">Semibold</option><option value="700">Bold</option></select></label><label>Alignment <select id="rsc-text-align"><option value="">Use site style</option><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></label></div></div>
          <div class="rsc-control-group"><button type="button" class="rsc-control-heading">Border <span>⌃</span></button><div class="rsc-control-body"><label>Border color <input id="rsc-border-color" type="color" value="#102832"></label><label>Corner radius <div class="rsc-range"><input id="rsc-radius" type="range" min="0" max="48" step="1"><output id="rsc-radius-output">—</output></div></label></div></div>
        </section>
        <section class="rsc-inspector-panel" data-panel="layout"><label>Top spacing <div class="rsc-range"><input id="rsc-padding-top" type="range" min="0" max="160" step="4"><output id="rsc-padding-top-output">—</output></div></label><label>Bottom spacing <div class="rsc-range"><input id="rsc-padding-bottom" type="range" min="0" max="160" step="4"><output id="rsc-padding-bottom-output">—</output></div></label><label>Maximum width <div class="rsc-range"><input id="rsc-max-width" type="range" min="0" max="1200" step="20"><output id="rsc-max-width-output">—</output></div></label><p class="rsc-control-note">Layout controls apply only to the selected component. Use zero maximum width to restore its approved width.</p></section>
      </div>
      <form method="post" id="rsc-visual-form" class="rsc-inspector-actions"><input type="hidden" name="site_id" value="<?= (int)$siteId ?>"><input type="hidden" name="route" value="<?= e($selectedRoute) ?>"><?= csrf_field() ?><input type="hidden" id="rsc-visual-json" name="visual_json"><button type="button" class="btn btn-secondary" id="rsc-discard-preview" aria-label="Discard preview" title="Discard current preview changes">Discard</button><button type="button" class="btn btn-secondary" id="rsc-save-draft" aria-label="Save draft" title="Save current changes as a draft">Draft</button><?php if (Auth::can('react-site-bridge.publish') || Auth::isSuperAdmin()): ?><button type="button" class="btn btn-primary rsc-save-publish" id="rsc-save-publish" aria-label="Review and publish" title="Review and publish current changes">Publish</button><?php endif; ?></form>
      <?php if (Auth::can('react-site-bridge.publish') || Auth::isSuperAdmin()): ?><dialog id="rsc-publish-dialog" class="rsc-publish-dialog" aria-labelledby="rsc-publish-title"><form method="dialog"><span class="rsc-dialog-kicker">Live revision</span><h2 id="rsc-publish-title">Review visual changes</h2><p id="rsc-publish-summary">Review the pending changes before creating a live Slate revision.</p><section class="rsc-revision-comparison" id="rsc-revision-comparison" aria-label="Pending visual changes" aria-live="polite"><p class="rsc-revision-empty">No local visual changes to review.</p></section><div><button type="button" class="btn btn-secondary" id="rsc-cancel-publish">Keep editing</button><button type="button" class="btn btn-primary" id="rsc-confirm-publish">Save &amp; publish</button></div></form></dialog><?php endif; ?>
    </aside>
    <aside class="rsc-history-panel" id="rsc-history-panel" aria-label="Edit history and revisions" aria-hidden="true"><header class="rsc-history-head"><div><span>Visual editor</span><strong>Edit history</strong></div><div><button type="button" id="rsc-history-dock" title="Dock history panel" aria-pressed="false">⇥</button><button type="button" id="rsc-history-close" title="Close edit history" aria-label="Close edit history">×</button></div></header><div class="rsc-history-tabs" role="tablist" aria-label="History source"><button class="is-active" type="button" data-history-tab="actions" role="tab" aria-selected="true">Actions</button><button type="button" data-history-tab="revisions" role="tab" aria-selected="false">Revisions</button></div><div class="rsc-history-scroll"><section class="rsc-history-tab is-active" data-history-panel="actions"><p class="rsc-history-hint" id="rsc-history-preview-hint">Changes in this session are synchronized with Undo and Redo.</p><div id="rsc-history-actions" class="rsc-history-list" aria-live="polite"></div></section><section class="rsc-history-tab" data-history-panel="revisions"><p class="rsc-history-hint">Saved working draft and published visual revisions. Loading a revision affects this working preview only until you save.</p><div id="rsc-history-revisions" class="rsc-history-list" aria-live="polite"></div></section></div><footer class="rsc-history-footer"><button type="button" id="rsc-history-return" class="btn btn-secondary" hidden>Return to current</button></footer></aside>
  </div>
</div>
<script>
(() => {
  const canvas = document.getElementById('rsc-canvas');
  if (!canvas) return;
  const origin = window.location.origin;
  const route = <?= json_encode($selectedRoute, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const visual = <?= json_encode($visualData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  // Earlier releases encoded an empty PHP route map as []. Normalize it before
  // any inspector control writes an override so JSON preserves the keyed route.
  if (Array.isArray(visual.routes) || !visual.routes || typeof visual.routes !== 'object') visual.routes = {};
  const baselineSnapshot = JSON.stringify(visual);
  const history = [baselineSnapshot];
  const historyEntries = [{snapshot: baselineSnapshot, label: 'Saved baseline', target: 'Current visual document', at: Date.now(), kind: 'baseline'}];
  const savedVisualRevisions = <?= json_encode($visualRevisions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const visualDraftInfo = <?= json_encode($visualDraftInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  let historyIndex = 0;
  let applyingHistory = false;
  let historyPreviewIndex = null;
  const fields = {
    text: document.getElementById('rsc-text'), link: document.getElementById('rsc-link'), mediaSource: document.getElementById('rsc-media-source'), mediaAlt: document.getElementById('rsc-media-alt'), focalX: document.getElementById('rsc-focal-x'), focalY: document.getElementById('rsc-focal-y'), darkOverlay: document.getElementById('rsc-dark-overlay'), color: document.getElementById('rsc-color'), backgroundColor: document.getElementById('rsc-background'), fontSize: document.getElementById('rsc-font-size'), fontWeight: document.getElementById('rsc-font-weight'), textAlign: document.getElementById('rsc-text-align'), borderColor: document.getElementById('rsc-border-color'), borderRadius: document.getElementById('rsc-radius'), paddingTop: document.getElementById('rsc-padding-top'), paddingBottom: document.getElementById('rsc-padding-bottom'), maxWidth: document.getElementById('rsc-max-width')
  };
  const outputs = {focalX: document.getElementById('rsc-focal-x-output'), focalY: document.getElementById('rsc-focal-y-output'), darkOverlay: document.getElementById('rsc-dark-overlay-output'), fontSize: document.getElementById('rsc-font-size-output'), borderRadius: document.getElementById('rsc-radius-output'), paddingTop: document.getElementById('rsc-padding-top-output'), paddingBottom: document.getElementById('rsc-padding-bottom-output'), maxWidth: document.getElementById('rsc-max-width-output')};
  const removeTextBackground = document.getElementById('rsc-remove-text-background');
  const form = document.getElementById('rsc-visual-form');
  const serialized = document.getElementById('rsc-visual-json');
  const mediaLibrary = document.getElementById('rsc-media-library');
  const mediaCards = Array.from(document.querySelectorAll('[data-rsc-media-url]'));
  const mediaFocalDefaults = <?= json_encode($mediaFocalDefaults, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  mediaCards.forEach(card => {
    const focal = mediaFocalDefaults[card.dataset.rscMediaKey] || {x: 50, y: 50};
    card.dataset.rscMediaFocalX = String(focal.x);
    card.dataset.rscMediaFocalY = String(focal.y);
  });
  const mediaLibraryEmpty = document.getElementById('rsc-media-library-empty');
  const mediaFilter = document.getElementById('rsc-media-filter');
  const mediaPreview = document.getElementById('rsc-media-preview');
  const focalControls = document.getElementById('rsc-focal-controls');
  const inspector = document.querySelector('.rsc-visual-inspector');
  const sheetToggle = document.getElementById('rsc-sheet-toggle');
  const hideInspectorButton = document.getElementById('rsc-hide-inspector');
  const showInspectorButton = document.getElementById('rsc-show-inspector');
  const visualApp = document.querySelector('.rsc-visual-app');
  const focusToggle = document.getElementById('rsc-focus-toggle');
  const focusExit = document.getElementById('rsc-focus-exit');
  let focusMode = false;
  let focusIdleTimer = 0;
  const focalPreview = document.getElementById('rsc-focal-preview');
  const resetSelected = document.getElementById('rsc-reset-selected');
  const undoButton = document.getElementById('rsc-undo');
  const redoButton = document.getElementById('rsc-redo');
  const changeStatus = document.getElementById('rsc-change-status');
  const changeCount = document.getElementById('rsc-change-count');
  const publishDialog = document.getElementById('rsc-publish-dialog');
  const revisionComparison = document.getElementById('rsc-revision-comparison');
  let selected = null;
  let selectedFrameNode = null;
  const currentRoute = (create = true) => {
    if (!visual.routes || typeof visual.routes !== 'object') visual.routes = {};
    if (create) visual.routes[route] = visual.routes[route] || {};
    return visual.routes[route] || {};
  };
  const override = () => selected ? (currentRoute()[selected] = currentRoute()[selected] || {style:{}}) : null;
  const cleanEmpty = () => {
    if (!selected) return;
    const entries = currentRoute(false);
    const item = entries[selected];
    if (item && (!item.text && !item.link && (!item.style || !Object.keys(item.style).length) && (!item.media || !Object.values(item.media).some(Boolean)))) delete entries[selected];
    if (!Object.keys(entries).length) delete visual.routes[route];
  };
  const postPreview = () => canvas.contentWindow?.postMessage({type:'rsc.visual.preview', route, data: currentRoute()}, origin);
  const isDirty = () => JSON.stringify(visual) !== baselineSnapshot;
  const updateStatus = () => {
    const count = Object.keys(currentRoute(false)).length;
    const dirty = isDirty();
    if (changeStatus) changeStatus.classList.toggle('is-dirty', dirty);
    if (changeStatus) changeStatus.querySelector('strong').textContent = dirty ? 'Unsaved preview changes' : 'Saved baseline';
    if (changeCount) changeCount.textContent = count + ' current-page change' + (count === 1 ? '' : 's');
    if (undoButton) undoButton.disabled = historyIndex <= 0;
    if (redoButton) redoButton.disabled = historyIndex >= history.length - 1;
    if (resetSelected) resetSelected.disabled = !selected || !currentRoute(false)[selected];
    if (typeof renderActionHistory === 'function') renderActionHistory();
  };
  const comparisonFieldLabel = field => ({
    text: 'Text content', link: 'Link destination', 'media.src': 'Image source', 'media.backgroundImage': 'Background image', 'media.alt': 'Alternative text', 'media.focalX': 'Focal point X', 'media.focalY': 'Focal point Y', 'media.darkOverlay': 'Dark overlay', color: 'Text color', backgroundColor: 'Background color', borderColor: 'Border color', fontSize: 'Font size', fontWeight: 'Font weight', textAlign: 'Text alignment', borderRadius: 'Corner radius', paddingTop: 'Top spacing', paddingBottom: 'Bottom spacing', maxWidth: 'Maximum width', opacity: 'Opacity'
  }[field] || field.replace(/^style\./, '').replace(/([A-Z])/g, ' $1').replace(/^./, value => value.toUpperCase()));
  const comparisonValues = document => {
    const result = new Map();
    const routes = document?.routes && typeof document.routes === 'object' && !Array.isArray(document.routes) ? document.routes : {};
    Object.keys(routes).sort().forEach(routeName => {
      const elements = routes[routeName] && typeof routes[routeName] === 'object' ? routes[routeName] : {};
      Object.keys(elements).sort().forEach(elementKey => {
        const item = elements[elementKey] && typeof elements[elementKey] === 'object' ? elements[elementKey] : {};
        if (Object.prototype.hasOwnProperty.call(item, 'text')) result.set([routeName, elementKey, 'text'].join('\u001f'), item.text);
        if (Object.prototype.hasOwnProperty.call(item, 'link')) result.set([routeName, elementKey, 'link'].join('\u001f'), item.link);
        const style = item.style && typeof item.style === 'object' ? item.style : {};
        Object.keys(style).sort().forEach(name => result.set([routeName, elementKey, name].join('\u001f'), style[name]));
        const media = item.media && typeof item.media === 'object' ? item.media : {};
        Object.keys(media).sort().forEach(name => result.set([routeName, elementKey, 'media.' + name].join('\u001f'), media[name]));
      });
    });
    return result;
  };
  const displayComparisonValue = (field, value) => {
    if (value === undefined || value === null || value === '') return '—';
    if (field === 'media.darkOverlay') return Math.round(Math.min(.9, Math.max(0, Number(value) || 0)) * 100) + '%';
    if (field === 'media.focalX' || field === 'media.focalY') return Math.min(100, Math.max(0, Math.round(Number(value) || 0))) + '%';
    const text = String(value);
    return text.length > 140 ? text.slice(0, 137) + '…' : text;
  };
  const revisionRows = () => {
    const before = comparisonValues(JSON.parse(baselineSnapshot));
    const after = comparisonValues(visual);
    const keys = Array.from(new Set([...before.keys(), ...after.keys()])).sort();
    return keys.filter(key => before.get(key) !== after.get(key)).map(key => {
      const [routeName, elementKey, field] = key.split('\u001f');
      return {routeName, elementKey, field, before: before.get(key), after: after.get(key)};
    });
  };
  const renderRevisionComparison = () => {
    if (!revisionComparison) return [];
    const rows = revisionRows();
    revisionComparison.replaceChildren();
    if (!rows.length) {
      const empty = document.createElement('p');
      empty.className = 'rsc-revision-empty';
      empty.textContent = 'No local visual changes to review. Make a change before publishing.';
      revisionComparison.appendChild(empty);
      return rows;
    }
    const heading = document.createElement('div');
    heading.className = 'rsc-revision-heading';
    const title = document.createElement('strong');
    title.textContent = rows.length + ' pending field ' + (rows.length === 1 ? 'change' : 'changes');
    const hint = document.createElement('span');
    hint.textContent = 'Baseline → proposed';
    heading.append(title, hint);
    revisionComparison.appendChild(heading);
    rows.forEach(row => {
      const item = document.createElement('article');
      item.className = 'rsc-revision-row';
      const meta = document.createElement('div');
      meta.className = 'rsc-revision-meta';
      const label = document.createElement('strong');
      label.textContent = comparisonFieldLabel(row.field);
      const target = document.createElement('span');
      target.textContent = (row.routeName === '/' ? 'Home' : row.routeName) + ' · ' + row.elementKey;
      meta.append(label, target);
      const values = document.createElement('div');
      values.className = 'rsc-revision-values';
      const baseline = document.createElement('div');
      const baselineLabel = document.createElement('span'); baselineLabel.textContent = 'Baseline';
      const baselineValue = document.createElement('code'); baselineValue.textContent = displayComparisonValue(row.field, row.before);
      baseline.append(baselineLabel, baselineValue);
      const proposed = document.createElement('div');
      const proposedLabel = document.createElement('span'); proposedLabel.textContent = 'Proposed';
      const proposedValue = document.createElement('code'); proposedValue.textContent = displayComparisonValue(row.field, row.after);
      proposed.append(proposedLabel, proposedValue);
      values.append(baseline, proposed);
      item.append(meta, values);
      revisionComparison.appendChild(item);
    });
    return rows;
  };
  const syncJson = () => { cleanEmpty(); serialized.value = JSON.stringify(visual); updateStatus(); };
  const captureHistory = (meta = {}) => {
    if (applyingHistory) return;
    const snapshot = JSON.stringify(visual);
    if (history[historyIndex] === snapshot) return;
    history.splice(historyIndex + 1);
    historyEntries.splice(historyIndex + 1);
    history.push(snapshot);
    historyEntries.push({snapshot, label: meta.label || 'Visual setting edited', target: meta.target || selected || 'Current page', at: Date.now(), kind: meta.kind || 'edit'});
    historyIndex = history.length - 1;
    historyPreviewIndex = null;
    updateStatus();
  };
  const restoreSnapshot = snapshot => {
    applyingHistory = true;
    const restored = JSON.parse(snapshot);
    Object.keys(visual).forEach(key => delete visual[key]);
    Object.assign(visual, restored);
    if (Array.isArray(visual.routes) || !visual.routes || typeof visual.routes !== 'object') visual.routes = {};
    applyingHistory = false;
    syncJson();
    postPreview();
    window.setTimeout(() => {
      const refreshed = selected ? canvas.contentDocument?.querySelector('[data-rsc-visual-key="' + CSS.escape(selected) + '"]') : null;
      if (refreshed) select(refreshed);
      else if (!selected) select(null);
    }, 80);
    updateStatus();
  };
  const historyPanel = document.getElementById('rsc-history-panel');
  const historyToggle = document.getElementById('rsc-history-toggle');
  const historyClose = document.getElementById('rsc-history-close');
  const historyDock = document.getElementById('rsc-history-dock');
  const historyActions = document.getElementById('rsc-history-actions');
  const historyRevisions = document.getElementById('rsc-history-revisions');
  const historyReturn = document.getElementById('rsc-history-return');
  const relativeHistoryTime = timestamp => { const seconds = Math.max(0, Math.round((Date.now() - Number(timestamp || Date.now())) / 1000)); if (seconds < 12) return 'Just now'; if (seconds < 60) return seconds + ' sec ago'; const minutes = Math.floor(seconds / 60); if (minutes < 60) return minutes + ' min ago'; return Math.floor(minutes / 60) + ' hr ago'; };
  const postSnapshotPreview = snapshot => { const documentState = JSON.parse(snapshot); const routeData = documentState?.routes && typeof documentState.routes === 'object' ? (documentState.routes[route] || {}) : {}; canvas.contentWindow?.postMessage({type:'rsc.visual.preview', route, data: routeData}, origin); };
  function renderActionHistory() {
    if (!historyActions) return;
    historyActions.replaceChildren();
    historyEntries.forEach((entry, index) => {
      const item = document.createElement('article'); item.className = 'rsc-history-item' + (index === historyIndex ? ' is-active' : '') + (index === historyPreviewIndex ? ' is-preview' : '');
      const meta = document.createElement('div'); meta.className = 'rsc-history-item-meta';
      const dot = document.createElement('i'); dot.setAttribute('aria-hidden', 'true');
      const title = document.createElement('strong'); title.textContent = entry.label;
      const target = document.createElement('span'); target.textContent = entry.target;
      meta.append(dot, title, target);
      const time = document.createElement('time'); time.textContent = relativeHistoryTime(entry.at); time.dateTime = new Date(entry.at).toISOString();
      const actions = document.createElement('div'); actions.className = 'rsc-history-item-actions';
      const preview = document.createElement('button'); preview.type = 'button'; preview.textContent = index === historyIndex ? 'Current' : 'Preview'; preview.disabled = index === historyIndex;
      preview.addEventListener('click', () => { historyPreviewIndex = index; postSnapshotPreview(entry.snapshot); if (historyReturn) historyReturn.hidden = false; renderActionHistory(); });
      actions.append(preview);
      if (index !== historyIndex) { const restore = document.createElement('button'); restore.type = 'button'; restore.className = 'is-restore'; restore.textContent = 'Restore'; restore.title = 'Restore this session state as the working draft'; restore.addEventListener('click', () => { history.splice(index + 1); historyEntries.splice(index + 1); historyIndex = index; historyPreviewIndex = null; if (historyReturn) historyReturn.hidden = true; restoreSnapshot(history[index]); }); actions.append(restore); }
      item.append(meta, time, actions); historyActions.append(item);
    });
  }
  const renderRevisionHistory = () => {
    if (!historyRevisions) return;
    historyRevisions.replaceChildren();
    const appendRevision = (label, detail, snapshot, stamp, state = 'saved') => {
      const item = document.createElement('article'); item.className = 'rsc-history-item rsc-history-revision';
      const meta = document.createElement('div'); meta.className = 'rsc-history-item-meta';
      const dot = document.createElement('i'); dot.className = state; dot.setAttribute('aria-hidden', 'true');
      const title = document.createElement('strong'); title.textContent = label;
      const target = document.createElement('span'); target.textContent = detail;
      meta.append(dot, title, target);
      const time = document.createElement('time'); time.textContent = stamp ? new Date(stamp).toLocaleString() : 'Current session';
      const actions = document.createElement('div'); actions.className = 'rsc-history-item-actions';
      if (snapshot) {
        const preview = document.createElement('button'); preview.type = 'button'; preview.textContent = 'Preview'; preview.addEventListener('click', () => { historyPreviewIndex = -1; postSnapshotPreview(snapshot); if (historyReturn) historyReturn.hidden = false; renderActionHistory(); });
        const load = document.createElement('button'); load.type = 'button'; load.className = 'is-restore'; load.textContent = 'Use as draft'; load.addEventListener('click', () => { if (!window.confirm('Use this revision as the current working draft? It will not publish until you publish normally.')) return; const next = String(snapshot); if (history[historyIndex] === next) return; history.splice(historyIndex + 1); historyEntries.splice(historyIndex + 1); history.push(next); historyEntries.push({snapshot:next,label:'Revision loaded as draft',target:label,at:Date.now(),kind:'revision'}); historyIndex = history.length - 1; historyPreviewIndex = null; if (historyReturn) historyReturn.hidden = true; restoreSnapshot(next); });
        actions.append(preview, load);
      } else { const unavailable = document.createElement('span'); unavailable.textContent = 'Metadata only'; actions.append(unavailable); }
      item.append(meta, time, actions); historyRevisions.append(item);
    };
    appendRevision('Saved baseline', visualDraftInfo.updated_at ? 'Current saved working draft' : 'Current visual document', baselineSnapshot, visualDraftInfo.updated_at, 'saved');
    savedVisualRevisions.forEach(revision => appendRevision('Published v' + revision.version, revision.note || 'Slate published revision', revision.visual_document ? JSON.stringify(revision.visual_document) : '', revision.published_at, 'published'));
    if (!savedVisualRevisions.length) { const empty = document.createElement('p'); empty.className = 'rsc-history-empty'; empty.textContent = 'No published visual revisions are available for this site yet.'; historyRevisions.append(empty); }
  };
  const setHistoryOpen = open => { const visible = !!open; visualApp?.classList.toggle('is-history-open', visible); historyPanel?.setAttribute('aria-hidden', visible ? 'false' : 'true'); historyToggle?.setAttribute('aria-expanded', visible ? 'true' : 'false'); if (visible) { renderActionHistory(); renderRevisionHistory(); } };
  const hex = value => /^#[0-9A-F]{6}$/i.test(value || '') ? value : '#102832';
  const px = value => { const n = parseFloat(value || '0'); return Number.isFinite(n) ? Math.round(n) : 0; };
  const setDisabled = disabled => {
    Object.values(fields).forEach(field => { if (field) field.disabled = disabled; });
    if (removeTextBackground) removeTextBackground.disabled = disabled;
    if (mediaLibrary) mediaLibrary.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    mediaCards.forEach(card => { card.disabled = disabled; });
    if (mediaFilter) mediaFilter.disabled = disabled;
  };
  const setMediaPreview = (source = '', alt = '') => {
    if (!mediaPreview) return;
    mediaPreview.replaceChildren();
    if (!source) {
      const empty = document.createElement('span');
      empty.textContent = 'Select an image target to preview its approved source.';
      mediaPreview.appendChild(empty);
      return;
    }
    const image = document.createElement('img');
    image.src = source;
    image.alt = alt || 'Selected media preview';
    image.loading = 'lazy';
    const label = document.createElement('small');
    label.textContent = alt || 'Slate-hosted media preview';
    mediaPreview.append(image, label);
  };
  const setLibrarySelected = source => {
    const selectedSource = String(source || '');
    mediaCards.forEach(card => {
      const active = card.dataset.rscMediaUrl === selectedSource;
      card.classList.toggle('is-selected', active);
      card.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  };
  const focalValue = value => Math.min(100, Math.max(0, Math.round(Number(value) || 0)));
  const setFocalPreview = (source = '', focalX = 50, focalY = 50) => {
    if (!focalPreview) return;
    const x = focalValue(focalX); const y = focalValue(focalY);
    focalPreview.hidden = !source;
    focalPreview.style.setProperty('--rsc-focal-x', x + '%');
    focalPreview.style.setProperty('--rsc-focal-y', y + '%');
    focalPreview.style.backgroundImage = source ? 'url("' + source + '")' : '';
    focalPreview.style.backgroundPosition = x + '% ' + y + '%';
  };
  const filterMediaLibrary = () => {
    const query = mediaFilter?.value.trim().toLowerCase() || '';
    let visible = 0;
    mediaCards.forEach(card => {
      const match = !query || (card.dataset.rscMediaSearch || '').includes(query);
      card.hidden = !match;
      if (match) visible += 1;
    });
    if (mediaLibraryEmpty) mediaLibraryEmpty.hidden = visible !== 0;
  };
  const labelFor = node => { const tag = node.tagName.toLowerCase(); const name = node.getAttribute('data-rsc-visual-label') || node.getAttribute('aria-label') || node.className?.toString().split(' ')[0] || tag; return {tag: tag.toUpperCase(), name}; };
  const setInspectorSheetState = expanded => {
    if (!inspector || !sheetToggle) return;
    inspector.classList.toggle('is-sheet-open', !!expanded);
    inspector.dataset.rscSheetState = expanded ? 'expanded' : 'collapsed';
    sheetToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  };
  const setInspectorVisible = visible => {
    const isVisible = !!visible;
    visualApp?.classList.toggle('is-inspector-hidden', !isVisible);
    inspector?.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
    if (showInspectorButton) showInspectorButton.hidden = isVisible;
  };
  const clearFocusIdle = () => {
    if (focusIdleTimer) window.clearTimeout(focusIdleTimer);
    focusIdleTimer = 0;
  };
  const scheduleFocusIdle = () => {
    clearFocusIdle();
    if (!focusMode || !focusExit || document.activeElement === focusExit) return;
    focusIdleTimer = window.setTimeout(() => {
      if (focusMode && document.activeElement !== focusExit) focusExit.classList.add('is-idle');
    }, 2600);
  };
  const revealFocusControl = () => {
    if (!focusMode || !focusExit) return;
    focusExit.classList.remove('is-idle');
    scheduleFocusIdle();
  };
  const setFocusMode = active => {
    focusMode = !!active;
    visualApp?.classList.toggle('is-focus-mode', focusMode);
    focusToggle?.setAttribute('aria-pressed', focusMode ? 'true' : 'false');
    focusToggle?.setAttribute('title', focusMode ? 'Show top controls' : 'Hide top controls');
    focusToggle?.setAttribute('aria-label', focusMode ? 'Show top controls' : 'Hide top controls for focused editing');
    if (focusExit) {
      focusExit.hidden = !focusMode;
      focusExit.classList.remove('is-idle');
    }
    if (focusMode) scheduleFocusIdle(); else clearFocusIdle();
  };
  const select = node => {
    if (selectedFrameNode) selectedFrameNode.classList.remove('rsc-visual-selected');
    selectedFrameNode = node;
    selected = node?.getAttribute('data-rsc-visual-key') || null;
    const textAllowed = node?.getAttribute('data-rsc-visual-text') === 'true';
    const linkAllowed = node?.getAttribute('data-rsc-visual-link') === 'true';
    const imageAllowed = node?.getAttribute('data-rsc-visual-image') === 'true';
    const backgroundAllowed = node?.getAttribute('data-rsc-visual-background') === 'true';
    const title = labelFor(node || document.body);
    document.getElementById('rsc-selection-tag').textContent = selected ? title.tag : 'Select an element';
    document.getElementById('rsc-selection-name').textContent = selected ? title.name : 'Click text, a button, a card, or a section in the canvas.';
    if (!node || !selected) { setInspectorSheetState(false); setDisabled(true); setTransparentBackgroundState(false); if (focalControls) focalControls.hidden = true; setFocalPreview(); setMediaPreview(); setLibrarySelected(''); updateStatus(); return; }
    node.classList.add('rsc-visual-selected');
    setInspectorSheetState(true);
    setDisabled(false);
    fields.text.disabled = !textAllowed;
    if (removeTextBackground) removeTextBackground.disabled = !textAllowed;
    fields.link.disabled = !linkAllowed;
    fields.mediaSource.disabled = !imageAllowed && !backgroundAllowed;
    fields.mediaAlt.disabled = !imageAllowed;
    fields.focalX.disabled = !backgroundAllowed;
    fields.focalY.disabled = !backgroundAllowed;
    fields.darkOverlay.disabled = !backgroundAllowed;
    if (focalControls) focalControls.hidden = !backgroundAllowed;
    mediaLibrary.setAttribute('aria-disabled', !imageAllowed && !backgroundAllowed ? 'true' : 'false');
    mediaCards.forEach(card => { card.disabled = !imageAllowed && !backgroundAllowed; });
    mediaFilter.disabled = !imageAllowed && !backgroundAllowed;
    const saved = currentRoute()[selected] || {}; const style = saved.style || {}; const computed = node.ownerDocument.defaultView.getComputedStyle(node);
    fields.text.value = saved.text || (textAllowed ? node.textContent.trim() : '');
    fields.link.value = saved.link || (linkAllowed ? node.getAttribute('href') || '' : '');
    fields.mediaSource.value = saved.media?.src || saved.media?.backgroundImage || (imageAllowed ? node.getAttribute('src') || '' : (backgroundAllowed ? (computed.backgroundImage.match(/^url\((?:"|')?(.*?)(?:"|')?\)$/)?.[1] || '') : ''));
    fields.mediaAlt.value = saved.media?.alt || (imageAllowed ? node.getAttribute('alt') || '' : '');
    fields.focalX.value = String(focalValue(saved.media?.focalX ?? 50));
    fields.focalY.value = String(focalValue(saved.media?.focalY ?? 50));
    fields.darkOverlay.value = String(Math.round(Math.min(0.9, Math.max(0, Number(saved.media?.darkOverlay || 0))) * 100));
    const transparentBackground = style.backgroundColor === 'transparent';
    fields.color.value = hex(style.color || computed.color); fields.backgroundColor.value = hex(transparentBackground ? '#FFFFFF' : (style.backgroundColor || computed.backgroundColor)); fields.borderColor.value = hex(style.borderColor || computed.borderTopColor);
    setTransparentBackgroundState(transparentBackground);
    fields.fontSize.value = px(style.fontSize || computed.fontSize); fields.fontWeight.value = style.fontWeight || ''; fields.textAlign.value = style.textAlign || '';
    fields.borderRadius.value = px(style.borderRadius || computed.borderTopLeftRadius); fields.paddingTop.value = px(style.paddingTop || computed.paddingTop); fields.paddingBottom.value = px(style.paddingBottom || computed.paddingBottom); fields.maxWidth.value = px(style.maxWidth || computed.maxWidth);
    setMediaPreview(fields.mediaSource.value, fields.mediaAlt.value);
    setFocalPreview(backgroundAllowed ? fields.mediaSource.value : '', fields.focalX.value, fields.focalY.value);
    setLibrarySelected(fields.mediaSource.value);
    updateOutputs();
    updateStatus();
  };
  const setTransparentBackgroundState = active => {
    if (!removeTextBackground) return;
    removeTextBackground.classList.toggle('is-active', !!active);
    removeTextBackground.setAttribute('aria-pressed', active ? 'true' : 'false');
    removeTextBackground.textContent = active ? 'Text background removed' : 'Remove text background';
  };
  const updateOutputs = () => { Object.entries(outputs).forEach(([key, out]) => { if (!out || !fields[key]) return; if (key === 'darkOverlay' || key === 'focalX' || key === 'focalY') { out.value = fields[key].value + '%'; return; } out.value = fields[key].value ? fields[key].value + 'px' : '—'; }); };
  const setFocalPoint = (focalX, focalY) => {
    const item = override(); if (!item) return;
    const x = focalValue(focalX); const y = focalValue(focalY);
    fields.focalX.value = String(x); fields.focalY.value = String(y);
    item.media = item.media || {}; item.media.focalX = String(x); item.media.focalY = String(y);
    cleanEmpty(); captureHistory({label:'Background focal point edited', target:selectedFrameNode?.getAttribute('data-rsc-visual-label') || selected || 'Current page'}); syncJson(); postPreview(); updateOutputs(); setFocalPreview(fields.mediaSource.value, x, y);
  };
  const update = (property, value, kind='style') => {
    const item = override(); if (!item) return;
    if (kind === 'text') { if (value) item.text = value.slice(0, 1000); else delete item.text; }
    else if (kind === 'link') { if (value) item.link = value.slice(0, 2048); else delete item.link; }
    else if (kind === 'media') { item.media = item.media || {}; if (property === 'darkOverlay' && Number(value) === 0) delete item.media[property]; else if (value !== '') item.media[property] = value.slice(0, property === 'alt' ? 500 : 2048); else delete item.media[property]; }
    else { item.style = item.style || {}; if (value !== '') item.style[property] = value; else delete item.style[property]; }
    cleanEmpty(); captureHistory({label:comparisonFieldLabel(kind === 'text' ? 'text' : kind === 'link' ? 'link' : kind === 'media' ? 'media.' + property : property) + ' edited', target:selectedFrameNode?.getAttribute('data-rsc-visual-label') || selected || 'Current page'});
    syncJson(); postPreview(); updateOutputs();
  };
  Object.entries(fields).forEach(([key, field]) => {
    if (!field) return;
    field.addEventListener('input', () => {
      if (key === 'text') return update('text', field.value, 'text');
      if (key === 'link') return update('link', field.value, 'link');
      if (key === 'mediaSource') { setMediaPreview(field.value, fields.mediaAlt.value); setFocalPreview(selectedFrameNode?.getAttribute('data-rsc-visual-background') === 'true' ? field.value : '', fields.focalX.value, fields.focalY.value); setLibrarySelected(field.value); return update(selectedFrameNode?.getAttribute('data-rsc-visual-background') === 'true' ? 'backgroundImage' : 'src', field.value, 'media'); }
      if (key === 'mediaAlt') { setMediaPreview(fields.mediaSource.value, field.value); return update('alt', field.value, 'media'); }
      if (key === 'focalX') return setFocalPoint(field.value, fields.focalY.value);
      if (key === 'focalY') return setFocalPoint(fields.focalX.value, field.value);
      if (key === 'darkOverlay') return update('darkOverlay', String(Math.min(90, Math.max(0, Number(field.value))) / 100), 'media');
      if (key === 'backgroundColor') setTransparentBackgroundState(false);
      const property = key; let value = field.value;
      if (['fontSize','borderRadius','paddingTop','paddingBottom','maxWidth'].includes(key)) value = key === 'maxWidth' && Number(value) === 0 ? '' : value + 'px';
      update(property, value);
    });
    field.addEventListener('change', () => { if (key === 'fontWeight' || key === 'textAlign') update(key, field.value); });
  });
  removeTextBackground?.addEventListener('click', () => {
    if (removeTextBackground.disabled || !selectedFrameNode?.getAttribute('data-rsc-visual-text')) return;
    update('backgroundColor', 'transparent');
    setTransparentBackgroundState(true);
  });
  mediaCards.forEach(card => card.addEventListener('click', () => {
    if (card.disabled || !selectedFrameNode) return;
    const source = card.dataset.rscMediaUrl || '';
    const alt = card.dataset.rscMediaAlt || '';
    if (!source) return;
    fields.mediaSource.value = source;
    setMediaPreview(source, alt || fields.mediaAlt.value);
    setLibrarySelected(source);
    const backgroundTarget = selectedFrameNode.getAttribute('data-rsc-visual-background') === 'true';
    update(backgroundTarget ? 'backgroundImage' : 'src', source, 'media');
    if (backgroundTarget) setFocalPoint(card.dataset.rscMediaFocalX || 50, card.dataset.rscMediaFocalY || 50);
    if (selectedFrameNode.getAttribute('data-rsc-visual-image') === 'true' && alt) {
      fields.mediaAlt.value = alt;
      update('alt', alt, 'media');
    }
  }));
  let focalPointerId = null;
  const focalPointFromEvent = event => {
    if (!focalPreview || !selectedFrameNode || selectedFrameNode.getAttribute('data-rsc-visual-background') !== 'true') return;
    const rect = focalPreview.getBoundingClientRect();
    if (!rect.width || !rect.height) return;
    setFocalPoint(((event.clientX - rect.left) / rect.width) * 100, ((event.clientY - rect.top) / rect.height) * 100);
  };
  focalPreview?.addEventListener('pointerdown', event => {
    focalPointerId = event.pointerId; focalPreview.setPointerCapture?.(event.pointerId); event.preventDefault(); focalPointFromEvent(event);
  });
  focalPreview?.addEventListener('pointermove', event => { if (focalPointerId === event.pointerId) focalPointFromEvent(event); });
  focalPreview?.addEventListener('pointerup', event => { if (focalPointerId === event.pointerId) focalPointerId = null; });
  focalPreview?.addEventListener('keydown', event => {
    if (!['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) return;
    event.preventDefault();
    const nextX = focalValue(Number(fields.focalX.value) + (event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0));
    const nextY = focalValue(Number(fields.focalY.value) + (event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0));
    setFocalPoint(nextX, nextY);
  });
  mediaFilter.addEventListener('input', filterMediaLibrary);
  let boundCanvasDocument = null;
  const canvasWrap = document.getElementById('rsc-canvas-wrap');
  const canvasModeButton = document.getElementById('rsc-canvas-mode');
  const headerCanvasModeButton = document.getElementById('rsc-header-canvas-mode');
  const headerCanvasModeLabel = document.getElementById('rsc-header-canvas-mode-label');
  let canvasMode = 'edit';
  const setCanvasMode = mode => {
    canvasMode = mode === 'scroll' ? 'scroll' : 'edit';
    if (canvasWrap) canvasWrap.dataset.rscCanvasMode = canvasMode;
    if (canvasModeButton) {
      const editing = canvasMode === 'edit';
      canvasModeButton.classList.toggle('is-edit', editing);
      canvasModeButton.classList.toggle('is-scroll', !editing);
      canvasModeButton.setAttribute('aria-pressed', editing ? 'true' : 'false');
      canvasModeButton.replaceChildren();
      const title = document.createElement('strong'); title.textContent = editing ? 'Edit mode' : 'Scroll mode';
      const hint = document.createElement('span'); hint.textContent = editing ? 'Tap an element to edit' : 'Drag the page to navigate';
      canvasModeButton.append(title, hint);
    }
    if (headerCanvasModeButton) {
      const editing = canvasMode === 'edit';
      headerCanvasModeButton.classList.toggle('is-edit', editing);
      headerCanvasModeButton.classList.toggle('is-scroll', !editing);
      headerCanvasModeButton.setAttribute('aria-pressed', editing ? 'true' : 'false');
      if (headerCanvasModeLabel) headerCanvasModeLabel.textContent = editing ? 'Edit mode' : 'Scroll mode';
    }
    canvas.contentWindow?.postMessage({type:'rsc.visual.mode', mode: canvasMode}, origin);
  };
  const enableCanvasBridge = () => {
    canvas.contentWindow?.postMessage({type:'rsc.visual.enable'}, origin);
    canvas.contentWindow?.postMessage({type:'rsc.visual.mode', mode: canvasMode}, origin);
  };
  const selectCanvasTarget = event => {
    if (canvasMode !== 'edit') return false;
    const target = event.target.closest?.('[data-rsc-visual-key]');
    if (!target) return false;
    event.preventDefault(); event.stopPropagation(); select(target);
    return true;
  };
  const attachCanvas = () => {
    const doc = canvas.contentDocument;
    if (!doc || doc.URL === 'about:blank' || doc === boundCanvasDocument) return;
    doc.addEventListener('click', selectCanvasTarget, true);
    doc.addEventListener('pointerup', event => { if (event.pointerType === 'touch') selectCanvasTarget(event); }, true);
    boundCanvasDocument = doc;
    enableCanvasBridge();
    postPreview();
  };
  canvas.addEventListener('load', attachCanvas);
  if (canvas.contentDocument?.readyState === 'complete' && canvas.contentDocument.URL !== 'about:blank') attachCanvas();
  window.addEventListener('message', event => {
    if (event.origin !== origin || event.source !== canvas.contentWindow) return;
    const message = event.data || {};
    if (message.type === 'rsc.visual.ready') {
      enableCanvasBridge();
      postPreview();
      return;
    }
    if (message.type === 'rsc.visual.select' && message.route === route && typeof message.key === 'string') {
      const node = canvas.contentDocument?.querySelector('[data-rsc-visual-key="' + CSS.escape(message.key) + '"]');
      if (node) select(node);
    }
  });
  const submitVisual = action => {
    syncJson();
    form.querySelector('[data-rsc-action]')?.remove();
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = '_action'; input.value = action; input.dataset.rscAction = 'true';
    form.appendChild(input); form.submit();
  };
  const discardPreview = () => {
    if (!isDirty() || window.confirm('Discard all unsaved local preview changes? Your last saved draft will remain unchanged.')) {
      history.splice(1); historyIndex = 0; restoreSnapshot(baselineSnapshot);
    }
  };
  const openPublishReview = () => {
    if (!publishDialog) return submitVisual('save_publish_visual');
    const rows = renderRevisionComparison();
    const summary = document.getElementById('rsc-publish-summary');
    const confirm = document.getElementById('rsc-confirm-publish');
    if (summary) summary.textContent = rows.length ? 'Review ' + rows.length + ' field ' + (rows.length === 1 ? 'change' : 'changes') + ' before making them live. This creates a new Slate revision.' : 'There are no local visual changes to publish.';
    if (confirm) confirm.disabled = !rows.length;
    publishDialog.showModal();
  };
  document.getElementById('rsc-reload').addEventListener('click', () => canvas.contentWindow?.location.reload());
  document.getElementById('rsc-reset-preview').addEventListener('click', discardPreview);
  document.getElementById('rsc-clear-selection').addEventListener('click', () => select(null));
  sheetToggle?.addEventListener('click', () => setInspectorSheetState(!inspector?.classList.contains('is-sheet-open')));
  hideInspectorButton?.addEventListener('click', () => setInspectorVisible(false));
  showInspectorButton?.addEventListener('click', () => setInspectorVisible(true));
  historyToggle?.addEventListener('click', () => setHistoryOpen(!visualApp?.classList.contains('is-history-open')));
  historyClose?.addEventListener('click', () => setHistoryOpen(false));
  historyDock?.addEventListener('click', () => { const docked = !visualApp?.classList.contains('is-history-docked'); visualApp?.classList.toggle('is-history-docked', docked); historyDock.setAttribute('aria-pressed', docked ? 'true' : 'false'); historyDock.setAttribute('title', docked ? 'Undock history panel' : 'Dock history panel'); });
  historyReturn?.addEventListener('click', () => { historyPreviewIndex = null; postPreview(); historyReturn.hidden = true; renderActionHistory(); });
  document.querySelectorAll('[data-history-tab]').forEach(button => button.addEventListener('click', () => { const tab = button.dataset.historyTab; document.querySelectorAll('[data-history-tab]').forEach(item => { const active = item === button; item.classList.toggle('is-active', active); item.setAttribute('aria-selected', active ? 'true' : 'false'); }); document.querySelectorAll('[data-history-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.historyPanel === tab)); if (tab === 'revisions') renderRevisionHistory(); }));
  document.getElementById('rsc-open-canvas').addEventListener('click', () => window.open(canvas.src, '_blank', 'noopener'));
  canvasModeButton?.addEventListener('click', () => setCanvasMode(canvasMode === 'edit' ? 'scroll' : 'edit'));
  headerCanvasModeButton?.addEventListener('click', () => setCanvasMode(canvasMode === 'edit' ? 'scroll' : 'edit'));
  focusToggle?.addEventListener('click', () => setFocusMode(!focusMode));
  focusExit?.addEventListener('click', () => setFocusMode(false));
  focusExit?.addEventListener('mouseenter', revealFocusControl);
  focusExit?.addEventListener('focus', revealFocusControl);
  visualApp?.addEventListener('pointermove', revealFocusControl, {passive:true});
  visualApp?.addEventListener('pointerdown', revealFocusControl, {passive:true});
  visualApp?.addEventListener('touchstart', revealFocusControl, {passive:true});
  canvas.addEventListener('load', () => {
    try { canvas.contentDocument?.addEventListener('pointerdown', revealFocusControl, {passive:true}); } catch (_) {}
  });
  document.getElementById('rsc-save-draft').addEventListener('click', () => submitVisual('save_visual'));
  document.getElementById('rsc-discard-preview').addEventListener('click', discardPreview);
  document.getElementById('rsc-submit-publish')?.addEventListener('click', openPublishReview);
  document.getElementById('rsc-save-publish')?.addEventListener('click', openPublishReview);
  document.getElementById('rsc-cancel-publish')?.addEventListener('click', () => publishDialog?.close());
  document.getElementById('rsc-confirm-publish')?.addEventListener('click', () => submitVisual('save_publish_visual'));
  undoButton?.addEventListener('click', () => { if (historyIndex > 0) { historyIndex -= 1; restoreSnapshot(history[historyIndex]); } });
  redoButton?.addEventListener('click', () => { if (historyIndex < history.length - 1) { historyIndex += 1; restoreSnapshot(history[historyIndex]); } });
  resetSelected?.addEventListener('click', () => {
    if (!selected || !currentRoute(false)[selected]) return;
    delete currentRoute(false)[selected]; cleanEmpty(); captureHistory({label:'Selected element reset', target:selectedFrameNode?.getAttribute('data-rsc-visual-label') || selected || 'Current page'}); syncJson(); postPreview(); select(selectedFrameNode);
  });
  window.addEventListener('keydown', event => {
    if (focusMode) revealFocusControl();
    if (event.key === 'Escape' && focusMode) { event.preventDefault(); setFocusMode(false); return; }
    const target = event.target;
    const editing = target instanceof HTMLElement && target.matches('input, textarea, select');
    if (!(event.ctrlKey || event.metaKey)) return;
    if (event.key.toLowerCase() === 's') { event.preventDefault(); submitVisual('save_visual'); return; }
    if (event.key.toLowerCase() === 'z') {
      if (editing && !event.shiftKey) return;
      event.preventDefault();
      if (event.shiftKey) { if (historyIndex < history.length - 1) { historyIndex += 1; restoreSnapshot(history[historyIndex]); } }
      else if (historyIndex > 0) { historyIndex -= 1; restoreSnapshot(history[historyIndex]); }
    }
  });
  document.querySelectorAll('[data-device]').forEach(button => button.addEventListener('click', () => { document.querySelectorAll('[data-device]').forEach(item => item.classList.toggle('is-active', item === button)); document.getElementById('rsc-canvas-wrap').className = 'rsc-canvas-wrap rsc-canvas--' + button.dataset.device; }));
  document.querySelectorAll('[data-tab]').forEach(button => button.addEventListener('click', () => { const tab = button.dataset.tab; document.querySelectorAll('[data-tab]').forEach(item => item.classList.toggle('is-active', item === button)); document.querySelectorAll('[data-panel]').forEach(panel => panel.classList.toggle('is-active', panel.dataset.panel === tab)); }));
  document.querySelectorAll('.rsc-control-heading').forEach(button => button.addEventListener('click', () => button.parentElement.classList.toggle('is-collapsed')));
  setCanvasMode('edit'); syncJson(); setDisabled(true); updateStatus();
})();
</script>
<style>
.rsc-visual-app{--v-bg:#131517;--v-panel:#1a1d1f;--v-panel-2:#212528;--v-line:#343a3f;--v-muted:#9da6aa;--v-text:#f0f3f2;--v-blue:#168cff;position:fixed;inset:0;z-index:1200;display:flex;flex-direction:column;background:var(--v-bg);color:var(--v-text);font-family:ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.rsc-visual-app .alert{margin:8px 18px 0}.rsc-visual-topbar{height:60px;flex:0 0 60px;display:flex;align-items:center;gap:14px;padding:0 18px;border-bottom:1px solid #2a2f32;background:#181a1c}.rsc-visual-back,.rsc-visual-icon{width:36px;height:36px;display:grid;place-items:center;border:1px solid transparent;background:transparent;color:#d9dfdf;text-decoration:none;font-size:25px;line-height:1;border-radius:7px}.rsc-visual-back:hover,.rsc-visual-icon:hover{background:#272c2f;border-color:#3a4145}.rsc-visual-brand{display:flex;align-items:center;gap:8px;font-size:14px;letter-spacing:-.01em}.rsc-visual-brand strong{font-weight:650}.rsc-visual-brand span:last-child{color:var(--v-muted);border-left:1px solid #3c4245;padding-left:8px}.rsc-visual-dot{width:9px;height:9px;border-radius:50%;background:#18b9e6;box-shadow:0 0 0 3px rgba(24,185,230,.15)}.rsc-visual-top-actions{margin-left:auto;display:flex;align-items:center;gap:7px}.rsc-visual-publish{border:0;border-radius:7px;background:#f4f5f2;color:#18211e;padding:10px 15px;font:600 13px/1 inherit;cursor:pointer}.rsc-visual-toolbar{height:58px;flex:0 0 58px;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;padding:0 18px;background:#16191b;border-bottom:1px solid #2a2f32}.rsc-visual-device-group{display:flex;gap:2px;padding:4px;background:#101214;border:1px solid #2b3033;border-radius:8px;width:max-content}.rsc-visual-device-group button,.rsc-visual-canvas-actions button{border:0;background:transparent;color:#929b9e;border-radius:5px;cursor:pointer}.rsc-visual-device-group button{width:30px;height:26px;font-size:14px}.rsc-visual-device-group button.is-active{background:#31373a;color:#fff}.rsc-visual-route-form{height:34px;display:flex;align-items:center;gap:8px;padding:0 10px;border:1px solid #2d3336;background:#101214;border-radius:7px;color:#b9c1c3}.rsc-visual-route-form select{border:0;outline:0;background:transparent;color:#eaf0ef;min-width:210px;font:13px inherit}.rsc-visual-canvas-actions{justify-self:end;display:flex;gap:6px}.rsc-visual-canvas-actions button{width:31px;height:31px;font-size:17px}.rsc-visual-workspace{min-height:0;flex:1;display:grid;grid-template-columns:minmax(0,1fr) 365px}.rsc-visual-stage{min-width:0;min-height:0;display:flex;align-items:stretch;justify-content:center;padding:22px;background:repeating-linear-gradient(45deg,#15191b 0,#15191b 11px,#171b1d 11px,#171b1d 22px);overflow:auto}.rsc-canvas-wrap{display:flex;min-height:0;height:100%;box-shadow:0 18px 45px rgba(0,0,0,.32);transition:width .2s cubic-bezier(.23,1,.32,1);background:#fff}.rsc-canvas--desktop{width:100%}.rsc-canvas--tablet{width:min(820px,100%)}.rsc-canvas--mobile{width:390px;max-width:100%}.rsc-canvas-wrap iframe{width:100%;height:100%;border:0;background:#fff}.rsc-visual-empty{width:100%;display:grid;place-content:center;gap:8px;text-align:center;background:#f3efe6;color:#102832}.rsc-visual-empty span{color:#536166}.rsc-visual-inspector{min-height:0;display:flex;flex-direction:column;background:#1b1f21;border-left:1px solid #303639}.rsc-inspector-head{display:flex;justify-content:space-between;gap:14px;padding:20px 18px 16px;border-bottom:1px solid var(--v-line)}.rsc-inspector-head span{display:block;color:#8e989b;font-size:11px;letter-spacing:.1em;text-transform:uppercase}.rsc-inspector-head strong{display:block;margin-top:5px;color:#f4f5f3;font-size:14px;line-height:1.35;font-weight:600}.rsc-inspector-head button{width:28px;height:28px;border:0;border-radius:5px;background:transparent;color:#aeb7b8;font-size:23px;cursor:pointer}.rsc-inspector-tabs{display:flex;gap:18px;padding:0 18px;border-bottom:1px solid var(--v-line)}.rsc-inspector-tabs button{padding:14px 0 11px;border:0;border-bottom:2px solid transparent;background:transparent;color:#9ca6a8;font:13px inherit;cursor:pointer}.rsc-inspector-tabs button.is-active{color:#fff;border-bottom-color:#fff}.rsc-inspector-scroll{overflow:auto;flex:1;padding:17px 18px}.rsc-inspector-panel{display:none}.rsc-inspector-panel.is-active{display:block}.rsc-inspector-panel label{display:grid;gap:7px;margin-bottom:16px;color:#d5dbdb;font-size:12px}.rsc-inspector-panel textarea,.rsc-inspector-panel select{box-sizing:border-box;width:100%;border:1px solid #3a4144;border-radius:6px;background:#262b2e;color:#f4f7f6;font:13px/1.45 inherit}.rsc-inspector-panel textarea{min-height:110px;padding:10px;resize:vertical}.rsc-inspector-panel select{height:36px;padding:0 8px}.rsc-inspector-panel input:disabled,.rsc-inspector-panel textarea:disabled,.rsc-inspector-panel select:disabled{opacity:.35;cursor:not-allowed}.rsc-control-note{margin:2px 0 0;color:#98a2a5;font-size:11px;line-height:1.55}.rsc-control-group{margin:0 -18px;border-top:1px solid var(--v-line)}.rsc-control-heading{display:flex;width:100%;justify-content:space-between;padding:15px 18px;border:0;background:transparent;color:#e4e9e8;font:600 13px inherit;text-align:left;cursor:pointer}.rsc-control-heading span{color:#9ba5a7}.rsc-control-body{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:0 18px 16px}.rsc-control-body label{margin:0}.rsc-control-body label:has(select){grid-column:1/-1}.rsc-control-group.is-collapsed .rsc-control-body{display:none}.rsc-control-group.is-collapsed .rsc-control-heading span{transform:rotate(180deg)}.rsc-control-body input[type=color]{box-sizing:border-box;width:100%;height:37px;border:1px solid #3a4144;border-radius:6px;background:#262b2e;padding:4px}.rsc-range{display:grid;grid-template-columns:minmax(0,1fr) 45px;gap:8px;align-items:center}.rsc-range input{accent-color:var(--v-blue)}.rsc-range output{color:#a9b2b3;text-align:right;font-size:11px}.rsc-inspector-actions{display:flex;gap:8px;padding:15px 18px;border-top:1px solid var(--v-line);background:#191c1e}.rsc-inspector-actions .btn{flex:1;white-space:nowrap}.rsc-visual-selected{outline:3px solid #168cff!important;outline-offset:4px!important;cursor:crosshair!important}@media(max-width:1050px){.rsc-visual-workspace{grid-template-columns:minmax(0,1fr) 320px}}@media(max-width:780px){.rsc-visual-app{position:absolute;min-height:100vh}.rsc-visual-toolbar{grid-template-columns:auto 1fr auto}.rsc-visual-route-form select{min-width:0;width:100%}.rsc-visual-workspace{grid-template-columns:1fr;overflow:auto}.rsc-visual-stage{height:70vh}.rsc-visual-inspector{min-height:560px;border-left:0;border-top:1px solid #303639}.rsc-visual-brand span:last-child{display:none}}
.rsc-inspector-panel input[type=text],.rsc-inspector-panel input[type=url]{box-sizing:border-box;width:100%;height:36px;padding:0 8px;border:1px solid #3a4144;border-radius:6px;background:#262b2e;color:#f4f7f6;font:13px/1.45 inherit}
.rsc-inspector-panel input[type=search]{box-sizing:border-box;width:100%;height:36px;padding:0 8px;border:1px solid #3a4144;border-radius:6px;background:#262b2e;color:#f4f7f6;font:13px/1.45 inherit}.rsc-visual-change-status{display:flex;align-items:center;gap:7px;margin-left:10px;color:#aeb8b9;font-size:11px}.rsc-visual-change-status>span{width:7px;height:7px;border-radius:50%;background:#5c6668}.rsc-visual-change-status strong{color:#dfe6e5;font-weight:600}.rsc-visual-change-status em{font-style:normal;color:#818d90}.rsc-visual-change-status.is-dirty>span{background:#f2b84a;box-shadow:0 0 0 3px rgba(242,184,74,.12)}.rsc-visual-change-status.is-dirty strong{color:#f5cf78}.rsc-visual-icon:disabled{opacity:.32;cursor:not-allowed}.rsc-selection-actions{display:grid;gap:5px;padding:11px 18px;border-bottom:1px solid var(--v-line);background:#1a1e20}.rsc-selection-actions button{justify-self:start;border:1px solid #455054;border-radius:5px;background:transparent;color:#d9e0df;padding:6px 8px;font:600 11px/1 inherit;cursor:pointer}.rsc-selection-actions button:hover:not(:disabled){border-color:#6e7a7d;background:#252b2e}.rsc-selection-actions button:disabled{opacity:.4;cursor:not-allowed}.rsc-selection-actions span{color:#8d979a;font-size:10px;line-height:1.35}.rsc-media-preview{display:grid;grid-template-columns:70px minmax(0,1fr);gap:9px;align-items:center;min-height:54px;margin:-5px 0 15px;padding:7px;border:1px solid #384145;border-radius:6px;background:#202527}.rsc-media-preview:has(span){display:block;min-height:0;color:#8f999c;font-size:11px;line-height:1.45}.rsc-media-preview img{width:70px;height:48px;object-fit:cover;border-radius:3px;background:#131719}.rsc-media-preview small{color:#b5bfc0;font-size:10px;line-height:1.35;overflow-wrap:anywhere}.rsc-publish-dialog{width:min(420px,calc(100vw - 32px));border:1px solid #465054;border-radius:10px;background:#1d2224;color:#eef3f2;padding:24px;box-shadow:0 24px 70px rgba(0,0,0,.52)}.rsc-publish-dialog::backdrop{background:rgba(4,8,10,.65);backdrop-filter:blur(2px)}.rsc-publish-dialog form{display:grid;gap:11px}.rsc-dialog-kicker{color:#18b9e6;font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase}.rsc-publish-dialog h2{margin:0;color:#fff;font-size:20px;letter-spacing:-.025em}.rsc-publish-dialog p{margin:0 0 8px;color:#b4c0c0;font-size:13px;line-height:1.55}.rsc-publish-dialog form>div{display:flex;justify-content:flex-end;gap:8px}.rsc-publish-dialog .btn{min-width:112px}@media(max-width:900px){.rsc-visual-change-status{display:none}}@media(max-width:780px){.rsc-selection-actions{padding-right:14px;padding-left:14px}.rsc-media-preview{grid-template-columns:62px minmax(0,1fr)}.rsc-media-preview img{width:62px}.rsc-inspector-actions{flex-wrap:wrap}.rsc-inspector-actions .btn{min-width:calc(50% - 4px)}}
.rsc-publish-dialog{width:min(620px,calc(100vw - 32px))}.rsc-revision-comparison{display:grid;gap:8px;max-height:330px;overflow:auto;padding:11px;border:1px solid #394347;border-radius:7px;background:#171b1d}.rsc-revision-empty{margin:0!important;color:#9da8aa!important;font-size:12px!important}.rsc-revision-heading{display:flex;justify-content:space-between;gap:12px;padding:0 2px;color:#eef4f3;font-size:12px}.rsc-revision-heading span{color:#99a6a8;font-size:11px}.rsc-revision-row{display:grid;gap:8px;padding:10px;border:1px solid #343e42;border-radius:6px;background:#202528}.rsc-revision-meta{display:flex;justify-content:space-between;gap:12px;align-items:baseline}.rsc-revision-meta strong{color:#f3f7f6;font-size:12px}.rsc-revision-meta span{color:#9aa5a7;font-size:10px;overflow-wrap:anywhere;text-align:right}.rsc-revision-values{display:grid;grid-template-columns:1fr 1fr;gap:8px}.rsc-revision-values>div{display:grid;gap:4px;min-width:0}.rsc-revision-values span{color:#889598;font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}.rsc-revision-values>div:last-child span{color:#6bcce9}.rsc-revision-values code{display:block;min-width:0;min-height:32px;max-height:58px;overflow:auto;padding:6px;border-radius:4px;background:#161a1c;color:#cdd6d5;font:10px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere;white-space:pre-wrap}.rsc-revision-values>div:last-child code{border-left:2px solid #18b9e6;color:#e2f5fa}.rsc-publish-dialog .btn:disabled{opacity:.45;cursor:not-allowed}@media(max-width:780px){.rsc-revision-meta{display:grid;gap:3px}.rsc-revision-meta span{text-align:left}.rsc-revision-values{grid-template-columns:1fr}}
.rsc-media-library{display:grid;gap:8px;margin:-3px 0 15px}.rsc-media-library[aria-disabled=true]{opacity:.4;pointer-events:none}.rsc-media-library-head{display:flex;align-items:baseline;justify-content:space-between;gap:10px}.rsc-media-library-head strong{color:#e6edeb;font-size:12px}.rsc-media-library-head span{color:#829093;font-size:10px}.rsc-media-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;max-height:320px;overflow:auto;padding:2px}.rsc-media-card{position:relative;display:grid;grid-template-rows:76px auto;min-width:0;overflow:hidden;border:1px solid #394347;border-radius:6px;background:#202528;color:#dce6e4;text-align:left;cursor:pointer;transition:border-color .16s cubic-bezier(.23,1,.32,1),transform .16s cubic-bezier(.23,1,.32,1),background .16s cubic-bezier(.23,1,.32,1)}.rsc-media-card:hover:not(:disabled){transform:translateY(-1px);border-color:#6d7e82;background:#272e31}.rsc-media-card:focus-visible{outline:2px solid #18b9e6;outline-offset:2px}.rsc-media-card.is-selected{border-color:#18b9e6;background:#203338;box-shadow:0 0 0 1px rgba(24,185,230,.25)}.rsc-media-card:disabled{cursor:not-allowed}.rsc-media-card[hidden]{display:none}.rsc-media-card img{width:100%;height:76px;object-fit:cover;background:#111719}.rsc-media-card>span{display:grid;gap:2px;min-width:0;padding:7px}.rsc-media-card strong{overflow:hidden;color:#eef6f4;font-size:10px;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.rsc-media-card small{color:#91a1a3;font-size:9px;line-height:1.25}.rsc-media-card i{position:absolute;top:6px;right:6px;display:none;width:18px;height:18px;border-radius:50%;place-items:center;background:#18b9e6;color:#06232d;font:800 12px/18px inherit;font-style:normal;text-align:center}.rsc-media-card.is-selected i{display:grid}.rsc-media-library-empty{margin:0;padding:11px;border:1px dashed #465256;border-radius:6px;color:#9aa6a8;font-size:11px;line-height:1.4}@media(max-width:780px){.rsc-media-grid{grid-template-columns:repeat(3,minmax(0,1fr));max-height:360px}.rsc-media-card{grid-template-rows:68px auto}.rsc-media-card img{height:68px}}
.rsc-focal-controls{display:grid!important;gap:13px;padding:4px 0 16px}.rsc-focal-controls[hidden]{display:none!important}.rsc-focal-heading{display:flex;align-items:baseline;justify-content:space-between;gap:10px}.rsc-focal-heading strong{color:#e7efed;font-size:12px}.rsc-focal-heading span{color:#829093;font-size:10px}.rsc-focal-preview{position:relative;display:grid;min-height:142px;overflow:hidden;place-items:center;border:1px solid #465256;border-radius:7px;background-color:#111719;background-repeat:no-repeat;background-size:cover;cursor:crosshair;isolation:isolate;touch-action:none}.rsc-focal-preview::before{position:absolute;z-index:1;inset:13% 10%;border:1px solid rgba(235,248,247,.82);border-radius:3px;box-shadow:0 0 0 999px rgba(2,10,13,.26);content:"";pointer-events:none}.rsc-focal-preview::after{position:absolute;z-index:2;left:var(--rsc-focal-x,50%);top:var(--rsc-focal-y,50%);width:18px;height:18px;border:2px solid #f5fffd;border-radius:50%;background:#18b9e6;box-shadow:0 0 0 2px rgba(2,26,31,.62);content:"";pointer-events:none;transform:translate(-50%,-50%)}.rsc-focal-preview:focus-visible{outline:2px solid #18b9e6;outline-offset:2px}.rsc-focal-preview-hint{position:relative;z-index:3;max-width:145px;padding:5px 7px;border:1px solid rgba(226,247,244,.26);border-radius:3px;background:rgba(7,24,29,.74);color:#f0f8f7;font-size:10px;line-height:1.35;text-align:center;pointer-events:none}.rsc-focal-controls label{margin:0}
.rsc-focal-controls{display:grid!important;gap:13px;padding:4px 0 16px}.rsc-focal-controls[hidden]{display:none!important}.rsc-focal-heading{display:flex;align-items:baseline;justify-content:space-between;gap:10px}.rsc-focal-heading strong{color:#e7efed;font-size:12px}.rsc-focal-heading span{color:#829093;font-size:10px}.rsc-focal-preview{position:relative;display:grid;min-height:142px;overflow:hidden;place-items:center;border:1px solid #465256;border-radius:7px;background-color:#111719;background-repeat:no-repeat;background-size:cover;cursor:crosshair;isolation:isolate;touch-action:none}.rsc-focal-preview::before{position:absolute;z-index:1;inset:13% 10%;border:1px solid rgba(235,248,247,.82);border-radius:3px;box-shadow:0 0 0 999px rgba(2,10,13,.26);content:"";pointer-events:none}.rsc-focal-preview::after{position:absolute;z-index:2;left:var(--rsc-focal-x,50%);top:var(--rsc-focal-y,50%);width:18px;height:18px;border:2px solid #f5fffd;border-radius:50%;background:#18b9e6;box-shadow:0 0 0 2px rgba(2,26,31,.62);content:"";pointer-events:none;transform:translate(-50%,-50%)}.rsc-focal-preview:focus-visible{outline:2px solid #18b9e6;outline-offset:2px}.rsc-focal-preview-hint{position:relative;z-index:3;max-width:145px;padding:5px 7px;border:1px solid rgba(226,247,244,.26);border-radius:3px;background:rgba(7,24,29,.74);color:#f0f8f7;font-size:10px;line-height:1.35;text-align:center;pointer-events:none}.rsc-focal-controls label{margin:0}
.rsc-canvas-touchbar{display:none}.rsc-visual-stage{position:relative}.rsc-canvas-touchbar button{width:100%;border:1px solid #455459;border-radius:7px;background:#1c2427;color:#ecf6f4;padding:9px 11px;text-align:left;font:inherit;cursor:pointer}.rsc-canvas-touchbar strong,.rsc-canvas-touchbar span{display:block}.rsc-canvas-touchbar strong{font-size:12px}.rsc-canvas-touchbar span{margin-top:2px;color:#9bb1b4;font-size:10px}.rsc-canvas-touchbar button.is-scroll{border-color:#8b6e3e;background:#2c261b}.rsc-canvas-touchbar button.is-scroll span{color:#e9cf9d}
@media(max-width:640px){.rsc-visual-stage{height:54dvh;min-height:320px;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:8px;padding:10px}.rsc-canvas-touchbar{display:block;position:sticky;top:0;z-index:5}.rsc-canvas-wrap{flex:1 1 auto;min-height:300px}.rsc-canvas-wrap[data-rsc-canvas-mode="edit"] iframe{touch-action:none}.rsc-canvas-wrap[data-rsc-canvas-mode="scroll"] iframe{touch-action:pan-y}.rsc-canvas--mobile{width:min(390px,100%);align-self:center}}
.rsc-remove-background{grid-column:1/-1;display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px dashed #637176;border-radius:6px;background:#202629;color:#e6efed;font:600 12px/1 inherit;cursor:pointer}.rsc-remove-background:hover:not(:disabled){border-color:#8fdce8;background:#26373b}.rsc-remove-background.is-active{border-style:solid;border-color:#55cfe3;background:#163941;color:#dffaff}.rsc-remove-background:disabled{opacity:.38;cursor:not-allowed}.rsc-remove-background-note{grid-column:1/-1;margin:-3px 0 0!important}
@media(max-width:980px){.rsc-visual-workspace{grid-template-columns:1fr;overflow:auto}.rsc-visual-stage{height:min(62dvh,680px);min-height:430px}.rsc-visual-inspector{min-height:0;border-top:1px solid #303639;border-left:0}.rsc-inspector-scroll{max-height:none}.rsc-inspector-actions{position:sticky;bottom:0;z-index:4;box-shadow:0 -9px 18px rgba(0,0,0,.18)}}
@media(max-width:640px){.rsc-visual-app{position:fixed;min-height:100dvh}.rsc-visual-topbar{height:auto;min-height:56px;gap:7px;padding:8px 10px}.rsc-visual-back{width:31px;height:31px;font-size:22px}.rsc-visual-brand{min-width:0;flex:1}.rsc-visual-brand strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rsc-visual-brand .rsc-visual-dot{flex:0 0 auto}.rsc-visual-top-actions{gap:2px}.rsc-visual-top-actions>a{display:none}.rsc-visual-icon{width:30px;height:30px;font-size:20px}.rsc-visual-publish{padding:9px 10px;font-size:11px;white-space:nowrap}.rsc-visual-toolbar{height:auto;min-height:52px;grid-template-columns:auto minmax(0,1fr);gap:8px;padding:8px 10px}.rsc-visual-route-form{min-width:0;width:100%}.rsc-visual-route-form select{min-width:0;width:100%}.rsc-visual-canvas-actions{display:none}.rsc-visual-stage{height:54dvh;min-height:320px;padding:10px;align-items:flex-start}.rsc-canvas-wrap{height:100%;min-height:0}.rsc-canvas--mobile{width:min(390px,100%)}.rsc-inspector-head{padding:16px 14px 13px}.rsc-inspector-tabs{gap:15px;padding:0 14px}.rsc-inspector-scroll{padding:15px 14px}.rsc-selection-actions{padding-right:14px;padding-left:14px}.rsc-control-group{margin-right:-14px;margin-left:-14px}.rsc-control-heading{padding-right:14px;padding-left:14px}.rsc-control-body{grid-template-columns:1fr;padding-right:14px;padding-left:14px}.rsc-control-body label:has(select){grid-column:auto}.rsc-inspector-actions{gap:7px;padding:12px 14px;flex-wrap:wrap}.rsc-inspector-actions .btn{min-width:calc(50% - 4px)}.rsc-inspector-actions .rsc-save-publish{width:100%}.rsc-media-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.rsc-visual-app{overflow:hidden}.rsc-visual-workspace{display:block;overflow:hidden}.rsc-visual-stage{height:calc(100dvh - 122px)!important;min-height:0!important;padding-bottom:84px}.rsc-visual-inspector{position:fixed;right:0;bottom:0;left:0;z-index:30;display:flex;height:min(72dvh,590px);min-height:0!important;border:1px solid #3b464a;border-right:0;border-left:0;border-radius:18px 18px 0 0;background:#1b1f21;box-shadow:0 -18px 40px rgba(0,0,0,.42);transform:translateY(calc(100% - 78px));transition:transform .22s cubic-bezier(.23,1,.32,1)}.rsc-visual-inspector.is-sheet-open{transform:translateY(0)}.rsc-inspector-head{position:relative;z-index:2;flex:0 0 78px;padding:10px 14px 8px;background:#1b1f21}.rsc-sheet-toggle{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;min-width:0;flex:1;border:0;background:transparent;color:#f4f7f6;text-align:left;cursor:pointer}.rsc-sheet-toggle span:last-of-type{display:grid;gap:3px;min-width:0}.rsc-sheet-toggle small{color:#90a0a2;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.rsc-sheet-toggle strong{overflow:hidden;font-size:13px;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.rsc-sheet-toggle i{color:#b8c8c8;font-size:17px;font-style:normal;transition:transform .2s cubic-bezier(.23,1,.32,1)}.rsc-visual-inspector.is-sheet-open .rsc-sheet-toggle i{transform:rotate(180deg)}.rsc-sheet-grip{position:absolute;top:7px;left:50%;width:38px;height:4px;border-radius:99px;background:#657276;transform:translateX(-50%)}.rsc-inspector-head>#rsc-clear-selection{align-self:center}.rsc-selection-actions{flex:0 0 auto;padding:8px 14px}.rsc-inspector-tabs{flex:0 0 auto;padding:0 14px}.rsc-inspector-scroll{min-height:0;padding:12px 14px;overflow:auto}.rsc-inspector-actions{position:sticky;bottom:0;z-index:3;flex:0 0 auto;padding:10px 14px calc(10px + env(safe-area-inset-bottom));background:#191c1e;box-shadow:0 -10px 22px rgba(0,0,0,.24)}.rsc-visual-inspector:not(.is-sheet-open) .rsc-selection-actions,.rsc-visual-inspector:not(.is-sheet-open) .rsc-inspector-tabs,.rsc-visual-inspector:not(.is-sheet-open) .rsc-inspector-scroll,.rsc-visual-inspector:not(.is-sheet-open) .rsc-inspector-actions{visibility:hidden;pointer-events:none}}
.rsc-visual-topbar{height:50px;flex-basis:50px;gap:10px;padding:0 14px}.rsc-visual-back,.rsc-visual-icon{width:30px;height:30px;font-size:20px}.rsc-visual-brand{gap:7px;font-size:13px}.rsc-visual-brand span:last-child{padding-left:7px}.rsc-visual-dot{width:8px;height:8px}.rsc-visual-top-actions{gap:4px}.rsc-visual-publish{padding:8px 12px;font-size:12px}.rsc-visual-toolbar{height:46px;flex-basis:46px;padding:0 14px}.rsc-visual-device-group{gap:1px;padding:3px;border-radius:7px}.rsc-visual-device-group button{width:27px;height:24px;font-size:12px}.rsc-visual-route-form{height:30px;gap:6px;padding:0 8px;border-radius:6px}.rsc-visual-route-form select{min-width:180px;font-size:12px}.rsc-visual-canvas-actions{gap:3px}.rsc-visual-canvas-actions button{width:27px;height:27px;font-size:15px}.rsc-visual-stage{padding:14px}.rsc-canvas-touchbar{display:block;position:absolute;top:22px;left:22px;z-index:8}.rsc-canvas-touchbar button{display:inline-flex;width:auto;min-height:32px;align-items:center;gap:7px;padding:6px 10px;border-radius:999px;background:rgba(20,31,34,.94);box-shadow:0 5px 16px rgba(0,0,0,.2);backdrop-filter:blur(8px)}.rsc-canvas-touchbar strong,.rsc-canvas-touchbar span{display:inline;margin:0}.rsc-canvas-touchbar strong{font-size:11px}.rsc-canvas-touchbar span{color:#afc3c5;font-size:10px}.rsc-canvas-touchbar button.is-scroll{background:rgba(49,40,25,.95)}
@media(max-width:980px){.rsc-visual-topbar{height:48px;flex-basis:48px}.rsc-visual-toolbar{height:44px;flex-basis:44px}.rsc-visual-stage{padding:12px}.rsc-canvas-touchbar{top:18px;left:18px}}
@media(max-width:640px){.rsc-visual-topbar{min-height:44px;height:44px;flex-basis:44px;padding:6px 10px}.rsc-visual-back{width:28px;height:28px;font-size:19px}.rsc-visual-brand{gap:6px;font-size:12px}.rsc-visual-top-actions{gap:2px}.rsc-visual-top-actions .rsc-visual-icon{width:28px;height:28px;font-size:18px}.rsc-visual-publish{min-height:30px;padding:7px 9px;font-size:11px}.rsc-visual-toolbar{min-height:42px;height:42px;flex-basis:42px;gap:6px;padding:6px 10px}.rsc-visual-device-group button{width:25px;height:22px}.rsc-visual-route-form{height:28px}.rsc-visual-stage{height:calc(100dvh - 96px)!important;padding:8px 8px 76px}.rsc-canvas-touchbar{top:14px;left:14px}.rsc-canvas-touchbar button{min-height:29px;padding:5px 9px}.rsc-canvas-touchbar span{display:none}.rsc-canvas-touchbar strong{font-size:10px}.rsc-canvas-wrap{height:100%}}
.rsc-focus-exit{position:absolute;z-index:12;top:18px;right:18px;display:inline-flex;align-items:center;gap:7px;min-height:32px;border:1px solid #536368;border-radius:999px;background:rgba(20,31,34,.95);box-shadow:0 5px 16px rgba(0,0,0,.22);color:#ecf5f3;padding:6px 10px;font:600 11px/1 inherit;cursor:pointer;backdrop-filter:blur(8px);transition:opacity .22s cubic-bezier(.23,1,.32,1),transform .22s cubic-bezier(.23,1,.32,1),border-color .18s ease,background .18s ease}.rsc-focus-exit.is-idle{opacity:.14;transform:translateY(-3px)}.rsc-focus-exit.is-idle:hover,.rsc-focus-exit.is-idle:focus-visible{opacity:1;transform:translateY(0)}.rsc-focus-exit:hover{border-color:#90a8ac;background:#29373a}.rsc-focus-exit:focus-visible{outline:2px solid #18b9e6;outline-offset:3px}.rsc-visual-app.is-focus-mode>.rsc-visual-topbar,.rsc-visual-app.is-focus-mode>.rsc-visual-toolbar,.rsc-visual-app.is-focus-mode>.alert{display:none}.rsc-visual-app.is-focus-mode .rsc-visual-workspace{height:100%;flex:1}.rsc-visual-app.is-focus-mode .rsc-visual-stage{padding-top:14px}@media(prefers-reduced-motion:reduce){.rsc-focus-exit{transition:none}}
@media(max-width:640px){.rsc-focus-exit{top:14px;right:14px;min-height:29px;padding:5px 9px;font-size:10px}.rsc-visual-app.is-focus-mode .rsc-visual-stage{height:100dvh!important;padding-top:8px}}
.rsc-visual-toolbar{display:grid;grid-template-columns:auto minmax(112px,150px) auto;align-items:center;justify-content:space-between;column-gap:10px;min-height:42px;height:42px;flex-basis:42px;padding:0 12px}.rsc-visual-device-group{box-sizing:border-box;height:32px;align-items:center;gap:1px;padding:2px;border-radius:7px}.rsc-visual-device-group button{width:26px;height:26px;line-height:1}.rsc-visual-route-form{box-sizing:border-box;justify-self:center;display:flex;align-items:center;width:min(150px,100%);min-width:0;height:32px;min-height:32px;max-height:32px;gap:5px;padding:0 8px}.rsc-visual-route-form>.rsc-visual-route-home{display:grid;flex:0 0 14px;place-items:center;height:30px;line-height:1}.rsc-visual-route-mobile-label{display:none}.rsc-visual-route-form select{box-sizing:border-box;display:block;flex:1;min-width:0!important;width:100%;height:30px!important;min-height:30px!important;max-height:30px!important;margin:0!important;padding:0!important;line-height:30px!important;font-size:12px}.rsc-visual-canvas-actions{display:flex;align-items:center;align-self:center;height:32px}.rsc-visual-canvas-actions button{width:28px;height:28px;line-height:1}.rsc-visual-route-form:focus-within{border-color:#70858a;box-shadow:0 0 0 2px rgba(24,185,230,.12)}
@media(max-width:640px){.rsc-visual-toolbar{grid-template-columns:auto minmax(92px,132px);justify-content:start;gap:7px;min-height:40px;height:40px;flex-basis:40px;padding:0 10px}.rsc-visual-device-group{height:30px}.rsc-visual-device-group button{width:24px;height:24px}.rsc-visual-route-form{position:relative;justify-self:stretch;width:100%;height:30px;min-height:30px;max-height:30px;padding:0 7px}.rsc-visual-route-form>.rsc-visual-route-home{display:none}.rsc-visual-route-form>.rsc-visual-route-mobile-label{display:flex;align-items:center;width:100%;min-width:0;height:28px;gap:5px;color:#dce8e8;font:700 10px/1 inherit;letter-spacing:.04em;text-transform:uppercase}.rsc-visual-route-mobile-label b{color:#80dbea;font-size:12px;font-weight:400}.rsc-visual-route-mobile-label strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rsc-visual-route-mobile-label i{margin-left:auto;color:#8fa4a7;font-size:13px;font-style:normal}.rsc-visual-route-form select{position:absolute!important;inset:0!important;z-index:2;display:block!important;width:100%!important;height:30px!important;min-height:30px!important;max-height:30px!important;margin:0!important;padding:0!important;opacity:0;cursor:pointer}.rsc-visual-canvas-actions{display:none}}
.rsc-inspector-visibility-toggle{color:#dbe7e5!important;font-size:24px!important;line-height:1}.rsc-inspector-visibility-toggle:hover{background:#2b3538!important;color:#fff!important}.rsc-show-inspector{position:absolute;right:18px;bottom:18px;z-index:14;display:inline-flex;align-items:center;gap:7px;min-height:34px;border:1px solid #536368;border-radius:999px;background:rgba(20,31,34,.95);box-shadow:0 6px 18px rgba(0,0,0,.24);color:#ecf5f3;padding:6px 10px;font:600 11px/1 inherit;cursor:pointer;backdrop-filter:blur(8px);transition:transform .16s cubic-bezier(.23,1,.32,1),border-color .16s ease,background .16s ease}.rsc-show-inspector[hidden]{display:none!important}.rsc-show-inspector:hover{border-color:#90a8ac;background:#29373a}.rsc-show-inspector:active{transform:scale(.97)}.rsc-show-inspector:focus-visible{outline:2px solid #18b9e6;outline-offset:3px}.rsc-show-inspector span{color:#80dbea;font-size:14px}.rsc-visual-app.is-inspector-hidden .rsc-visual-workspace{grid-template-columns:minmax(0,1fr);overflow:hidden}.rsc-visual-app.is-inspector-hidden .rsc-visual-inspector{display:none}.rsc-visual-app.is-inspector-hidden .rsc-visual-stage{height:100%!important;max-height:none}.rsc-visual-app.is-inspector-hidden .rsc-canvas-wrap{height:100%}@media(max-width:640px){.rsc-inspector-head>#rsc-clear-selection,.rsc-inspector-head>.rsc-inspector-visibility-toggle{align-self:center;flex:0 0 28px}.rsc-show-inspector{right:14px;bottom:14px;min-height:32px;padding:5px 9px;font-size:10px}.rsc-visual-app.is-inspector-hidden .rsc-visual-stage{height:calc(100dvh - 96px)!important;padding-bottom:54px}.rsc-visual-app.is-inspector-hidden .rsc-canvas-touchbar{top:14px}}
.rsc-visual-toolbar>.rsc-visual-route-form{align-self:center!important;margin:0!important}
.rsc-visual-topbar{height:58px;flex:0 0 58px;display:flex;align-items:center;justify-content:space-between;flex-wrap:nowrap;gap:10px;padding:0 12px;border-bottom:1px solid #2a2f32;background:#181a1c}.rsc-visual-header-left,.rsc-visual-header-center,.rsc-visual-header-right{display:flex;align-items:center;min-width:0;white-space:nowrap}.rsc-visual-header-left{flex:1 1 31%;gap:8px}.rsc-visual-header-center{flex:0 1 auto;justify-content:center;gap:8px}.rsc-visual-header-right{flex:1 1 31%;justify-content:flex-end;gap:8px}.rsc-visual-back,.rsc-visual-icon{box-sizing:border-box;width:28px;height:28px;flex:0 0 28px;display:grid;place-items:center;border:1px solid transparent;background:transparent;color:#d9dfdf;text-decoration:none;font-size:18px;line-height:1;border-radius:6px}.rsc-visual-back:hover,.rsc-visual-icon:hover{background:#272c2f;border-color:#3a4145}.rsc-visual-brand{display:flex;align-items:center;min-width:0;gap:6px;font-size:12px;letter-spacing:-.01em}.rsc-visual-brand strong{min-width:0;overflow:hidden;font-weight:650;text-overflow:ellipsis}.rsc-visual-brand span:last-child{color:var(--v-muted);border-left:1px solid #3c4245;padding-left:6px}.rsc-visual-dot{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:#18b9e6;box-shadow:0 0 0 3px rgba(24,185,230,.15)}.rsc-visual-change-status{display:flex;align-items:center;min-width:0;gap:5px;margin-left:2px;color:#aeb8b9;font-size:10px}.rsc-visual-change-status>span{width:6px;height:6px;flex:0 0 6px;border-radius:50%;background:#5c6668}.rsc-visual-change-status strong{overflow:hidden;color:#dfe6e5;font-weight:600;text-overflow:ellipsis}.rsc-visual-change-status em{overflow:hidden;font-style:normal;color:#818d90;text-overflow:ellipsis}.rsc-visual-header-center .rsc-visual-device-group{box-sizing:border-box;height:30px;flex:0 0 auto;gap:1px;padding:2px;border-radius:7px}.rsc-visual-header-center .rsc-visual-device-group button{width:24px;height:24px;border:0;background:transparent;color:#929b9e;border-radius:5px;font-size:12px;line-height:1;cursor:pointer}.rsc-visual-header-center .rsc-visual-device-group button.is-active{background:#31373a;color:#fff}.rsc-visual-header-center .rsc-visual-route-form{box-sizing:border-box;display:flex;align-items:center;width:clamp(122px,14vw,168px);min-width:0;height:30px;min-height:30px;max-height:30px;gap:5px;margin:0!important;padding:0 7px;border:1px solid #2d3336;border-radius:6px;background:#101214;color:#b9c1c3}.rsc-visual-header-center .rsc-visual-route-form>.rsc-visual-route-home{display:grid;flex:0 0 13px;place-items:center;height:28px;line-height:1}.rsc-visual-header-center .rsc-visual-route-form select{box-sizing:border-box;display:block;flex:1;min-width:0!important;width:100%;height:28px!important;min-height:28px!important;max-height:28px!important;margin:0!important;padding:0!important;border:0;outline:0;background:transparent;color:#eaf0ef;font:11px/28px inherit}.rsc-visual-header-actions{display:flex;align-items:center;flex:0 0 auto;gap:2px}.rsc-visual-header-actions .rsc-visual-icon{width:26px;height:26px;flex-basis:26px;font-size:16px}.rsc-visual-mode-chip{display:inline-flex;align-items:center;gap:5px;min-height:30px;border:1px solid #455459;border-radius:999px;background:#1c2427;color:#ecf6f4;padding:5px 8px;font:600 10px/1 inherit;cursor:pointer}.rsc-visual-mode-chip span{color:#80dbea;font-size:12px}.rsc-visual-mode-chip.is-scroll{border-color:#8b6e3e;background:#2c261b}.rsc-visual-mode-chip.is-scroll span{color:#e9cf9d}.rsc-visual-mode-chip:focus-visible{outline:2px solid #18b9e6;outline-offset:3px}.rsc-visual-network-badge{display:inline-flex;align-items:center;gap:5px;color:#99a7a8;font:600 10px/1 inherit}.rsc-visual-network-badge i{color:#6fd7ae;font-size:9px;font-style:normal}.rsc-visual-publish{min-height:30px;border:0;border-radius:6px;background:#f4f5f2;color:#18211e;padding:7px 10px;font:600 11px/1 inherit;cursor:pointer}.rsc-visual-app.is-focus-mode>.rsc-visual-toolbar{display:none}.rsc-visual-app.is-focus-mode .rsc-visual-workspace{height:100%;flex:1}.rsc-visual-app.is-focus-mode .rsc-visual-stage{padding-top:14px}@media(max-width:1180px){.rsc-visual-topbar{gap:7px;padding:0 10px}.rsc-visual-header-left,.rsc-visual-header-right{flex-basis:auto}.rsc-visual-brand strong{max-width:180px}.rsc-visual-change-status em{display:none}.rsc-visual-network-badge{font-size:0}.rsc-visual-network-badge i{font-size:9px}.rsc-visual-header-center .rsc-visual-route-form{width:136px}.rsc-visual-header-actions .rsc-visual-icon{width:24px;height:24px;flex-basis:24px;font-size:15px}.rsc-visual-publish{padding:7px 8px;font-size:10px}}@media(max-width:980px){.rsc-visual-topbar{height:52px;flex-basis:52px;justify-content:flex-start;overflow-x:auto;scrollbar-width:none}.rsc-visual-topbar::-webkit-scrollbar{display:none}.rsc-visual-header-left,.rsc-visual-header-center,.rsc-visual-header-right{flex:0 0 auto}.rsc-visual-header-left{max-width:330px}.rsc-visual-brand span:last-child{display:none}.rsc-visual-header-center{gap:6px}.rsc-visual-header-center .rsc-visual-route-form{width:132px}.rsc-visual-header-actions .rsc-visual-icon{width:23px;height:23px;flex-basis:23px;font-size:14px}.rsc-visual-stage{padding:12px}}@media(max-width:640px){.rsc-visual-topbar{height:48px;flex-basis:48px;gap:6px;padding:0 8px}.rsc-visual-header-left{max-width:260px}.rsc-visual-back{width:26px;height:26px;flex-basis:26px;font-size:17px}.rsc-visual-brand strong{max-width:128px;font-size:11px}.rsc-visual-change-status{max-width:98px;font-size:9px}.rsc-visual-header-center .rsc-visual-device-group{height:28px}.rsc-visual-header-center .rsc-visual-device-group button{width:22px;height:22px}.rsc-visual-header-center .rsc-visual-route-form{position:relative;width:124px;height:28px;min-height:28px;max-height:28px;padding:0 6px}.rsc-visual-header-center .rsc-visual-route-form>.rsc-visual-route-home{display:none}.rsc-visual-header-center .rsc-visual-route-form>.rsc-visual-route-mobile-label{display:flex;align-items:center;width:100%;min-width:0;height:26px;gap:5px;color:#dce8e8;font:700 9px/1 inherit;letter-spacing:.04em;text-transform:uppercase}.rsc-visual-header-center .rsc-visual-route-mobile-label b{color:#80dbea;font-size:11px;font-weight:400}.rsc-visual-header-center .rsc-visual-route-mobile-label strong{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rsc-visual-header-center .rsc-visual-route-mobile-label i{margin-left:auto;color:#8fa4a7;font-size:12px;font-style:normal}.rsc-visual-header-center .rsc-visual-route-form select{position:absolute!important;inset:0!important;z-index:2;width:100%!important;height:28px!important;min-height:28px!important;max-height:28px!important;opacity:0;cursor:pointer}.rsc-visual-header-actions{gap:1px}.rsc-visual-header-actions .rsc-visual-icon{width:22px;height:22px;flex-basis:22px;font-size:13px}.rsc-visual-mode-chip{min-height:28px;padding:5px 7px;font-size:9px}.rsc-visual-network-badge{display:none}.rsc-visual-publish{min-height:28px;padding:6px 8px;font-size:10px}.rsc-visual-stage{height:calc(100dvh - 48px)!important;padding:8px 8px 76px}.rsc-visual-app.is-inspector-hidden .rsc-visual-stage{height:calc(100dvh - 48px)!important;padding-bottom:54px}.rsc-canvas-touchbar{top:14px;left:14px}.rsc-canvas-wrap{height:100%}}
.rsc-visual-workspace{grid-template-columns:minmax(0,1fr) clamp(320px,26vw,400px)}.rsc-visual-inspector,.rsc-visual-inspector *{box-sizing:border-box}.rsc-visual-inspector{width:100%;min-width:320px;max-width:400px;min-height:0;overflow:hidden}.rsc-inspector-head{position:relative;z-index:3;display:grid;grid-template-columns:minmax(0,1fr) 32px 32px;align-items:start;gap:8px;min-height:92px;margin:0;padding:14px 16px 12px;border-bottom:1px solid var(--v-line)}.rsc-inspector-head>.rsc-sheet-toggle{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:start;min-width:0;width:100%;margin:0;padding:0;border:0;background:transparent;color:#f4f7f6;text-align:left;cursor:pointer}.rsc-inspector-head>.rsc-sheet-toggle>span:last-of-type{display:block;min-width:0}.rsc-inspector-head>.rsc-sheet-toggle small{display:block;margin:0 0 5px;color:#90a0a2;font-size:10px;line-height:1.2;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.rsc-inspector-head>.rsc-sheet-toggle strong{display:-webkit-box;max-width:100%;max-height:2.6em;margin:0;overflow:hidden;color:#f4f7f6;font-size:14px;line-height:1.3;font-weight:650;overflow-wrap:anywhere;text-overflow:ellipsis;-webkit-box-orient:vertical;-webkit-line-clamp:2}.rsc-inspector-head>.rsc-sheet-toggle i{align-self:center;color:#b8c8c8;font-size:17px;font-style:normal;line-height:1}.rsc-inspector-head>#rsc-clear-selection,.rsc-inspector-head>.rsc-inspector-visibility-toggle{width:32px;height:32px;margin:0;padding:0;line-height:1}.rsc-selection-actions{position:relative;z-index:2;display:grid;grid-template-columns:minmax(0,1fr);gap:8px;width:100%;min-width:0;max-width:100%;margin:0;padding:12px 16px;border-bottom:1px solid var(--v-line)}.rsc-selection-actions button{width:max-content;max-width:100%;min-height:36px;margin:0;white-space:normal;line-height:1.25;text-align:left}.rsc-selection-actions span{display:block;margin:0;overflow-wrap:anywhere;color:#9da8aa;font-size:11px;line-height:1.45}.rsc-inspector-tabs{position:relative;z-index:1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;width:100%;min-width:0;max-width:100%;min-height:44px;gap:0;margin:0;padding:0 16px;border-bottom:1px solid var(--v-line)}.rsc-inspector-tabs button{min-width:0;min-height:44px;margin:0;padding:0 6px;border:0;border-bottom:2px solid transparent;background:transparent;color:#9ca6a8;font:13px/1.25 inherit;overflow-wrap:anywhere;text-align:center;cursor:pointer}.rsc-inspector-tabs button.is-active{color:#fff;border-bottom-color:#fff}.rsc-inspector-scroll{box-sizing:border-box;flex:1 1 auto;min-height:0;width:100%;min-width:0;max-width:100%;margin:0;overflow-y:auto!important;overflow-x:hidden!important;overscroll-behavior:contain;padding:16px}.rsc-inspector-panel,.rsc-inspector-panel.is-active{width:100%;min-width:0;max-width:100%}.rsc-inspector-panel label{width:100%;min-width:0;max-width:100%;margin:0 0 18px;line-height:1.35;overflow-wrap:anywhere}.rsc-inspector-panel input,.rsc-inspector-panel textarea,.rsc-inspector-panel select{box-sizing:border-box!important;display:block;min-width:0;max-width:100%!important;width:100%!important;margin:0;line-height:1.4}.rsc-inspector-panel textarea{min-height:118px;resize:vertical}.rsc-control-body{width:100%;min-width:0;max-width:100%;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.rsc-control-body>*{min-width:0;max-width:100%}.rsc-media-library,.rsc-media-preview,.rsc-media-grid,.rsc-focal-controls{width:100%;min-width:0;max-width:100%}.rsc-media-grid{overflow-y:auto!important;overflow-x:hidden!important}.rsc-media-card{width:100%;min-width:0;max-width:100%}.rsc-inspector-actions{position:relative;z-index:4;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));flex:0 0 auto;gap:8px;width:100%;min-width:0;max-width:100%;margin:0;padding:12px 16px;border-top:1px solid var(--v-line);background:#191c1e}.rsc-inspector-actions .btn{width:100%;min-width:0!important;max-width:100%;min-height:38px;margin:0;white-space:normal;line-height:1.2;text-align:center;overflow-wrap:anywhere}@media(max-width:1050px){.rsc-visual-workspace{grid-template-columns:minmax(0,1fr) minmax(320px,360px)}.rsc-visual-inspector{max-width:360px}}@media(max-width:980px){.rsc-visual-workspace{display:flex;flex-direction:column;overflow:hidden}.rsc-visual-inspector{width:100%;min-width:0;max-width:none;min-height:min(56dvh,600px);border-top:1px solid #303639;border-left:0}.rsc-inspector-scroll{max-height:none}}@media(max-width:640px){.rsc-visual-inspector{--rsc-sheet-collapsed-height:96px;height:min(76dvh,640px)!important;min-height:0!important}.rsc-visual-inspector:not(.is-sheet-open){transform:translateY(calc(100% - var(--rsc-sheet-collapsed-height)))!important}.rsc-inspector-head{flex:0 0 var(--rsc-sheet-collapsed-height);min-height:var(--rsc-sheet-collapsed-height);padding:18px 14px 10px;grid-template-columns:minmax(0,1fr) 30px 30px;gap:7px}.rsc-inspector-head>.rsc-sheet-toggle strong{font-size:13px;line-height:1.25}.rsc-inspector-head>#rsc-clear-selection,.rsc-inspector-head>.rsc-inspector-visibility-toggle{width:30px;height:30px}.rsc-selection-actions{padding:11px 14px}.rsc-inspector-tabs{padding:0 14px}.rsc-inspector-tabs button{min-height:42px;font-size:12px}.rsc-inspector-scroll{padding:14px}.rsc-inspector-panel label{margin-bottom:16px}.rsc-control-group{margin-right:-14px;margin-left:-14px}.rsc-control-heading{min-height:46px;padding:13px 14px;line-height:1.3}.rsc-control-body{grid-template-columns:1fr;padding-right:14px;padding-left:14px}.rsc-inspector-actions{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px 14px calc(10px + env(safe-area-inset-bottom))}.rsc-inspector-actions .rsc-save-publish{grid-column:1/-1}.rsc-media-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
.rsc-inspector-actions{grid-template-columns:repeat(3,minmax(0,1fr));gap:6px;padding:8px 12px}.rsc-inspector-actions .btn{min-height:32px;padding:6px 7px;white-space:nowrap;font-size:11px;line-height:1;overflow:hidden;text-overflow:ellipsis}.rsc-inspector-actions .rsc-save-publish{grid-column:auto}@media(max-width:640px){.rsc-inspector-actions{grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;padding:8px 10px calc(8px + env(safe-area-inset-bottom))}.rsc-inspector-actions .btn{min-height:32px;padding:6px 4px;font-size:10px}.rsc-inspector-actions .rsc-save-publish{grid-column:auto}}
/* v0.7.14: focus recovery and synchronized edit history */
.rsc-focus-exit{z-index:80!important;pointer-events:auto!important;touch-action:manipulation}.rsc-visual-app.is-focus-mode .rsc-focus-exit{position:fixed}.rsc-history-panel{position:absolute;z-index:50;top:0;right:0;bottom:0;display:flex;flex-direction:column;width:clamp(300px,25vw,372px);min-width:0;overflow:hidden;border-left:1px solid #354044;background:#191d20;box-shadow:-18px 0 40px rgba(0,0,0,.34);color:#edf4f2;opacity:0;pointer-events:none;transform:translateX(104%);transition:transform .22s cubic-bezier(.23,1,.32,1),opacity .18s cubic-bezier(.23,1,.32,1)}.rsc-visual-app.is-history-open .rsc-history-panel{opacity:1;pointer-events:auto;transform:translateX(0)}.rsc-history-head{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:64px;padding:12px 14px;border-bottom:1px solid #354044;background:#1d2225}.rsc-history-head>div:first-child{display:grid;gap:3px}.rsc-history-head span{color:#7f9194;font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase}.rsc-history-head strong{font-size:15px;line-height:1.2}.rsc-history-head>div:last-child{display:flex;gap:4px}.rsc-history-head button{display:grid;width:30px;height:30px;place-items:center;border:1px solid transparent;border-radius:6px;background:transparent;color:#c7d3d1;font:20px/1 inherit;cursor:pointer}.rsc-history-head button:hover{border-color:#465459;background:#273034}.rsc-history-tabs{display:grid;grid-template-columns:1fr 1fr;padding:0 14px;border-bottom:1px solid #354044}.rsc-history-tabs button{min-height:42px;border:0;border-bottom:2px solid transparent;background:transparent;color:#9aa9aa;font:600 12px/1 inherit;cursor:pointer}.rsc-history-tabs button.is-active{border-bottom-color:#6fd5e5;color:#eef9f7}.rsc-history-scroll{min-height:0;flex:1;overflow-y:auto;overflow-x:hidden;padding:12px}.rsc-history-tab{display:none}.rsc-history-tab.is-active{display:block}.rsc-history-hint,.rsc-history-empty{margin:0 0 11px;color:#95a5a7;font-size:11px;line-height:1.45}.rsc-history-list{display:grid;gap:8px}.rsc-history-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px 8px;padding:10px;border:1px solid #354044;border-radius:7px;background:#202629}.rsc-history-item.is-active{border-color:#5cd4e5;background:#1d3035;box-shadow:inset 3px 0 #5cd4e5}.rsc-history-item.is-preview{border-color:#d9bc74}.rsc-history-item-meta{position:relative;display:grid;gap:2px;min-width:0;padding-left:11px}.rsc-history-item-meta i{position:absolute;top:5px;left:0;width:6px;height:6px;border-radius:50%;background:#758487}.rsc-history-item-meta i.published{background:#6fd5a5}.rsc-history-item-meta i.saved{background:#68cde0}.rsc-history-item-meta strong{overflow:hidden;color:#f1f7f6;font-size:12px;line-height:1.25;text-overflow:ellipsis;white-space:nowrap}.rsc-history-item-meta span{overflow:hidden;color:#99a8aa;font-size:10px;line-height:1.3;text-overflow:ellipsis;white-space:nowrap}.rsc-history-item time{align-self:start;color:#839294;font-size:10px;white-space:nowrap}.rsc-history-item-actions{display:flex;grid-column:1/-1;gap:6px}.rsc-history-item-actions button{min-height:27px;padding:5px 7px;border:1px solid #455256;border-radius:5px;background:#252d30;color:#cbd8d6;font:600 10px/1 inherit;cursor:pointer}.rsc-history-item-actions button:hover:not(:disabled){border-color:#7e989b;background:#303b3e}.rsc-history-item-actions button:disabled{opacity:.55;cursor:default}.rsc-history-item-actions .is-restore{border-color:#3d8d9b;background:#19353b;color:#ddfbf7}.rsc-history-item-actions span{align-self:center;color:#8b9899;font-size:10px}.rsc-history-footer{display:flex;justify-content:flex-end;padding:9px 12px;border-top:1px solid #354044;background:#1c2023}.rsc-history-footer .btn{min-height:30px;padding:6px 9px;font-size:11px}.rsc-history-footer .btn[hidden]{display:none!important}@media(min-width:1181px){.rsc-visual-app.is-history-docked .rsc-visual-workspace{grid-template-columns:minmax(0,1fr) clamp(320px,26vw,400px) clamp(300px,23vw,372px)}.rsc-visual-app.is-history-docked .rsc-history-panel{position:relative;grid-column:3;transform:translateX(0);box-shadow:none}.rsc-visual-app.is-history-docked:not(.is-history-open) .rsc-history-panel{display:none}}@media(max-width:1180px){.rsc-history-head #rsc-history-dock{display:none}}@media(max-width:640px){.rsc-history-panel{position:fixed;z-index:60;top:48px;right:0;bottom:0;left:0;width:auto;border-left:0;transform:translateX(100%)}.rsc-visual-app.is-history-open .rsc-history-panel{transform:translateX(0)}.rsc-history-head{min-height:58px}.rsc-history-scroll{padding:10px}.rsc-history-item{padding:9px}.rsc-history-item-actions button{min-height:30px}.rsc-visual-app.is-focus-mode .rsc-focus-exit{z-index:90!important;top:12px;right:12px}}@media(prefers-reduced-motion:reduce){.rsc-history-panel{transition:none}}
</style>
<?php require $root . '/admin/partials/footer.php'; ?>
