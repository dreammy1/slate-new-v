# Flat Rate Shipping for Slate Shop

Charges customers a fixed price per box at checkout. You define a small
set of box presets (Small / Medium / Large / Envelope, plus any custom
ones you add), and each product picks which box it ships in. When a
customer adds items to their cart, the plugin packs them into the
fewest boxes needed and charges the total of those boxes.

Designed for stores that ship in USPS Flat Rate boxes (or their own
boxes priced at fixed rates per size). No carrier API calls, no
shipping-rate latency at checkout — the price is known instantly.

## Requirements

- Shop plugin v1.2.6 or later (provides the `shop_shipping_rate`
  and `shop_product_db_row` filters this plugin hooks into)

## Install

1. Drop the plugin folder into `slate/plugins/shipping-flat-rate/`.
2. Activate it in admin → Plugins.
3. Go to Shop → Shipping boxes. The four USPS Flat Rate boxes are
   seeded with their default prices; adjust to whatever you actually
   pay (Commercial Plus rates, your own boxes, etc).
4. Go to Shop → Products. For each product, edit it and pick the box
   it ships in from the new "Ships in" dropdown in the Shipping
   section. Products left at the default ship in the smallest active
   box.

That's it. The cart and checkout pages will start showing the correct
shipping cost based on what's in the cart.

## How the cart packer works

The algorithm is a greedy bin-packer with a "shrink-fit" pass:

1. Group items by the box each one is assigned to.
2. Walk from largest-capacity box down to smallest.
3. For each box class, compute how many boxes that class needs:
   `ceil(items / capacity)`.
4. Check the LAST box of that class for spare capacity. If there's
   room, "shrink-fit" items from smaller-box classes into it — they
   ride along free.
5. The shipping cost is the sum of all boxes × their per-box price.

### Worked examples

Suppose your boxes are: Envelope (cap 2, $10.85), Small (cap 3, $10.40),
Medium (cap 6, $17.10), Large (cap 12, $22.80).

| Cart | Result |
|------|--------|
| 1 medium-box item | 1 Medium = **$17.10** |
| 1 medium + 1 small item | 1 Medium covers both = **$17.10** |
| 7 medium-box items | 2 Mediums (capacity 6 each) = **$34.20** |
| 1 large + 1 medium + 1 small | 1 Large covers all 3 = **$22.80** |
| 1 large + 11 medium items | 1 Large (capacity 12, fits all 12) = **$22.80** |
| 1 large + 13 medium items | 1 Large + 1 Medium for the overflow = **$39.90** |

### Why capacity is in "items"

We treat box capacity as a number of items, not weight or volume. For
most stores with reasonably uniform inventory inside a box class (e.g.
all spice jars are roughly the same size), this is accurate enough and
much simpler than a 3D bin-packer. If your inventory varies wildly in
size within a box class, set capacities conservatively (lower numbers)
so you don't end up with un-packable carts in practice.

## Schema

One table:

```
shippingflatrate_boxes (
    id, slug, name, price, capacity, sort_order, is_active, notes,
    created_at, updated_at
)
```

One added column on `shop_products`: `shipping_box_slug VARCHAR(64) NULL`.

The added column stays in `shop_products` even after this plugin is
uninstalled (so you can re-activate the plugin later and the
assignments are preserved). To remove it, run:

```sql
ALTER TABLE shop_products DROP COLUMN shipping_box_slug;
```

## Uninstall

1. Deactivate via admin → Plugins.
2. Drop the plugin folder.
3. The `shippingflatrate_boxes` table is dropped automatically by
   Slate's uninstall mechanism (uses `uninstall.sql`).
4. The `shop_products.shipping_box_slug` column is left alone — see
   above.

## When NOT to use this plugin

- If you ship internationally and need real-time rates → use a carrier
  API integration (USPS, FedEx, UPS — not built yet for Slate).
- If your inventory varies wildly in size and a "capacity in items"
  model doesn't fit (e.g. you sell both tiny earrings and bulky lamps,
  same box class won't work for both).
- If you want weight-based tiers without box selection → use shop's
  built-in `shop.flat_shipping_rate` setting and don't install this
  plugin.
