-- SEO plugin teardown.
-- Note: per-post SEO meta lives in contentbuilder_post_meta and is owned by
-- Content Builder; we leave it intact on uninstall.
DROP TABLE IF EXISTS `seo_settings`;
