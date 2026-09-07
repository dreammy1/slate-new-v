<?php
/**
 * Shop storefront — shared layout.
 *
 * Lightweight public template (no admin chrome). Inherits Slate's
 * design tokens (cream cream + tan accent) but renders as a clean
 * customer-facing storefront.
 *
 * Usage from any storefront page:
 *   require __DIR__ . '/includes/layout.php';
 *   sf_header('Page title');
 *   ... page content ...
 *   sf_footer();
 */

if (!function_exists('sf_header')) {

function sf_session_id(): string {
    if (empty($_COOKIE['shop_sid'])) {
        $sid = bin2hex(random_bytes(16));
        setcookie('shop_sid', $sid, [
            'expires'  => time() + 60*60*24*30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE['shop_sid'] = $sid;
    }
    return $_COOKIE['shop_sid'];
}

function sf_cart_count(): int {
    $sid = sf_session_id();
    $cartId = (int)Database::value(
        "SELECT id FROM shop_carts WHERE tenant_id = ? AND session_id = ?",
        [current_tenant_id(), $sid]);
    if (!$cartId) return 0;
    return (int)Database::value(
        "SELECT COALESCE(SUM(qty),0) FROM shop_cart_items WHERE cart_id = ?",
        [$cartId]);
}

function sf_money(float $amount): string {
    $cur = Database::setting('shop.currency') ?: 'USD';
    return ShopAPI::formatMoney($amount, $cur);
}

function sf_image_url(?string $stored): string {
    if (!$stored) return '';
    if (preg_match('#^https?://#', $stored)) return $stored;
    return SLATE_URL . '/' . ltrim($stored, '/');
}

/**
 * Clean a product name for display.
 *
 * Some admins paste names from rich-text editors and end up with
 * literal <b>...</b> or <span style="...">...</span> tags in the
 * database. e() escapes them safely but the user sees the markup as
 * text ("Southwestern Flair <b>LOW SODIUM</b>"). strip_tags removes
 * any HTML so the visible name is just the words.
 *
 * We deliberately don't render the HTML as actual formatting because
 * (a) it bypasses our XSS hardening on the name field, (b) the
 * pattern of names like "<b>LOW SODIUM</b> Mild with a little kick"
 * suggests the admin meant the bold for emphasis, but the rendered
 * result on a card is uglier than the plain text.
 *
 * We DO NOT html_entity_decode after stripping — that would let
 * &lt;script&gt; sneak through as <script>. strip_tags is the only
 * pass.
 */
function sf_clean_name(?string $name): string {
    if (!$name) return '';
    return trim(strip_tags((string)$name));
}

/**
 * Clean a free-form description fragment.
 *
 * Same idea as sf_clean_name but tuned for short_desc / blurb fields
 * which sometimes contain block-level tags. We strip all HTML, then
 * collapse repeated whitespace so "x</p><p>y" becomes "x y" rather
 * than "xy" or "x  y".
 */
function sf_clean_blurb(?string $text): string {
    if (!$text) return '';
    $clean = strip_tags((string)$text);
    $clean = preg_replace('/\s+/', ' ', $clean);
    return trim($clean ?? '');
}

/**
 * Detect "category header" rows — products with $0 price AND no image.
 * These are typically intentional dividers an admin entered into the
 * products table to group sections (e.g. "World-famous peanut brittle
 * — over 20 flavors to choose from"). They render as section headings
 * instead of clickable product tiles.
 *
 * If you ever need to make a real free product, add an image and the
 * header detection backs off.
 */
function sf_is_category_header(array $p): bool {
    $hasPrice = (float)($p['price'] ?? 0) > 0
             || (float)($p['sale_price'] ?? 0) > 0;
    $hasImage = !empty($p['image_url']);
    return !$hasPrice && !$hasImage;
}

/**
 * Per-session CSRF token for storefront forms.
 *
 * The storefront has its own session cookie (`shop_sid`) separate from the
 * admin's PHP session, so we can't reuse Slate's csrf_field()/csrf_verify()
 * helpers (which key off $_SESSION). We instead derive a stable token from
 * HMAC(APP_SECRET, shop_sid). This:
 *   - Doesn't require a server-side session at all (the storefront serves
 *     guests with no PHP session by default)
 *   - Rotates automatically when the shop_sid cookie changes
 *   - Is unguessable without APP_SECRET, even with a known shop_sid
 *
 * Token is sent as a hidden field `_sf_csrf` on every storefront POST form,
 * and verified with sf_csrf_verify() at the top of each handler.
 */
function sf_csrf_token(): string {
    // CORE-1: fail closed. This used APP_SECRET unguarded, and APP_SECRET is
    // always defined (empty when unset) — so with no secret configured the
    // token was HMAC under the empty key and every storefront POST was
    // forgeable. An empty return means no token is issued and
    // sf_csrf_verify() rejects everything, which is the correct failure.
    $sig = slate_sign('shop-csrf', sf_session_id());
    return $sig ?? '';
}

function sf_csrf_field(): string {
    return '<input type="hidden" name="_sf_csrf" value="' . e(sf_csrf_token()) . '">';
}

function sf_csrf_verify(): bool {
    $submitted = (string)($_POST['_sf_csrf'] ?? '');
    if ($submitted === '') return false;
    return slate_sign_equals('shop-csrf', sf_session_id(), $submitted);
}

function sf_header(string $title = ''): void {
    $siteName = Database::setting('site_name') ?: 'Shop';
    $accent   = Database::setting('brand_accent_color') ?: '#B17A3C';
    $logoPath = (string)(Database::setting('brand_logo_path') ?: '');
    $logoUrl  = $logoPath !== '' ? sf_image_url($logoPath) : '';
    $cartCount = sf_cart_count();
    $cats = ShopAPI::categories(true);

    // Pull business details so the footer can show them too. We
    // resolve here (not in sf_footer) so we don't double-query.
    static $biz = null;
    if ($biz === null) {
        $biz = [
            'name'    => Database::setting('business_name')    ?: $siteName,
            'email'   => Database::setting('business_email')   ?: '',
            'phone'   => Database::setting('business_phone')   ?: '',
            'address' => Database::setting('business_address') ?: '',
            'hours'   => Database::setting('business_hours')   ?: '',
        ];
    }
    $GLOBALS['_sf_biz'] = $biz;

    $pageTitle = $title !== '' ? ($title . ' — ' . $siteName) : $siteName;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500&family=Inter:wght@400;500;600;700&display=swap"
      rel="stylesheet">
<style>
/* ═════════════════════════════════════════════════════════════
   Slate Shop storefront — soft theme
   Ported from the same Claude Design bundle as the admin redesign.
   Linear/Vercel-style clean modern: neutral surfaces, ink black
   text, tan accent for selection/active states, crisp single-layer
   shadows. Storefront keeps a slightly more spacious feel than the
   admin (it's customer-facing, not a dense dashboard).
   ═════════════════════════════════════════════════════════════ */
:root {
    /* Surfaces — warmer cream tones for handcrafted-food feel */
    --bg:             #FAF6EE;
    --bg-2:           #F1ECE0;
    --surface:        #FFFFFF;
    --surface-sunken: #F7F2E8;

    /* Ink ladder */
    --text:           #2A1F12;
    --text-2:         #3D2F1E;
    --text-muted:     #6B5B47;
    --subtle:         #968571;
    --faint:          #C9BDA8;

    /* Lines */
    --border:         rgba(63, 47, 30, 0.07);
    --border-strong:  rgba(63, 47, 30, 0.12);
    --border-stronger:rgba(63, 47, 30, 0.20);

    /* Accent — configurable, but defaults to muted tan */
    --accent:         <?= e($accent) ?>;
    --accent-soft:    <?= e($accent) ?>1A;
    --accent-deep:    #7E5325;

    /* Semantic */
    --success:        #5C8A52;
    --success-soft:   #E8F0E1;
    --danger:         #B05A3B;
    --danger-soft:    #F4DDD2;
    --warning:        #B89230;
    --warning-soft:   #F2E9C8;

    /* Radii */
    --radius-sm:      6px;
    --radius:         10px;
    --radius-lg:      14px;
    --radius-xl:      18px;
    --radius-full:    9999px;

    /* Shadows — crisp single-layer */
    --shadow-xs:      0 0 0 1px var(--border);
    --shadow:         0 1px 2px rgba(63,47,30,0.05), 0 0 0 1px var(--border);
    --shadow-md:      0 4px 12px rgba(63,47,30,0.06), 0 0 0 1px var(--border);
    --shadow-lg:      0 16px 48px rgba(63,47,30,0.12), 0 0 0 1px var(--border-strong);

    /* Type — serif for headings, sans for body. The serif gives
       the "handcrafted artisanal food" feel; sans for body keeps
       small UI labels readable. */
    --font: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
            Roboto, Helvetica, Arial, sans-serif;
    --font-display: "Playfair Display", "Source Serif Pro", Georgia,
                    "Times New Roman", serif;
    --font-mono: "JetBrains Mono", ui-monospace, "SF Mono", Menlo, monospace;
}

* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }

body {
    background: var(--bg);
    color: var(--text);
    font: 14px/1.5 var(--font);
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: optimizeLegibility;
    font-feature-settings: "ss01", "cv11", "tnum";
    min-height: 100vh;
    min-height: 100dvh;
}
a {
    color: inherit;
    text-decoration: none;
    transition: opacity 0.15s ease, color 0.15s ease;
}
a:hover { opacity: 0.7; }
img { max-width: 100%; height: auto; display: block; }

/* ─── Topbar ───────────────────────────────────────────── */
.sf-topbar {
    background: rgba(250, 250, 247, 0.78);
    backdrop-filter: saturate(180%) blur(16px);
    -webkit-backdrop-filter: saturate(180%) blur(16px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 30;
}
.sf-topbar-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 14px 32px;
    display: flex;
    align-items: center;
    gap: 28px;
}
.sf-brand {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 15px;
    font-weight: 700;
    letter-spacing: -0.015em;
    color: var(--text);
}
.sf-brand:hover { color: var(--text); opacity: 1; }
.sf-brand-dot {
    width: 8px;
    height: 8px;
    border-radius: 999px;
    background: var(--accent);
    flex-shrink: 0;
}
.sf-nav {
    display: flex;
    gap: 20px;
    flex: 1;
    flex-wrap: wrap;
    align-items: center;
}
.sf-nav a {
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    transition: color 0.12s ease;
}
.sf-nav a:hover, .sf-nav a.active {
    color: var(--text);
    opacity: 1;
}

/* Cart button — pill with hairline ring, count badge on the right */
.sf-cart-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px 7px 14px;
    border-radius: 999px;
    background: var(--surface);
    box-shadow: inset 0 0 0 1px var(--border-strong);
    font-size: 13px;
    font-weight: 500;
    color: var(--text);
    transition: box-shadow 0.14s ease;
}
.sf-cart-link:hover {
    color: var(--text);
    opacity: 1;
    box-shadow: inset 0 0 0 1px var(--border-stronger), 0 1px 2px rgba(20,22,28,0.04);
}
.sf-cart-badge {
    display: inline-block;
    min-width: 20px;
    padding: 1px 7px;
    text-align: center;
    background: var(--text);
    color: #fff;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

/* ─── Main + sections ──────────────────────────────────── */
.sf-main {
    max-width: 1200px;
    margin: 0 auto;
    padding: 36px 32px 80px;
}

.sf-h1 {
    font-family: var(--font-display);
    font-size: 38px;
    font-weight: 600;
    letter-spacing: -0.015em;
    margin: 0 0 10px;
    line-height: 1.1;
    color: var(--text);
}
.sf-h2 {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin: 0 0 14px;
}
.sf-sub {
    color: var(--text-muted);
    margin: 0 0 28px;
    font-size: 14.5px;
    line-height: 1.55;
}

/* ─── Card ─────────────────────────────────────────────── */
.sf-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    padding: 24px;
    box-shadow: var(--shadow-xs);
}

/* ─── Buttons ──────────────────────────────────────────── */
.sf-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 9px 14px;
    border-radius: 8px;
    border: 0;
    background: var(--surface);
    color: var(--text);
    box-shadow: inset 0 0 0 1px var(--border-strong);
    font-weight: 500;
    font-family: inherit;
    font-size: 13px;
    cursor: pointer;
    transition: box-shadow 0.15s ease, background 0.15s ease;
}
.sf-btn:hover {
    background: var(--surface);
    color: var(--text);
    opacity: 1;
    box-shadow: inset 0 0 0 1px var(--border-stronger), 0 1px 2px rgba(20,22,28,0.04);
}
.sf-btn:active { background: var(--surface-sunken); }

.sf-btn-primary {
    background: var(--text);
    color: var(--bg);
    box-shadow: 0 1px 2px rgba(20,22,28,0.15), inset 0 1px 0 rgba(255,255,255,0.08);
}
.sf-btn-primary:hover {
    background: #000;
    color: #fff;
    opacity: 1;
    box-shadow: 0 2px 6px rgba(20,22,28,0.18), inset 0 1px 0 rgba(255,255,255,0.10);
}

.sf-btn-block {
    width: 100%;
    padding: 13px 18px;
    font-size: 14px;
    border-radius: 10px;
}

/* ─── Form fields ──────────────────────────────────────── */
.sf-input, .sf-select, .sf-textarea {
    width: 100%;
    padding: 10px 12px;
    border-radius: 8px;
    border: 0;
    background: var(--surface);
    box-shadow: inset 0 0 0 1px var(--border-strong);
    font: inherit;
    font-size: 13.5px;
    color: var(--text);
    outline: none;
    transition: box-shadow 0.14s ease;
    -webkit-appearance: none;
    appearance: none;
}
.sf-input:hover, .sf-select:hover, .sf-textarea:hover {
    box-shadow: inset 0 0 0 1px var(--border-stronger);
}
.sf-input:focus, .sf-select:focus, .sf-textarea:focus {
    box-shadow: inset 0 0 0 1px var(--text), 0 0 0 3px rgba(20,22,28,0.08);
}
.sf-input::placeholder, .sf-textarea::placeholder { color: var(--subtle); }
.sf-textarea { min-height: 100px; resize: vertical; line-height: 1.5; }
.sf-select {
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'><path d='M1 1.5L6 6.5L11 1.5' stroke='%235F6168' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round'/></svg>");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
}
.sf-label {
    display: block;
    font-size: 12.5px;
    font-weight: 500;
    margin-bottom: 6px;
    color: var(--text-2);
}
.sf-field { margin-bottom: 16px; }

/* ─── Product grid ─────────────────────────────────────── */
.sf-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
}
.sf-product-card {
    background: var(--surface);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-xs);
    display: flex;
    flex-direction: column;
    transition: box-shadow 0.18s ease, transform 0.18s ease;
    color: inherit;
}
.sf-product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(20,22,28,0.06), 0 0 0 1px var(--border-strong);
    color: inherit;
    opacity: 1;
}
.sf-product-img {
    aspect-ratio: 1;
    background: var(--surface-sunken);
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--subtle);
    overflow: hidden;
}
.sf-product-img img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.4s cubic-bezier(.22,1,.36,1);
}
.sf-product-card:hover .sf-product-img img { transform: scale(1.03); }
.sf-product-img .sf-placeholder {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: var(--bg-2);
    display: grid;
    place-items: center;
    color: var(--subtle);
    opacity: 0.7;
}
.sf-product-img .sf-placeholder svg { width: 28px; height: 28px; }

.sf-product-info {
    padding: 14px 16px 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.sf-product-name {
    font-weight: 600;
    font-size: 14px;
    color: var(--text);
    margin: 0;
    line-height: 1.35;
    letter-spacing: -0.005em;
}
.sf-product-cat {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.4;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}
.sf-product-price {
    font-weight: 600;
    font-size: 14.5px;
    margin-top: 6px;
    color: var(--text);
    font-variant-numeric: tabular-nums;
}
.sf-product-price .sf-sale { color: var(--accent); margin-right: 8px; }
.sf-product-price .sf-orig {
    text-decoration: line-through;
    color: var(--subtle);
    font-weight: 500;
    font-size: 12.5px;
}

/* Featured badge: tan-tint pill, mono-styled all-caps */
.sf-featured-badge {
    position: absolute;
    top: 12px;
    left: 12px;
    background: var(--accent);
    color: #fff;
    padding: 4px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-family: var(--font);
    box-shadow: 0 1px 2px rgba(20,22,28,0.10);
}
.sf-stock-out {
    position: absolute;
    inset: 0;
    background: rgba(255, 255, 255, 0.86);
    backdrop-filter: blur(2px);
    -webkit-backdrop-filter: blur(2px);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 12px;
    color: var(--danger);
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

/* ─── Featured section heading (Featured / All products) ─── */
.sf-section-heading {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 600;
    letter-spacing: -0.005em;
    color: var(--text);
    margin: 36px 0 16px;
    /* Subtle warm-accent underline to feel artisanal */
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border-strong);
}
.sf-section-heading:first-child { margin-top: 0; }

/* ─── Flash messages ───────────────────────────────────── */
.sf-flash {
    padding: 11px 16px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 13.5px;
    box-shadow: inset 0 0 0 1px var(--border-strong);
}
.sf-flash-success { background: var(--success-soft); color: #3F6029; }
.sf-flash-error   { background: var(--danger-soft);  color: #8F362C; }
.sf-flash-info    { background: var(--accent-soft);  color: var(--accent-deep); }

/* ─── Cart table ───────────────────────────────────────── */
.sf-cart-table {
    width: 100%;
    border-collapse: collapse;
}
.sf-cart-table th, .sf-cart-table td {
    padding: 14px 0;
    text-align: left;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.sf-cart-table tbody tr:last-child td { border-bottom: 0; }
.sf-cart-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 600;
    padding-bottom: 10px;
}
.sf-cart-row-img {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    background: var(--surface-sunken);
    object-fit: cover;
    flex-shrink: 0;
}
.sf-cart-qty {
    width: 64px;
    padding: 6px 10px;
    font-size: 13px;
}

/* ─── Footer ───────────────────────────────────────────── */
.sf-footer {
    border-top: 1px solid var(--border);
    padding: 28px 32px;
    text-align: center;
    color: var(--text-muted);
    font-size: 12.5px;
    background: var(--bg);
}

/* ─── Product detail page (used by product.php) ─────────── */
.sf-product-detail {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 40px;
    align-items: start;
}
.sf-gallery-wrap {
    position: sticky;
    top: 88px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.sf-product-gallery {
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs);
    overflow: hidden;
    aspect-ratio: 1;
    display: flex;
    align-items: center;
    justify-content: center;
}
.sf-product-gallery img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

/* Thumbnail strip */
.sf-gallery-thumbs {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(64px, 1fr));
    gap: 8px;
}
.sf-gallery-thumb {
    aspect-ratio: 1;
    border: 0;
    background: var(--surface);
    border-radius: 8px;
    overflow: hidden;
    padding: 0;
    cursor: pointer;
    box-shadow: inset 0 0 0 1px var(--border);
    transition: box-shadow 140ms ease, transform 140ms ease;
}
.sf-gallery-thumb img {
    width: 100%; height: 100%; object-fit: cover;
    display: block;
}
.sf-gallery-thumb:hover { transform: translateY(-1px); }
.sf-gallery-thumb.is-active {
    box-shadow: inset 0 0 0 2px var(--text);
}

/* ─── Checkout / cart layout (two-column) ───────────────── */
.sf-checkout-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    gap: 24px;
    align-items: start;
}

/* Payment-method radio list on the checkout page. One <label> per
   registered provider; selected state highlights with the accent
   border + soft tint. Optional .sf-payment-option-extra slot below
   each radio for providers that need to render extra UI (a card
   form, hosted Pay button, etc). */
.sf-payment-methods {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.sf-payment-option {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border: 1px solid var(--border, #e5e9f0);
    border-radius: 10px;
    cursor: pointer;
    transition: border-color 0.12s ease, background 0.12s ease;
}
.sf-payment-option:hover { border-color: var(--border-strong, #d1d8e3); }
.sf-payment-option.is-selected {
    border-color: var(--accent, #5b8def);
    background: var(--accent-soft, rgba(91,141,239,0.06));
}
.sf-payment-option input[type="radio"] {
    margin-top: 3px;
    flex-shrink: 0;
}
.sf-payment-option-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.sf-payment-option-label {
    font-weight: 600;
    color: var(--text, #111);
}
.sf-payment-option-desc {
    font-size: 12.5px;
    color: var(--text-2, #5a6b7e);
}
.sf-payment-option-extra {
    padding: 12px 14px 4px;
    margin-top: -2px;
}
.sf-payment-option-extra[hidden] { display: none; }
.sf-summary {
    position: sticky;
    top: 88px;
}
.sf-summary-line {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0;
    font-size: 13px;
    color: var(--text-2);
    border-top: 1px solid var(--border);
}
.sf-summary-line:first-child { border-top: 0; }
.sf-summary-line.total {
    font-weight: 700;
    font-size: 15px;
    color: var(--text);
    padding-top: 14px;
    border-top: 1px solid var(--border-strong);
}
.sf-summary-line .qty-x {
    color: var(--subtle);
    font-size: 12px;
    margin-left: 4px;
}
.sf-summary-note {
    text-align: center;
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 10px;
}

/* ─── Product detail — buybox content styles ──────────── */
.sf-breadcrumb {
    font-size: 12.5px;
    color: var(--text-muted);
    margin-bottom: 18px;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
}
.sf-breadcrumb a { color: var(--text-muted); }
.sf-breadcrumb a:hover { color: var(--text); opacity: 1; }
.sf-breadcrumb-sep { color: var(--faint); }
.sf-breadcrumb span:last-child { color: var(--text); }

.sf-eyebrow {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 10px;
}
.sf-price-display {
    font-size: 26px;
    font-weight: 700;
    letter-spacing: -0.025em;
    margin-bottom: 18px;
    font-variant-numeric: tabular-nums;
}
.sf-price-display .sf-price-sale { color: var(--accent); }
.sf-price-display .sf-price-orig {
    text-decoration: line-through;
    color: var(--text-muted);
    font-size: 16px;
    font-weight: 500;
    margin-left: 10px;
    letter-spacing: -0.01em;
}
.sf-product-lede {
    color: var(--text-muted);
    margin: 0 0 22px;
    font-size: 14px;
    line-height: 1.6;
}
.sf-stock-warning {
    margin-top: 14px;
    padding: 8px 12px;
    font-size: 12.5px;
    color: #8F541A;
    background: var(--warning-soft);
    border-radius: 8px;
    font-weight: 500;
}
.sf-sku {
    margin-top: 22px;
    padding-top: 16px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--text-muted);
    font-family: var(--font-mono);
}

/* ─── Premium product detail (PDP) ────────────────────── */

/* Quantity stepper — − [N] + with shared hairline ring */
.sf-qty-stepper {
    display: inline-flex;
    align-items: stretch;
    border-radius: 9px;
    box-shadow: inset 0 0 0 1px var(--border-strong);
    background: var(--surface);
    overflow: hidden;
    height: 44px;
}
.sf-qty-stepper button {
    width: 40px;
    border: 0;
    background: transparent;
    color: var(--text-2);
    font-size: 18px;
    font-weight: 500;
    cursor: pointer;
    transition: background 120ms ease, color 120ms ease;
    font-family: inherit;
    line-height: 1;
    display: grid;
    place-items: center;
}
.sf-qty-stepper button:hover { background: var(--surface-sunken); color: var(--text); }
.sf-qty-stepper button:disabled { color: var(--faint); cursor: not-allowed; }
.sf-qty-stepper input {
    width: 50px;
    border: 0;
    background: transparent;
    text-align: center;
    font: inherit;
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    font-variant-numeric: tabular-nums;
    outline: none;
    /* hide spin arrows */
    -moz-appearance: textfield;
    -webkit-appearance: none;
    appearance: textfield;
}
.sf-qty-stepper input::-webkit-outer-spin-button,
.sf-qty-stepper input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* PDP price display — premium typography */
.sf-pdp-price {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin: 14px 0 0;
    font-variant-numeric: tabular-nums;
}
.sf-pdp-price .price-now {
    font-size: 32px;
    font-weight: 700;
    letter-spacing: -0.03em;
    color: var(--text);
    line-height: 1;
}
.sf-pdp-price .price-now.is-sale { color: var(--accent); }
.sf-pdp-price .price-was {
    font-size: 18px;
    color: var(--subtle);
    text-decoration: line-through;
    font-weight: 500;
    letter-spacing: -0.01em;
}
.sf-pdp-price .price-save {
    margin-left: auto;
    font-size: 11px;
    font-weight: 600;
    color: var(--success);
    background: var(--success-soft);
    padding: 4px 9px;
    border-radius: 999px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}

/* Rating row */
.sf-rating {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}
.sf-rating-stars {
    display: inline-flex;
    gap: 2px;
    color: #C6A36A;
}
.sf-rating-stars svg { width: 14px; height: 14px; }
.sf-rating-stars .empty { color: var(--faint); }
.sf-rating-text {
    font-size: 12px;
    color: var(--text-muted);
    font-weight: 500;
}
.sf-rating-text strong {
    color: var(--text);
    font-variant-numeric: tabular-nums;
}
.sf-rating-text.is-new {
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 11px;
    font-weight: 600;
}

/* Lede paragraph — 4-line clamp with expand link */
.sf-product-lede {
    color: var(--text-2);
    margin: 18px 0 0;
    font-size: 14px;
    line-height: 1.65;
}

/* Stock pill (under price) */
.sf-stock-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 500;
    margin-top: 16px;
    box-shadow: inset 0 0 0 1px var(--border-strong);
    background: var(--surface);
    color: var(--text-2);
}
.sf-stock-pill .dot {
    width: 7px; height: 7px;
    border-radius: 999px;
    background: currentColor;
}
.sf-stock-pill.is-in   { color: var(--success); }
.sf-stock-pill.is-low  { color: #8F541A; background: var(--warning-soft); box-shadow: none; }
.sf-stock-pill.is-out  { color: var(--danger);  background: var(--danger-soft);  box-shadow: none; }

/* Add-to-cart row: stepper + button side by side */
.sf-buy-row {
    display: flex;
    gap: 10px;
    margin-top: 22px;
    align-items: stretch;
}
.sf-buy-row .sf-qty-stepper { flex-shrink: 0; }
.sf-buy-row .sf-btn-primary {
    flex: 1;
    min-height: 44px;
    padding: 0 22px;
    font-size: 14px;
    font-weight: 600;
    border-radius: 9px;
    letter-spacing: -0.005em;
    box-shadow: 0 2px 4px rgba(20,22,28,0.18), inset 0 1px 0 rgba(255,255,255,0.10);
    transition: transform 140ms cubic-bezier(.22,1,.36,1), box-shadow 140ms ease;
}
.sf-buy-row .sf-btn-primary:hover {
    background: #000;
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(20,22,28,0.22), inset 0 1px 0 rgba(255,255,255,0.10);
}
.sf-buy-row .sf-btn-primary:active {
    transform: translateY(0);
    box-shadow: 0 2px 4px rgba(20,22,28,0.18), inset 0 1px 0 rgba(255,255,255,0.10);
}

/* Trust strip below the buybox */
.sf-trust-strip {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0;
    margin-top: 24px;
    padding-top: 22px;
    border-top: 1px solid var(--border);
}
.sf-trust-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 0 8px 0 0;
}
.sf-trust-icon {
    width: 32px; height: 32px;
    flex-shrink: 0;
    border-radius: 9px;
    background: var(--surface-sunken);
    display: grid;
    place-items: center;
    color: var(--text-2);
}
.sf-trust-icon svg { width: 16px; height: 16px; }
.sf-trust-text {
    display: flex;
    flex-direction: column;
    gap: 1px;
    min-width: 0;
}
.sf-trust-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text);
    line-height: 1.3;
    letter-spacing: -0.005em;
}
.sf-trust-sub {
    font-size: 11px;
    color: var(--text-muted);
    line-height: 1.4;
}

/* PDP detail sub-row: SKU, category, etc. */
.sf-pdp-meta {
    margin-top: 22px;
    padding-top: 18px;
    border-top: 1px solid var(--border);
    display: flex;
    flex-wrap: wrap;
    gap: 16px 28px;
    font-size: 12px;
    color: var(--text-muted);
}
.sf-pdp-meta .meta-label {
    color: var(--subtle);
    margin-right: 6px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-size: 10.5px;
    font-weight: 600;
}
.sf-pdp-meta .meta-value {
    color: var(--text-2);
    font-family: var(--font-mono);
    font-size: 12px;
}

/* Tabbed lower section — Description / Details / Reviews */
.sf-tabs-card {
    margin-top: 56px;
    background: var(--surface);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xs);
    overflow: hidden;
}
.sf-tab-strip {
    display: flex;
    border-bottom: 1px solid var(--border);
    padding: 0 8px;
    gap: 0;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
.sf-tab-btn {
    border: 0;
    background: transparent;
    padding: 16px 18px;
    font: inherit;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    position: relative;
    transition: color 120ms ease;
    white-space: nowrap;
}
.sf-tab-btn::after {
    content: '';
    position: absolute;
    left: 14px; right: 14px; bottom: -1px;
    height: 2px;
    background: var(--text);
    border-radius: 2px 2px 0 0;
    transform: scaleX(0);
    transform-origin: center;
    transition: transform 200ms cubic-bezier(.22,1,.36,1);
}
.sf-tab-btn:hover { color: var(--text-2); }
.sf-tab-btn.is-active { color: var(--text); font-weight: 600; }
.sf-tab-btn.is-active::after { transform: scaleX(1); }

.sf-tab-panel {
    padding: 28px 32px 32px;
    display: none;
}
.sf-tab-panel.is-active { display: block; }
.sf-tab-panel h3 {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 12px;
    color: var(--text);
}
.sf-tab-panel .prose {
    line-height: 1.75;
    color: var(--text-2);
    font-size: 14px;
    max-width: 70ch;
}
.sf-tab-panel .prose p { margin: 0 0 14px; }
.sf-tab-panel .prose p:last-child { margin-bottom: 0; }

/* Details panel — definition list */
.sf-details-list {
    margin: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
    gap: 0;
    max-width: 600px;
}
.sf-details-list dt, .sf-details-list dd {
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    margin: 0;
}
.sf-details-list dt {
    font-size: 11.5px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 600;
    padding-right: 16px;
}
.sf-details-list dd {
    color: var(--text);
    font-variant-numeric: tabular-nums;
}
.sf-details-list dt:last-of-type,
.sf-details-list dd:last-of-type { border-bottom: 0; }

/* Reviews panel — empty state */
.sf-reviews-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
}
.sf-reviews-empty-stars {
    display: inline-flex;
    gap: 4px;
    color: var(--faint);
    margin-bottom: 10px;
}
.sf-reviews-empty-stars svg { width: 18px; height: 18px; }
.sf-reviews-empty-title {
    font-size: 15px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}
.sf-reviews-empty-sub {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 18px;
}

/* Mobile adjustments */
@media (max-width: 768px) {
    .sf-pdp-price .price-now { font-size: 26px; }
    .sf-pdp-price .price-was { font-size: 15px; }
    .sf-buy-row { flex-direction: column; }
    .sf-buy-row .sf-qty-stepper { align-self: flex-start; }
    .sf-trust-strip {
        grid-template-columns: 1fr;
        gap: 12px;
    }
    .sf-tab-panel { padding: 20px 16px 24px; }
    .sf-tab-strip { padding: 0; }
    .sf-tab-btn { padding: 14px 14px; }
    .sf-details-list {
        grid-template-columns: 1fr;
        gap: 0;
    }
    .sf-details-list dt {
        padding-bottom: 2px;
        border-bottom: 0;
    }
    .sf-details-list dd { padding-top: 0; padding-bottom: 14px; }
}
/* ════════════════════════════════════════════════════════════
   REDESIGN: hero, brand logo, footer, category header,
   product-card add-to-cart button.
   Added later in the file so it can override earlier rules
   without rewriting the entire stylesheet.
   ════════════════════════════════════════════════════════════ */

/* ─── Brand logo in topbar ──────────────────────────────── */
.sf-brand-logo {
    height: 36px;
    width: auto;
    max-width: 180px;
    display: block;
    object-fit: contain;
}
@media (max-width: 768px) {
    .sf-brand-logo { height: 28px; max-width: 140px; }
}

/* ─── Hero ──────────────────────────────────────────────── */
.sf-hero {
    background:
        linear-gradient(135deg, rgba(255,255,255,0.55) 0%, rgba(255,255,255,0) 60%),
        radial-gradient(120% 80% at 80% 20%, var(--accent-soft) 0%, transparent 60%),
        var(--bg-2);
    border-radius: var(--radius-xl);
    padding: 56px 48px;
    margin-bottom: 40px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-xs);
}
.sf-hero::after {
    /* Subtle paper-grain texture using stacked tiny gradients —
       avoids a separate image file. */
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(rgba(126, 83, 37, 0.04) 1px, transparent 1px);
    background-size: 24px 24px;
    pointer-events: none;
    z-index: 0;
}
.sf-hero-eyebrow {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.18em;
    color: var(--accent);
    text-transform: uppercase;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
.sf-hero-title {
    font-family: var(--font-display);
    font-size: 48px;
    font-weight: 600;
    letter-spacing: -0.018em;
    line-height: 1.05;
    color: var(--text);
    margin: 0 0 18px;
    max-width: 720px;
    position: relative;
    z-index: 1;
}
.sf-hero-title em {
    font-style: italic;
    color: var(--accent);
    font-weight: 500;
}
.sf-hero-sub {
    font-size: 16px;
    color: var(--text-2);
    margin: 0 0 28px;
    max-width: 580px;
    line-height: 1.55;
    position: relative;
    z-index: 1;
}
.sf-hero-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    position: relative;
    z-index: 1;
}
@media (max-width: 768px) {
    .sf-hero { padding: 36px 22px; border-radius: 14px; }
    .sf-hero-title { font-size: 32px; }
    .sf-hero-sub { font-size: 15px; }
}
@media (max-width: 480px) {
    .sf-hero-title { font-size: 28px; }
}

/* ─── Product card: wrapper + add button ────────────────── */
/* The card wrap holds the clickable <a> AND the Add to cart
   button beneath it. The button is OUTSIDE the <a> so a click
   on it doesn't navigate to the detail page. */
.sf-product-card-wrap {
    display: flex;
    flex-direction: column;
}
.sf-product-card-wrap .sf-product-card {
    /* The original card's bottom radius is killed because the
       Add button continues below it. */
    border-bottom-left-radius: 0;
    border-bottom-right-radius: 0;
}
.sf-card-add,
.sf-card-add-form button.sf-card-add,
a.sf-card-add-link {
    /* Unified styling: tile-wide button beneath the card. */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 11px 14px;
    border: 0;
    border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    background: var(--text);
    color: #fff;
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.01em;
    cursor: pointer;
    text-align: center;
    transition: background-color 0.15s ease, transform 0.15s ease;
    box-shadow: 0 0 0 1px var(--border-strong);
    /* Avoid the link's hover-opacity rule */
    opacity: 1;
}
.sf-card-add:hover,
a.sf-card-add-link:hover {
    background: var(--accent-deep);
    color: #fff;
    opacity: 1;
}
.sf-card-add:active { transform: translateY(1px); }
.sf-card-add-disabled,
.sf-card-add-disabled:hover {
    background: var(--bg-2);
    color: var(--text-muted);
    cursor: not-allowed;
    box-shadow: 0 0 0 1px var(--border);
}
.sf-card-add-form { margin: 0; }
/* "Just added" state — flashes green for 1.5s after a successful
   add. JS toggles this class. */
.sf-card-add.is-just-added {
    background: var(--success);
    transition: background-color 0.2s ease;
}

/* Sale badge — sits opposite "Featured" */
.sf-sale-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    background: var(--danger);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    padding: 4px 10px;
    border-radius: var(--radius-full);
    text-transform: uppercase;
    box-shadow: 0 1px 2px rgba(0,0,0,0.15);
    z-index: 1;
}

/* ─── Category-header row (spans full grid width) ───────── */
.sf-category-header {
    grid-column: 1 / -1;
    padding: 28px 4px 8px;
    border-top: 1px solid var(--border-strong);
    margin-top: 8px;
}
.sf-category-header:first-child {
    margin-top: 0;
    border-top: 0;
    padding-top: 0;
}
.sf-category-header-title {
    font-family: var(--font-display);
    font-size: 26px;
    font-weight: 600;
    letter-spacing: -0.005em;
    margin: 0 0 8px;
    color: var(--text);
}
.sf-category-header-blurb {
    font-size: 14.5px;
    color: var(--text-muted);
    margin: 0;
    line-height: 1.55;
    max-width: 60ch;
}

/* ─── Footer redesign ───────────────────────────────────── */
.sf-footer {
    margin-top: 80px;
    background: linear-gradient(180deg, var(--bg-2) 0%, var(--surface-sunken) 100%);
    border-top: 1px solid var(--border-strong);
    padding: 56px 0 28px;
    color: var(--text-2);
}
.sf-footer-inner {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 32px;
}
.sf-footer-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr;
    gap: 40px;
    margin-bottom: 40px;
}
@media (max-width: 880px) {
    .sf-footer-grid {
        grid-template-columns: 1fr 1fr;
        gap: 32px 20px;
    }
}
@media (max-width: 480px) {
    .sf-footer-grid { grid-template-columns: 1fr; gap: 28px; }
    .sf-footer-inner { padding: 0 20px; }
    .sf-footer { padding: 40px 0 24px; margin-top: 56px; }
}
.sf-footer-brand-col { max-width: 280px; }
.sf-footer-logo {
    height: 44px;
    width: auto;
    max-width: 220px;
    margin-bottom: 14px;
}
.sf-footer-brand-title {
    font-family: var(--font-display);
    font-size: 20px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 10px;
}
.sf-footer-heading {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--text);
    margin-bottom: 14px;
}
.sf-footer-blurb {
    font-size: 13.5px;
    line-height: 1.7;
    color: var(--text-muted);
}
.sf-footer-links {
    list-style: none;
    padding: 0;
    margin: 0;
}
.sf-footer-links li {
    margin-bottom: 8px;
}
.sf-footer-links a {
    font-size: 13.5px;
    color: var(--text-muted);
    transition: color 0.12s ease;
}
.sf-footer-links a:hover { color: var(--text); opacity: 1; }

.sf-footer-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 24px;
    border-top: 1px solid var(--border-strong);
    flex-wrap: wrap;
    gap: 12px;
}
.sf-footer-copyright {
    font-size: 12.5px;
    color: var(--text-muted);
}
.sf-footer-trust {
    display: flex;
    gap: 8px;
    align-items: center;
    color: var(--subtle);
}
.sf-pay-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 24px;
    border-radius: 4px;
    background: var(--surface);
    box-shadow: inset 0 0 0 1px var(--border-strong);
    color: var(--text-muted);
}

/* ─── Cart-link "bump" animation when item added ────────── */
@keyframes sf-cart-bump {
    0%   { transform: scale(1); }
    30%  { transform: scale(1.18); }
    60%  { transform: scale(0.96); }
    100% { transform: scale(1); }
}
.sf-cart-link.is-bumping .sf-cart-badge {
    animation: sf-cart-bump 0.4s ease;
}

/* ─── Mobile ───────────────────────────────────────────── */
@media (max-width: 768px) {
    .sf-topbar-inner { padding: 12px 16px; gap: 14px; }
    .sf-main { padding: 24px 16px 60px; }
    .sf-nav { display: none; }
    .sf-h1 { font-size: 24px; }
    .sf-grid { grid-template-columns: 1fr 1fr; gap: 12px; }
    .sf-product-info { padding: 12px; }
    .sf-product-name { font-size: 13px; }
    .sf-card { padding: 18px; border-radius: 14px; }
    .sf-btn { font-size: 13px; padding: 10px 14px; border-radius: 9px; min-height: 40px; }
    .sf-btn-block { padding: 14px 18px; font-size: 14px; }
    .sf-input, .sf-select, .sf-textarea {
        padding: 11px 12px; font-size: 14px; border-radius: 9px;
    }
    .sf-product-detail {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    .sf-gallery-wrap {
        position: static;
    }
    .sf-product-gallery {
        aspect-ratio: 1;
    }
    .sf-checkout-layout {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    .sf-summary { position: static; }
}
@media (max-width: 480px) {
    .sf-grid { grid-template-columns: 1fr; }
    .sf-h1 { font-size: 22px; }
}
</style>
</head>
<body>
<header class="sf-topbar">
    <div class="sf-topbar-inner">
        <a href="<?= e(SLATE_URL) ?>/shop/" class="sf-brand">
            <?php if ($logoUrl !== ''): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>"
                     class="sf-brand-logo">
            <?php else: ?>
                <span class="sf-brand-dot" aria-hidden="true"></span>
                <span><?= e($siteName) ?></span>
            <?php endif; ?>
        </a>
        <nav class="sf-nav">
            <a href="<?= e(SLATE_URL) ?>/shop/">Shop</a>
            <?php foreach ($cats as $c): if ((int)$c['product_count'] === 0) continue; ?>
                <a href="<?= e(SLATE_URL) ?>/shop/category/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a>
            <?php endforeach; ?>
        </nav>
        <a href="<?= e(SLATE_URL) ?>/shop/cart" class="sf-cart-link" aria-label="Cart" id="sf-cart-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;color:var(--text-2);">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="20" cy="21" r="1"/>
                <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>
            </svg>
            <span>Cart</span>
            <span class="sf-cart-badge" id="sf-cart-badge"
                  <?= $cartCount > 0 ? '' : 'hidden' ?>><?= (int)$cartCount ?></span>
        </a>
    </div>
</header>
<main class="sf-main">
<?php
}

function sf_footer(): void {
    $siteName = Database::setting('site_name') ?: 'Shop';
    $logoPath = (string)(Database::setting('brand_logo_path') ?: '');
    $logoUrl  = $logoPath !== '' ? sf_image_url($logoPath) : '';
    $biz = $GLOBALS['_sf_biz'] ?? [
        'name'    => $siteName,
        'email'   => Database::setting('business_email')   ?: '',
        'phone'   => Database::setting('business_phone')   ?: '',
        'address' => Database::setting('business_address') ?: '',
        'hours'   => Database::setting('business_hours')   ?: '',
    ];
    $bizName = $biz['name'] ?: $siteName;
    $cats = ShopAPI::categories(true);
?>
</main>
<footer class="sf-footer">
    <div class="sf-footer-inner">
        <div class="sf-footer-grid">
            <!-- Brand column -->
            <div class="sf-footer-brand-col">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($bizName) ?>"
                         class="sf-footer-logo">
                <?php else: ?>
                    <div class="sf-footer-brand-title"><?= e($bizName) ?></div>
                <?php endif; ?>
                <?php if (!empty($biz['address'])): ?>
                    <div class="sf-footer-blurb">
                        <?= nl2br(e($biz['address'])) ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Shop links -->
            <div>
                <div class="sf-footer-heading">Shop</div>
                <ul class="sf-footer-links">
                    <li><a href="<?= e(SLATE_URL) ?>/shop/">All products</a></li>
                    <?php foreach ($cats as $c):
                        if ((int)$c['product_count'] === 0) continue; ?>
                        <li><a href="<?= e(SLATE_URL) ?>/shop/category/<?= e($c['slug']) ?>"><?= e($c['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Help / customer links -->
            <div>
                <div class="sf-footer-heading">Help</div>
                <ul class="sf-footer-links">
                    <li><a href="<?= e(SLATE_URL) ?>/shop/cart">Your cart</a></li>
                    <?php if (!empty($biz['email'])): ?>
                        <li><a href="mailto:<?= e($biz['email']) ?>"><?= e($biz['email']) ?></a></li>
                    <?php endif; ?>
                    <?php if (!empty($biz['phone'])): ?>
                        <li><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $biz['phone'])) ?>"><?= e($biz['phone']) ?></a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Hours -->
            <?php if (!empty($biz['hours'])): ?>
                <div>
                    <div class="sf-footer-heading">Hours</div>
                    <div class="sf-footer-blurb">
                        <?= nl2br(e($biz['hours'])) ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="sf-footer-bottom">
            <div class="sf-footer-copyright">
                &copy; <?= date('Y') ?> <?= e($bizName) ?>. All rights reserved.
            </div>
            <div class="sf-footer-trust">
                <!-- Generic payment-method icons (no brand-name SVGs to avoid
                     trademark concerns; these are abstract card glyphs). -->
                <span class="sf-pay-icon" aria-label="Visa, Mastercard, American Express accepted" title="Cards accepted">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
                        <rect x="2" y="5" width="20" height="14" rx="2"/>
                        <line x1="2" y1="10" x2="22" y2="10"/>
                    </svg>
                </span>
                <span class="sf-pay-icon" aria-label="Secure checkout" title="Secure checkout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round" style="width:18px;height:18px;">
                        <rect x="3" y="11" width="18" height="11" rx="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </span>
            </div>
        </div>
    </div>
</footer>

<!-- AJAX add-to-cart from product listing tiles.
     Intercepts the .sf-card-add-form submit, POSTs with
     Accept: application/json, and updates the header badge +
     button label on success. Falls back to normal form submit
     (no-JS users land on /shop/cart). -->
<script>
(function () {
    var badge = document.getElementById('sf-cart-badge');
    var cartLink = document.getElementById('sf-cart-link');
    document.querySelectorAll('form.sf-card-add-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            // Fallback path set this flag — let the form submit normally.
            if (form.dataset.mlpBypass === '1') return;

            // If a key modifier is held (cmd/ctrl) let the form submit
            // normally so power users can open the cart page directly.
            if (e.metaKey || e.ctrlKey || e.shiftKey) return;

            e.preventDefault();
            var btn = form.querySelector('.sf-card-add');
            if (!btn || btn.disabled) return;
            btn.disabled = true;

            var data = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data.ok) throw new Error(data.error || 'Add failed');
                // Update cart badge
                if (badge) {
                    badge.textContent = data.cart_count;
                    badge.hidden = (data.cart_count <= 0);
                }
                // Bump animation on the cart link
                if (cartLink) {
                    cartLink.classList.remove('is-bumping');
                    // Force reflow so the animation restarts every click
                    void cartLink.offsetWidth;
                    cartLink.classList.add('is-bumping');
                }
                // Flash the button green for ~1.5s
                var orig = btn.innerHTML;
                btn.classList.add('is-just-added');
                btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;flex-shrink:0;"><path d="M20 6L9 17l-5-5"/></svg><span>Added</span>';
                setTimeout(function () {
                    btn.classList.remove('is-just-added');
                    btn.innerHTML = orig;
                    btn.disabled = false;
                }, 1500);
            })
            .catch(function (err) {
                console.error('Add-to-cart failed', err);
                // Fall back to a regular form submit so the customer
                // still gets feedback (they'll land on /shop/cart).
                // Set bypass flag so our submit handler short-circuits
                // on the recursive call.
                form.dataset.mlpBypass = '1';
                btn.disabled = false;
                form.submit();
            });
        });
    });
})();
</script>

</body>
</html>
<?php
}

function sf_product_card(array $p): void {
    // Category-header rows (no price, no image) get a different
    // treatment — they're not clickable products, they're section
    // markers an admin entered into the products list to group items
    // visually. We render them as a wide section divider that spans
    // the grid (handled by CSS grid-column: 1 / -1).
    if (sf_is_category_header($p)) {
        $heading = sf_clean_name($p['name'] ?? '');
        $blurb   = sf_clean_blurb($p['short_desc'] ?? '');
        ?>
        <div class="sf-category-header" role="separator">
            <h2 class="sf-category-header-title"><?= e($heading) ?></h2>
            <?php if ($blurb !== ''): ?>
                <p class="sf-category-header-blurb"><?= e($blurb) ?></p>
            <?php endif; ?>
        </div>
        <?php
        return;
    }

    $url = SLATE_URL . '/shop/product/' . urlencode($p['slug']);
    $imgUrl = sf_image_url($p['image_url'] ?? null);
    $hasSale = !empty($p['sale_price']) && (float)$p['sale_price'] > 0
            && (float)$p['sale_price'] < (float)$p['price'];
    $outOfStock = !empty($p['manage_stock']) && (int)$p['stock_qty'] <= 0;
    $cleanName  = sf_clean_name($p['name'] ?? '');
    $cleanBlurb = sf_clean_blurb($p['short_desc'] ?? '');

    // Detect whether the product has variants — if it does, the
    // listing card's add-to-cart links to the detail page (since the
    // customer needs to choose a variant). For variant-free products
    // we wire up the quick-add button which POSTs to /shop/cart with
    // _action=add.
    $hasVariants = !empty($p['variant_count']) && (int)$p['variant_count'] > 0;
    // Some product result shapes don't include variant_count; if not
    // present we conservatively assume "no variants" rather than
    // doing a lookup per card. The worst case is a customer with a
    // variant product hits "Add" and gets bounced to the detail page
    // anyway because our backend will return an error.
?>
<div class="sf-product-card-wrap">
    <a href="<?= e($url) ?>" class="sf-product-card">
        <div class="sf-product-img">
            <?php if (!empty($p['featured'])): ?>
                <span class="sf-featured-badge">Featured</span>
            <?php endif; ?>
            <?php if ($hasSale):
                $diff    = (float)$p['price'] - (float)$p['sale_price'];
                $savePct = (int)round(($diff / (float)$p['price']) * 100);
            ?>
                <span class="sf-sale-badge">Save <?= $savePct ?>%</span>
            <?php endif; ?>
            <?php if ($imgUrl): ?>
                <img src="<?= e($imgUrl) ?>" alt="<?= e($cleanName) ?>" loading="lazy">
            <?php else: ?>
                <span class="sf-placeholder" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                         stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16.5 9.4L7.5 4.21"/>
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <path d="M3.27 6.96L12 12l8.73-5.04M12 22V12"/>
                    </svg>
                </span>
            <?php endif; ?>
            <?php if ($outOfStock): ?>
                <div class="sf-stock-out">Out of stock</div>
            <?php endif; ?>
        </div>
        <div class="sf-product-info">
            <div class="sf-product-name"><?= e($cleanName) ?></div>
            <?php if ($cleanBlurb !== ''): ?>
                <div class="sf-product-cat"><?= e(mb_strimwidth($cleanBlurb, 0, 70, '…')) ?></div>
            <?php endif; ?>
            <div class="sf-product-price">
                <?php if ($hasSale): ?>
                    <span class="sf-sale"><?= e(sf_money((float)$p['sale_price'])) ?></span>
                    <span class="sf-orig"><?= e(sf_money((float)$p['price'])) ?></span>
                <?php else: ?>
                    <?= e(sf_money((float)$p['price'])) ?>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <!-- Add-to-cart button. Sits OUTSIDE the <a> wrapper so a click
         on it doesn't navigate to the product page. We use JS to POST
         to /shop/cart and update the header badge without reloading.
         If JS is disabled, the form submits normally and the
         customer lands on /shop/cart — still functional, just less
         smooth. -->
    <?php if ($outOfStock): ?>
        <button type="button" class="sf-card-add sf-card-add-disabled" disabled>
            Out of stock
        </button>
    <?php elseif ($hasVariants): ?>
        <a href="<?= e($url) ?>" class="sf-card-add sf-card-add-link">
            Choose options
        </a>
    <?php else: ?>
        <form method="post" action="<?= e(SLATE_URL) ?>/shop/cart"
              class="sf-card-add-form">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="_action" value="add">
            <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="qty" value="1">
            <button type="submit" class="sf-card-add">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                     stroke-linecap="round" stroke-linejoin="round"
                     style="width:14px;height:14px;flex-shrink:0;">
                    <circle cx="9" cy="21" r="1"/>
                    <circle cx="20" cy="21" r="1"/>
                    <path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/>
                </svg>
                <span>Add to cart</span>
            </button>
        </form>
    <?php endif; ?>
</div>
<?php
}

}  // end if (!function_exists)
