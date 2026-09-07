<?php
/**
 * T1 — document to server-rendered HTML, driven by fixtures.
 *
 * The existing tests/render suite renders ADMIN PAGES and asks whether they
 * fatal. It says nothing about content, and what it covers depends on whatever
 * rows the database happens to hold — which is why its count moved from 27 to 23
 * simply by pointing at a freshly provisioned database. Coverage that varies
 * with data is not coverage.
 *
 * This renders the frozen fixtures instead, so what is exercised is fixed by the
 * repository and identical everywhere: a laptop, CI, and a provisioned worktree
 * all render the same four documents.
 *
 * The expected HTML is checked in under fixtures/documents/expected/. Asserting
 * on whole output rather than fragments is deliberate — a change to what
 * visitors see should surface as a diff someone reads, not slip past a loose
 * substring match. Regenerate with:
 *
 *     php tests/bin/render-goldens.php
 *
 * and read the diff before committing it.
 */

declare(strict_types=1);

/** Every fixture document, by name. */
/**
 * Remove this install's base prefix, so a comparison is about markup rather than
 * about where the checkout happens to be served from. Goldens are stored
 * base-neutral; that the base is applied at all is asserted separately.
 */
function _strip_install_base(string $html): string
{
    $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');
    if ($base !== '') {
        $html = str_replace(['="' . $base . '/', "('" . $base . '/'], ['="/', "('/"], $html);
    }
    $origin = rtrim((string) parse_url(SLATE_URL, PHP_URL_SCHEME), ':/') . '://'
            . (string) parse_url(SLATE_URL, PHP_URL_HOST)
            . ((int) parse_url(SLATE_URL, PHP_URL_PORT) > 0 ? ':' . (int) parse_url(SLATE_URL, PHP_URL_PORT) : '');
    return $origin !== '://' ? str_replace($origin, 'http://localhost', $html) : $html;
}

function _render_fixtures(): array
{
    $dir = dirname(__DIR__) . '/fixtures/documents';
    $out = [];
    foreach (glob($dir . '/*.json') ?: [] as $path) {
        $out[basename($path, '.json')] = (string) file_get_contents($path);
    }
    return $out;
}

unit('every fixture document renders to its checked-in HTML', function (): void {
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $expectedDir = dirname(__DIR__) . '/fixtures/documents/expected';
    $fixtures    = _render_fixtures();
    assert_true($fixtures !== [], 'the fixture set is not empty');

    foreach ($fixtures as $name => $json) {
        $goldenPath = $expectedDir . '/' . $name . '.html';
        assert_true(
            is_file($goldenPath),
            "no golden for '{$name}' — run: php tests/bin/render-goldens.php"
        );

        $actual = _strip_install_base(ContentCoreBridge::render($json));
        $golden = (string) file_get_contents($goldenPath);

        assert_eq(
            $golden,
            $actual,
            "'{$name}' renders differently than its golden. If the change is "
            . "intended, regenerate with php tests/bin/render-goldens.php and "
            . "review the diff — it is a change to what visitors see."
        );
    }
});

unit('the core renderer is byte-identical to the legacy renderer', function (): void {
    // Zero appearance change is T1's hard requirement. The core path renders a
    // document through PageRenderer while the legacy path concatenates blocks
    // directly; both must produce the same bytes or the cutover is not neutral.
    if (!class_exists('ContentCoreBridge') || !class_exists('Renderer')) {
        assert_true(true, 'content-builder inactive'); return;
    }

    foreach (_render_fixtures() as $name => $json) {
        $decoded = json_decode($json, true);

        // The legacy renderer takes a flat block list; derive it from whichever
        // shape the fixture is stored in.
        $blocks = array_is_list($decoded ?? [])
            ? $decoded
            : array_merge(...array_map(
                static fn (array $s): array => $s['blocks'] ?? [],
                $decoded['sections'] ?? []
            ) ?: [[]]);

        assert_eq(
            Renderer::render($blocks),
            ContentCoreBridge::render($json),
            "'{$name}': core and legacy output diverged — the cutover would change the page"
        );
    }
});

unit('the two renderers disagree about unknown blocks, deliberately', function (): void {
    // Found by the parity test above, not assumed. ContentCoreBridge's docblock
    // says parity is guaranteed "by construction" because each core block
    // delegates to the legacy renderer. That holds only for blocks that ARE
    // registered: legacy returns '' for an unregistered type and drops it
    // silently, while the core renderer emits a diagnostic comment.
    //
    // An HTML comment is not an appearance change to a visitor, but it is a byte
    // difference, so the parity claim is narrower than it reads.
    //
    // T3 registered the `react` block, so escape-hatches is back in the parity
    // loop above and every shipped block agrees. This stays as a permanent
    // characterisation: the divergence is real for ANY unregistered type, and a
    // renderer that silently drops content is worth knowing about. A page
    // referencing a block from a deactivated plugin hits exactly this path.
    if (!class_exists('ContentCoreBridge') || !class_exists('Renderer')) {
        assert_true(true, 'content-builder inactive'); return;
    }

    $unknown = [['type' => 'definitely-not-registered', 'props' => []]];

    assert_eq('', Renderer::render($unknown), 'legacy drops an unknown block silently');

    $core = ContentCoreBridge::render(json_encode([
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [['id' => 's1', 'layout' => ['kind' => 'default'], 'blocks' => $unknown]],
        'seo' => [],
    ]));
    assert_true(
        str_contains($core, 'unknown block'),
        'the core renderer leaves a diagnostic comment instead — deliberate, but not byte-parity'
    );
    assert_false(
        str_contains($core, '<script'),
        'and the diagnostic never emits markup beyond a comment'
    );
});

unit('rendering the same document twice produces the same bytes', function (): void {
    // Render caching and any future diffing assume this. A block reaching for
    // time, randomness or an auto-increment would break it silently.
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    foreach (_render_fixtures() as $name => $json) {
        assert_eq(
            ContentCoreBridge::render($json),
            ContentCoreBridge::render($json),
            "'{$name}' is not deterministic across two renders"
        );
    }
});

unit('rendered output escapes text and refuses executable URL schemes', function (): void {
    // The security work merged in #6 has to survive the move onto the contract.
    // Rendering a document is the path a visitor actually takes, so it is
    // asserted here as well as at the block level.
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }

    $doc = json_encode([
        'schema' => 1, 'type' => 'page', 'template' => '',
        'sections' => [[
            'id' => 's1',
            'layout' => ['kind' => 'default'],
            'blocks' => [
                ['type' => 'heading', 'props' => ['text' => '<script>alert(1)</script>', 'level' => 2]],
                ['type' => 'button',  'props' => ['text' => 'x', 'href' => 'javascript:alert(1)']],
            ],
        ]],
        'seo' => [],
    ]);

    $html = ContentCoreBridge::render($doc);

    assert_false(str_contains($html, '<script>alert(1)</script>'), 'heading text is escaped');
    assert_true(str_contains($html, '&lt;script&gt;'), 'and escaped as entities, not stripped');
    assert_false(str_contains($html, 'javascript:'), 'an executable href never reaches the page');
});

unit('the document head carries the --slate-* token vocabulary', function (): void {
    // ADR-0008: one vocabulary, consumed by both renderers. T2 reads the same
    // tokens as CSS variables, so the SSR path has to emit them.
    if (!class_exists('ContentCoreBridge')) { assert_true(true, 'content-builder inactive'); return; }
    if (!method_exists('ContentCoreBridge', 'injectHeadTokens')) {
        assert_true(true, 'no head-token hook on this build'); return;
    }

    $head = ContentCoreBridge::injectHeadTokens('', null);
    assert_true(
        str_contains((string) $head, '--slate-'),
        'the head emits --slate-* custom properties for the renderers to consume'
    );
});

unit('a rendered image carries the install base path', function (): void {
    // The regression this exists for is invisible until it 404s every image on a
    // sub-path install: a stored '/uploads/x' must render as '/slate/uploads/x'.
    //
    // The golden comparison alone cannot catch it. Goldens regenerated on a root
    // install would agree with a JS renderer that also dropped the base, and the
    // suite would go green while live images broke. So this asserts the base
    // reaches the markup, not that two renderers agree about it.
    if (!class_exists('Renderer')) { assert_true(true, 'content-builder inactive'); return; }

    $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');
    $html = Renderer::renderBlock(['type' => 'image', 'props' => ['src' => '/uploads/probe.png', 'alt' => 'p']]);

    if ($base === '') {
        // A root install legitimately has no prefix; assert the un-prefixed form
        // rather than skipping, so the test still means something there.
        assert_true(str_contains($html, 'src="/uploads/probe.png"'), 'root install renders the bare path');
        return;
    }

    assert_true(
        str_contains($html, 'src="' . $base . '/uploads/probe.png"'),
        "an image on a sub-path install must carry the base; got: {$html}"
    );
    // slate_safe_url() runs after mediaUrl(); it must not strip what was added.
    assert_false(
        str_contains($html, 'src="/uploads/probe.png"'),
        'the base survives URL sanitisation'
    );
});

unit('the goldens are stored base-neutral, so they travel between installs', function (): void {
    // The first version of this asserted the goldens matched THIS install's
    // base, which made them install-coupled: goldens baked with '/slate' on a
    // dev checkout failed in CI, which runs at the root. A checked-in
    // expectation must not depend on where it was generated.
    //
    // Portability is the property now. That the base is applied at runtime is
    // asserted by the test above, against the live configuration — that is what
    // protects the sub-path install, not these files.
    $ctx = dirname(__DIR__) . '/fixtures/documents/expected/_context.json';
    assert_true(is_file($ctx), 'render-goldens.php records the base it used');

    $recorded = json_decode((string) file_get_contents($ctx), true)['base'] ?? null;
    assert_eq('', $recorded, 'goldens must be generated base-neutral — regenerate them');

    // And no golden may carry an install prefix.
    foreach (glob(dirname(__DIR__) . '/fixtures/documents/expected/*.html') ?: [] as $file) {
        $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');
        if ($base === '') continue;
        assert_false(
            str_contains((string) file_get_contents($file), '="' . $base . '/'),
            basename($file) . ' carries this install\'s base — regenerate the goldens'
        );
    }
});

unit('rx background images carry the install base path', function (): void {
    // rx-hero, rx-story, rx-menu, rx-gallery and rx-reviews built their CSS
    // url() values from the raw field, bypassing mediaUrl(). On a sub-path
    // install every one of those images 404d — the same class of bug as the
    // base-path regression, in a different block.
    //
    // The golden comparison cannot catch this: goldens are stored base-neutral,
    // so the prefix is stripped from both sides and the fix is invisible there.
    // This asserts against the live configuration instead, which is the only
    // place the difference is observable.
    if (!class_exists('Renderer')) { assert_true(true, 'content-builder inactive'); return; }

    $base = rtrim((string) parse_url(SLATE_URL, PHP_URL_PATH), '/');

    $cases = [
        'rx-hero'    => ['image' => '/uploads/rx/hero.jpg', 'heading' => 'h'],
        'rx-story'   => ['image' => '/uploads/rx/story.jpg', 'statBig' => '1'],
        'rx-menu'    => ['items' => [['image' => '/uploads/rx/dish.jpg', 'name' => 'd']]],
        'rx-gallery' => ['items' => [['image' => '/uploads/rx/tile.jpg']]],
        'rx-reviews' => ['items' => [['avatar' => '/uploads/rx/face.jpg', 'name' => 'n']]],
    ];

    foreach ($cases as $type => $props) {
        $html = Renderer::renderBlock(['type' => $type, 'props' => $props]);

        if ($base === '') {
            assert_true(str_contains($html, '/uploads/rx/'), "{$type} renders its image on a root install");
            continue;
        }
        assert_true(
            str_contains($html, $base . '/uploads/rx/'),
            "{$type} must prefix its background image with '{$base}'; got: {$html}"
        );
        assert_false(
            str_contains($html, "url('/uploads/rx/"),
            "{$type} still emits an unprefixed url() — it would 404 on this install"
        );
    }
});
