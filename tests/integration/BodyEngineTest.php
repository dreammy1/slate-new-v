<?php
/**
 * D0 — which engine rendered the body is observable.
 *
 * The document path reports `served` by returning it. The body path could not:
 * renderLayoutForPublic() returns the HTML and has callers, so the engine is
 * recorded instead. Until it was, the body parity gate had exactly the blind
 * spot the document gate used to have — it serves legacy on divergence, so the
 * page looks right either way and "the block rendered" could not be told apart
 * from "the block rendered THROUGH THE CORE PATH".
 *
 * That distinction is the prerequisite for Phase D. Converting a feature plugin
 * into a real block means new markup on the core path; if that markup diverges
 * from the legacy renderer by so much as a byte, every page containing the block
 * is silently demoted to legacy. Without this, the conversion would appear to
 * succeed while quietly turning the render cutover off.
 */

declare(strict_types=1);

unit('a normal page body is rendered by the core engine', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    ContentCoreBridge::resetBodyEngine();
    assert_true(ContentCoreBridge::lastBodyEngine() === null, 'nothing recorded before a render');

    $html = ContentCoreBridge::renderLayoutForPublic([
        ['type' => 'paragraph', 'props' => ['text' => 'Ordinary copy.']],
    ]);

    assert_true(str_contains($html, 'Ordinary copy.'), 'the body rendered');
    assert_eq(
        'core',
        ContentCoreBridge::lastBodyEngine(),
        'the core renderer produced it. "legacy" means the parity gate diverted the '
        . 'page — the output still looks correct, which is precisely why this has to '
        . 'be asserted rather than inferred from the markup'
    );
});

unit('a block the core registry does not know demotes the page to legacy, visibly', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    // The Phase D failure mode in miniature: a block present in the document but
    // not in the registry the core path renders from. The two renderers disagree,
    // parity trips, and legacy is served — correctly, and silently. The engine
    // marker is what makes that silence audible.
    ContentCoreBridge::resetBodyEngine();
    ContentCoreBridge::renderLayoutForPublic([
        ['type' => 'not-a-registered-block-type', 'props' => []],
    ]);

    assert_eq(
        'legacy',
        ContentCoreBridge::lastBodyEngine(),
        'divergence is reported as a legacy render rather than passing unnoticed'
    );
});

unit('render_engine=legacy is reported as legacy', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $prior = ContentBuilderAPI::getSiteSetting('render_engine', 'core');
    try {
        ContentBuilderAPI::setSiteSetting('render_engine', 'legacy');
        ContentCoreBridge::resetBodyEngine();
        ContentCoreBridge::renderLayoutForPublic([['type' => 'paragraph', 'props' => ['text' => 'x']]]);
        assert_eq('legacy', ContentCoreBridge::lastBodyEngine(), 'the kill-switch is reported honestly');
    } finally {
        ContentBuilderAPI::setSiteSetting('render_engine', (string) $prior);
        ContentCoreBridge::resetBodyEngine();
    }
});
