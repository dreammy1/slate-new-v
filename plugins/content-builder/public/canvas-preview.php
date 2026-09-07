<?php
/**
 * Content Builder — Canvas Preview
 *
 * Iframe preview renderer for the visual page editor. Renders a post's draft_data
 * (falling back to layout) inside a minimal HTML shell with the site's theme tokens.
 */
require_once dirname(__DIR__, 3) . '/config.php';
slate_public_entry('content-builder');

Auth::require();
Auth::requirePerm('content.view');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(404);
    echo 'Post not found';
    exit;
}

$post = ContentBuilderAPI::getPost($id);
if (!$post) {
    http_response_code(404);
    echo 'Post not found';
    exit;
}

// Live-preview mode: JS editor POSTs the current in-memory layout
// directly so the canvas reflects unsaved changes instantly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_preview_layout'])) {
    if (!csrf_verify()) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $rawLayout = json_decode((string)$_POST['_preview_layout'], true);
    $layout = is_array($rawLayout) ? $rawLayout : [];
    $draft = ['layout' => $layout, 'isDraft' => true];
} else {
    $draft = ContentBuilderAPI::getDraft($id);
}
$renderedHtml = ContentBuilderAPI::renderLayout($draft['layout'] ?? []);

$themeData = ContentBuilderAPI::themeTokens();
$themeTokens = [];
if (is_array($themeData)) {
    if (isset($themeData['tokens']) && is_array($themeData['tokens'])) {
        $themeTokens = $themeData['tokens'];
    } else {
        $themeTokens = $themeData;
        unset($themeTokens['slug']);
    }
}

$publicCssRel = 'plugins/content-builder/public/assets/style.css';
// CSS files to load in the canvas preview (in order)
$cssFiles = [
    'plugins/content-builder/assets/css/public.css',
    'plugins/small-business-kit/assets/css/sb.css',
    'plugins/forms/assets/css/public.css',
    'plugins/booking/assets/css/public.css',
];
$root = dirname(__DIR__, 3);
$baseUrl = rtrim(SLATE_URL, '/');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($post['title'] ?? 'Preview') ?></title>
    <base href="<?= $baseUrl . '/' ?>">
    <?php foreach ($cssFiles as $cssRel): ?>
    <?php if (file_exists($root . '/' . $cssRel)): ?>
    <link rel="stylesheet" href="<?= e($baseUrl . '/' . $cssRel) ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <style>
        :root {
<?php foreach ($themeTokens as $name => $val): ?>
<?php if (is_scalar($val)): ?>
            <?= e(str_starts_with((string)$name, '--') ? (string)$name : '--' . (string)$name) ?>: <?= e((string)$val) ?>;
<?php endif; ?>
<?php endforeach; ?>
        }
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; font-family: var(--font-body, system-ui, sans-serif); background: #fff; color: #111; }
        img { max-width: 100%; height: auto; }
        .canvas-preview-body { max-width: 100%; }
    </style>
</head>
<body>
    <main class="canvas-preview-body">
        <?= $renderedHtml ?>
    </main>
    <script>
    (function() {
        function postHeight() {
            window.parent.postMessage({type:'canvas-height', height: document.body.scrollHeight}, '*');
        }

        window.addEventListener('load', postHeight);
        window.addEventListener('resize', postHeight);
        postHeight();

        // The parent sends complete preview POSTs into this iframe. Keep the
        // message channel for backwards compatibility, but only accept it from
        // the embedding origin and refresh the current document when requested.
        window.addEventListener('message', function(event) {
            if (event.source !== window.parent || event.origin !== window.location.origin) return;
            var data = event.data;
            if (data && data.type === 'canvas-update-layout') {
                window.location.reload();
            }
        });
    })();
    </script>
</body>
</html>
