-- Drop the boxes table when this plugin is uninstalled.
-- The shop_products.shipping_box_slug column is left alone — it's
-- on the shop plugin's table, not ours. If we dropped it, we'd be
-- modifying another plugin's schema. Admins can clear those values
-- by setting each product's "shipping box" to (none) in the shop
-- admin before uninstalling.

DROP TABLE IF EXISTS shippingflatrate_boxes;
