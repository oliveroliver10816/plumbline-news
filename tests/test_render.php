<?php
declare(strict_types=1);

/**
 * tests/test_render.php — TEB\Render + assets/js/app.js + assets/css/site.css
 *
 * Four things get this build rejected, and three of them are provable here, so
 * most of this file is aimed straight at them:
 *
 *   · a brand or a domain hardcoded anywhere but config.php
 *   · an absolute URL path, which breaks the moment the ZIP lands in /teb/
 *   · an image without lazy loading, dimensions, alt or a fallback
 *   · escaping that a headline can break out of
 *
 * Everything is rendered for real — no mocks, no string fixtures of expected
 * HTML. The assertions read the markup back out and check properties of it,
 * because a fixed expected string only proves the renderer still does what it
 * did yesterday, not that what it does is right.
 */

// Xml is a real dependency of Render, not a convenience: Render reads
// Xml::BODY_MAX and Xml::TRUNCATED to decide whether a story was cut at the
// ingest cap and therefore needs the line that says so. The number lives in
// one place on purpose.
teb_require_app('Config', 'Paths', 'Feeds', 'Xml', 'Render');

use TEB\Config;
use TEB\Feeds;
use TEB\Paths;
use TEB\Render;

// ---------------------------------------------------------------------------
//  fixtures
// ---------------------------------------------------------------------------

/** $_SERVER for a request to a subdirectory install — the hard case. */
function rServer(string $uri = '/teb/', string $script = '/teb/index.php'): array
{
    return [
        'REQUEST_URI'    => $uri,
        'SCRIPT_NAME'    => $script,
        'PHP_SELF'       => $script,
        'HTTP_HOST'      => 'staging.example.test',
        'SERVER_NAME'    => 'staging.example.test',
        'REQUEST_METHOD' => 'GET',
        'HTTPS'          => 'on',
    ];
}

/**
 * Point Paths at a request and pin the rewrite decision, so no test ever makes
 * a loopback HTTP call or depends on what a previous test cached.
 */
function rInit(bool $rewrite = true, string $uri = '/teb/'): void
{
    Paths::init(rServer($uri), teb_root());
    Paths::allowProbe(false);
    Paths::forceRewrite($rewrite);
}

function rCfg(array $over = []): array
{
    $cfg = [
        'site' => [
            'name'        => 'Fixture Gazette',
            'short_name'  => 'FG',
            'domain'      => 'fixture-gazette.test',
            'tagline'     => 'Set in order, every evening.',
            'description' => 'A fixture description for the head of the document.',
            'timezone'    => 'America/New_York',
            'city'        => 'New York',
            'locale'      => 'en_US',
            'theme_color' => '#FBFAF7',
        ],
        'ads' => [
            'enabled' => false,
            'slots'   => ['leaderboard' => [970, 250], 'rail' => [300, 600], 'inline' => [728, 90]],
        ],
        'compose' => ['ticker_count' => 12],
    ];

    foreach ($over as $k => $v) {
        $cfg[$k] = is_array($v) && isset($cfg[$k]) && is_array($cfg[$k]) ? array_merge($cfg[$k], $v) : $v;
    }

    return $cfg;
}

function rRow(int $id, array $over = []): array
{
    return array_merge([
        'id'            => $id,
        'title'         => 'Headline number ' . $id,
        'url'           => 'https://news.example.test/story/' . $id,
        'summary'       => 'A feed-provided summary for story ' . $id . '.',
        'image_url'     => 'https://cdn.example.test/' . $id . '.jpg',
        'image_width'   => 1200,
        'image_height'  => 800,
        'published_at'  => (time() - 600) * 1000,
        'section'       => 'us',
        'section_label' => 'U.S.',
        'source'        => 'example-news',
        'source_name'   => 'Example News',
        'size'          => 'medium',
        'fresh'         => false,
    ], $over);
}

/** The shape TEB\Compose::home() returns, plus the optional extras Render reads. */
function rModel(array $over = []): array
{
    $ticker = [];
    for ($i = 90; $i < 102; $i++) {
        $ticker[] = rRow($i, ['fresh' => $i === 90]);
    }

    $us = [rRow(1, ['size' => 'large'])];
    for ($i = 2; $i <= 6; $i++) {
        // id 4 deliberately has no photograph: the text-only card must be
        // emitted server-side, with no <img> at all.
        $us[] = rRow($i, ['size' => 'medium', 'image_url' => $i === 4 ? null : 'https://cdn.example.test/' . $i . '.jpg']);
    }

    $intl = [];
    for ($i = 10; $i <= 15; $i++) {
        $intl[] = rRow($i, [
            'size' => $i === 10 ? 'large' : 'medium',
            'section' => 'international', 'section_label' => 'International',
            'source' => 'wire-two', 'source_name' => 'Wire Two',
        ]);
    }

    $world = [];
    for ($i = 20; $i <= 27; $i++) {
        $world[] = rRow($i, ['size' => 'small', 'section' => 'world', 'section_label' => 'World']);
    }

    $culture = [];
    for ($i = 30; $i <= 33; $i++) {
        $culture[] = rRow($i, [
            'size' => 'medium', 'section' => 'culture', 'section_label' => 'Culture',
            'source' => 'wire-three', 'source_name' => 'Wire Three',
        ]);
    }

    $model = [
        'ticker' => $ticker,
        'hero'   => [
            'lead' => rRow(50, ['size' => 'lead', 'fresh' => true, 'image_width' => 1600, 'image_height' => 900]),
            'subs' => [rRow(51), rRow(52), rRow(53, ['image_url' => null]), rRow(54)],
        ],
        'blocks' => [
            ['id' => 'us', 'label' => 'U.S.', 'href' => '/section/us', 'note' => 'the national desk',
             'grid' => 'block-grid', 'items' => $us],
            ['id' => 'international', 'label' => 'International', 'href' => '/section/international',
             'note' => 'beyond the border', 'grid' => 'block-grid block-grid--6up', 'items' => $intl],
            ['id' => 'world', 'label' => 'World', 'href' => '/section/world', 'note' => 'the world wire',
             'grid' => 'block-grid block-grid--wire', 'items' => $world],
            ['id' => 'culture', 'label' => 'Culture', 'href' => '/section/culture', 'note' => 'books, film and ideas',
             'grid' => 'block-grid', 'items' => $culture],
        ],
        'markets' => [
            rRow(70, ['size' => 'small', 'section' => 'business', 'section_label' => 'Business']),
            rRow(71, ['size' => 'small', 'section' => 'business', 'section_label' => 'Business']),
        ],
        'sources' => [
            ['slug' => 'example-news', 'name' => 'Example News'],
            ['slug' => 'wire-two', 'name' => 'Wire Two'],
        ],
    ];

    return array_merge($model, $over);
}

// ---------------------------------------------------------------------------
//  little parsers — the assertions read the markup back, they never diff it
// ---------------------------------------------------------------------------

/** @return array<int,string> every <img ...> tag in the document */
function rImgs(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $m);

    return $m[0];
}

/** @return array<int,string> every href value, still HTML-escaped */
function rHrefs(string $html): array
{
    preg_match_all('/\shref="([^"]*)"/i', $html, $m);

    return $m[1];
}

function rHasAttr(string $tag, string $name): bool
{
    return (bool) preg_match('/\s' . preg_quote($name, '/') . '="[^"]*"/i', $tag);
}

function rAttr(string $tag, string $name): string
{
    return preg_match('/\s' . preg_quote($name, '/') . '="([^"]*)"/i', $tag, $m) ? $m[1] : '';
}

/** The brand, exactly as config.php defines it — never typed out in a test. */
function rRealBrand(): string
{
    $cfg = Config::load(teb_root());

    return (string) ($cfg['site']['name'] ?? '');
}

return [

    // =====================================================================
    //  esc()
    // =====================================================================

    'esc escapes every dangerous character, in text AND in an attribute' => function (): void {
        assertSame('&amp;', Render::esc('&'), 'ampersand');
        assertSame('&lt;', Render::esc('<'), 'less-than');
        assertSame('&gt;', Render::esc('>'), 'greater-than');
        assertSame('&quot;', Render::esc('"'), 'double quote — attribute breakout');
        assertSame('&#039;', Render::esc("'"), 'single quote — attribute and inline-JS breakout');

        // Text position: no live tag survives.
        $payload = '<script>alert(1)</script>';
        $text = Render::esc($payload);
        assertNotContains('<script', $text, 'a script tag survived escaping in text position');
        assertContains('&lt;script&gt;', $text);

        // Attribute position: neither quote style can close the attribute.
        $attr = Render::esc('" onmouseover="alert(1)');
        assertNotContains('"', str_replace('&quot;', '', $attr), 'a raw double quote survived');
        $attr2 = Render::esc("' onmouseover='alert(1)");
        assertNotContains("'", str_replace('&#039;', '', $attr2), 'a raw single quote survived');

        // Invalid UTF-8 must be repaired, never silently blanked.
        $broken = Render::esc("head\xC3(line");
        assertTrue($broken !== '', 'invalid UTF-8 made esc() return an empty string — a headline would vanish');
    },

    // =====================================================================
    //  images — SPEC 0.6, the client's stated priority
    // =====================================================================

    'the home page has exactly ONE eager image and every other one is lazy' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());
        $imgs = rImgs($html);

        assertGreaterThan(4, count($imgs), 'the fixture should have produced a page full of pictures');

        $eager = 0;
        foreach ($imgs as $img) {
            $loading = rAttr($img, 'loading');
            assertTrue($loading === 'eager' || $loading === 'lazy', 'an <img> has loading="' . $loading . '": ' . $img);
            if ($loading === 'eager') {
                $eager++;
                assertSame('high', rAttr($img, 'fetchpriority'), 'the hero image must carry fetchpriority="high"');
            } else {
                assertFalse(rHasAttr($img, 'fetchpriority'), 'only the hero may set fetchpriority: ' . $img);
            }
        }
        assertSame(1, $eager, 'expected exactly one eager image on the page, found ' . $eager);
    },

    'every image carries width, height, alt, decoding, referrerpolicy and a fallback' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());
        foreach (rImgs($html) as $img) {
            assertTrue((int) rAttr($img, 'width') > 0, 'missing or zero width: ' . $img);
            assertTrue((int) rAttr($img, 'height') > 0, 'missing or zero height: ' . $img);
            assertTrue(rAttr($img, 'alt') !== '', 'empty alt on a content image: ' . $img);
            assertSame('async', rAttr($img, 'decoding'), 'decoding="async" is required: ' . $img);
            assertSame('no-referrer', rAttr($img, 'referrerpolicy'), 'hotlinked publisher images need no-referrer: ' . $img);
            assertTrue(rHasAttr($img, 'onerror'), 'no onerror fallback: ' . $img);
            assertContains('card--text', rAttr($img, 'onerror'), 'the fallback must promote the card to its no-photo state');
        }
    },

    'alt is the headline, and the stored dimensions win over the nominal box' => function (): void {
        rInit();
        $row  = rRow(7, ['title' => 'A very particular headline', 'image_width' => 1234, 'image_height' => 567]);
        $html = Render::card($row, ['size' => 'medium', 'cfg' => rCfg()]);
        $img  = rImgs($html)[0];

        assertSame('A very particular headline', rAttr($img, 'alt'));
        assertSame('1234', rAttr($img, 'width'));
        assertSame('567', rAttr($img, 'height'));

        // No stored dimensions means we do not KNOW the picture is big enough,
        // so the publisher image is not used at all and the masthead placeholder
        // is drawn instead — at its own true size. Declaring an unmeasured image
        // to be the card's nominal width is exactly how a 60x60 CBS thumbnail
        // ended up stretched across a lead slot.
        $html2 = Render::card(rRow(8, ['image_width' => 0, 'image_height' => 0]), ['size' => 'medium', 'cfg' => rCfg()]);
        $img2  = rImgs($html2)[0];
        assertContains('placeholder.svg', rAttr($img2, 'src'), 'an unmeasured image must not be gambled on');
        assertSame('1200', rAttr($img2, 'width'));
        assertSame('630', rAttr($img2, 'height'));
    },

    'a card with no usable image draws OUR placeholder, never the publisher junk' => function (): void {
        rInit();
        $cfg = rCfg();

        // Almost every newsroom on this roster licenses its words and not its
        // photographs, and The Conversation ships no picture in its feed at
        // all, so a real share of the grid had holes in it. Those cards carry
        // the masthead placeholder instead.
        foreach ([null, '', 'javascript:alert(1)', 'data:text/html,<b>x</b>'] as $bad) {
            $html = Render::card(rRow(9, ['image_url' => $bad]), ['size' => 'medium', 'cfg' => $cfg]);
            $imgs = rImgs($html);
            assertCount(1, $imgs, 'exactly one placeholder for image_url=' . var_export($bad, true));
            assertContains('placeholder.svg', rAttr($imgs[0], 'src'), 'the placeholder must be ours');
            // The hostile values must never reach the page.
            assertNotContains('javascript:', $html);
            assertNotContains('data:text/html', $html);
        }

        // …and the same page still shows the headline and the source line.
        $html = Render::card(rRow(9, ['image_url' => null]), ['size' => 'medium', 'cfg' => $cfg]);
        assertContains('Headline number 9', $html);
        assertContains('card-src', $html);
    },

    'small and wire cards never download a picture nobody can see' => function (): void {
        rInit();
        $html = Render::card(rRow(11), ['size' => 'small', 'cfg' => rCfg()]);
        assertSame([], rImgs($html), 'card--small hides its media in CSS, so the markup must not carry one');
        assertNotContains('card-sum', $html, 'card--small is headline and source only');
        assertContains('card--small', $html);
    },

    // =====================================================================
    //  injection
    // =====================================================================

    'a hostile headline cannot break out of text, of an attribute, or of the onerror handler' => function (): void {
        rInit();
        $evil = '</h3><script>alert("xss")</script> & \'quoted\' "double" <img src=x onerror=alert(2)>';
        // Every field the renderer prints, not just the three obvious ones:
        // the kicker and the alt text reach the page as well.
        $row  = rRow(60, [
            'title' => $evil, 'summary' => $evil, 'source_name' => $evil,
            'section_label' => $evil, 'image_alt' => $evil,
        ]);

        foreach (['lead', 'large', 'medium', 'small', 'text'] as $size) {
            $html = Render::card($row, ['size' => $size, 'cfg' => rCfg(), 'lazy' => $size !== 'lead']);

            assertNotContains('<script', $html, "a script tag survived in a card--$size");
            // The escaped headline still contains the WORDS "onerror=alert(2)" as
            // inert text, which is correct — what must never appear is the live tag.
            assertNotContains('<img src=x', $html, "an injected <img> survived in a card--$size");
            assertNotContains('</h3><script', $html, "the headline closed its own element in a card--$size");

            // Exactly the images we emitted ourselves, and no smuggled one.
            foreach (rImgs($html) as $img) {
                assertContains('cdn.example.test', rAttr($img, 'src'), 'an <img> appeared that we did not render: ' . $img);
            }

            // The headline still reaches the reader, just inert.
            assertContains('&lt;script&gt;', $html, "the headline text was lost instead of escaped in card--$size");
        }

        // The alt attribute is the headline, and it must not close early.
        $img = rImgs(Render::card($row, ['size' => 'medium', 'cfg' => rCfg()]))[0];
        assertContains('&quot;', rAttr($img, 'alt'), 'the double quote in the headline was not escaped inside alt');

        // And a whole page built from that row is still one document.
        $model = rModel(['hero' => ['lead' => array_merge($row, ['size' => 'lead']), 'subs' => [$row]]]);
        $page  = Render::home($model, rCfg());
        assertNotContains('<script>alert', $page, 'the payload executed at page level');
        assertSame(
            1,
            preg_match_all('/<script\b/i', $page) - preg_match_all('/<script[^>]*\bsrc=/i', $page)
                - preg_match_all('/<script type="application\/ld\+json">/i', $page),
            'the page must contain exactly one inline script: the pre-paint theme snippet'
        );
    },

    'a hostile URL never becomes a link' => function (): void {
        rInit();
        assertSame('', Render::outbound('javascript:alert(1)'));
        assertSame('', Render::outbound('data:text/html;base64,PHN2Zz4='));
        assertSame('', Render::outbound('  jAvAsCrIpT:alert(1)'));
        assertSame('', Render::outbound("java\nscript:alert(1)"));
        assertSame('https://a.test/x', Render::outbound('https://a.test/x'));

        // The scheme test has to carry its own weight. Every case above is
        // ALSO caught by the has-a-host test underneath it, so deleting the
        // scheme check entirely would leave them all green — these three have
        // a perfectly good host and must still be refused on the scheme alone.
        assertSame('', Render::outbound('javascript://a.test/%0aalert(1)'));
        assertSame('', Render::outbound('ftp://a.test/payload'));
        assertSame('', Render::outbound('file://a.test/etc/passwd'));

        $html = Render::card(rRow(61, ['url' => 'javascript:alert(1)']), ['size' => 'lead', 'cfg' => rCfg(), 'lazy' => false]);
        assertNotContains('javascript:', $html, 'a javascript: URL reached the document');
        assertNotContains('card-out', $html, 'the outbound link must be dropped, not rendered pointing nowhere');

        // …and the same filter guards the social card, which is a URL we print
        // into an attribute for someone else's crawler to follow.
        foreach (['javascript:alert(1)', 'data:text/html,<b>x</b>', 'ftp://a.test/x.jpg'] as $bad) {
            $page = Render::article(['article' => rRow(63, ['image_url' => $bad])], rCfg());
            assertNotContains('og:image', $page, 'a ' . $bad . ' image became og:image');
        }
        $ok = Render::article(['article' => rRow(63, ['image_url' => 'https://cdn.example.test/63.jpg'])], rCfg());
        assertContains('property="og:image" content="https://cdn.example.test/63.jpg"', $ok, 'a real image must still reach og:image');
    },

    'no JSON-LD payload can escape its <script> block or blank the page' => function (): void {
        rInit();

        // Three payloads. The first two are the obvious ones; the THIRD is the
        // one that blanks the whole page and that '</' escaping does not stop:
        // '<!--<script' puts the HTML5 tokenizer into the script-data DOUBLE
        // escaped state, where the next '</script>' is text, not an end tag —
        // so everything after it, <body> included, is swallowed as script.
        // Measured before the fix with Chrome and with a spec-compliant HTML5
        // parser: the body went from 112 elements to 1.
        $payloads = [
            '</script><script>alert(1)</script>',
            '</SCRIPT ><script>alert(1)</script>',
            '<!--<script>',
        ];

        foreach ($payloads as $payload) {
            $page = Render::article([
                'article' => rRow(62, ['title' => 'Story ' . $payload]),
                'jsonld'  => json_encode(['@type' => 'NewsArticle', 'headline' => $payload]),
            ], rCfg());

            assertSame(
                1,
                preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $page, $m),
                'the JSON-LD block is missing or was closed early by: ' . $payload
            );

            // The invariant, stated once and covering every script-data state
            // there is: no raw angle bracket may exist inside the block.
            assertNotContains('<', $m[1], 'a raw "<" survived inside the JSON-LD block: ' . $payload);
            assertNotContains('>', $m[1], 'a raw ">" survived inside the JSON-LD block: ' . $payload);

            // …and the page is still a whole document after it.
            assertContains('class="footer"', $page, 'the document was truncated by: ' . $payload);
            assertTrue(
                strpos($page, '<script type="application/ld+json">') < strpos($page, '</head>'),
                'the JSON-LD block escaped the head: ' . $payload
            );

            // Neutralising must be LOSSLESS: \u003C is '<' to a JSON parser, so
            // Google still reads the headline we meant to publish.
            $decoded = json_decode($m[1], true);
            assertTrue(is_array($decoded), 'the escaped block is no longer valid JSON: ' . $payload);
            assertSame($payload, $decoded['headline'] ?? null, 'the escaping changed what the JSON means');
        }

        // The array form goes through json_encode, which must not lose the
        // whole block to one bad byte out of a publisher's feed.
        $withBadByte = Render::layout([
            'cfg'    => rCfg(),
            'body'   => '',
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => "Bad\xC3Name"],
        ]);
        assertContains('application/ld+json', $withBadByte, 'invalid UTF-8 silently deleted the structured data');
    },

    // =====================================================================
    //  URLs — the subdirectory rule
    // =====================================================================

    'every internal href goes through Paths, at a web root and in a subdirectory' => function (): void {
        foreach ([true, false] as $rewrite) {
            rInit($rewrite);
            $html = Render::home(rModel(), rCfg());

            $internal = 0;
            foreach (rHrefs($html) as $href) {
                $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
                if ($href === '' || $href[0] === '#') {
                    continue;                       // the skip link's fragment
                }
                if (strpos($href, 'https://') === 0 || strpos($href, 'http://') === 0) {
                    continue;                       // a publisher link, or the canonical
                }
                $internal++;
                assertTrue(
                    strpos($href, '/teb/') === 0,
                    'an internal href does not carry the base path (rewrite=' . var_export($rewrite, true) . '): ' . $href
                );
                assertFalse(
                    (bool) preg_match('#^/(?!teb/)#', $href),
                    'a root-absolute href would 404 in a subdirectory: ' . $href
                );
                if (!$rewrite && strpos($href, '/assets/') === false && $href !== '/teb/') {
                    // Two exemptions, both correct: assets are real files served
                    // directly, and the front page is the directory itself, which
                    // DirectoryIndex resolves to index.php with no query at all.
                    assertContains('/teb/index.php?r=', $href, 'with mod_rewrite off every link must use ?r=: ' . $href);
                }
            }
            assertGreaterThan(20, $internal, 'the page should be full of internal links');
        }
    },

    'the stylesheet, the script and the canonical are all built by Paths' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());

        assertContains('href="' . Render::esc(Paths::asset('css/site.css')) . '"', $html, 'the stylesheet is not linked through Paths::asset');
        assertContains('src="' . Render::esc(Paths::asset('js/app.js')) . '"', $html, 'app.js is not linked through Paths::asset');
        assertContains('<link rel="canonical" href="' . Render::esc(Paths::absolute('/')) . '">', $html);
        assertContains('https://staging.example.test/teb/', $html, 'the canonical must use the REQUEST host, not the configured domain');
        assertNotContains('fixture-gazette.test', $html, 'config.site.domain must not be printed as a URL');

        // The stylesheet is linked, not inlined, and the script is deferred.
        assertNotContains('<style', $html, 'no stylesheet may be inlined');
        assertMatches('/<script src="[^"]*app\.js[^"]*" defer><\/script>/', $html, 'app.js must be deferred');
    },

    'a GET search form still finds its route when mod_rewrite is missing' => function (): void {
        rInit(false);
        $form = Render::searchbar('iran', rCfg());
        assertContains('name="r"', $form, 'without mod_rewrite the form must re-state the route or it submits to the front page');
        assertContains('value="/search"', $form);

        rInit(true);
        $form = Render::searchbar('iran', rCfg());
        assertNotContains('name="r"', $form, 'with mod_rewrite the hidden carrier is pointless clutter');
        assertContains('value="iran"', $form);
    },

    // =====================================================================
    //  brand
    // =====================================================================

    'the brand name exists only in config — never in the renderer or the JS' => function (): void {
        $brand = rRealBrand();
        assertTrue($brand !== '', 'config.php has no site.name to test against');

        // The whole brand string must not appear in ANY shipped file.
        foreach (['app/Render.php', 'assets/js/app.js', 'assets/css/site.css'] as $rel) {
            $src = (string) file_get_contents(teb_root() . '/' . $rel);
            assertFalse(
                stripos($src, $brand) !== false,
                $rel . ' contains the brand name literally — it must come from config.php'
            );
        }

        // Individual words of the brand are checked only in the files this
        // renderer owns. src/design.css names its three design DIRECTIONS in its
        // own comments (AUTHORITY / SIGNAL / EVENING) and is shipped byte for
        // byte, so a word-level sweep there would flag the designer's prose, not
        // a brand leak.
        foreach (['app/Render.php', 'assets/js/app.js'] as $rel) {
            $src = (string) file_get_contents(teb_root() . '/' . $rel);
            foreach (explode(' ', $brand) as $word) {
                if (strlen($word) > 4) {
                    assertFalse(
                        stripos($src, $word) !== false,
                        $rel . ' contains "' . $word . '" from the brand name — too close to a hardcoded brand'
                    );
                }
            }
        }

        $domain = (string) (Config::load(teb_root())['site']['domain'] ?? '');
        if ($domain !== '') {
            foreach (['app/Render.php', 'assets/js/app.js'] as $rel) {
                assertFalse(
                    stripos((string) file_get_contents(teb_root() . '/' . $rel), $domain) !== false,
                    $rel . ' contains the production domain'
                );
            }
        }
    },

    'renaming the site in config renames the whole page' => function (): void {
        rInit();
        $model = rModel();

        $a = Render::home($model, rCfg());
        assertContains('Fixture Gazette', $a, 'the configured name never reached the page');

        $b = Render::home($model, rCfg(['site' => ['name' => 'Second Ledger', 'tagline' => 'Another line entirely']]));
        assertContains('Second Ledger', $b);
        assertNotContains('Fixture Gazette', $b, 'the previous brand leaked through — something is holding state');
        assertContains('Another line entirely', $b);

        // …in the wordmark, the title, the og tags and the copyright line.
        assertContains('<title>Second Ledger', $b);
        assertContains('property="og:site_name" content="Second Ledger"', $b);
        assertContains('© ' . date('Y') . ' Second Ledger', $b);
    },

    // =====================================================================
    //  document shape
    // =====================================================================

    'layout emits a valid, indexable, theme-aware document head' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());

        assertTrue(strpos($html, '<!doctype html><html lang="en-US">') === 0, 'doctype or lang is wrong');
        assertContains('<meta charset="utf-8">', $html);
        assertContains('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        // "light", not "light dark". The stylesheet has no prefers-color-scheme
        // block, so advertising a dark canvas to the UA would paint the page
        // ground, the scrollbars and the form controls dark behind a white
        // page — the dark default the client rejected. The explicit toggle is
        // unaffected: the stylesheet sets color-scheme itself under
        // [data-theme], and CSS beats this meta.
        assertContains('<meta name="color-scheme" content="light">', $html);
        assertNotContains('content="light dark"', $html, 'a dark canvas must never be offered by default');
        assertContains('<meta name="theme-color" content="#FBFAF7">', $html);
        assertContains('rel="preconnect" href="https://fonts.googleapis.com"', $html);
        assertContains('rel="preconnect" href="https://fonts.gstatic.com" crossorigin', $html);
        assertContains('property="og:title"', $html);
        assertContains('name="twitter:card"', $html);
        assertContains('application/ld+json', $html);
        assertContains('localStorage.getItem("theme")', $html, 'the pre-paint theme snippet is missing — dark mode will flash');

        // app.js reaches for these three by name. They are a contract between
        // two files, so breaking either side has to fail here.
        $js = (string) file_get_contents(teb_root() . '/assets/js/app.js');
        assertContains('id="clock"', $html, 'app.js looks the clock up by id and would find nothing');
        assertContains("getElementById('clock')", $js);
        assertContains('data-tz="America/New_York"', $html, 'app.js reads data-tz to tick in the site timezone');
        assertContains("getAttribute('data-tz')", $js);
        assertContains('data-theme-toggle', $html, 'the theme button carries the hook app.js selects on');
        assertContains('[data-theme-toggle]', $js);
        assertTrue(
            strpos($html, 'localStorage.getItem("theme")') < strpos($html, 'rel="stylesheet"'),
            'the theme snippet must run before the stylesheet is applied'
        );
        assertContains('</body></html>', $html);
    },

    'the page is assembled in the order the design specifies' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());

        $order = ['class="skip"', 'class="ticker"', 'class="masthead wrap"', 'class="oxford"',
                  'class="nav"', '<main id="top-stories"', 'class="hero wrap"', 'class="adslot"',
                  'class="markets-strip"', '</main>', 'class="footer"'];
        $at = -1;
        foreach ($order as $needle) {
            $found = strpos($html, $needle);
            assertTrue($found !== false, 'missing from the page: ' . $needle);
            assertGreaterThan($at, $found, 'out of order: ' . $needle);
            $at = $found;
        }

        // Section order is US -> International -> World -> Culture.
        $us = strpos($html, '>U.S.</span>');
        $in = strpos($html, '>International</span>');
        $wo = strpos($html, '>World</span>');
        $re = strpos($html, '>Culture</span>');
        assertTrue($us < $in && $in < $wo && $wo < $re, 'the section blocks are out of order');

        // Markets is last and it is a strip, not a block (SPEC 0.5).
        assertGreaterThan(strpos($html, 'class="markets-strip"'), strpos($html, 'class="footer"'));
        assertTrue(strpos($html, 'markets-strip') > $re, 'money must sit below the last desk, never above the news');

        // …and the hard quota: at most two finance cards on the whole page.
        $strip = substr($html, (int) strpos($html, 'class="markets-strip"'));
        assertLessThanOrEqual(2, preg_match_all('/<article class="card/', $strip), 'SPEC 0.5 caps the front page at two finance cards');
        $many = rModel(['markets' => [rRow(70), rRow(71), rRow(72), rRow(73), rRow(74)]]);
        $strip2 = substr(Render::home($many, rCfg()), (int) strpos(Render::home($many, rCfg()), 'class="markets-strip"'));
        assertSame(2, preg_match_all('/<article class="card/', $strip2), 'a model offering five finance stories must still yield two');

        // The front-page lead links out to the publisher, which is the whole of
        // The lead sends the reader to OUR article page, never to the publisher.
        // The link out lives at the foot of the story text on that page.
        $hero = substr($html, (int) strpos($html, 'hero-lead'));
        $hero = substr($hero, 0, (int) strpos($hero, '</article>'));
        assertNotContains('class="card-out"', $hero, 'the front-page lead must not link to the publisher');
        assertContains('/article/', $hero, 'the lead must link to our own article page');
    },

    'the ticker duplicates its list for the CSS loop and hides the copy from everyone' => function (): void {
        rInit();
        $html = Render::ticker([rRow(1, ['fresh' => true]), rRow(2)], rCfg());

        assertSame(2, preg_match_all('/<ul[ >]/', $html), 'the -50% keyframe needs exactly two lists');
        assertContains('<ul aria-hidden="true">', $html, 'the duplicate must be hidden from assistive tech');
        assertSame(2, preg_match_all('/tabindex="-1"/', $html), 'every link in the copy must leave the tab order');
        // One fresh item out of two, and the list is emitted twice: 2 chips.
        assertSame(2, preg_match_all('/class="chip"/', $html), 'the chip belongs on fresh items only');
        assertSame(0, preg_match_all('/class="chip"/', Render::ticker([rRow(1), rRow(2)], rCfg())), 'nothing fresh, no chips');
        assertSame('', Render::ticker([], rCfg()), 'no headlines means no ticker band at all');
    },

    // =====================================================================
    //  ads
    // =====================================================================

    'a disabled ad slot reserves its exact height and does nothing else' => function (): void {
        rInit();
        $cfg  = rCfg();
        $html = Render::adSlot('leaderboard', $cfg);

        assertContains('class="adslot"', $html);
        assertContains('--ad-w:970px;--ad-h:250px', $html, 'the box must reserve the configured size, or enabling ads shifts the page');
        assertContains('class="adslot-frame"', $html);
        assertContains('970 × 250', $html);
        assertNotContains('<script', $html, 'a disabled slot must not carry a script');
        assertNotContains('<iframe', $html);
        assertNotContains('adslot-mount', $html, 'nothing is mounted while ads are off');

        // A small unit uses the designed box variant.
        assertContains('adslot adslot--box', Render::adSlot('rail', $cfg));
        // An unknown or malformed slot renders nothing rather than guessing.
        assertSame('', Render::adSlot('nope', $cfg));
        assertSame('', Render::adSlot('leaderboard', ['ads' => ['slots' => ['leaderboard' => [0, 0]]]]));
    },

    'enabling ads changes what is inside the box, never the size of the box' => function (): void {
        rInit();
        $off = Render::adSlot('inline', rCfg());
        $on  = Render::adSlot('inline', rCfg(['ads' => ['enabled' => true]]));

        assertContains('--ad-w:728px;--ad-h:90px', $off);
        assertContains('--ad-w:728px;--ad-h:90px', $on, 'the reserved geometry must be identical — that is the whole point');
        assertContains('adslot-mount', $on);
        assertNotContains('adslot-label', $on);
        assertNotContains('<script', $on, 'no network call until a real tag is pasted in');
    },

    // =====================================================================
    //  the other pages, and degradation
    // =====================================================================

    'a section page has one hero image, a working list and honest pagination' => function (): void {
        rInit();
        $items = [];
        for ($i = 1; $i <= 8; $i++) {
            $items[] = rRow($i);
        }
        $html = Render::section([
            'label' => 'U.S.', 'slug' => 'us', 'href' => '/section/us', 'note' => 'the national desk',
            'grid' => 'block-grid', 'items' => $items,
            'page' => 2, 'pages' => 5, 'template' => '/section/us?page={page}',
        ], rCfg());

        $eager = 0;
        foreach (rImgs($html) as $img) {
            if (rAttr($img, 'loading') === 'eager') {
                $eager++;
            }
        }
        assertSame(1, $eager, 'a section page has exactly one hero image too');

        assertContains('class="pagination"', $html);
        assertContains('pg pg-prev', $html);
        assertContains('pg pg-next', $html);
        assertContains('<span class="pg pg-now" aria-current="page">2</span>', $html);
        assertNotContains('pg-disabled', $html, 'a disabled link must not be rendered at all');

        // Page 1 of 1 gets no pagination furniture whatsoever.
        assertSame('', Render::pagination(['page' => 1, 'pages' => 1, 'template' => '/x?p={page}'], rCfg()));
        // No template means no links we could build — better nothing than a broken one.
        assertSame('', Render::pagination(['page' => 1, 'pages' => 9, 'template' => ''], rCfg()));

        // The ends. 'Omit prev/next when absent' is only worth anything if the
        // omission is asserted: rendering them anyway links to page 0 and to
        // page 6, both of which are 404s we generated ourselves.
        $first = Render::pagination(['page' => 1, 'pages' => 5, 'template' => '/section/us?page={page}'], rCfg());
        assertNotContains('pg-prev', $first, 'page 1 offered a "Newer" link — it points at page 0');
        assertContains('pg-next', $first);
        $last = Render::pagination(['page' => 5, 'pages' => 5, 'template' => '/section/us?page={page}'], rCfg());
        assertNotContains('pg-next', $last, 'the last page offered an "Older" link — it points past the end');
        assertContains('pg-prev', $last);
        foreach ([$first, $last] as $bar) {
            foreach (rHrefs($bar) as $href) {
                assertTrue(
                    (bool) preg_match('/page=([1-5])$/', $href),
                    'a pagination link points outside 1..5: ' . $href
                );
            }
        }
    },

    'the search page is noindexed, keeps the query and never reflects it as markup' => function (): void {
        rInit();
        $html = Render::section([
            'label' => 'Results', 'search' => true, 'q' => '"><script>alert(1)</script>',
            'items' => [], 'total' => 0, 'canonical' => '/search',
        ], rCfg());

        assertContains('name="robots" content="noindex,follow"', $html);
        assertContains('class="searchbar"', $html);
        assertNotContains('<script>alert', $html, 'the query was reflected into the document');
        assertContains('Nothing matched', $html);
    },

    'an article page sets a real article, not a card with a summary in it' => function (): void {
        rInit();
        $body = "The opening paragraph of the piece, which runs on for a while because these\n"
            . "feeds carry whole articles rather than a teaser.\n\n"
            . "A second paragraph.\n\nA third paragraph, and then the end of it.";
        $full = null;
        foreach (Feeds::all() as $f) {
            if (!$f['extract']) {
                $full = $f;
                break;
            }
        }
        assertNotNull($full, 'the roster must carry a full-text source');
        $a = rRow(80, [
            'title'       => 'The one story',
            'summary'     => 'The standfirst the feed supplied.',
            'body'        => $body,
            'author'      => 'Jane Reporter',
            'source_slug' => $full['slug'],
        ]);
        $html = Render::article(['article' => $a, 'related' => [rRow(81), rRow(82), rRow(83)]], rCfg());

        // the headline is an h1 and does not link to itself
        assertContains('<h1 class="story-hed">The one story</h1>', $html);
        assertSame(0, preg_match_all('#<h1[^>]*>\s*<a#', $html), 'the h1 must not link to itself');

        // dek, byline, dateline, reading time
        assertContains('class="story-dek">The standfirst the feed supplied.', $html, 'the summary is the standfirst');
        assertContains('class="story-byline">By Jane Reporter', $html, 'the author is credited above the text');
        assertContains('class="story-dateline"', $html);
        assertContains('class="story-read"', $html);
        assertMatches('/class="story-read">\d+ min read</', $html, 'the reading time is a real number of minutes');

        // the body is paragraphs, not one blob
        assertContains('class="article-body"', $html);
        assertSame(3, preg_match_all('#<div class="article-body">.*?</div>#s', $html)
            ? preg_match_all('#<p>#', (string) (preg_match('#<div class="article-body">(.*?)</div>#s', $html, $m) ? $m[1] : ''))
            : 0, 'three blank-line-separated paragraphs must become three <p> elements');
        assertContains('A second paragraph.', $html);

        // the way out, and the standing position
        assertContains('class="card-out"', $html);
        assertContains('href="https://news.example.test/story/80"', $html, 'the outbound link to the publisher is missing');
        assertContains('property="og:type" content="article"', $html);
        assertContains(Render::esc(Paths::absolute('/article/the-one-story-80')), $html, 'the canonical must be the slugged article URL');
        assertContains('>Related</span>', $html);

        $eager = 0;
        foreach (rImgs($html) as $img) {
            if (rAttr($img, 'loading') === 'eager') {
                $eager++;
            }
        }
        assertSame(1, $eager, 'the story photograph is this page hero, and the only eager image');
    },

    'the dek is never the first paragraph of the story printed twice' => function (): void {
        rInit();
        // The commonest shape in these feeds: <description> IS the opening of
        // <content:encoded>. Printing it as a standfirst AND as paragraph one is
        // the tell of a page nobody read before shipping.
        $opening = 'Every winter the river freezes from the bank inwards, and every winter somebody walks on it.';
        $a = rRow(84, [
            'summary' => $opening,
            'body'    => $opening . "\n\nThen the second paragraph starts, and the article goes on from there.",
        ]);
        $html = Render::article(['article' => $a], rCfg());
        // Counted inside <main> only: the summary is legitimately in the <head>
        // three times over, as description, og:description and twitter:description.
        $main = (string) (preg_match('#<main\b.*?</main>#s', $html, $mm) ? $mm[0] : '');
        assertTrue($main !== '', 'the page has a <main>');
        assertNotContains('story-dek', $main, 'the summary was already the first paragraph');
        assertSame(1, substr_count($main, $opening), 'the same sentence was printed twice');

        // ...but a genuinely different summary IS a dek.
        $b = rRow(85, ['summary' => 'A different standfirst entirely.', 'body' => $opening . "\n\nMore of it."]);
        assertContains('class="story-dek">A different standfirst entirely.', Render::article(['article' => $b], rCfg()));

        // ...and when the body IS only the summary, it is the story, not a dek.
        $c = rRow(86, ['summary' => 'All the feed gave us.', 'body' => '']);
        $main = (string) (preg_match('#<main\b.*?</main>#s', Render::article(['article' => $c], rCfg()), $mm) ? $mm[0] : '');
        assertNotContains('story-dek', $main, 'a summary-only story must not print its one sentence twice');
        assertSame(1, substr_count($main, 'All the feed gave us.'), 'once, in the body');
    },

    'every article carries the attribution its licence requires' => function (): void {
        rInit();
        // Author, publication, licence and a link to the original — CC BY, CC BY-ND
        // and CC BY-NC-ND all require it, and /editorial-standards promises it. The
        // licence data comes from TEB\Feeds, so this reads a REAL registry entry
        // rather than a fixture that could drift away from the shipped roster.
        $feed = null;
        foreach (Feeds::all() as $f) {
            if (!$f['extract'] && $f['license'] !== '' && $f['license_url'] !== '') {
                $feed = $f;
                break;
            }
        }
        assertNotNull($feed, 'the roster must carry at least one full-text licensed source');

        $a = rRow(90, [
            'source_slug' => $feed['slug'],
            'source_name' => $feed['name'],
            'author'      => 'A Named Writer',
            'body'        => str_repeat('A sentence of the article. ', 60),
        ]);
        $html = Render::article(['article' => $a], rCfg());

        assertContains('class="credit-block"', $html, 'the attribution block is missing');
        assertContains($feed['attribution'], $html, "the publisher's own credit wording must be used");
        assertContains('>Author</dt><dd>A Named Writer</dd>', $html, 'the author must be named in the credit');
        assertContains('>Licence</dt>', $html, 'the licence must be named');
        assertContains(Render::esc($feed['license']), $html, 'by its real name: ' . $feed['license']);
        assertContains(Render::esc($feed['license_url']), $html, 'and linked to its own deed');
        assertContains('>Publication</dt>', $html);
        assertContains('>Original</dt>', $html, 'and the original must be linked');
        assertContains('href="https://news.example.test/story/90"', $html);

        // Above the text as well as below it: the byline is the "above" half.
        $creditAt  = (int) strpos($html, 'class="credit-block"');
        $bylineAt  = (int) strpos($html, 'class="story-byline"');
        assertTrue($bylineAt > 0 && $bylineAt < $creditAt, 'the author must be credited above the text too');
    },

    'a non-commercially licensed source runs as an extract, never in full' => function (): void {
        rInit();
        // ProPublica, The 19th, The Markup, IEEE Spectrum: their licences do not
        // reach a page that carries advertising, so the body must NOT be published
        // whole however much of it the feed handed us. This is the single rule in
        // the build that is a legal condition rather than a design choice.
        $feed = null;
        foreach (Feeds::all() as $f) {
            if ($f['extract']) {
                $feed = $f;
                break;
            }
        }
        assertNotNull($feed, 'the roster must carry at least one extract-only source');

        $sentence = 'This is one sentence of the reporting, and it is a fairly long one so the cut has somewhere to land. ';
        $body     = str_repeat($sentence, 40);          // ~4,000 characters
        $a = rRow(91, ['source_slug' => $feed['slug'], 'source_name' => $feed['name'], 'body' => $body]);
        $html = Render::article(['article' => $a], rCfg());

        assertNotContains(rtrim($body), $html, 'the whole body of an NC-licensed story reached the page');
        assertContains('class="story-flag">Extract<', $html, 'an extract must be labelled as one');
        assertContains('class="story-extract-note"', $html, 'and must say why it is one');
        assertContains('class="card-out"', $html, 'with a prominent link to the original');

        // ...and it really is about four hundred characters, cut at a sentence.
        preg_match('#<div class="article-body">(.*?)</div>#s', $html, $m);
        $shown = trim(strip_tags($m[1] ?? ''));
        assertTrue(mb_strlen($shown) > 150, 'an extract that short is not an extract: ' . mb_strlen($shown));
        assertTrue(mb_strlen($shown) < 700, 'the extract ran to ' . mb_strlen($shown) . ' characters');

        // The same story from a full-text source IS published whole — otherwise
        // this test would pass with a renderer that truncated everything.
        $full = null;
        foreach (Feeds::all() as $f) {
            if (!$f['extract']) {
                $full = $f;
                break;
            }
        }
        $b = rRow(92, ['source_slug' => $full['slug'], 'source_name' => $full['name'], 'body' => $body]);
        $htmlB = Render::article(['article' => $b], rCfg());
        assertContains(rtrim($sentence) . ' ' . rtrim($sentence), $htmlB, 'a licensed full-text story must run whole');
        assertNotContains('class="story-flag">Extract<', $htmlB);
    },

    'an unknown source is treated as extract-only, not published in full' => function (): void {
        rInit();
        // A row whose source the registry cannot identify is one whose licence we
        // do not know. The safe default is to publish LESS of it.
        $body = str_repeat('A sentence that we have no licence for at all. ', 60);
        $html = Render::article([
            'article' => rRow(93, ['source_slug' => 'a-feed-that-is-not-registered', 'body' => $body]),
        ], rCfg());
        assertNotContains(rtrim($body), $html, 'an unidentifiable source was published in full');
    },

    'reading time is computed from the words on the page' => function (): void {
        assertSame(1, Render::readingTime(''), 'never zero minutes');
        assertSame(1, Render::readingTime('a few words only'));
        assertSame(2, Render::readingTime(str_repeat('word ', 300)), '300 words at 220 wpm is two minutes');
        assertSame(5, Render::readingTime(str_repeat('word ', 1000)), '1,000 words is five minutes');

        // and it is the RENDERED text, not the stored body: an extract page must
        // not claim the reading time of the article it extracts from.
        rInit();
        $feed = null;
        foreach (Feeds::all() as $f) {
            if ($f['extract']) {
                $feed = $f;
            }
        }
        $long = str_repeat('A sentence of the original reporting. ', 300);
        $html = Render::article([
            'article' => rRow(94, ['source_slug' => $feed['slug'], 'body' => $long]),
        ], rCfg());
        preg_match('/class="story-read">(\d+) min read</', $html, $m);
        assertSame('1', $m[1] ?? '', 'an extract page claimed the whole article\'s reading time');
    },

    'an extract is cut at the end of a sentence, never mid-word' => function (): void {
        $text = 'First sentence here. Second sentence, rather longer than the first one was. '
            . 'Third sentence which runs past the limit and should not appear at all.';
        $cut = Render::extractOf($text, 60);
        assertTrue(mb_strlen($cut) <= 140, 'the cut is near the limit: ' . mb_strlen($cut));
        assertMatches('/[.!?]$/', $cut, 'an extract must end on a sentence: ' . $cut);
        assertNotContains('Third sentence', $cut);

        // No sentence ending within reach: fall back to a word boundary, and SAY
        // it was cut. A word sliced in half is what makes an extract look scraped.
        $run = str_repeat('word ', 100);
        $cut = Render::extractOf($run, 50);
        assertContains('…', $cut, 'a mid-sentence cut must be marked');
        assertFalse((bool) preg_match('/wor…$/', $cut), 'the cut landed inside a word: ' . $cut);

        // Shorter than the limit: returned untouched.
        assertSame('Short.', Render::extractOf('Short.', 400));
        assertSame('', Render::extractOf('   ', 400));
    },

    'paragraph breaks in the feed survive into the page' => function (): void {
        $out = Render::paragraphs("One.\n\nTwo.\n\n\n\nThree.");
        assertSame(3, substr_count($out, '<p>'), 'blank lines are paragraph breaks');
        assertNotContains('<p></p>', $out, 'a run of blank lines must not make an empty paragraph');

        // A single newline is a soft break inside a paragraph, not a paragraph.
        $out = Render::paragraphs("Line one\nLine two");
        assertSame(1, substr_count($out, '<p>'));
        assertContains('<br>', $out, 'a single newline must not glue two lines into one word');

        // and it is still escaped
        assertContains('&lt;script&gt;', Render::paragraphs('<script>alert(1)</script>'));
        assertSame('', Render::paragraphs("   \n\n  "));
    },

    'a photograph we are not licensed to republish is never emitted' => function (): void {
        rInit();
        // Nearly every newsroom on this roster licenses its TEXT and withholds its
        // PICTURES. The feed still carries a URL; the page must draw the house
        // placeholder instead — on the card, on the article page, and in the
        // Open Graph tag, which is a republication too.
        $off = null;
        $on  = null;
        foreach (Feeds::all() as $f) {
            if (!$f['images'] && $off === null) {
                $off = $f;
            }
            if ($f['images'] && $on === null) {
                $on = $f;
            }
        }
        assertNotNull($off, 'the roster must carry a source whose pictures are withheld');

        $card = Render::card(rRow(95, ['source_slug' => $off['slug']]), ['size' => 'medium', 'cfg' => rCfg()]);
        assertNotContains('cdn.example.test', $card, 'an unlicensed publisher image reached a card');
        assertContains('placeholder.svg', $card, 'and the house placeholder was not drawn in its place');

        $html = Render::article(['article' => rRow(96, ['source_slug' => $off['slug']])], rCfg());
        assertNotContains('cdn.example.test', $html, 'an unlicensed publisher image reached the article page');
        assertContains('placeholder.svg', $html);

        if ($on !== null) {
            $ok = Render::card(rRow(97, ['source_slug' => $on['slug']]), ['size' => 'medium', 'cfg' => rCfg()]);
            assertContains('cdn.example.test', $ok, 'a licensed photograph must still be shown');
        }
    },

    'the front page splits its regions and says which is which' => function (): void {
        rInit();
        $model = rModel(['regions' => [
            ['id' => 'lead', 'label' => '', 'note' => '', 'blocks' => ['us', 'international']],
            ['id' => 'desks', 'label' => 'More from the desks',
             'note' => 'These desks are checked every 30 minutes.', 'blocks' => ['world', 'culture']],
        ]]);
        $html = Render::home($model, rCfg());

        assertContains('class="region-head wrap"', $html, 'the band that splits the page is missing');
        assertContains('<h2 class="region-title">More from the desks</h2>', $html);
        assertContains('class="region-note">These desks are checked every 30 minutes.', $html);
        assertContains('class="region-desks"', $html, 'the band must link to the desks below it');

        // It sits BETWEEN the two groups, not at the top of the page.
        $bandAt = (int) strpos($html, 'class="region-head');
        assertTrue($bandAt > (int) strpos($html, '>International</span>'), 'the band is above the fast desks');
        assertTrue($bandAt < (int) strpos($html, '>World</span>'), 'the band is below the slow desks');

        // The lead region carries no band at all — the hero is its heading.
        assertSame(1, substr_count($html, 'class="region-head'), 'only the slower region gets a band');

        // A model with no regions renders every block, flat and in order — an
        // older caller, or a hand-built fixture, must not lose the page.
        $flat = Render::home(rModel(), rCfg());
        assertNotContains('region-head', $flat);
        foreach (['>U.S.</span>', '>International</span>', '>World</span>', '>Culture</span>'] as $needle) {
            assertContains($needle, $flat, 'a block vanished when the model carried no regions');
        }
    },

    'the article href is slug + id, and survives a title made only of punctuation' => function (): void {
        rInit();
        assertSame('/article/the-one-story-80', Render::articleHref(rRow(80, ['title' => 'The one story'])));
        assertSame('/article/80', Render::articleHref(['id' => 80, 'title' => '!!! ??? ***']));
        assertSame('/', Render::articleHref(['id' => 0, 'title' => 'no id']));
        assertContains('and', Render::articleHref(['id' => 5, 'title' => 'Rock & Roll']));
        assertTrue(strlen(Render::slug(str_repeat('long headline ', 40))) <= 72, 'the slug must be capped');
    },

    'the page still renders with three articles, no ticker and no markets' => function (): void {
        rInit();
        $thin = [
            'ticker' => [],
            'hero'   => ['lead' => rRow(1, ['size' => 'lead']), 'subs' => []],
            'blocks' => [['id' => 'us', 'label' => 'U.S.', 'href' => '/section/us', 'grid' => 'block-grid',
                          'items' => [rRow(2), rRow(3)]]],
            'markets' => [],
        ];
        $html = Render::home($thin, rCfg());

        assertContains('Headline number 1', $html);
        assertContains('Headline number 3', $html);
        assertNotContains('class="ticker"', $html, 'an empty ticker must not leave an empty band');
        assertNotContains('class="hero wrap"', $html, 'with no rail the hero grid would leave a 2fr hole at 2560px');
        assertNotContains('hero-lead', $html, 'the lead must lose its grid-area padding outside the hero grid');
        assertNotContains('markets-strip', $html);
        assertContains('class="footer"', $html, 'the page must still be a whole document');

        // And with literally nothing at all.
        $empty = Render::home(['ticker' => [], 'hero' => ['lead' => null, 'subs' => []], 'blocks' => [], 'markets' => []], rCfg());
        assertContains('<main id="top-stories"', $empty);
        assertContains('</body></html>', $empty);

        // A block whose stories all failed to render must take its heading and
        // its grid with it, not leave a labelled empty box on the front page.
        $hollow = Render::home([
            'ticker' => [], 'hero' => ['lead' => rRow(1, ['size' => 'lead']), 'subs' => []],
            'blocks' => [['id' => 'us', 'label' => 'U.S.', 'href' => '/section/us', 'grid' => 'block-grid',
                          'items' => [['id' => 2, 'title' => ''], ['id' => 3]]]],
            'markets' => [],
        ], rCfg());
        assertNotContains('block-label', $hollow, 'an all-empty block left its heading behind');
        assertNotContains('<div class="block-grid"></div>', $hollow, 'an empty grid band was rendered');
    },

    'a lead with no headline degrades instead of leaving a hole in the hero grid' => function (): void {
        rInit();
        // The hero grid is rail | subs | lead. card() refuses to build a card
        // with no headline, so a lead in that state used to leave the 6fr
        // column empty — the same hole the no-rail case is guarded against.
        foreach ([['id' => 5], ['id' => 5, 'title' => ''], ['id' => 5, 'title' => '   ']] as $lead) {
            $html = Render::home([
                'ticker' => [rRow(1), rRow(2)], 'hero' => ['lead' => $lead, 'subs' => []],
                'blocks' => [], 'markets' => [],
            ], rCfg());
            assertNotContains('class="hero wrap"', $html, 'the hero grid was rendered with nothing in its lead column');
            assertContains('</body></html>', $html, 'the page must still be a whole document');
        }

        // A lead that DOES have a headline still gets the hero grid.
        $ok = Render::home([
            'ticker' => [rRow(1), rRow(2)], 'hero' => ['lead' => rRow(5, ['size' => 'lead']), 'subs' => []],
            'blocks' => [], 'markets' => [],
        ], rCfg());
        assertContains('class="hero wrap"', $ok);
        assertContains('hero-lead', $ok);
    },

    'the hero keeps its rail whenever there is anything left to put in it' => function (): void {
        rInit();
        // The rail is fed from the ticker, i.e. the stories the page did not
        // otherwise place — so a real front page always has one.
        $html = Render::home(rModel(), rCfg());
        assertContains('class="hero wrap"', $html);
        assertContains('class="hero-rail"', $html);
        assertContains('class="hero-subs"', $html);
        assertContains('card card--lead hero-lead', $html);
        assertSame(6, preg_match_all('/<li><a href="[^"]*"><span class="n">/', $html), 'the rail holds six numbered items');

        // DOM order is rail -> subs -> lead; the grid places the lead visually right.
        assertTrue(
            strpos($html, 'hero-rail') < strpos($html, 'hero-subs')
            && strpos($html, 'hero-subs') < strpos($html, 'hero-lead'),
            'the hero must read rail, seconds, lead in the source'
        );

        // An explicit rail in the model wins over the ticker.
        $m = rModel(['rail' => [rRow(999, ['title' => 'Handpicked rail item'])]]);
        assertContains('Handpicked rail item', Render::home($m, rCfg()));
    },

    'error pages are real pages with a way out' => function (): void {
        rInit();
        $html = Render::error(404, '', rCfg());
        assertContains('noindex', $html);
        assertContains('That page is not here.', $html);
        assertContains('class="searchbar"', $html);
        assertContains('class="nav"', $html);
        assertContains('>404</span>', $html);
        assertContains('Fixture Gazette', $html, 'even the 404 is branded from config');
        assertNotContains('aria-current="page"', $html, 'a 404 is not one of the sections, so no nav item is current');

        $html500 = Render::error(500, '', rCfg());
        assertContains('Something went wrong at our end.', $html500);
        // A nonsense status must not become a nonsense page.
        assertContains('>500</span>', Render::error(0, 'x', rCfg()));
    },

    // =====================================================================
    //  markup / stylesheet contract
    // =====================================================================

    'every class the renderer emits actually exists in the stylesheet' => function (): void {
        rInit();
        $css = (string) file_get_contents(teb_root() . '/assets/css/site.css');
        assertTrue($css !== '', 'assets/css/site.css is missing — the page would render unstyled');

        $pages = [
            Render::home(rModel(), rCfg()),
            Render::section(['label' => 'U.S.', 'slug' => 'us', 'items' => [rRow(1), rRow(2)],
                             'page' => 2, 'pages' => 4, 'template' => '/section/us?page={page}'], rCfg()),
            Render::article(['article' => rRow(80), 'related' => [rRow(81)]], rCfg()),
            Render::error(404, '', rCfg()),
            Render::adSlot('rail', rCfg(['ads' => ['enabled' => true]])),
        ];

        $used = [];
        foreach ($pages as $html) {
            preg_match_all('/\sclass="([^"]+)"/', $html, $m);
            foreach ($m[1] as $group) {
                foreach (preg_split('/\s+/', trim($group)) as $class) {
                    if ($class !== '') {
                        $used[$class] = true;
                    }
                }
            }
        }
        assertGreaterThan(25, count($used), 'suspiciously few classes — did the parse work?');

        // The match must END at the class name. A plain substring search is a
        // test that cannot fail: '.kick' is found inside '.kicker', so renaming
        // .kicker to .kick in the renderer — a class the stylesheet does not
        // have — left this green. Proven, then fixed.
        $missing = [];
        foreach (array_keys($used) as $class) {
            if (!preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css)) {
                $missing[] = $class;
            }
        }
        assertSame([], $missing, 'classes emitted but never styled: ' . implode(', ', $missing));

        // A positive control for the check itself: a class that genuinely is
        // only a prefix of a real one must be reported.
        assertFalse(
            (bool) preg_match('/\.kick(?![A-Za-z0-9_-])/', $css),
            'the word-boundary check is not actually bounded — it matched .kicker'
        );
        assertTrue((bool) preg_match('/\.kicker(?![A-Za-z0-9_-])/', $css), '.kicker really is in the stylesheet');
    },

    'the shipped stylesheet is the designer\'s file, unmodified, plus an appended block' => function (): void {
        $src  = (string) file_get_contents(teb_root() . '/src/design.css');
        $ship = (string) file_get_contents(teb_root() . '/assets/css/site.css');

        assertTrue(strpos($ship, $src) === 0, 'assets/css/site.css must START with src/design.css byte for byte');
        $extra = substr($ship, strlen($src));
        assertContains('RENDERER EXTENSION', $extra, 'anything added must be marked as an addition');
        assertNotContains('@import', $extra, 'the extension must not pull another network resource');
    },

    'app.js is small, dependency-free and safe to defer' => function (): void {
        $js = (string) file_get_contents(teb_root() . '/assets/js/app.js');
        assertTrue($js !== '', 'assets/js/app.js is missing');
        // 10 KB is the brief's number. It went up from 8 KB when the client asked
        // for the top stories to rotate from an API — that feature is about 2 KB
        // of fetch, patch and cross-fade, and it is a requirement, not an extra.
        assertLessThan(10240, strlen($js), 'the client JS budget is 10 KB; this is ' . strlen($js) . ' bytes');

        assertNotContains('document.write', $js, 'document.write breaks a deferred script');
        assertNotContains('require(', $js);
        assertNotContains('import ', $js);
        // ONE network call is allowed and required: the rotation feed. Anything
        // else — an analytics beacon, a font, a third-party widget — is not.
        assertSame(1, substr_count($js, 'fetch('), 'exactly one fetch: the rotation poll');
        assertContains('/api/top.json', $js, 'and it is the rotation feed it calls');
        assertNotContains('XMLHttpRequest', $js);
        assertNotMatches('/fetch\\(\\s*[\'"`]?https?:/i', $js, 'the poll never leaves this origin');

        // The five jobs, and nothing else.
        assertContains('data-theme', $js);
        assertContains('visibilitychange', $js, 'the ticker must pause when the tab is hidden');
        assertContains('animationPlayState', $js);
        assertContains('prefers-reduced-motion', $js, 'reduced motion must be honoured');
        assertContains("getElementById('clock')", $js);
        assertContains('time[datetime]', $js);
        assertContains('localStorage', $js);
    },

    'the rotation settings in config.php actually reach the page and the script' => function (): void {
        rInit();

        // config.php has always shipped a 'rotation' block with a comment
        // explaining each key, and nothing read it: app.js held its own
        // hardcoded 80-100s timer, so switching rotation off or changing the
        // interval in config changed nothing whatsoever on the page. A knob
        // that turns nothing is worse than no knob, so both ends are pinned.
        $on = Render::home(rModel(), rCfg(['rotation' => ['enabled' => true, 'seconds' => 75, 'count' => 3]]));
        assertContains('data-rotate-seconds="75"', $on, 'the interval must reach the hero');
        assertContains('data-rotate-count="3"', $on, 'and so must the cards-per-turn');

        // Off means off: no interval, so app.js never starts the timer at all.
        $off = Render::home(rModel(), rCfg(['rotation' => ['enabled' => false, 'seconds' => 75, 'count' => 3]]));
        assertContains('data-rotate-seconds="0"', $off, "enabled => false must publish a zero interval");
        assertNotContains('data-rotate-count', $off, 'a stopped rotation needs no cards-per-turn');

        // Nonsense is clamped rather than passed through: a 2-second poll would
        // hammer the endpoint, and a four-hour one is not rotation.
        assertContains('data-rotate-seconds="30"', Render::home(rModel(), rCfg(['rotation' => ['seconds' => 2]])));
        assertContains('data-rotate-seconds="600"', Render::home(rModel(), rCfg(['rotation' => ['seconds' => 99999]])));

        // A missing or malformed block still leaves a page that rotates.
        foreach ([[], null, 'junk', ['seconds' => 'soon']] as $junk) {
            assertContains(
                'data-rotate-seconds="90"',
                Render::home(rModel(), rCfg(['rotation' => $junk])),
                'a missing or malformed rotation block falls back to the default interval'
            );
        }

        // And the script reads exactly what the renderer writes.
        $js = (string) file_get_contents(teb_root() . '/assets/js/app.js');
        assertContains("getAttribute('data-rotate-seconds')", $js, 'app.js must read the interval, not hold one');
        assertContains("getAttribute('data-rotate-count')", $js, 'and it must read the cards-per-turn');
        assertNotContains('80000', $js, 'the old hardcoded 80-100s timer must be gone');
        assertContains('!rotMs', $js, 'a zero interval must stop the timer ever starting');
    },

    'the server renders an absolute time, so timestamps are right with no JavaScript' => function (): void {
        rInit();
        // Stated as a real instant rather than a magic integer, so the expected
        // local times below can be read off it.
        $when = (new DateTimeImmutable('2026-08-10 21:48:00', new DateTimeZone('UTC')))->getTimestamp() * 1000;
        $html = Render::card(rRow(1, ['published_at' => $when]), ['size' => 'medium', 'cfg' => rCfg()]);

        assertMatches('/<time class="t" datetime="[^"]+">[^<]+<\/time>/', $html);
        assertContains('5:48 p.m.', $html, 'the time must be rendered in the configured timezone (America/New_York)');
        assertContains('datetime="2026-08-10T17:48:00-04:00"', $html, 'the machine-readable instant is what app.js reads');
        assertContains('Aug 10, 5:48 p.m.', $html, 'anything older than a day needs its date, or "5:48 p.m." is a lie');

        // A different timezone in config moves the printed time, not the instant.
        $la = Render::card(rRow(1, ['published_at' => $when]), ['size' => 'medium', 'cfg' => rCfg(['site' => ['timezone' => 'America/Los_Angeles']])]);
        assertContains('2:48 p.m.', $la);
        assertContains('datetime="2026-08-10T14:48:00-07:00"', $la, 'the instant must not move with the display timezone');

        // Something from this hour prints the bare time — no date to clutter it.
        $recent = Render::card(rRow(1, ['published_at' => (time() - 900) * 1000]), ['size' => 'medium', 'cfg' => rCfg()]);
        assertMatches('/>\d{1,2}:\d{2} [ap]\.m\.</', $recent);
        assertNotMatches('/>[A-Z][a-z]{2} \d{1,2}, /', $recent, 'a story from 15 minutes ago should not be date-stamped');

        // A missing or nonsense timestamp prints nothing rather than 1 Jan 1970.
        assertNotContains('<time', Render::card(rRow(1, ['published_at' => null]), ['size' => 'medium', 'cfg' => rCfg()]));
        assertNotContains('<time', Render::card(rRow(1, ['published_at' => 0]), ['size' => 'medium', 'cfg' => rCfg()]));
    },

    'accessibility furniture is present and the media link is not a second tab stop' => function (): void {
        rInit();
        $html = Render::home(rModel(), rCfg());

        assertContains('<a class="skip" href="#top-stories">', $html);
        assertContains('<main id="top-stories" tabindex="-1">', $html);
        assertContains('aria-label="Sections"', $html);
        assertContains('aria-current="page"', $html, 'the current nav item must be marked');
        assertContains('aria-label="Latest headlines"', $html);
        assertContains('aria-label="Advertisement slot"', $html);

        // Every .card-media anchor is decorative: the headline link is the real one.
        preg_match_all('/<a class="card-media"[^>]*>/', $html, $m);
        assertGreaterThan(0, count($m[0]));
        foreach ($m[0] as $tag) {
            assertSame('-1', rAttr($tag, 'tabindex'), 'the picture link would double the tab stops: ' . $tag);
            assertSame('true', rAttr($tag, 'aria-hidden'), 'the picture link would double the announcements: ' . $tag);
        }
    },
];
