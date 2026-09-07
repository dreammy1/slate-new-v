<?php
/**
 * ContentCoreBridge — Phase 3A B5 bridge between content-builder and the core
 * Slate\Presentation content spine.
 *
 * Two jobs, both purely additive (the live editor and public render path are
 * untouched):
 *
 *   1. REVISION SNAPSHOTS. On every save/publish, append an immutable snapshot of
 *      the post's document to content_revisions (via RevisionStore). Fail-soft:
 *      if the core migration hasn't run (table absent) or anything errors, the
 *      save is never affected.
 *
 *   2. OPT-IN CORE RENDER + PARITY GATE. Expose a path that renders a stored
 *      layout through the core PageRenderer, and a check that it is BYTE-IDENTICAL
 *      to content-builder's legacy Renderer::render(). This proves the core
 *      renderer is at parity on real pages before any future cutover (the Phase-2A
 *      dual-write/parity pattern), without switching production onto it here.
 *
 * Parity is guaranteed by construction: each core Block is a thin adapter that
 * DELEGATES to the legacy \Renderer::renderBlock() for its type, and the adapters
 * carry NO defaults so the core renderer passes props through verbatim. A legacy
 * layout normalizes to one default-layout implicit Section, which the core
 * renderer emits transparently (no wrapper) — so the core output equals the
 * legacy per-block concatenation exactly.
 *
 * Lives in the plugin (not core): it depends on the plugin's global \Renderer /
 * \BlockRegistry / \ContentBuilderAPI, and core must never depend on a plugin.
 */

declare(strict_types=1);

use Slate\Presentation\DocumentSchema;
use Slate\Presentation\FieldSchema;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Rendering\CallbackBlock;
use Slate\Presentation\Rendering\InMemoryBlockRegistry;
use Slate\Presentation\Rendering\PageAssembler;
use Slate\Presentation\Rendering\PageRenderer;
use Slate\Presentation\Theme\Theme;
use Slate\Presentation\Theme\TenantThemeResolver;
use Slate\Presentation\Templates\RegionContent;
use Slate\Presentation\Templates\TemplateResolver;
use Slate\Presentation\Tokens\TokenEmitter;
use Slate\Services\Content\RevisionStore;
use Slate\Tenancy\TenantContext;

final class ContentCoreBridge
{
    /** owner_type used for content-builder posts in content_revisions. */
    public const OWNER_TYPE = 'content-builder:post';

    private static ?bool $revisionsTable = null;

    /**
     * Which engine produced the last body render: 'core', 'legacy', or null if
     * none has run this request (a 404, or a full_html page, never renders one).
     *
     * The document path reports this by returning it; the body path cannot,
     * because renderLayoutForPublic() returns the HTML itself and has callers.
     * So it is recorded here instead. Without it the body parity gate is exactly
     * the blind spot the document gate used to be: it silently serves legacy on
     * divergence, so "the block rendered" and "the block rendered THROUGH THE
     * CORE PATH" are indistinguishable from the output. Converting a feature
     * plugin to a real block without this would be unverifiable — a block whose
     * markup diverges would quietly demote every page containing it.
     */
    private static ?string $bodyEngine = null;

    /** Wire the hooks. Called from ContentBuilder::boot() after blocks register. */
    public static function register(): void
    {
        Hook::addAction('content_post_saved', [self::class, 'onPostSaved']);
        Hook::addFilter('content_head_tags', [self::class, 'injectHeadTokens']);
        Hook::addAction('admin_head', [self::class, 'injectAdminHead']);
    }

    /**
     * Inject the --slate-* design-token vocabulary into a content page's <head>
     * (Phase 3B, item 2). Themed by the tenant's brand accent. Emitted WITHOUT the
     * `color-scheme` declaration, so on existing pages — which do not yet consume
     * the tokens — this is inert (defines unused custom properties only), a safe
     * prerequisite for blocks/Components adopting them in 3C.
     *
     * Kill-switch: site setting `inject_slate_tokens` = anything but 'on' disables it.
     * Fail-soft: never breaks head assembly.
     */
    public static function injectHeadTokens($headTags, $post = null): string
    {
        $headTags = (string) $headTags;
        if ((string) ContentBuilderAPI::getSiteSetting('inject_slate_tokens', 'on') !== 'on') {
            return $headTags;
        }
        try {
            return $headTags . self::tokenHeadCss() . self::bridgeCss();
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge token injection failed: ' . $e->getMessage(), 'warning');
            }
            return $headTags;
        }
    }

    /**
     * Emit the --slate-* vocabulary + consolidation bridge into the ADMIN head
     * (admin_head fires after slate_brand_accent_emit, so the bridge alias wins the
     * cascade). Gated by `token_bridge` = 'on' so admin pages carry nothing extra
     * until consolidation is enabled. Value-preserving: admin `--accent` already
     * resolves to brand_accent_color, which is what `--slate-color-accent` resolves
     * to. Fail-soft.
     */
    public static function injectAdminHead(): void
    {
        if ((string) ContentBuilderAPI::getSiteSetting('token_bridge', 'off') !== 'on') {
            return;
        }
        try {
            echo self::tokenHeadCss() . self::bridgeCss();
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge admin head injection failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    /**
     * The tenant's Theme, from the one stored brand input.
     *
     * This is the seam Presentation cannot build for itself: TenantThemeResolver
     * is PURE by contract ("Presentation must not read the database"), so some
     * adapter has to read `brand_accent_color` and hand the hex in. Doing it in
     * one place means the head token block and the RenderContext carry the SAME
     * theme — before this, the head was themed while every RenderContext was
     * built unthemed, so anything rendering through DocumentTemplate would have
     * emitted default tokens under a branded head.
     *
     * Fail-soft: no Database (CLI, tests) → null accent → DefaultTheme.
     */
    private static function siteTheme(): Theme
    {
        // The ACCENT still comes from the core brand_accent_color, not from
        // Branding's accent_color. The two are separate settings and are known
        // to drift, so switching the source here would change the accent on any
        // site where they disagree — a behaviour change this step does not own.
        $accent = class_exists('Database') ? (string) Database::setting('brand_accent_color') : '';

        // Everything ELSE — ink, muted, surface, surface-2, canvas, the font
        // families, the radii — comes from Branding, which is what feeds the
        // tenant-facing --cb-* vocabulary. Without this, --slate-color-text sits
        // at neutral-900 while --cb-ink is whatever the tenant chose, and Phase
        // E's renames would change colour on every branded site.
        $brand = class_exists('Branding') ? Branding::resolve() : [];

        // One accent, in both directions. The core setting wins when it is set —
        // the owner's decision — and when it is NOT set the slate tokens follow
        // Branding's accent rather than falling back to the default blue. Only
        // feeding one direction would leave the two divergent on every install
        // that has never set a core brand colour, which is the majority.
        $brand['accent'] = $accent !== '' ? $accent : (string) ($brand['accent'] ?? '');

        // Manual override for the accent's on-fill text color (Settings ->
        // Branding -> Button text color). TenantThemeResolver is PURE and
        // cannot read Database itself, so it arrives as a plain array key —
        // same shape as every other brand value already threaded through here.
        $mode = class_exists('Database') ? strtolower(trim((string) Database::setting('brand_button_text_mode'))) : '';
        $brand['onAccentOverride'] = ($mode === 'light' || $mode === 'dark') ? $mode : null;

        return TenantThemeResolver::fromBrand($brand);
    }

    /**
     * The default RenderContext for this install: the tenant, carrying the
     * tenant Theme.
     *
     * Named rather than inlined into render()'s `??=` so the seam is observable.
     * Its absence was invisible precisely because nothing could see it — a
     * context built without a Theme renders identically at body level, and the
     * difference only surfaces in the envelope's <head>. A seam that cannot be
     * asserted on is a seam that silently stops working.
     */
    public static function defaultContext(): RenderContext
    {
        return RenderContext::for(self::tenantId())->withTheme(self::siteTheme());
    }

    /** The themed --slate-* token block (no color-scheme), shared by public + admin. */
    private static function tokenHeadCss(): string
    {
        return TokenEmitter::css(self::siteTheme()->tokens(), true, false);
    }

    /**
     * Token-consolidation bridge, slice 1 — ACCENT (docs/09-Roadmap/
     * phase3-consolidation-design.md). Aliases the legacy `--accent`/`--on-accent`
     * to the single `--slate-*` source so both share one value. VALUE-PRESERVING:
     * both already resolve to `brand_accent_color`, so this changes the source of
     * truth, not the rendered color, regardless of cascade order.
     *
     * OFF by default (site setting `token_bridge` must equal 'on'); dormant until a
     * reviewed live verification flips it. Emitted after the token block so the
     * `--slate-*` values it references are defined.
     */
    public static function bridgeCss(): string
    {
        if ((string) ContentBuilderAPI::getSiteSetting('token_bridge', 'off') !== 'on') {
            return '';
        }
        return '<style id="slate-token-bridge">:root{'
            . '--accent:var(--slate-color-accent);'
            . '--on-accent:var(--slate-color-on-accent);'
            . '}</style>';
    }

    // ── 1. Revision snapshots ─────────────────────────────────

    /** Listener for the 'content_post_saved' action (positional: $postId). */
    public static function onPostSaved($postId, $data = null): void
    {
        self::snapshotPost((int) $postId);
    }

    /**
     * Append a revision snapshot of a post's current document. Fail-soft — never
     * throws into a save. Draft/any status → 'working'; published → 'published';
     * trashed posts are skipped.
     */
    public static function snapshotPost(int $postId): void
    {
        if ($postId <= 0 || !self::revisionsAvailable()) {
            return;
        }
        try {
            if (!class_exists('ContentBuilderAPI')) {
                return;
            }
            $post = ContentBuilderAPI::getPost($postId);
            if (!$post) {
                return;
            }
            $status = (string) ($post['status'] ?? 'draft');
            if ($status === 'trash') {
                return;
            }

            $env = DocumentSchema::normalize($post['layout'] ?? [], (string) ($post['type'] ?? 'page'));

            (new RevisionStore(new TenantContext()))->snapshot(
                self::OWNER_TYPE,
                $postId,
                $env,
                $status === 'published' ? RevisionStore::STATUS_PUBLISHED : RevisionStore::STATUS_WORKING,
                self::currentUserId(),
                null,
                (int) $env['schema'],
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge snapshot failed: ' . $e->getMessage(), 'warning');
            }
        }
    }

    // ── 2. Core render + parity gate ──────────────────────────

    /**
     * Build a core BlockRegistry whose blocks delegate to the legacy renderer.
     * Rebuilt per call (cheap; ~20 blocks) so newly-registered blocks are picked
     * up. Adapters carry the legacy fields but NO defaults, so the core renderer
     * passes props through verbatim (exact parity).
     */
    public static function coreRegistry(): InMemoryBlockRegistry
    {
        $registry = new InMemoryBlockRegistry();
        if (!class_exists('BlockRegistry') || !class_exists('Renderer')) {
            return $registry;
        }
        foreach (BlockRegistry::all() as $type => $def) {
            $fields = $def['fields'] ?? [];
            $registry->register(new CallbackBlock(
                (string) $type,
                FieldSchema::of($fields, []),                 // no defaults → verbatim props
                static fn (array $props, RenderContext $ctx): string =>
                    Renderer::renderBlock(['type' => (string) $type, 'props' => $props])
            ));
        }
        return $registry;
    }

    /** Render a stored layout through the core PageRenderer. */
    public static function render($layout, ?RenderContext $ctx = null): string
    {
        // Body rendering never reads $ctx->theme — PageRenderer and the blocks
        // do not touch it — so carrying the Theme here is appearance-neutral for
        // the checked-in body goldens, which is asserted by their reproducing
        // byte-for-byte. It matters for the envelope: DocumentTemplate reads
        // $ctx->theme, and until this seam existed always found null.
        $ctx ??= self::defaultContext();
        $page = DocumentSchema::toPage($layout, 'page');
        return (new PageRenderer(self::coreRegistry()))->renderPage($page, $ctx);
    }

    /**
     * True when the core renderer's output for $layout is byte-identical to the
     * legacy Renderer::render(). The parity gate.
     */
    /**
     * Assemble the full public document through the core template layer.
     *
     * Returns ['html' => …, 'served' => 'core'|'legacy'].
     *
     * `served` is the point of this method. The parity gate below falls back to
     * Theme::renderPage on any divergence, so the served HTML looks correct
     * whether or not the core path ran — a test asserting on the markup passes
     * while the seam is dead. That is the failure this cutover exists to end,
     * one level up. Reporting which engine served makes "the renderer is
     * actually invoked" assertable, in CI and on a live URL.
     *
     * Byte-parity is structural: ContentSiteTemplate delegates to
     * Theme::renderPage, so the FRAME cannot drift. The comparison still earns
     * its place by guarding the ASSEMBLY — a template that fails to resolve, a
     * context wrongly marked fragment, or a page whose `template` override names
     * something unregistered each produce wrong output through a correct frame.
     *
     * Kill-switch: site setting `document_engine` != 'core' serves legacy.
     */
    public static function renderDocumentForPublic(array $post, string $headTags, string $bodyHtml): array
    {
        // \Theme, explicitly: this file imports Slate\Presentation\Theme\Theme
        // (the token-carrying interface) for siteTheme()'s return type, so an
        // unqualified `Theme` here resolves to that and not to content-builder's
        // global Theme class. Two different things legitimately named Theme.
        $legacy = \Theme::renderPage($headTags, $bodyHtml, $post);

        if ((string) ContentBuilderAPI::getSiteSetting('document_engine', 'core') !== 'core') {
            return ['html' => $legacy, 'served' => 'legacy'];
        }

        try {
            $assembler = new PageAssembler(
                new ContentPrecomposedBody($bodyHtml),
                (new TemplateResolver())
                    ->register(new ContentSiteTemplate($post))
                    ->setFallback('site')
            );

            $core = $assembler->assemble(
                DocumentSchema::toPage($post['layout'] ?? [], (string) ($post['type'] ?? 'page')),
                self::defaultContext(),
                (new RegionContent())->with('head', $headTags)
            );
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge document assembly failed, using legacy: ' . $e->getMessage(), 'warning');
            }
            return ['html' => $legacy, 'served' => 'legacy'];
        }

        if ($core !== $legacy) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge document assembly diverged from legacy; served legacy for safety', 'warning');
            }
            return ['html' => $legacy, 'served' => 'legacy'];
        }

        return ['html' => $core, 'served' => 'core'];
    }

    public static function parityHolds($layout): bool
    {
        return self::render($layout) === self::legacyRender($layout);
    }

    /** The legacy render of a layout (accepts a JSON string or an array). */
    public static function legacyRender($layout): string
    {
        if (is_string($layout)) {
            $layout = json_decode($layout, true);
        }
        return class_exists('Renderer') ? Renderer::render(is_array($layout) ? $layout : []) : '';
    }

    /**
     * The public render path (Phase 3B render cutover). Routes a page's body
     * through the core PageRenderer, but is CONSTRUCTED to never change visitor
     * output: it renders both the core and legacy paths and serves the core result
     * ONLY when it is byte-identical to legacy; on any divergence or error it serves
     * legacy and logs. So the live output is provably the same as before, while the
     * core spine is exercised in production and edge cases surface in the log.
     *
     * Kill-switch: set site setting `render_engine` = 'legacy' to bypass the core
     * path entirely (no code change).
     *
     * This is intentionally belt-and-suspenders for the first production cutover;
     * the self-check can be dropped to core-only once confidence is established.
     */
    /** Which engine produced the last body render, or null if none has run. */
    public static function lastBodyEngine(): ?string
    {
        return self::$bodyEngine;
    }

    /** Test seam: forget the recorded engine so one test cannot read another's. */
    public static function resetBodyEngine(): void
    {
        self::$bodyEngine = null;
    }

    private static function bodyServed(string $engine, string $html): string
    {
        self::$bodyEngine = $engine;
        return $html;
    }

    public static function renderLayoutForPublic($layout): string
    {
        $legacy = self::legacyRender($layout);

        $engine = (string) ContentBuilderAPI::getSiteSetting('render_engine', 'core');
        if ($engine !== 'core') {
            return self::bodyServed('legacy', $legacy);
        }

        try {
            $core = self::render($layout);
        } catch (\Throwable $e) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge public render failed, using legacy: ' . $e->getMessage(), 'warning');
            }
            return self::bodyServed('legacy', $legacy);
        }

        if ($core !== $legacy) {
            if (function_exists('slate_log')) {
                slate_log('ContentCoreBridge public render diverged from legacy; served legacy for safety', 'warning');
            }
            return self::bodyServed('legacy', $legacy);
        }

        return self::bodyServed('core', $core);
    }

    // ── internals ─────────────────────────────────────────────

    private static function revisionsAvailable(): bool
    {
        if (self::$revisionsTable !== null) {
            return self::$revisionsTable;
        }
        try {
            $found = Database::row("SHOW TABLES LIKE 'content_revisions'");
            return self::$revisionsTable = (bool) $found;
        } catch (\Throwable $e) {
            return self::$revisionsTable = false;
        }
    }

    private static function tenantId(): int
    {
        return function_exists('current_tenant_id') ? (int) current_tenant_id() : 1;
    }

    private static function currentUserId(): ?int
    {
        if (class_exists('Auth') && method_exists('Auth', 'userId')) {
            $uid = Auth::userId();
            return $uid ? (int) $uid : null;
        }
        return null;
    }
}
