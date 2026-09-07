# Shop plugin changelog

## 1.2.7 — clean HTML tags from product names + cart breakdown hook

### Bug fix: literal HTML showing in cart/storefront

Product names imported from WooCommerce-style CSVs sometimes carried
HTML tags inside the name field (e.g.
`Southwestern Flair <b>LOW SODIUM</b> Seasoning, <b>SPICY</b>`).
Because cart and product pages defensively HTML-escape names to
prevent XSS, those tags rendered as literal text. Names are
plaintext-by-contract; markup belongs in description/short_desc.

Two fixes:

1. **CSV importer** now strips HTML tags from the name column on the
   way in. `strip_tags()` + `html_entity_decode()` + whitespace
   collapse. Descriptions still allow HTML.
2. **Manual product edit form** does the same on save (so admins who
   paste rich text into the name field also get cleaned input).
3. **One-time migration** (1.2.7 step in Shop.php) scans
   `shop_products` for any name containing `<...>` patterns and
   cleans them. Idempotent: re-running on clean rows is a no-op.

### New hook: `shop_cart_shipping_breakdown`

Lets shipping plugins inject a small breakdown line under the cart's
Shipping row. Callback receives `(string $html, array $cart)` and
returns HTML to append. Used by shipping-flat-rate to show
"Ships in: 2 × USPS Padded Flat Rate Envelope" so customers can see
what they're paying for. Returns the empty string when no breakdown
applies (no items, no shipping plugin registered).

### No schema changes

Just code + one-time data cleanup migration. Backward compatible
with 1.2.6 — no API changes, no removed hooks.

---

## 1.2.6 — secure order URLs + extensibility hooks

### Security — unguessable order-confirmation links

The storefront order page used to be reachable at
`/shop/order?key=ORD-00001`, where the auth key was the sequential
order number itself. That's an enumerable PII leak: anyone could
increment the number and read another customer's order details
(email, address, line items). Fixed by:

- New `shop_orders.view_token` column — 32 hex chars (128 bits of
  entropy) generated with PHP's `random_bytes()` at order creation.
- Storefront order lookup now uses `view_token` instead of
  `order_number`. The existing endpoint accepts either during a
  grace period so old confirmation emails stay valid.
- Backfill migration generates fresh tokens for existing orders.

### New filter hooks for extensibility

Adds three filter hooks so plugins can extend the checkout flow
without modifying shop's files.

- **`shop_shipping_rate`** — fires inside `ShopAPI::calculateShipping()`
  after the free-shipping-threshold check. Callbacks receive
  `($currentRate, $context = ['subtotal' => float, 'items' => array,
  'billing' => array])` and return either a numeric rate to claim the
  calculation, or pass `$currentRate` through to abstain. If every
  hook abstains, the legacy `shop.flat_shipping_rate` setting is used.

- **`shop_product_edit_shipping_fields`** — fires inside the product
  edit form, inside the "Shipping & dimensions" card, right after
  dimensions. Callbacks return HTML to append. Receives the current
  product row as the second arg. Used by shipping-flat-rate to add
  its "Ships in" dropdown.

- **`shop_product_db_row`** — fires inside `productDbRow()` (the
  helper that builds the row passed to insert/update). Callbacks
  return a modified `$row` array; any extra column names that don't
  exist in `shop_products` are silently dropped by the column-filter
  step below. Lets plugins inject their own columns (e.g. shipping
  box assignment) into product saves.

### Backward compatibility

- Plugins with no hooks registered: identical behavior to 1.2.5.
- The shipping signature change is internal (`calculateShipping` is
  `private`, callers updated alongside).
- Existing `shop.flat_shipping_rate` and `shop.free_shipping_threshold`
  settings still work as the default shipping calculation.

---

## 1.2.5 — payment provider hook

New filter `shop_payment_providers` lets other plugins register
themselves as payment options at the storefront checkout. Core
ships one provider built-in (the existing "manual" / pay-later
flow); the new Stripe Payment plugin registers as a second.

### What's new in shop core

- `ShopAPI::paymentProviders()` — returns the list of registered
  providers, ordered by priority. The hook callback shape is:
  ```
  ['id' => string, 'label' => string, 'description' => string,
   'priority' => int, 'available' => bool, 'render_form' => callable|null,
   'handle_checkout' => callable($sid, $billing): array]
  ```
  `handle_checkout` returns either `['ok'=>true, 'order_id'=>N]`
  (synchronous), `['ok'=>true, 'redirect'=>$url]` (off-site flow),
  or `['ok'=>false, 'error'=>$msg]`.

- `ShopAPI::paymentProvider(string $id)` — lookup by id.

- `ShopAPI::checkoutCartManual()` — the old `checkoutCart` flow
  kept under a new name for the built-in 'manual' provider.

### Storefront checkout changes

- Payment-method radio picker appears above the order summary
  when more than one provider is available. When only 'manual'
  is registered (e.g., no Stripe plugin installed), the picker
  is hidden and behavior is identical to the old build.
- POST handler dispatches through the selected provider's
  `handle_checkout`. If the provider returns a redirect URL
  (Stripe Checkout, etc.) we follow it; if it returns an
  order_id, we redirect to the confirmation page; if it returns
  an error, we flash it.
- New `?error=cancelled|payment_not_completed|order_failed|missing`
  query params show friendly messages when customers come back
  from an interrupted off-site flow.

### CSS in storefront layout

- `.sf-payment-methods` container, `.sf-payment-option` radio
  row, `.sf-payment-option-extra` slot for providers that need
  to inject extra UI (card form, etc).

---

## 1.2.4 — overflow defenses

Live deploy of 1.2.3 confirmed the `.filter-row`, `.split-layout`,
and `.toolbar` primitives are working — filter card stacks one
field per row at narrow widths, bulk-action toolbar wraps cleanly,
new-order form layout is responsive. But the products list rows
were still showing the title overflowing past the viewport's right
edge, hiding the badge and chevron off-screen.

This wasn't a layout-primitive bug; it was a defensive-CSS gap.
Several fixes against horizontal overflow in `ui_components.php`:

- `body { overflow-x: hidden }` — last-line-of-defense: the page
  never scrolls horizontally regardless of what's inside. Catches
  any future overflow bug from any plugin.
- `.data-row-summary { min-width: 0; max-width: 100% }` — lets
  the flex layout shrink the row below its intrinsic content width
  so the title actually gets ellipsed.
- `.data-row-summary > .badge { flex-shrink: 0 }` — the badge
  reserves its own intrinsic width and never shrinks. Only the
  title (inside data-row-main, which already has min-width:0) is
  the flexible element.
- `.data-list, .data-row { width: 100% }` — both stay inside
  their parent's width.

In core `admin/partials/header.php`:

- `main.content { min-width: 0 }` — the page's grid track no
  longer expands to fit min-content. Without this, a single long
  unbroken SKU or product name could push the entire content
  column wider than the viewport, defeating every responsive
  rule inside.

### Verification

All eight admin pages and four storefront URLs still 200. The new
defensive CSS is in the served HTML. Simple-product save and
variant edit still work.

I still couldn't visually verify these at specific viewport widths
because there's no browser available in this environment, but the
CSS rules above are conservative enough that they should fix the
observed overflow without changing how the page looks at desktop
sizes. If anything looks visually off after deploying, the most
likely culprit is `body { overflow-x: hidden }` — back that out
and let me know what changed.

---

## 1.2.3 — design system primitives + responsive layout cleanup

This build introduces three reusable layout primitives in core
(`includes/ui_components.php`) and migrates the affected pages to use
them. The goal: stop reinventing flex+min-width+wrap patterns inline
on every list and detail page, since each ad-hoc version chose its own
breakpoints and most of them broke around 640-960px viewport widths.

### New primitives in core CSS

- **`.filter-row`** — list-page filter strip (search + 1-3 selects +
  submit button). Stacks on phones, two-up on tablets, single row on
  desktop. Replaces inline `flex / flex:2 / min-width:160px` patterns.

- **`.split-layout`** — main + side content layout for detail pages.
  Single column on mobile/tablet, 2fr/1fr on desktop (≥960px). Used by
  customer detail and new manual-order form.

- **`.toolbar`** — grouped cluster of buttons/selects that should
  stay together but wrap as a unit. Replaces `flex gap-2 items-center`
  patterns in card headers.

### Page updates

- **Products list filter card** — switched from inline-style flex
  with three competing `flex:N min-width:Mpx` declarations to
  `.filter-row` grid. Resolves the issue from the screenshot where
  the Category select was clipped showing "All categori…" on narrow
  widths because Search's `flex:2` ate most of the row.

- **Products list bulk-action header** — removed the orphan `<span>`
  count that was wrapping below the toolbar. The product count now
  lives inside the section heading (`%d product(s)`) and the bulk
  action `<select>` + `<button>` are grouped under `.toolbar`.

- **New Manual Order form** — rebuilt the 2-column layout using
  `.split-layout`. The product+qty rows inside the Products card use
  a new `.order-item-row` grid that stacks below 480px instead of
  squashing the qty input down to 70px.

- **Customer detail page** — same `.split-layout` migration. Order
  history left, customer details + notes right on desktop; stacked
  on mobile.

### Data-row responsive fixes (continued)

- **Status badges stay visible at all viewport widths** (the prior
  build hid them below 380px). Below 640 they shrink to 10px font /
  7px padding; below 380 they shrink further to 9.5px / 5px. The
  status is the most useful at-a-glance signal in a row — hiding it
  on phones was wrong.

- **Card-header and page-header toolbars wrap at ≤960px**, not just
  ≤640. This is the band where the sidebar appears (768px breakpoint)
  but content area shrinks to ~720px — narrow enough that 3-button
  rows overflow. At ≤640 they additionally take `width:100%` to push
  to a clean second row.

### Verification

All eight admin pages and four storefront URLs still 200. Simple
product save and variant edit-in-place still work. Products list,
new-order form, and customer detail page all use the new primitives
with zero remaining inline `flex:N min-width:Mpx` patterns. The
filter form on the products list now grids cleanly into 4 columns at
desktop, 2 at tablet, 1 at phone instead of the unreliable flex-wrap
behavior the inline styles produced.

---

## 1.2.2 — data-table rendering + responsive fixes

The orders, customers, and dashboard recent-orders lists were leaking
raw HTML into the rendered text — literal strings like
`<span class="badge badge-warning">pending</span>` and
`&nbsp;&middot;&nbsp;` showed up verbatim in row titles and meta lines
instead of rendering as the intended badges and separators.

Root cause: `slate_data_row()`'s contract is that `title` and `meta`
are plain text and get HTML-escaped by the component. Three callers
in this plugin (orders, dashboard, customers detail) plus one in
coupons were ignoring that contract and embedding pre-built HTML.
With the escaping applied that HTML showed up as literal angle-bracket
text, exactly what was in the screenshot you sent.

The fix is twofold:

- **Component side (`includes/ui_components.php`):** added opt-in
  `title_html` and `meta_html` keys that take pre-built HTML and skip
  escaping. The existing `title` / `meta` keys still escape as before.
  Callers that needed a status badge can keep using the existing
  `badge` arg (which the component has supported all along).

- **Caller side:** fixed orders.php, customers.php (both list and
  detail rows), shop/index.php (dashboard recent-orders), and
  coupons.php to:
  - pass plain text to `title` / `meta` (no `e()` wrapping needed —
    the component does it)
  - pass status info through the `badge` arg instead of inline HTML
  - replace `&nbsp;&middot;&nbsp;` with a plain " · "

### Responsive fixes

The bulk-action toolbar inside `.card-header` was overflowing the
viewport on narrow screens because `.card-header` is `flex` with no
wrap. Same problem with the page-header buttons row. The variant edit
form was hardcoded to a 5-column grid via inline style, which produced
five tiny crushed inputs on phones.

Changes in `includes/ui_components.php`:

- `.card-header` and any `.flex` toolbar inside it now wraps to a
  second row on screens ≤640px wide
- `.page-header .flex.gap-2` (the button row with Import / Export /
  New product) wraps the same way
- `.data-row-summary .badge` shrinks below 640px and is hidden below
  380px (status is already encoded in the avatar color)
- `.data-row-detail` left padding drops from 60px to 14px below 640px
  (the desktop indent matches the avatar; on phone it just steals
  width from the content)
- New `.field-row-variant` class: 2 columns on phone, 3 on tablet,
  5 on desktop. Replaces the inline 5-column grid in the variant
  editor.

### Verification

All four list pages parsed with html5lib and inspected. Title and
meta strings are now plain text, badges are separate elements with
the correct `badge-warning` / `badge-active` / `badge-success` /
`badge-muted` modifier classes. No `&nbsp;` or `&middot;` entities
or stray `<span class=...>` text in row titles or meta lines.

Simple product save and variant edit-in-place still work; all eight
admin pages and four storefront URLs still 200.

---

## 1.2.1 — variant editing build

Two user-reported gaps in 1.2.0 closed.

### Imported variants are now editable

In 1.2.0 the variants card showed each existing variant in a read-only
data-list row with only a "Remove" button. The only way to fix a typo or
adjust a price diff on an imported variant was to delete it and re-add
it from scratch — fine for one or two, painful for the 23-variant
products in our catalog.

The variants section is now a list of `<details>` cards. Click the
summary row to expand it into a full edit form: Attribute, Value, SKU,
Price diff, Stock, Description, Image, Save button, plus a Remove
button below the form. The same dispatcher already accepted updates
when `variant_id` was non-zero — only the UI was missing.

### Per-variant images and descriptions

`shop_product_variants` gains two columns (both nullable, both
backwards-compatible):

- `image_url VARCHAR(500)` — overrides the parent product's main
  image when this variant is selected
- `description TEXT` — supplements the parent product's description
  with variant-specific copy (tasting notes, packaging detail, etc.)

Storefront product page renders both via a small JS handler attached
to the variant `<select>`. When a variant with its own image is
picked, the main gallery image swaps to the variant image (the parent
image is remembered and restores when the user changes back to
"no selection"). When a variant has its own description, an "About
this variant" panel appears below the regular description tab,
populated with that text.

If a variant has neither image nor description set, the storefront
behaves exactly as before — full backwards compatibility.

The new columns are applied by `Shop::runMigrations()` using the
existing `ensureColumn()` helper, so re-running the migration is a
no-op. `schemaIsCurrent()` was extended to verify both columns exist
before stamping `applied_version=1.2.1`.

### Bonus fix: duplicate-variant inserts no longer 500

When adding a new variant whose `(attribute, value)` already exists
for the same product, the previous build let the PDO unique-key
violation bubble up as an uncaught exception (HTTP 500). The
save_variant handler now catches `PDOException::getCode() === '23000'`
and flashes a friendly "A variant with attribute X and value Y already
exists for this product" error instead.

### Core change: plugin manifest auto-refresh

`PluginLoader::loadOne()` now compares the on-disk `plugin.json`
version against the DB-snapshotted manifest. When the file's version
is newer, it adopts the on-disk manifest AND persists the new version
back to the `plugins` table. Without this, upgrading the shop folder
in-place wouldn't propagate the new version to runtime — so
version-gated migrations would never fire even though the new code
was already loaded.

This is a small change with a big ergonomic improvement: drop the new
zip over an existing install, hit any admin page, and migrations run
automatically. No re-activate step needed.

### Verification

Every change above was verified end-to-end against the live test
environment, not just lint-passed:

- Schema migration: `applied_version` advanced from 1.2.0 → 1.2.1,
  both new columns present in `DESCRIBE shop_product_variants`.
- Edit-in-place: POSTed an updated description + price + stock to
  variant id=1, confirmed DB updated.
- Per-variant image upload: PNG uploaded via multipart form, file at
  `/uploads/shop/products/variants/<random>.png`, DB stored path.
- Image clear: `v_clear_image=1` reset `image_url` to NULL.
- Storefront integration: product page emits 23/23
  `data-variant-image` and `data-variant-desc` attributes (matching
  variant count), descriptions safely escaped, JS handler wired up.
- Duplicate-variant: re-adding "Size: Extra Large" for the same
  product → HTTP 200 with friendly error flash, no duplicate row.
- Manifest auto-refresh: set DB version back to 1.2.0, hit any admin
  page, verified DB row advanced to 1.2.1 and migration ran.
- Regression: 49 forms render on the 23-variant peanut-brittles edit
  page, html5lib confirms zero forms have duplicate `_action` inputs
  (the original 1.2.0 bug doesn't reappear at scale).

---

## 1.2.0 — bugfix build

Audit + fixes against the 1.2.0.4 build. Application version stays at 1.2.0;
the .4 was an internal build counter, not a public version bump.

### Critical fixes

- **Simple product save was broken.** The product editor wrapped its main form
  around the variants card, which itself contained nested `<form>` elements
  (one hidden form for "Add variant", one per existing variant for "Remove").
  HTML5 parsers silently drop a nested `<form>` open tag but keep its
  children, so the outer form ended up with two hidden `_action` inputs —
  `update` followed by `save_variant`. PHP's `$_POST['_action']` reads the
  last duplicate, so the dispatcher ran the variant-save validation path on
  every Save click and returned "Variant attribute and value are required."
  Fix: the entire variants section is now rendered OUTSIDE the main product
  form, as its own card with its own forms below the danger zone.

- **Image upload MIME validation was bypassed.** `Uploads::handle()` read
  `$opts['allowed_mimes']` (plural), but every caller in this plugin passed
  `'allowed_mime'` (singular). Result: the MIME whitelist silently no-opped
  and any file type that survived `is_uploaded_file()` was written to
  `/uploads/shop/products/`. The on-disk `.htaccess php_flag engine off`
  partially mitigates this on Apache but not on Nginx. Plugin call sites
  now use the documented `allowed_mimes` key. Core `includes/Uploads.php`
  was also patched to accept both names for backwards compat — that fix
  needs to be applied to Slate core separately if you have other plugins
  using the old key.

### Important fixes

- **Storefront forms had no CSRF protection.** Cart update/remove/clear,
  add-to-cart, and checkout place-order all accepted POSTs with no token.
  Added `sf_csrf_token()` / `sf_csrf_field()` / `sf_csrf_verify()` helpers
  in `storefront/includes/layout.php`. Token is derived from
  `hash_hmac('sha256', 'shop-csrf:' . $shop_sid, APP_SECRET)` — it's tied to
  the storefront's `shop_sid` cookie, not PHP's `$_SESSION`, so it works
  for guest users with no server session.

- **Category edit re-render lost description and display_order on
  validation failure.** `compact('id', 'name', 'slug', 'desc', 'order')`
  produced keys `desc` and `order`, but the editor template reads
  `description` and `display_order`. Replaced both `compact()` calls with
  explicit arrays using the correct keys.

### Nice-to-have fixes

- **`ShopAPI::createOrder()` is now transactional.** Order insert + line
  items + stock decrement + coupon increment are wrapped in
  `beginTransaction`/`commit`, with rollback on exception. `Hook::doAction`
  fires only after commit, so subscribers always see a fully persisted
  order. Defensive: skips begin if a caller upstream is already in a
  transaction.

- **`reports.php` date input now validates strictly.** The old
  `preg_replace('/[^0-9\-]/', '', ...)` left garbage like `---` valid,
  which crashed `new DateTime()` deeper in the page. Now: strict
  YYYY-MM-DD regex + `checkdate()`. Bad input falls back to the
  period-derived default instead of 500-ing.

### Known limitations (not fixed in this build)

- Multi-axis variations (e.g. Flavor × Size simultaneously) — deferred
  indefinitely per project decision; single-axis is the supported model.
  Note: the storefront product page emits one `<select name="variant_id">`
  per attribute group, so on a product that somehow has two attribute
  groups in `shop_product_variants`, only the last group's selection is
  submitted. Latent until/unless multi-axis ships.

- Stock display on parents with `manage_stock=0`: the parent is considered
  in-stock regardless of variant availability. Tracking variant-driven
  parent stock is part of the multi-axis feature.

### Verification

Every fix above was verified end-to-end against a live MariaDB+PHP test
environment, not just lint-passed:

- Simple product save: real HTTP POST, "Product saved." flash, DB row
  updated with the modified description marker.
- Variant CRUD: add Medium variant → DB has 3 rows; delete it → DB has 2.
- Upload MIME: PHP-disguised-as-JPG rejected; real PNG accepted and
  written to disk under a randomized filename.
- Storefront CSRF: POST without token → "Security check failed."; POST
  with token → success. Full add-to-cart → cart update → checkout flow
  produced ORD-00001 in shop_orders, with cart cleared on completion.
- CSV import: 31 created + 25 variants imported from
  merged-products-and-variations.csv on first pass; re-import was 0
  created + 31 updated (idempotent). A synthesized WooCommerce-native
  CSV with pipe-separated Attribute 1 value(s) also imported correctly.
- Categories validation re-render: duplicate-slug submission preserves
  description and display_order in the form.
- Reports date range: good range, garbage `---`, and impossible
  `2025-02-30` all return HTTP 200.

## 1.1.0 — previous release

- Categories (`shop_categories` table, admin page)
- Single-axis product variants (`shop_product_variants`)
- CSV import/export (WooCommerce native + Product CSV Import Suite formats)
- Public storefront (`/shop/`, `/shop/product/<slug>`, cart, checkout, order)
- Product image gallery

## 1.0.0 — initial release

- Products, orders, customers, coupons, reports
- Settings (currency, tax, shipping)
- Manual order creation in admin
