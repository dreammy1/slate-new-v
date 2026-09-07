<?php
/**
 * SEO — Slate plugin bootstrap.
 *
 * A worked example of integrating with Content Builder through its three
 * extension points, with NO hard dependency: every hook checks that the
 * Content Builder API exists first, so SEO stays inert (but installable) if
 * Content Builder is absent or deactivated.
 *
 *   content_edit_sidebar  → add SEO fields to the post editor
 *   content_save_post     → persist them as post meta
 *   content_head_tags     → emit <title>/<meta> on the rendered page
 */

class Seo extends Plugin {

    /* Meta keys we store on each post (via ContentBuilderAPI meta store). */
    const META = ['seo_title', 'seo_description', 'seo_canonical', 'seo_og_image', 'seo_noindex'];

    public function boot(): void {
        $api = $this->dir('SEOAPI.php');
        if (file_exists($api)) require_once $api;

        // Admin: a settings page for site-wide defaults.
        Hook::addFilter('admin_nav_items', [$this, 'addAdminNav']);

        // --- Content Builder integration (all optional) ---
        Hook::addAction('content_edit_sidebar', [$this, 'renderSidebar']);
        Hook::addAction('content_save_post', [$this, 'saveMeta'], 10, 2);
        Hook::addFilter('content_head_tags', [$this, 'headTags'], 10, 2);
    }

    public function addAdminNav(array $items): array {
        $items[] = [
            'slug'  => 'seo-settings',
            'label' => 'SEO',
            'href'  => $this->url('admin/index.php'),
            'icon'  => 'search',
            'perm'  => 'seo.manage',
            'order' => 600,
        ];
        return $items;
    }

    /** Inject the SEO card into the Content Builder post editor sidebar. */
    public function renderSidebar($post): void {
        if (!class_exists('ContentBuilderAPI')) return;
        $postId = (int)($post['id'] ?? 0);
        $m = $postId ? ContentBuilderAPI::getAllMeta($postId) : [];

        $title  = $m['seo_title']       ?? '';
        $desc   = $m['seo_description']  ?? '';
        $canon  = $m['seo_canonical']    ?? '';
        $ogimg  = $m['seo_og_image']     ?? '';
        $noidx  = !empty($m['seo_noindex']);
        ?>
        <div class="card">
            <div class="card-header"><h2>SEO</h2></div>
            <div class="field">
                <label class="field-label" for="seo_title">Meta title</label>
                <input type="text" id="seo_title" name="seo_title" maxlength="180"
                       class="field-input" value="<?= e($title) ?>"
                       placeholder="Defaults to the post title">
            </div>
            <div class="field">
                <label class="field-label" for="seo_description">Meta description</label>
                <textarea id="seo_description" name="seo_description" rows="3"
                          maxlength="320" class="field-input"
                          placeholder="~155 chars"><?= e($desc) ?></textarea>
            </div>
            <div class="field">
                <label class="field-label" for="seo_canonical">Canonical URL</label>
                <input type="text" id="seo_canonical" name="seo_canonical"
                       class="field-input" value="<?= e($canon) ?>"
                       placeholder="https://… (optional)">
            </div>
            <div class="field">
                <label class="field-label" for="seo_og_image">Social image URL</label>
                <input type="text" id="seo_og_image" name="seo_og_image"
                       class="field-input" value="<?= e($ogimg) ?>"
                       placeholder="og:image (optional)">
                <?php if (PluginLoader::isActive('media-library')): ?>
                    <button type="button" class="btn btn-sm mt-2" id="seo-ogimg-pick">Choose from library</button>
                    <script>
                    (function(){
                        var btn=document.getElementById('seo-ogimg-pick');
                        if(!btn) return;
                        btn.addEventListener('click',function(){
                            if(!window.MediaPicker||!window.MediaPicker.open){ alert('Media library is still loading — try again in a moment.'); return; }
                            window.MediaPicker.open({mode:'single',onPick:function(path){
                                document.getElementById('seo_og_image').value=path;
                            }});
                        });
                    })();
                    </script>
                <?php endif; ?>
            </div>
            <div class="field">
                <label class="cb-term">
                    <input type="checkbox" name="seo_noindex" value="1" <?= $noidx ? 'checked' : '' ?>>
                    Discourage search engines (noindex)
                </label>
            </div>
        </div>
        <?php
    }

    /** Persist SEO fields after the post is saved. */
    public function saveMeta($postId, $postData): void {
        if (!class_exists('ContentBuilderAPI')) return;
        $postId = (int)$postId;

        ContentBuilderAPI::setMeta($postId, 'seo_title',       trim($postData['seo_title'] ?? ''));
        ContentBuilderAPI::setMeta($postId, 'seo_description', trim($postData['seo_description'] ?? ''));
        ContentBuilderAPI::setMeta($postId, 'seo_canonical',   trim($postData['seo_canonical'] ?? ''));
        ContentBuilderAPI::setMeta($postId, 'seo_og_image',    trim($postData['seo_og_image'] ?? ''));
        ContentBuilderAPI::setMeta($postId, 'seo_noindex',     empty($postData['seo_noindex']) ? '' : '1');
    }

    /** Build the <head> tags for the rendered page. */
    public function headTags(string $tags, $post): string {
        if (!is_array($post)) return $tags;
        return SEOAPI::renderHeadTags($post, $tags);
    }
}
