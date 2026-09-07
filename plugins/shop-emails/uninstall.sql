-- shop-emails plugin uninstall.
--
-- Remove the settings rows that this plugin wrote. We use LIKE with
-- the plugin's prefix so any future template additions also get
-- cleaned up without needing to maintain a key list here.
--
-- This is a write to the `settings` table, NOT an ALTER, so it's
-- allowed by PluginLoader's SQL validator.
DELETE FROM settings WHERE setting_key LIKE 'shop_email.%';
