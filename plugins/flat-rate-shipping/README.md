# Flat-rate-shipping (Slate plugin)

Charges shipping based on cart weight tiers.

## Requirements

- Slate core ≥ 1.0.0
- Shop plugin ≥ 1.2.6 (the version with the `shop_shipping_rate` filter hook)

This plugin does nothing useful without Shop installed and active — it
registers itself on a Shop-defined filter. If Shop isn't there, the
filter is never fired and the plugin sits idle.

## Setup

1. Install via the Slate admin (`Plugins → Upload ZIP`).
2. Visit `Admin → Shipping rates` (granted to roles with the
   `shipping.manage` permission, or super admins).
3. Click **New tier** for each weight bracket. Typical first store:
   - `0–8 oz   → $4.50`  (small flat envelope)
   - `8–32 oz  → $8.00`  (small box)
   - `32+ oz   → $15.00` (overflow, leave max blank)
4. Make sure your products have weights set (Shop → Products → Edit →
   Weight). Products with no weight are treated as 0.

## Resolution order (most important → least)

1. **Free-shipping threshold** in Shop → Settings. If the cart subtotal
   meets it, shipping is $0 and nothing else runs.
2. **The first matching active tier** in this plugin's list. Tiers are
   ordered by `display_order` then `min_weight`. Range is
   `min ≤ weight < max` (or `min ≤ weight` if max is blank).
3. **Legacy flat-rate setting** in Shop → Settings. Used when no tier
   matches — e.g., a heavy cart with no overflow tier. This is a safety
   net so the store doesn't break if you forget to configure a tier.

## Weight unit

Whatever Shop → Settings says (`oz` by default). The plugin uses the
same unit; no conversion happens. If you switch units mid-life, you'll
need to re-enter every tier with the new numbers — there's no automatic
conversion. (We could add one; tell me if you want it.)

## What this plugin does NOT do

- **Live USPS / UPS / FedEx rate quotes.** Those need a separate plugin
  per carrier with API credentials.
- **Per-country / per-state shipping.** Right now every tier applies
  globally. The hook already passes the billing address to plugins, so
  this is a future-version concern.
- **Per-product shipping classes.** Some products ship from different
  warehouses or have different rate structures. Not modeled here.
- **Display the shipping method label to the customer.** Customers see
  the computed dollar amount only, not the tier name. The tier label is
  internal-only.

## How to extend

The Shop plugin's filter hook is:

```php
Hook::applyFilters('shop_shipping_rate', $current, $context);
```

Where `$context = ['subtotal' => float, 'items' => array, 'billing' => array]`.
Return either a float (the rate in the store's currency) or null (no
opinion, let the next plugin or the legacy default decide). A USPS plugin
would inspect `$context['billing']['postcode']` and call the USPS API to
get a real rate.

This plugin uses priority 10 (the default). If you ever install another
shipping plugin, the one registered FIRST wins (the filter chain short-
circuits on a non-null return). To override, register your competing
plugin's filter at a lower priority number.

## Uninstalling

Drops the `flatrateshipping_tiers` table. Your tier configuration is
gone. Shop reverts to its legacy `shop.flat_shipping_rate` setting for
all subsequent checkouts.
