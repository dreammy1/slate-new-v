<?php
/**
 * Slate — Global Styles & Theme Controls.
 *
 * Content -> Global Styles (/admin/styles.php)
 * Central management of design tokens, color palette, typography, and component styling.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

Auth::require();
Auth::requirePerm('content.edit');

$canEdit    = Auth::can('content.edit') || Auth::isSuperAdmin();
$pageTitle  = __('global_styles', 'Global Styles');
$currentNav = 'styles';

$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit global styles.')];
    } else {
        $action = $_POST['_action'] ?? '';
        if ($action === 'save_styles') {
            $accent = trim((string)($_POST['brand_accent_color'] ?? '#2563EB'));
            if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $accent)) $accent = '#2563EB';

            $fontFamily = trim((string)($_POST['font_family'] ?? 'system'));
            $allowedFonts = ['system', 'inter', 'plus-jakarta', 'outfit', 'playfair', 'roboto'];
            if (!in_array($fontFamily, $allowedFonts, true)) $fontFamily = 'system';

            $radius = trim((string)($_POST['border_radius'] ?? 'medium'));
            $allowedRadius = ['none', 'small', 'medium', 'large', 'pill'];
            if (!in_array($radius, $allowedRadius, true)) $radius = 'medium';

            Database::setSetting('brand_accent_color', $accent);
            Database::setSetting('brand_font_family', $fontFamily);
            Database::setSetting('brand_border_radius', $radius);

            AuditLog::record('settings.global_styles_updated', 'theme', [
                'accent' => $accent,
                'font'   => $fontFamily,
                'radius' => $radius,
            ]);

            $flash = ['type' => 'success', 'msg' => __('styles_saved', 'Global styles saved successfully.')];
        }
    }
}

$accent = (string)(Database::setting('brand_accent_color') ?: '#2563EB');
$fontFamily = (string)(Database::setting('brand_font_family') ?: 'system');
$radius = (string)(Database::setting('brand_border_radius') ?: 'medium');

require __DIR__ . '/partials/header.php';
?>

<div class="content-header" style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;">
    <div>
        <h1 style="margin:0 0 6px;font-size:24px;font-weight:700;color:var(--text);"><?= __('global_styles', 'Global Styles') ?></h1>
        <p style="margin:0;color:var(--muted);font-size:14px;"><?= __('global_styles_desc', 'Customize design tokens, theme colors, typography, and button appearance across your site.') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" style="margin-bottom:20px;">
        <?= e($flash['msg']) ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0, 1.4fr) minmax(320px, 1fr);gap:24px;align-items:start;">
    <div class="card">
        <div class="card-header">
            <h2><?= __('design_tokens', 'Design Tokens') ?></h2>
        </div>
        <div class="card-body" style="padding:24px;">
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_styles">

                <div class="field" style="margin-bottom:24px;">
                    <label class="field-label" for="brand_accent_color"><?= __('accent_color', 'Brand Accent Color') ?></label>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                        <input type="color" id="accent_picker" value="<?= e($accent) ?>" style="width:44px;height:40px;padding:2px;border:1px solid var(--border);border-radius:8px;cursor:pointer;" oninput="document.getElementById('brand_accent_color').value=this.value;updatePreview();">
                        <input type="text" id="brand_accent_color" name="brand_accent_color" class="input" value="<?= e($accent) ?>" style="max-width:140px;font-family:var(--font-mono);" oninput="document.getElementById('accent_picker').value=this.value;updatePreview();" <?= $canEdit ? '' : 'disabled' ?>>
                    </div>
                    <div class="field-hint"><?= __('accent_hint', 'Used for primary CTAs, active navigation items, links, and highlights.') ?></div>
                </div>

                <div class="field" style="margin-bottom:24px;">
                    <label class="field-label" for="font_family"><?= __('typography', 'Typography Font Family') ?></label>
                    <select id="font_family" name="font_family" class="input" style="max-width:320px;" onchange="updatePreview();" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="system" <?= $fontFamily === 'system' ? 'selected' : '' ?>>System Sans (-apple-system, BlinkMacSystemFont)</option>
                        <option value="plus-jakarta" <?= $fontFamily === 'plus-jakarta' ? 'selected' : '' ?>>Plus Jakarta Sans (Modern Editorial)</option>
                        <option value="inter" <?= $fontFamily === 'inter' ? 'selected' : '' ?>>Inter (Clean UI)</option>
                        <option value="outfit" <?= $fontFamily === 'outfit' ? 'selected' : '' ?>>Outfit (Contemporary Geometric)</option>
                        <option value="playfair" <?= $fontFamily === 'playfair' ? 'selected' : '' ?>>Playfair Display (Classic Serif)</option>
                    </select>
                    <div class="field-hint"><?= __('font_hint', 'Global typeface applied to headings, body text, and UI components.') ?></div>
                </div>

                <div class="field" style="margin-bottom:28px;">
                    <label class="field-label" for="border_radius"><?= __('corner_radius', 'Corner Radius') ?></label>
                    <select id="border_radius" name="border_radius" class="input" style="max-width:320px;" onchange="updatePreview();" <?= $canEdit ? '' : 'disabled' ?>>
                        <option value="none" <?= $radius === 'none' ? 'selected' : '' ?>>Sharp (0px)</option>
                        <option value="small" <?= $radius === 'small' ? 'selected' : '' ?>>Subtle (6px)</option>
                        <option value="medium" <?= $radius === 'medium' ? 'selected' : '' ?>>Rounded (10px — Default)</option>
                        <option value="large" <?= $radius === 'large' ? 'selected' : '' ?>>Smooth (16px)</option>
                        <option value="pill" <?= $radius === 'pill' ? 'selected' : '' ?>>Full Pill (999px)</option>
                    </select>
                    <div class="field-hint"><?= __('radius_hint', 'Curvature for buttons, inputs, cards, and containers.') ?></div>
                </div>

                <?php if ($canEdit): ?>
                    <button type="submit" class="btn btn-primary">
                        <?= __('save_styles', 'Save Global Styles') ?>
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Live Preview Card -->
    <div class="card" style="position:sticky;top:80px;">
        <div class="card-header">
            <h2><?= __('live_preview', 'Live Token Preview') ?></h2>
        </div>
        <div class="card-body" style="padding:24px;" id="preview_box">
            <div id="demo_container" style="border:1px solid var(--border);border-radius:10px;padding:20px;background:var(--surface);">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:var(--muted);margin-bottom:6px;">Preview Sample</div>
                <h3 id="demo_heading" style="margin:0 0 10px;font-size:20px;color:var(--text);font-weight:700;">Headline Text Sample</h3>
                <p id="demo_body" style="font-size:14px;color:var(--muted);line-height:1.5;margin-bottom:16px;">
                    This is how typography, button accents, and corner radius look when rendered in client pages and widgets.
                </p>
                <div style="display:flex;gap:10px;align-items:center;">
                    <button type="button" id="demo_btn" class="btn btn-primary" style="background:<?= e($accent) ?>;border-color:<?= e($accent) ?>;">Primary Action</button>
                    <span id="demo_pill" class="badge" style="background:color-mix(in srgb, <?= e($accent) ?> 12%, transparent);color:<?= e($accent) ?>;">Tag Sample</span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updatePreview() {
    var accent = document.getElementById('brand_accent_color').value || '#2563EB';
    var font = document.getElementById('font_family').value;
    var radius = document.getElementById('border_radius').value;

    var fontMap = {
        'system': '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        'plus-jakarta': '"Plus Jakarta Sans", sans-serif',
        'inter': 'Inter, sans-serif',
        'outfit': 'Outfit, sans-serif',
        'playfair': '"Playfair Display", serif'
    };

    var radiusMap = {
        'none': '0px',
        'small': '6px',
        'medium': '10px',
        'large': '16px',
        'pill': '999px'
    };

    var btn = document.getElementById('demo_btn');
    var pill = document.getElementById('demo_pill');
    var container = document.getElementById('demo_container');
    var heading = document.getElementById('demo_heading');
    var body = document.getElementById('demo_body');

    btn.style.backgroundColor = accent;
    btn.style.borderColor = accent;
    btn.style.borderRadius = radiusMap[radius] || '10px';

    pill.style.color = accent;
    pill.style.backgroundColor = 'color-mix(in srgb, ' + accent + ' 15%, transparent)';

    container.style.borderRadius = radiusMap[radius] || '10px';
    container.style.fontFamily = fontMap[font] || fontMap['system'];
    heading.style.fontFamily = fontMap[font] || fontMap['system'];
    body.style.fontFamily = fontMap[font] || fontMap['system'];
}
</script>

<?php
require __DIR__ . '/partials/footer.php';
