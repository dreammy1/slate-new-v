# Content Builder

Pages, posts, custom post types, taxonomies, and a block-based page builder
for the Slate platform — built to be **extended by your other plugins**.

## Install

Zip the `content-builder/` folder and upload it on **Dashboard → Plugins →
Upload a plugin**, then activate. Pages and Posts appear in the sidebar.

## Front-end routing

The resolver lives at `public/render.php`. Point pretty URLs at it with an
`.htaccess` rule at your web root:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
# /<type>/<slug>  → posts & custom post types
RewriteRule ^([a-z0-9_-]+)/([a-z0-9_-]+)/?$  slate/plugins/content-builder/public/render.php?type=$1&slug=$2 [QSA,L]
# /<slug>         → pages
RewriteRule ^([a-z0-9_-]+)/?$                slate/plugins/content-builder/public/render.php?type=page&slug=$1 [QSA,L]
```

Adjust the `slate/` path segment to match where your install lives. If you have
a public theme, edit the bottom of `render.php` to hand `$headTags` and
`$bodyHtml` to it instead of the built-in shell.

---

## Extending the builder from another plugin

Content Builder exposes **three extension points**. Your Forms, Stripe, Media,
and SEO plugins use these instead of touching its tables.

### 1. Register a block — `content_register_blocks` (action)

Add a block to the builder palette. Use a `render` callback (not a template).
Register it in your plugin's `boot()`:

```php
Hook::addAction('content_register_blocks', function ($registry) {
    if (!class_exists('FormsAPI')) return;          // degrade gracefully
    $registry::register('contact-form', [
        'label'  => 'Contact Form',
        'group'  => 'Forms',
        'fields' => [
            ['key' => 'formId', 'type' => 'select', 'label' => 'Form',
             'options' => array_map(function ($f) {
                 return ['v' => (string)$f['id'], 'l' => $f['name']];
             }, FormsAPI::listForms())],
        ],
        'defaults' => ['formId' => ''],
        'render'   => function (array $props) {
            $id = (int)($props['formId'] ?? 0);
            return $id ? FormsAPI::renderForm($id) : '';
        },
    ]);
});
```

The block now appears in the palette **only while your plugin is active**.
At render time the builder calls your `render` callback with the saved props —
your plugin owns the markup, validation, and submission endpoint entirely.

The same shape covers a Stripe "Buy button" block (`render` calls
`StripeAPI::checkoutButton($props)`) or a Media "Gallery" block.

### 2. Add editor sidebar fields — `content_edit_sidebar` (action)

Receives the `$post` array. Print a `.card`; name your inputs so they arrive in
`$_POST`. See the bundled SEO plugin for a complete example.

```php
Hook::addAction('content_edit_sidebar', function ($post) {
    $val = ContentBuilderAPI::getMeta($post['id'], 'my_key');
    echo '<div class="card"><div class="card-header"><h2>My Plugin</h2></div>';
    echo '<div class="field"><input name="my_key" value="' . e($val) . '"></div></div>';
});
```

### 3. Persist sidebar data — `content_save_post` (action)

Fires after a post is saved, with `($postId, $_POST)`. Store your fields via the
generic meta API — no extra tables needed:

```php
Hook::addAction('content_save_post', function ($postId, $post) {
    ContentBuilderAPI::setMeta($postId, 'my_key', trim($post['my_key'] ?? ''));
}, 10, 2);
```

### 4. Inject into the rendered page — `content_head_tags` / `content_footer` (filters)

`content_head_tags` receives the current `$tags` string and the `$post`; return
the augmented string. Used by the SEO plugin to emit `<title>` and `<meta>`.

```php
Hook::addFilter('content_head_tags', function ($tags, $post) {
    return $tags . '<meta name="generator" content="Slate">';
}, 10, 2);
```

---

## Public API (for other plugins)

Call `ContentBuilderAPI::*`; never read `contentbuilder_*` tables directly.

| Method | Purpose |
|---|---|
| `getPost($id)` / `getPostBySlug($type,$slug)` | Fetch one (layout decoded to array) |
| `listPosts($type, $args)` | `status, limit, offset, term_id, orderby` |
| `savePost($data)` | Create/update; returns id; handles unique slug |
| `publish($id)` / `trash($id)` / `deletePost($id)` | Status changes |
| `getMeta/$setMeta/$getAllMeta/$deleteMeta` | Generic per-post key/value |
| `registerPostType($def)` / `getPostTypes()` | CPTs |
| `registerTaxonomy($def)` / `getTaxonomies($postType)` | Taxonomies |
| `addTerm($tax,$name)` / `getTerms($tax)` / `setPostTerms($id,$ids)` | Terms |
| `renderLayout($layout)` | Layout (JSON or array) → HTML |

Always guard cross-plugin calls:

```php
if (PluginLoader::isActive('content-builder') && class_exists('ContentBuilderAPI')) {
    $page = ContentBuilderAPI::getPostBySlug('page', 'home');
}
```

## Notes & next steps

- The `html` block is gated behind `content.publish` (it renders raw markup).
- The editor uses up/down reordering for reliability; swap in SortableJS if you
  want true drag-and-drop — only `builder.js` changes.
- No revisions table in v1; add `contentbuilder_revisions` if you need history.
- Slug collisions are auto-resolved (`about`, `about-2`, …).
