<?php
/**
 * Slate — the public site's document frame, as a Template (Phase C exit).
 *
 * The body cutover already happened: router.php renders page content through the
 * core renderer via renderLayoutForPublic(), parity-gated. The FRAME never moved
 * — Theme::renderPage() still produced the document, so PageAssembler,
 * TemplateResolver and Template had no production caller at all. They were
 * complete, tested, and unreached: the same shape as RenderContext::withTheme()
 * and content_resolve_media_key before them.
 *
 * This gives them one. Everything downstream — Phase D blocks, Phase E tokens,
 * eventually a second renderer — then flows through one assembly path instead of
 * a legacy call the spine cannot see.
 *
 * It DELEGATES to Theme::renderPage rather than reimplementing the frame, so the
 * output is byte-identical by construction rather than by careful copying. That
 * is deliberate and it is the point: this milestone changes the routing, not the
 * markup. Converging the public markup onto the spine's semantics (a real
 * <main class="slate-content">, the width system as tokens) is a visible change
 * and belongs in its own reviewed commit — bundled here, a regression could not
 * be attributed to either the routing or the restyle.
 *
 * DocumentTemplate is NOT this. It emits no body class, wraps content in its own
 * <main>, has no `content_footer` region and emits its own token block — routing
 * public pages through it would produce two <style id="slate-tokens"> elements
 * with the later winning. It stays the minimal frame for fragments and preview.
 */

declare(strict_types=1);

use Slate\Presentation\RenderContext;
use Slate\Presentation\Templates\RegionContent;
use Slate\Presentation\Templates\Template;

final class ContentSiteTemplate implements Template
{
    /**
     * @param array|null $post the post row, for the per-page width override and
     *                         the content_footer filter. Theme::renderPage reads
     *                         both from it, and neither is expressible as a
     *                         region, so it is constructor state rather than
     *                         something squeezed into RegionContent.
     */
    public function __construct(private readonly ?array $post = null)
    {
    }

    public function name(): string
    {
        return 'site';
    }

    /**
     * Only the two regions the caller supplies. Theme builds its own header and
     * footer from site settings, so claiming them here would advertise a seam
     * that does not exist yet.
     */
    public function regions(): array
    {
        return ['head', 'content'];
    }

    public function render(RegionContent $regions, RenderContext $ctx): string
    {
        return Theme::renderPage($regions->get('head'), $regions->get('content'), $this->post);
    }
}
