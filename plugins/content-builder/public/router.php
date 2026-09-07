<?php
/**
 * Content Builder — public route handler.
 *
 * Registered on the `public_routes` filter under the 'p' prefix for pages
 * and one prefix per non-page post type. Receives from PublicRouter:
 *   $_GET['_route_prefix']  e.g. 'p' or 'post'
 *   $_GET['_route_path']    e.g. 'about' or 'hello-world'
 *
 * Pages:  /p/<slug>           → type=page
 * Posts:  /<type>/<slug>      → type=<prefix>
 *
 * Draft preview (admin only): append ?preview=1; requires content.view.
 *
 * The full HTML document (header, nav, footer) is produced by Theme.php.
 */

require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('content-builder');

$prefix = (string)($_GET['_route_prefix'] ?? 'p');
$path   = trim((string)($_GET['_route_path'] ?? ''), '/');

$type = ($prefix === 'p') ? 'page' : $prefix;
$slug = $path !== '' ? $path : 'home';

// Only the last path segment is the slug (ignore any nested remainder).
if (str_contains($slug, '/')) {
    $slug = substr($slug, strrpos($slug, '/') + 1);
}

$preview = !empty($_GET['preview']);

$post = ContentBuilderAPI::getPostBySlug($type, $slug);

// Preview mode: allow viewing a draft, but only for logged-in users with
// content.view. Everyone else gets the published-only behaviour.
$canPreview = $preview && class_exists('Auth') && Auth::check() && Auth::can('content.view');

if (!$post || ($post['status'] !== 'published' && !$canPreview)) {
    http_response_code(404);
    $headTags = '<title>404 — Not found</title>';
    $bodyHtml = '<main class="cb-page"><h1>404</h1><p>That page could not be found.</p></main>';
    echo Theme::renderPage($headTags, $bodyHtml, null);
    return;
}

$default  = '<title>' . e($post['title'] ?: 'Untitled') . '</title>';
$headTags = Hook::applyFilters('content_head_tags', $default, $post);

// Render mode: 'full_html' outputs the page's raw HTML verbatim (no theme/
// header/footer shell) — for pasting a complete standalone document.
$mode = (string)ContentBuilderAPI::getMeta((int)$post['id'], 'cb_render_mode', 'builder');
if ($mode === 'full_html') {
    // Concatenate the raw content of every 'html' block, output as-is.
    $html = '';
    foreach (($post['layout'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'html') {
            $html .= (string)($block['props']['html'] ?? '');
        }
    }
    if ($post['status'] !== 'published') {
        echo '<div style="background:#fef3c7;color:#92400e;padding:.5rem 1rem;text-align:center;font:14px system-ui">Draft preview — not publicly visible.</div>';
    }
    echo $html;
    return;
}

$banner = ($post['status'] !== 'published')
    ? '<div class="cb-preview-banner">Draft preview — not publicly visible.</div>'
    : '';

// Phase 3B render cutover: route the body through the core Slate\Presentation
// renderer via the bridge, which self-verifies against the legacy renderer and
// serves legacy on any divergence/error (so visitor output is unchanged). Falls
// back to the direct legacy call if the bridge isn't loaded.
$body = class_exists('ContentCoreBridge')
    ? ContentCoreBridge::renderLayoutForPublic($post['layout'])
    : ContentBuilderAPI::renderLayout($post['layout']);

$bodyHtml = $banner . '<main class="cb-page">' . $body . '</main>';

// Phase C exit — the FRAME cutover. The body already routes through the core
// renderer above; the document itself still came from Theme::renderPage, so
// PageAssembler / TemplateResolver / Template had no production caller at all.
// This routes the frame through them, parity-gated the same way: byte-identical
// output, legacy served on any divergence, `document_engine` as the rollback.
//
// The engine that actually served is announced as a response HEADER rather than
// in the markup, because a marker in the body would break the byte-parity this
// cutover is proving. It is what makes "the core path really ran" checkable on a
// live URL after deploy, not only in CI:
//
//     curl -sI https://…/p/home | grep X-Slate-Document-Engine
if (class_exists('ContentCoreBridge')) {
    $doc = ContentCoreBridge::renderDocumentForPublic($post, $headTags, $bodyHtml);
    if (!headers_sent()) {
        header('X-Slate-Document-Engine: ' . $doc['served']);
        // The body has its own parity gate, and it silently serves legacy on
        // divergence too. Reported separately because the two can disagree: a
        // page whose block markup diverges is served a LEGACY body inside a CORE
        // document, and one header alone would call that a success.
        $bodyEngine = ContentCoreBridge::lastBodyEngine();
        if ($bodyEngine !== null) {
            header('X-Slate-Body-Engine: ' . $bodyEngine);
        }
    }
    echo $doc['html'];
} else {
    echo Theme::renderPage($headTags, $bodyHtml, $post);
}
