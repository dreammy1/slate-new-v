<?php
/**
 * Content Builder — site settings (public theme header/footer/branding).
 */
require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();
Auth::requirePerm('content.manage_types');

$pageTitle  = 'Site Settings';
$currentNav = 'content-site';

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type'=>'error','msg'=>'Security check failed.'];
    } else {
        ContentBuilderAPI::setSiteSetting('theme',        $_POST['theme'] ?? 'clean-corporate');
        ContentBuilderAPI::setSiteSetting('layout_width', $_POST['layout_width'] ?? 'boxed');
        ContentBuilderAPI::setSiteSetting('type_scale',   $_POST['type_scale'] ?? 'm');
        ContentBuilderAPI::setSiteSetting('btn_shape',    $_POST['btn_shape'] ?? 'rounded');
        ContentBuilderAPI::setSiteSetting('site_name',    trim($_POST['site_name'] ?? ''));
        ContentBuilderAPI::setSiteSetting('logo_url',     trim($_POST['logo_url'] ?? ''));
        ContentBuilderAPI::setSiteSetting('tagline',      trim($_POST['tagline'] ?? ''));
        ContentBuilderAPI::setSiteSetting('accent_color', trim($_POST['accent_color'] ?? '#2563eb'));
        ContentBuilderAPI::setSiteSetting('footer_text',  trim($_POST['footer_text'] ?? ''));
        ContentBuilderAPI::setSiteSetting('show_header',  empty($_POST['show_header']) ? '0' : '1');
        ContentBuilderAPI::setSiteSetting('show_footer',  empty($_POST['show_footer']) ? '0' : '1');
        ContentBuilderAPI::setSiteSetting('header_style', $_POST['header_style'] ?? 'simple');
        ContentBuilderAPI::setSiteSetting('footer_style', $_POST['footer_style'] ?? 'simple');
        ContentBuilderAPI::setSiteSetting('header_cta_text', trim($_POST['header_cta_text'] ?? ''));
        ContentBuilderAPI::setSiteSetting('header_cta_href', trim($_POST['header_cta_href'] ?? ''));
        ContentBuilderAPI::setSiteSetting('palette',      $_POST['palette'] ?? '');
        ContentBuilderAPI::setSiteSetting('font_pairing', $_POST['font_pairing'] ?? '');
        ContentBuilderAPI::setSiteSetting('radius',       (string)(int)($_POST['radius'] ?? 10));

        // Site theme, owned by the engine since Phase A. Distinct from the
        // 'theme' preset above: that one drives Branding.php's tokens, this one
        // drives the themes plugins register on `content_register_themes`.
        // Only written when the form actually offered a choice, so an install
        // with no registered themes cannot blank an existing selection.
        if (isset($_POST['site_theme']) && $_POST['site_theme'] !== '') {
            ContentBuilderAPI::setActiveTheme((string)$_POST['site_theme']);
        }

        $flash = ['type'=>'success','msg'=>'Settings saved.'];
    }
}

$siteName   = ContentBuilderAPI::getSiteSetting('site_name', '');
$tagline    = ContentBuilderAPI::getSiteSetting('tagline', '');
$accent     = ContentBuilderAPI::getSiteSetting('accent_color', '#2563eb');
$footerText = ContentBuilderAPI::getSiteSetting('footer_text', '');
$showHeader = ContentBuilderAPI::getSiteSetting('show_header', '1') !== '0';
$showFooter = ContentBuilderAPI::getSiteSetting('show_footer', '1') !== '0';
$headerStyle= ContentBuilderAPI::getSiteSetting('header_style', 'simple');
$footerStyle= ContentBuilderAPI::getSiteSetting('footer_style', 'simple');
$headerCtaText = ContentBuilderAPI::getSiteSetting('header_cta_text', '');
$headerCtaHref = ContentBuilderAPI::getSiteSetting('header_cta_href', '');
$palette    = ContentBuilderAPI::getSiteSetting('palette', '');
$fontPair   = ContentBuilderAPI::getSiteSetting('font_pairing', '');
$radiusRaw  = ContentBuilderAPI::getSiteSetting('radius', '');
$radius     = $radiusRaw !== '' ? (int)$radiusRaw : (int)(Branding::resolve()['radius']);
$palettes   = Branding::palettes();
$fonts      = Branding::fontPairings();
$logoUrl    = ContentBuilderAPI::getSiteSetting('logo_url', '');
$cbHasMedia = PluginLoader::isActive('media-library');
$theme      = ContentBuilderAPI::getSiteSetting('theme', 'clean-corporate');
$themes     = Branding::themes();
$layoutWidth= ContentBuilderAPI::getSiteSetting('layout_width', 'boxed');
$typeScale  = ContentBuilderAPI::getSiteSetting('type_scale', 'm');
$btnShape   = ContentBuilderAPI::getSiteSetting('btn_shape', 'rounded');

$headerMenu = ContentBuilderAPI::getMenuByLocation('header');
$footerMenu = ContentBuilderAPI::getMenuByLocation('footer');

require SLATE_ROOT . '/admin/partials/header.php';
?>
<?php if ($cbHasMedia): ?>
    <link rel="stylesheet" href="<?= e(SLATE_URL) ?>/plugins/media-library/assets/css/picker.css">
    <script src="<?= e(SLATE_URL) ?>/plugins/media-library/assets/js/picker.js"></script>
<?php endif; ?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Site Settings'],
]); ?>

<div class="page-header">
    <div><h1>Site Settings</h1>
    <p class="page-header-sub">Branding and the global header/footer for your public pages.</p></div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>

    <?php $siteThemes = ContentBuilderAPI::themes(); if ($siteThemes): $activeSiteTheme = ContentBuilderAPI::activeTheme(); ?>
    <div class="card" id="theme">
        <div class="card-header"><h2>Site theme</h2></div>
        <p class="text-sub">Styles your small-business pages — colours, type and button style across every block. Switching is instant; your content is untouched.</p>
        <div class="cb-theme-grid">
            <?php foreach ($siteThemes as $slug => $th): $t = $th['tokens'] ?? []; ?>
                <label class="cb-theme-card <?= $activeSiteTheme === $slug ? 'is-active' : '' ?>">
                    <input type="radio" name="site_theme" value="<?= e($slug) ?>" <?= $activeSiteTheme === $slug ? 'checked' : '' ?> hidden>
                    <span class="cb-theme-swatch" style="background:<?= e($t['--sb-page'] ?? '#ffffff') ?>">
                        <span style="background:<?= e($t['--sb-accent'] ?? '#0ea5e9') ?>"></span>
                        <span style="background:<?= e($t['--sb-surface'] ?? '#f5f5f5') ?>"></span>
                    </span>
                    <span class="cb-theme-name"><?= e($th['label'] ?? $slug) ?></span>
                    <?php if (!empty($th['blurb'])): ?><span class="text-sub"><?= e($th['blurb']) ?></span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h2>Theme preset</h2></div>
        <p class="text-sub">Colours and type for pages built from the core block set. Separate from the site theme above, which styles the small-business blocks. <em>These two will merge in a later phase.</em></p>
        <div class="cb-theme-grid">
            <?php foreach ($themes as $key => $th): ?>
                <label class="cb-theme-card <?= $theme===$key?'is-active':'' ?>">
                    <input type="radio" name="theme" value="<?= e($key) ?>" <?= $theme===$key?'checked':'' ?> hidden>
                    <span class="cb-theme-swatch" style="background:<?= e($th['page_bg']) ?>">
                        <span style="background:<?= e($th['accent']) ?>"></span>
                        <span style="background:<?= e($th['surface']) ?>"></span>
                    </span>
                    <span class="cb-theme-name"><?= e($th['label']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Branding</h2></div>
        <div class="field">
            <label class="field-label" for="site_name">Site name</label>
            <input type="text" id="site_name" name="site_name" value="<?= e($siteName) ?>"
                   placeholder="Chef Gregory's">
        </div>
        <div class="field">
            <label class="field-label" for="logo_url">Logo image (optional — replaces text name in header)</label>
            <input type="text" id="logo_url" name="logo_url" value="<?= e($logoUrl) ?>"
                   placeholder="https://… or pick from library">
            <?php if ($cbHasMedia): ?>
                <button type="button" class="btn btn-sm mt-2" id="cb-logo-pick">Choose from library</button>
            <?php endif; ?>
            <?php if ($logoUrl): ?>
                <div class="mt-2"><img src="<?= e($logoUrl) ?>" alt="" style="max-height:48px;border:1px solid #e4e7ec;border-radius:6px;padding:4px;background:#fff"></div>
            <?php endif; ?>
        </div>
        <div class="field">
            <label class="field-label" for="tagline">Tagline</label>
            <input type="text" id="tagline" name="tagline" value="<?= e($tagline) ?>"
                   placeholder="Fine dining, delivered">
        </div>

        <div class="field">
            <label class="field-label">Color palette <span class="text-muted">(optional — overrides the theme's color)</span></label>
            <div class="cb-swatch-grid">
                <label class="cb-swatch <?= $palette===''?'is-active':'' ?>">
                    <input type="radio" name="palette" value="" <?= $palette===''?'checked':'' ?> hidden>
                    <span class="cb-swatch-dot" style="background:linear-gradient(135deg,#999,#ddd)"></span>
                    <span class="cb-swatch-label">Follow theme</span>
                </label>
                <?php foreach ($palettes as $key => $p): ?>
                    <label class="cb-swatch <?= $palette===$key?'is-active':'' ?>">
                        <input type="radio" name="palette" value="<?= e($key) ?>" <?= $palette===$key?'checked':'' ?> hidden>
                        <span class="cb-swatch-dot" style="background:<?= e($p['accent']) ?>"></span>
                        <span class="cb-swatch-label"><?= e($p['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="accent_color">Custom accent (overrides palette — leave default to use palette)</label>
            <input type="text" id="accent_color" name="accent_color"
                   value="<?= e(ContentBuilderAPI::getSiteSetting('accent_color','')) ?>"
                   placeholder="#2563eb (optional)">
        </div>

        <div class="field">
            <label class="field-label" for="font_pairing">Font pairing (system fonts — zero load time)</label>
            <select id="font_pairing" name="font_pairing" class="field-input">
                <option value="" <?= $fontPair===''?'selected':'' ?>>Follow theme</option>
                <?php foreach ($fonts as $key => $f): ?>
                    <option value="<?= e($key) ?>" <?= $fontPair===$key?'selected':'' ?>><?= e($f['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label class="field-label" for="radius">Corner roundness: <span id="cb-radius-val"><?= $radius ?></span>px</label>
            <input type="range" id="radius" name="radius" min="0" max="24" value="<?= $radius ?>"
                   oninput="document.getElementById('cb-radius-val').textContent=this.value">
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Layout &amp; type</h2></div>
        <div class="field">
            <label class="field-label" for="layout_width">Default page width</label>
            <select id="layout_width" name="layout_width" class="field-input">
                <option value="boxed"  <?= $layoutWidth==='boxed'?'selected':'' ?>>Boxed (centered, comfortable)</option>
                <option value="full"   <?= $layoutWidth==='full'?'selected':'' ?>>Full (wider content)</option>
                <option value="canvas" <?= $layoutWidth==='canvas'?'selected':'' ?>>Canvas (edge to edge)</option>
            </select>
            <small class="text-muted">Each page can override this in its editor.</small>
        </div>
        <div class="field">
            <label class="field-label" for="type_scale">Font size scale</label>
            <select id="type_scale" name="type_scale" class="field-input">
                <option value="s"  <?= $typeScale==='s'?'selected':'' ?>>Small</option>
                <option value="m"  <?= $typeScale==='m'?'selected':'' ?>>Medium (default)</option>
                <option value="l"  <?= $typeScale==='l'?'selected':'' ?>>Large</option>
                <option value="xl" <?= $typeScale==='xl'?'selected':'' ?>>Extra large</option>
            </select>
        </div>
        <div class="field">
            <label class="field-label" for="btn_shape">Button style</label>
            <select id="btn_shape" name="btn_shape" class="field-input">
                <option value="rounded" <?= $btnShape==='rounded'?'selected':'' ?>>Rounded</option>
                <option value="square"  <?= $btnShape==='square'?'selected':'' ?>>Square</option>
                <option value="pill"    <?= $btnShape==='pill'?'selected':'' ?>>Pill</option>
            </select>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Header</h2></div>
        <div class="field">
            <label class="field-label">Header preset <span class="text-muted">(live preview — click to choose)</span></label>
            <input type="hidden" id="header_style" name="header_style" value="<?= e($headerStyle) ?>">
            <div class="cb-pick" data-pick-for="header_style">
                <?php foreach (Theme::headerPresets() as $key => $p): ?>
                    <button type="button" class="cb-pick-card<?= $headerStyle===$key?' is-active':'' ?>" data-value="<?= e($key) ?>">
                        <span class="cb-pick-frame cb-pick-frame-h">
                            <iframe loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true"
                                    srcdoc="<?= e(Theme::previewDoc(Theme::previewHeader($key))) ?>"></iframe>
                        </span>
                        <span class="cb-pick-meta"><b><?= e($p['label']) ?></b><small><?= e($p['desc']) ?></small></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="header_cta_text">Header button label <span class="text-muted">(optional)</span></label>
            <input type="text" id="header_cta_text" name="header_cta_text" value="<?= e($headerCtaText) ?>"
                   placeholder="Get started">
        </div>
        <div class="field">
            <label class="field-label" for="header_cta_href">Header button link</label>
            <input type="text" id="header_cta_href" name="header_cta_href" value="<?= e($headerCtaHref) ?>"
                   placeholder="/p/contact">
            <small class="text-muted">Adds a call-to-action button at the end of the header. Leave the label blank to hide it.</small>
        </div>
        <div class="field">
            <label class="cb-term">
                <input type="checkbox" name="show_header" value="1" <?= $showHeader?'checked':'' ?>>
                Show header on public pages
            </label>
        </div>
        <p class="text-sub">
            Header navigation:
            <?php if ($headerMenu): ?>
                <strong><?= e($headerMenu['name']) ?></strong> (<?= count($headerMenu['items']) ?> items).
            <?php else: ?>
                none assigned.
            <?php endif; ?>
            <a href="menus.php">Manage menus →</a>
        </p>
    </div>

    <div class="card">
        <div class="card-header"><h2>Footer</h2></div>
        <div class="field">
            <label class="cb-term">
                <input type="checkbox" name="show_footer" value="1" <?= $showFooter?'checked':'' ?>>
                Show footer on public pages
            </label>
        </div>
        <div class="field">
            <label class="field-label">Footer preset <span class="text-muted">(live preview — click to choose)</span></label>
            <input type="hidden" id="footer_style" name="footer_style" value="<?= e($footerStyle) ?>">
            <div class="cb-pick" data-pick-for="footer_style">
                <?php foreach (Theme::footerPresets() as $key => $p): ?>
                    <button type="button" class="cb-pick-card<?= $footerStyle===$key?' is-active':'' ?>" data-value="<?= e($key) ?>">
                        <span class="cb-pick-frame cb-pick-frame-f">
                            <iframe loading="lazy" scrolling="no" tabindex="-1" aria-hidden="true"
                                    srcdoc="<?= e(Theme::previewDoc(Theme::previewFooter($key))) ?>"></iframe>
                        </span>
                        <span class="cb-pick-meta"><b><?= e($p['label']) ?></b><small><?= e($p['desc']) ?></small></span>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="field">
            <label class="field-label" for="footer_text">Footer text</label>
            <input type="text" id="footer_text" name="footer_text" value="<?= e($footerText) ?>"
                   placeholder="© 2026 Chef Gregory's. All rights reserved.">
            <small class="text-muted">Leave blank for an automatic copyright line.</small>
        </div>
        <p class="text-sub">
            Footer navigation:
            <?php if ($footerMenu): ?>
                <strong><?= e($footerMenu['name']) ?></strong> (<?= count($footerMenu['items']) ?> items).
            <?php else: ?>
                none assigned.
            <?php endif; ?>
            <a href="menus.php">Manage menus →</a>
        </p>
    </div>

    <button type="submit" class="btn btn-primary">Save settings</button>
</form>

<script>
(function(){
  var swatches = document.querySelectorAll('.cb-swatch');
  swatches.forEach(function(s){
    s.addEventListener('click', function(){
      swatches.forEach(function(x){ x.classList.remove('is-active'); });
      s.classList.add('is-active');
    });
  });
  // Highlight the picked card, scoped to its own grid. There is more than one
  // theme grid on this page since Phase A (site theme + theme preset), and they
  // are independent choices — clearing globally would visibly deselect the
  // other grid while its radio stayed checked.
  document.querySelectorAll('.cb-theme-grid').forEach(function(grid){
    var cards = grid.querySelectorAll('.cb-theme-card');
    cards.forEach(function(c){
      c.addEventListener('click', function(){
        cards.forEach(function(x){ x.classList.remove('is-active'); });
        c.classList.add('is-active');
      });
    });
  });
  var logoBtn = document.getElementById('cb-logo-pick');
  if (logoBtn && window.MediaPicker) {
    logoBtn.addEventListener('click', function(){
      window.MediaPicker.open({ mode:'single', onPick:function(path){
        document.getElementById('logo_url').value = path;
      }});
    });
  }
  // Visual preset pickers (header / footer): click a live preview to select.
  document.querySelectorAll('.cb-pick').forEach(function(grid){
    var input = document.getElementById(grid.getAttribute('data-pick-for'));
    grid.querySelectorAll('.cb-pick-card').forEach(function(card){
      card.addEventListener('click', function(){
        grid.querySelectorAll('.cb-pick-card').forEach(function(x){ x.classList.remove('is-active'); });
        card.classList.add('is-active');
        if (input) input.value = card.getAttribute('data-value');
      });
    });
  });
})();
</script>
<style>
.cb-pick{display:grid;grid-template-columns:repeat(auto-fill,300px);justify-content:start;gap:.9rem;margin-top:.4rem}
/* On phones the fixed 300px columns/frame can exceed a narrow viewport — let
   the picker go full-width single-column so nothing overflows. */
@media (max-width:640px){
  .cb-pick{grid-template-columns:1fr;}
  .cb-pick-card,.cb-pick-frame{width:100%;max-width:100%;}
}
.cb-pick-card{display:flex;flex-direction:column;gap:.55rem;width:300px;max-width:100%;padding:0;background:#fff;border:2px solid #e4e7ec;border-radius:12px;cursor:pointer;text-align:left;overflow:hidden;transition:border-color .15s,box-shadow .15s,transform .15s}
.cb-pick-card:hover{border-color:#cbd2da;transform:translateY(-2px);box-shadow:0 8px 22px -12px rgba(16,24,40,.35)}
.cb-pick-card.is-active{border-color:var(--slate-color-accent,#2563eb);box-shadow:0 0 0 3px color-mix(in srgb,var(--slate-color-accent,#2563eb) 22%,transparent)}
.cb-pick-frame{position:relative;display:block;width:300px;overflow:hidden;background:#fff;border-bottom:1px solid #eef0f3}
.cb-pick-frame-h{height:50px}
.cb-pick-frame-f{height:114px}
.cb-pick-frame iframe{position:absolute;top:0;left:0;width:600px;height:600px;border:0;transform:scale(.5);transform-origin:top left;pointer-events:none}
.cb-pick-meta{display:flex;flex-direction:column;gap:.1rem;padding:.1rem .8rem .8rem}
.cb-pick-meta b{font-size:.92rem}
.cb-pick-meta small{color:#667085;font-size:.78rem;line-height:1.3}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
