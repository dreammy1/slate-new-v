# Shop — Slate E-Commerce Plugin

A WooCommerce-style e-commerce plugin for the Slate admin platform.
Manages products with variants and categories, takes orders through
a public storefront with cart and checkout, and supports CSV
import/export for migrating from existing stores.

**Version 1.1.0** adds: categories, single-axis variants, CSV import/
export, a public-facing storefront with cart and checkout, and a
WooCommerce-style admin product editor.

---

## Features

### Admin
- **Products** — WooCommerce-style 2-column editor: name, SKU, slug, short + long description, pricing (regular + sale), stock, weight, category, tags, image upload, status (draft/published/archived), featured flag
- **Single-axis variants** — Size **or** Color (e.g. S/M/L) per product, each with its own SKU, price diff, and stock
- **Categories** — one-level, with slug, description, display order, and product counts
- **Bulk actions** — publish/draft/archive/delete multiple products at once
- **CSV export/import** — WooCommerce-compatible columns, matches by SKU then slug
- **Orders** — full order lifecycle (`pending → processing → completed → refunded`), line items with variant info, shipping address, coupons, tax + shipping
- **Customers** — auto-created on first order, lifetime value tracking
- **Coupons** — percentage or fixed-amount, minimum order, usage limits, expiry
- **Reports** — revenue KPIs, daily trend, top products, status breakdown; date-filterable
- **Settings** — currency, tax rate, flat shipping, free shipping threshold, order number prefix
- **Dashboard widget** — today's revenue, active product count, low-stock alert, pending order count, link to storefront

### Public storefront
- **Catalog** at `/shop/` — search, sort, pagination, featured strip
- **Product detail** at `/shop/product/<slug>` — gallery, variant selector with stock-aware options, quantity input, add-to-cart
- **Category listing** at `/shop/category/<slug>` — same grid as catalog, scoped
- **Cart** at `/shop/cart` — line edit, qty update, remove, clear, order summary with shipping/tax/total
- **Checkout** at `/shop/checkout` — guest-friendly, billing + shipping address, order notes, no account required
- **Order confirmation** at `/shop/order?key=<view_token>` — full order detail, capability-key URL safe to email. `view_token` is a 32-hex-char (128-bit) random key, distinct from the sequential `order_number` shown to humans.
- Cream-and-tan theme that matches the Slate admin (uses your accent color setting)
- Mobile-responsive throughout

---

## Storefront setup

The storefront pages live inside the plugin at `storefront/`. To make
them reachable at `/shop/*` URLs on your domain, add the rewrite
snippet to your **Slate root `.htaccess`**:

1. Open `storefront-htaccess-snippet.txt` in this plugin folder
2. Copy the `<IfModule mod_rewrite.c>` block
3. Paste it into your Slate install's root `.htaccess` (anywhere
   after the `AddDefaultCharset UTF-8` line is fine)
4. Visit `https://yourdomain.com/shop/` — the storefront should
   load

The rewrite rules send `/shop/*` requests to the plugin's router,
which dispatches to the right page based on the URL. No core
Slate files need to be modified.

If your Slate install lives in a subdirectory (e.g. `/slate`),
change `RewriteBase /` in the snippet to match.

**Apache is required** for the rewrite rules. If you're on Nginx,
add an equivalent location block:

```nginx
location /shop/ {
    try_files $uri $uri/ /plugins/shop/storefront/router.php?_path=$request_uri;
}
```

---

## CSV import / export

### Export

In the admin: **Shop → Products → Export CSV**.

You get a file named `products-YYYY-MM-DD.csv` with these columns:

| Column              | Notes                                                       |
|---------------------|-------------------------------------------------------------|
| ID                  | Internal id (informational; not used by import to match)    |
| Type                | Always `simple` (variants exported separately later)        |
| SKU                 | Used for matching on import                                 |
| Name                | Required                                                    |
| Slug                | Used for matching when SKU is empty                         |
| Published           | `1` for active, `0` for draft                               |
| Featured            | `1` or `0`                                                  |
| Short description   |                                                             |
| Description         |                                                             |
| Regular price       | e.g. `19.99`                                                |
| Sale price          | Optional; leave blank for no sale                           |
| In stock?           | `1` if in stock OR if not tracking stock                    |
| Stock               | Quantity (when tracking)                                    |
| Manage stock        | `1` to track, `0` to not                                    |
| Weight              | In kg                                                       |
| Categories          | Category name (single)                                      |
| Tags                | Comma-separated                                             |
| Images              | URL or path to product image                                |

### Import

In the admin: **Shop → Products → Import CSV**, upload your file.

Matching rules: existing products are found by **SKU first**, then by
**slug** if no SKU is set. Matched rows are updated; unmatched rows
are created. The CSV import will create any missing categories
automatically.

If you're migrating from WooCommerce, export your products from
WooCommerce (Products → Export) and import the file directly —
the column names match.

Errors are collected per row and shown after the import completes,
so a few bad rows don't kill the rest.

---

## Permissions

| Permission              | Description                                  |
|-------------------------|----------------------------------------------|
| `shop.view_orders`      | View orders and customer details             |
| `shop.manage_orders`    | Create, edit, and update order status        |
| `shop.manage_products`  | Create, edit, and delete products            |
| `shop.manage_coupons`   | Create, edit, and delete coupons             |
| `shop.view_reports`     | View revenue reports                         |
| `shop.manage_settings`  | Change shop settings                         |

Managers get `view_orders`, `manage_orders`, `manage_products`, and
`view_reports` by default.

---

## Public API (`ShopAPI`)

Other plugins can integrate with Shop without touching its tables.

```php
if (PluginLoader::isActive('shop') && class_exists('ShopAPI')) {

    // Catalog (public-safe)
    $products = ShopAPI::publishedProducts(['category_slug' => 'clothing'], 20);
    $product  = ShopAPI::productBySlug('red-tshirt');

    // Categories
    $cats = ShopAPI::categories(true);   // with product counts

    // Variants
    $variants = ShopAPI::variantsFor($productId);
    $price    = ShopAPI::variantPrice($product, $variant);

    // Cart (session-based, guest-friendly)
    $sid = $_COOKIE['shop_sid'];
    ShopAPI::addToCart($sid, $productId, 2, $variantId);
    $totals = ShopAPI::cartTotals($sid);
    $result = ShopAPI::checkoutCart($sid, [
        'first_name' => 'Jane', 'last_name' => 'Smith',
        'email'      => 'jane@example.com',
        'address_1'  => '123 Main St',
        'city'       => 'Springfield', 'country' => 'US',
    ]);

    // Admin: create order programmatically (with optional variants)
    $orderId = ShopAPI::createOrder(
        'jane@example.com', 'Jane Smith',
        [
            ['product_id' => 7,  'qty' => 2, 'variant_id' => 14],
            ['product_id' => 12, 'qty' => 1],
        ],
        ['coupon_code' => 'SUMMER20']
    );
}
```

### Hook

Listen in your plugin:

```php
Hook::addAction('shop_order_created', function (int $orderId, string $email) {
    // e.g. send confirmation email, trigger fulfilment, update CRM
});
```

---

## File layout

```
shop/
├── plugin.json
├── Shop.php                       ← bootstrap
├── ShopAPI.php                    ← public API
├── install.sql                    ← schema (v1.0.0 base)
├── uninstall.sql                  ← teardown
├── migrations/
│   └── 1.1.0.sql                  ← v1.1.0 schema additions
├── storefront-htaccess-snippet.txt← rewrite rules for /shop/* URLs
├── admin/
│   ├── index.php                  ← dashboard overview
│   ├── orders.php                 ← order list + detail + new order
│   ├── products.php               ← WC-style product editor + CSV
│   ├── categories.php             ← category management
│   ├── customers.php              ← customer list + detail
│   ├── coupons.php                ← coupon list + edit
│   ├── reports.php                ← revenue reports
│   └── settings.php               ← shop settings
├── storefront/
│   ├── router.php                 ← URL dispatcher
│   ├── index.php                  ← catalog
│   ├── product.php                ← product detail
│   ├── category.php               ← category listing
│   ├── cart.php                   ← cart view
│   ├── checkout.php               ← checkout
│   ├── order.php                  ← order confirmation
│   └── includes/
│       └── layout.php             ← shared theme + helpers
└── README.md
```

---

## Packaging

```bash
php bin/package-plugin.php plugins/shop --dist
# → plugins/_dist/shop-v1.1.0.zip
```

---

## Settings stored

| Key                           | Default | Description                          |
|-------------------------------|---------|--------------------------------------|
| `shop.currency`               | USD     | ISO currency code                    |
| `shop.tax_rate`               | 0       | Tax % applied at checkout            |
| `shop.flat_shipping_rate`     | 0       | Flat shipping added per order        |
| `shop.free_shipping_threshold`| 0       | Order total for free shipping (0=off)|
| `shop.order_prefix`           | ORD     | Prefix for order numbers             |
| `shop.applied_version`        | -       | Last applied migration version       |

---

## Upgrading from v1.0.0

The plugin runs migrations automatically on the next request after
upload. You don't need to do anything special — the `1.1.0.sql`
migration in `migrations/` will:

1. Create `shop_categories`, `shop_product_variants`, `shop_carts`,
   `shop_cart_items` tables
2. Add `featured`, `category_id`, `gallery_urls` columns to
   `shop_products` (idempotent — safe to re-run)
3. Add a `tenant_slug` index on `shop_products` for fast slug lookups
4. Backfill slugs for existing products from their names
5. Insert a default "Uncategorized" category

The original `install.sql` still applies to fresh installs; new
installs get both the v1.0 schema AND the v1.1.0 migration in one go.

---

## What's NOT in v1.1.0 (yet)

Honest scope statement. These features will land in future versions:

- **Payment gateways** (Stripe, PayPal) — checkout creates the order
  with status `pending` and no payment is collected; you'd integrate
  via a future v1.2.0 update
- **Email notifications** (order confirmation, shipped, etc.) — uses
  the Slate `Mailer` once we wire up the templates
- **Multi-axis variants** (Size × Color) — single-axis only for now
- **Multi-image gallery** — schema has `gallery_urls` ready, UI ships
  later
- **Nested categories**
- **Reviews / ratings**
- **Coupons on the public checkout** (admin can apply them; public
  cart doesn't have a code field yet)
- **Shipping zones + per-zone methods**
- **Customer accounts on the public side** (login, order history)
- **REST API + webhooks**

If you need any of these urgently, file an issue describing the
exact use case.
