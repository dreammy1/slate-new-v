-- Shop plugin teardown.
-- Drops all shop tables in reverse dependency order.

DROP TABLE IF EXISTS `shop_order_items`;
DROP TABLE IF EXISTS `shop_orders`;
DROP TABLE IF EXISTS `shop_coupons`;
DROP TABLE IF EXISTS `shop_customers`;
DROP TABLE IF EXISTS `shop_products`;
