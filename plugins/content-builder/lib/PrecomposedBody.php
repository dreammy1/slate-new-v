<?php
/**
 * Slate — a Renderer that returns body HTML somebody else already rendered.
 *
 * PageAssembler owns the content region: it calls the renderer and overwrites
 * whatever chrome supplied. That is right in general and wrong for this step.
 * The public body is already composed upstream by router.php as
 *
 *     banner + '<main class="cb-page">' + renderLayoutForPublic(layout) + '</main>'
 *
 * and that inner call is PARITY-GATED — it renders through the core renderer,
 * compares against the legacy renderer per request, and serves legacy on any
 * divergence or error. Letting PageAssembler render the body directly would
 * discard both the wrapper and that gate, which is currently load-bearing on
 * production. A frame cutover must not quietly become a body cutover.
 *
 * So the body is handed in, and this adapter presents it as a Renderer. It is
 * also the migration seam: when the assembler should own body rendering too,
 * that is a one-line swap here rather than a rewrite of the route.
 */

declare(strict_types=1);

use Slate\Presentation\Page;
use Slate\Presentation\RenderContext;
use Slate\Presentation\Renderer;
use Slate\Presentation\Section;

final class ContentPrecomposedBody implements Renderer
{
    public function __construct(private readonly string $html)
    {
    }

    public function renderPage(Page $page, RenderContext $ctx): string
    {
        return $this->html;
    }

    /**
     * Never reached through assemble(), which only calls renderPage(). Present
     * because the contract requires it; returning '' rather than the whole body
     * so a mistaken call cannot duplicate the page.
     */
    public function renderSection(Section $section, RenderContext $ctx): string
    {
        return '';
    }

    public function renderBlock(array $block, RenderContext $ctx): string
    {
        return '';
    }
}
