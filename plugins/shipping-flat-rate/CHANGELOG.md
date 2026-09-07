# Flat Rate Shipping changelog

## 1.0.1 — visible cart breakdown

Adds a small "Ships in: X" line under the Shipping price on the cart
page so customers (and admins) can see which box combination is
producing the price. Requires Shop v1.2.7+ which provides the
`shop_cart_shipping_breakdown` filter hook.

Examples:
- "Ships in: USPS Medium Flat Rate Box"
- "Ships in: 2 × USPS Padded Flat Rate Envelope"
- "Ships in: 1 USPS Large Flat Rate Box + 1 USPS Medium Flat Rate Box"

The count prefix is omitted when there's only one of a box ("USPS
Medium Flat Rate Box" not "1 × USPS Medium Flat Rate Box") for
natural reading.

---

## 1.0.0 — initial release

Adds box-based flat-rate shipping calculation to Slate Shop. Each
product picks which "box" it ships in (Envelope / Small / Medium /
Large or any custom box you define); the cart's shipping cost is
the sum of boxes needed to pack the cart, computed with greedy
bin-packing plus a "shrink-fit" pass that lets smaller-box items
ride along free in larger boxes that are already needed.

### Includes

- Admin page at Shop → Shipping boxes for managing box presets
  (add / edit / delete / reorder / disable individual boxes).
- "Ships in" dropdown injected into the product edit form's
  Shipping section.
- Seeded with the four current USPS Flat Rate prices (early 2026):
  Padded Envelope $10.85, Small Box $10.40, Medium Box $17.10,
  Large Box $22.80. Admins can update these when USPS bumps rates.
- Hooks into Shop's `shop_shipping_rate` filter so the cart
  page, checkout page, and order creation all see the same
  calculated price.
- Backward compatible: if all boxes are disabled or the plugin is
  inactive, Shop falls back to its built-in `shop.flat_shipping_rate`
  setting. No customer-facing breakage when activating/deactivating.

### Schema

- New table: `shippingflatrate_boxes` (slug, name, price, capacity,
  sort_order, is_active, notes, timestamps).
- New column: `shop_products.shipping_box_slug VARCHAR(64) NULL`.
  Left in place on uninstall so re-activation preserves assignments.

### Verified end-to-end

- Bin-packer correctly handles: single item, shrink-fit (small rides
  in medium), overflow (7 items in cap-6 boxes = 2 boxes), 3-size mix
  (large covers all), large-with-spare (1 large + 11 mediums = 1
  large), and overflow-beyond-largest (1 large + 13 mediums = 1
  large + 1 medium).
- Cart page shows "Shipping $17.10" upfront (no checkout-only
  display).
- Order creation persists the calculated `shipping_total`.
- Admin box CRUD: add, edit, delete, reorder, validate slug format,
  transactional save (all-or-nothing).
- Backward compat: deactivating the plugin returns the storefront
  to Shop's built-in flat rate.
