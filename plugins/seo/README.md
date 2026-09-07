# SEO (starter)

Per-page SEO for the Slate platform, built as a worked example of integrating
with **Content Builder** through its hooks — with no hard dependency.

## What it does

- Adds an **SEO** card to every Content Builder page/post editor: meta title,
  meta description, canonical URL, social (OG) image, and a noindex toggle.
- Stores those per-page values as **post meta** in Content Builder's shared
  `contentbuilder_post_meta` table (no duplicate join table).
- Injects `<title>`, `<meta name="description">`, canonical, robots, and Open
  Graph / Twitter tags into the rendered page `<head>` automatically.
- Provides a settings page for site-wide defaults (site name, title suffix,
  fallback description).

## How the integration works

All wiring is in `SEO.php::boot()` and uses three Content Builder hooks:

| Hook | Type | What SEO does |
|---|---|---|
| `content_edit_sidebar` | action | Prints the SEO fields card in the editor |
| `content_save_post` | action | Saves those fields via `ContentBuilderAPI::setMeta()` |
| `content_head_tags` | filter | Returns the `<title>`/`<meta>` markup for the page head |

Each handler first checks `class_exists('ContentBuilderAPI')`, so if Content
Builder is inactive the SEO plugin stays installed and inert — the settings
page still works; the per-page fields and rendered tags simply don't appear
until Content Builder is enabled.

## Install

Zip the `seo/` folder, upload on **Dashboard → Plugins**, activate. Install
Content Builder too for the per-page features.

## Public API

```php
SEOAPI::renderHeadTags($post);          // <head> markup for a post array
SEOAPI::getSetting('site_name');        // site-wide default
SEOAPI::setSetting('site_name', 'X');
SEOAPI::allSettings();
```

## Use this as a template

To build the **Forms**, **Stripe**, or **Media** integration, copy the pattern:
register a block in `content_register_blocks` with a `render` callback (for
something users place on a page), or use the sidebar/save/head hooks (for
per-page data like SEO). See Content Builder's README for the block example.
