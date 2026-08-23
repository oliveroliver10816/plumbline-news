<?php
declare(strict_types=1);

/**
 * tests/test_ticker.php — the ticker cycles the VERTICALS (SPEC §6)
 *
 * The client's complaint, verbatim: "MAKE SURE the tickers update data from
 * different verticals." The old strip was newest-first, so on a slow news
 * morning it was five headlines off one desk. It now takes the newest story
 * from each section in turn and wraps round.
 *
 * The property under test is one sentence, and every assertion below is a way
 * of pinning it down:
 *
 *   two neighbours in the strip share a section ONLY when every other section
 *   had already run out — in which case the whole tail is that one section.
 *
 * That is checked at all three levels: the pure ordering function, the HTML it
 * renders, and the front page Compose builds. The last one matters most: it is
 * the only one that proves the SELECTION is section-diverse too, rather than
 * a one-desk list politely rearranged.
 */

teb_require_app('Config', 'Paths', 'Feeds', 'Compose', 'Render');

use TEB\Compose;
use TEB\Paths;
use TEB\Render;

const TK_NOW = 1787000000000;   // fixed clock — Compose is pure, so this file is too

// ---------------------------------------------------------------------------
//  fixtures
// ---------------------------------------------------------------------------

/** One article row, in the shape Db::recentArticles() returns. */
function tkArt(int $id, string $section, string $source, int $minutesAgo, array $over = []): array
{
    return array_merge([
        'id'           => $id,
        'title'        => ucfirst($section) . ' story ' . $id,
        'url'          => 'https://example.test/' . $section . '/' . $id,
        'summary'      => 'A feed-provided summary for story ' . $id . '.',
        'image_url'    => null,
        'published_at' => TK_NOW - ($minutesAgo * 60000),
        'section'      => $section,
        'source'       => $source,
        'source_name'  => strtoupper($source),
        'slug'         => 'story-' . $id,
    ], $over);
}

/** The sections of a list of rows, in order. */
function tkSections(array $items): array
{
    return array_map(static fn(array $r): string => (string) ($r['section'] ?? ''), $items);
}

/**
 * THE assertion this file exists for.
 *
 * Walks the strip and, at every point where two neighbours share a section,
 * proves that no other section still had a story to give — which, for a round
 * robin, is exactly the same statement as "everything from here on is that one
 * section". Anything else is a repeat that did not have to happen.
 */
function tkAssertVerticalsAlternate(array $sections, string $what): void
{
    $n = count($sections);
    for ($i = 0; $i + 1 < $n; $i++) {
        if ($sections[$i] !== $sections[$i + 1]) {
            continue;
        }
        $tail = array_slice($sections, $i);
        assertSame(
            [$sections[$i]],
            array_values(array_unique($tail)),
            $what . ': "' . $sections[$i] . '" repeats at position ' . $i
            . ' while other sections still have stories — ' . implode(' → ', $sections)
        );
    }
}

/** A slow news morning: one desk floods the wire, the rest trickle in. */
function tkSlowMorning(): array
{
    $rows = [];
    $id   = 1;
    for ($i = 0; $i < 14; $i++) {                       // the flood
        $rows[] = tkArt($id++, 'politics', 'wire-p' . ($i % 4), 1 + $i);
    }
    foreach (['world' => 3, 'technology' => 2, 'science' => 2, 'health' => 2,
              'environment' => 1, 'education' => 1] as $section => $n) {
        for ($i = 0; $i < $n; $i++) {                   // the trickle, all older
            $rows[] = tkArt($id++, $section, $section . '-desk' . $i, 40 + ($i * 11));
        }
    }

    return $rows;
}

/**
 * Compose config: no blocks and no rail, so everything falls through to the
 * ticker and this file tests the ticker rather than the front page.
 *
 * The desks to switch off are read from TEB\Feeds, never typed here: the two
 * editions run different rosters, and a hardcoded list would silently stop
 * zeroing anything the day an editor renamed a desk — leaving the blocks to eat
 * the rows this file needs and failing with a message about the ticker.
 */
function tkCfg(array $composeOver = []): array
{
    $noBlocks = [];
    foreach (\TEB\Feeds::blockOrder() as $slug) {
        $noBlocks[$slug] = 0;
    }
    $noBlocks['markets'] = 0;

    return [
        'site'    => ['name' => 'Fixture Wire', 'timezone' => 'UTC', 'domain' => 'fixture.test'],
        'compose' => array_merge([
            'ticker_count'   => 12,
            'block_counts'   => $noBlocks,
            'hero_sub_count' => 0,
            'rail_count'     => 0,
        ], $composeOver),
    ];
}

function tkInit(): void
{
    Paths::init([
        'REQUEST_URI'    => '/',
        'SCRIPT_NAME'    => '/index.php',
        'PHP_SELF'       => '/index.php',
        'HTTP_HOST'      => 'fixture.test',
        'SERVER_NAME'    => 'fixture.test',
        'REQUEST_METHOD' => 'GET',
        'HTTPS'          => 'on',
    ], teb_root());
    Paths::allowProbe(false);
    Paths::forceRewrite(true);
}

/** The section label printed on each <li>, in strip order. */
function tkRenderedLabels(string $html): array
{
    // The first <ul> is the live list; the second is the aria-hidden CSS copy.
    $live = substr($html, 0, (int) strpos($html, '<ul aria-hidden'));
    preg_match_all('/<li><a[^>]*><(?:span class="chip">New<\/span><)?span class="s">([^<]*)<\/span>/', $live, $m);

    return $m[1];
}

return [

    // ------------------------------------------------------- the ordering --

    'consecutive ticker items are never from the same vertical while another has stories' => function (): void {
        // 9 stories off one desk and a handful off four others: newest-first
        // would open with nine politics headlines in a row.
        $items = [];
        $id    = 1;
        for ($i = 0; $i < 9; $i++) {
            $items[] = tkArt($id++, 'politics', 'p', 1 + $i);
        }
        foreach (['world', 'technology', 'science', 'health'] as $k => $section) {
            $items[] = tkArt($id++, $section, $section, 20 + $k);
        }

        $out      = Render::tickerOrder($items);
        $sections = tkSections($out);

        assertCount(13, $out, 'nothing is dropped when there is no limit');
        tkAssertVerticalsAlternate($sections, 'tickerOrder');

        // The first five items are five different desks, which is the whole ask.
        assertCount(5, array_unique(array_slice($sections, 0, 5)), 'the first five items are five desks');
        assertSame('politics', $sections[0], 'the strip still opens on the newest story');
        assertSame(
            ['politics', 'world', 'technology', 'science', 'health'],
            array_slice($sections, 0, 5),
            'desks are visited in the order their newest story appears'
        );
        // Only once the other four desks are spent may politics run on.
        assertSame(array_fill(0, 8, 'politics'), array_slice($sections, 5), 'the tail is what is left');
    },

    'a vertical with nothing in it is skipped, not left as a gap' => function (): void {
        $items = [
            tkArt(1, 'politics', 'a', 1),
            tkArt(2, 'science', 'b', 2),
            tkArt(3, 'politics', 'a', 3),
            tkArt(4, 'politics', 'a', 4),
        ];
        $out = Render::tickerOrder($items);

        assertCount(4, $out, 'every story is still placed');
        assertSame(['politics', 'science', 'politics', 'politics'], tkSections($out));
        foreach ($out as $r) {
            assertTrue(($r['title'] ?? '') !== '', 'no empty slot is emitted where a desk ran dry');
        }
        tkAssertVerticalsAlternate(tkSections($out), 'one thin desk');
    },

    'order inside a vertical is preserved, so each turn takes that desk\'s newest' => function (): void {
        $items = [
            tkArt(10, 'world', 'a', 1),
            tkArt(11, 'world', 'a', 5),
            tkArt(12, 'world', 'a', 9),
            tkArt(20, 'health', 'b', 2),
            tkArt(21, 'health', 'b', 6),
        ];
        $out = Render::tickerOrder($items);

        assertSame([10, 20, 11, 21, 12], array_map(static fn(array $r): int => $r['id'], $out),
            'newest of world, newest of health, then the next of each');
    },

    'the limit truncates the strip without breaking the alternation' => function (): void {
        $out = Render::tickerOrder(tkSlowMorning(), 6);

        assertCount(6, $out, 'the cap is honoured');
        assertCount(6, array_unique(tkSections($out)), 'six slots, six different desks');
        tkAssertVerticalsAlternate(tkSections($out), 'capped strip');

        assertCount(25, Render::tickerOrder(tkSlowMorning(), 0), '0 means no cap');
        assertCount(25, Render::tickerOrder(tkSlowMorning(), -3), 'a negative cap is not a trap');
        assertCount(1, Render::tickerOrder(tkSlowMorning(), 1), 'a one-item strip is legal');
        assertSame([], Render::tickerOrder([], 12), 'nothing in, nothing out');
    },

    'a single-vertical newsroom still renders, in the order it arrived' => function (): void {
        $items = [tkArt(1, 'politics', 'a', 1), tkArt(2, 'politics', 'a', 2), tkArt(3, 'politics', 'a', 3)];
        $out   = Render::tickerOrder($items);

        assertSame([1, 2, 3], array_map(static fn(array $r): int => $r['id'], $out),
            'with one desk there is nothing to alternate with, and nothing is lost');
    },

    'rows with no section form their own desk instead of colliding' => function (): void {
        $items = [
            tkArt(1, '', 'a', 1) + [],
            tkArt(2, 'politics', 'b', 2),
            tkArt(3, '', 'a', 3),
        ];
        $out = Render::tickerOrder($items);

        assertSame(['', 'politics', ''], tkSections($out), 'the unfiled desk takes its turn like any other');
        tkAssertVerticalsAlternate(tkSections($out), 'unfiled rows');
    },

    'a numeric section slug is grouped as its own desk' => function (): void {
        // A slug like '2026' becomes an int key in a bare PHP array. Grouping
        // must not care: it is a desk like any other and keeps its turn.
        $items = [
            tkArt(1, '2026', 'a', 1),
            tkArt(2, 'politics', 'b', 2),
            tkArt(3, '2026', 'a', 3),
            tkArt(4, 'politics', 'b', 4),
        ];
        assertSame(['2026', 'politics', '2026', 'politics'], tkSections(Render::tickerOrder($items)));
    },

    'the ordering is idempotent — re-running it changes nothing' => function (): void {
        $once  = Render::tickerOrder(tkSlowMorning(), 12);
        $twice = Render::tickerOrder($once, 12);

        assertSame(
            array_map(static fn(array $r): int => $r['id'], $once),
            array_map(static fn(array $r): int => $r['id'], $twice),
            'Compose interleaves and Render interleaves again; the second pass must be a no-op'
        );
    },

    // ----------------------------------------------------------- the strip --

    'the rendered strip alternates verticals and labels every item with its desk' => function (): void {
        tkInit();
        $items = Render::tickerOrder(tkSlowMorning(), 12);
        $html  = Render::ticker($items, tkCfg());

        $labels = tkRenderedLabels($html);
        assertCount(12, $labels, 'every live item carries a section label');
        tkAssertVerticalsAlternate($labels, 'rendered strip');
        assertTrue(count(array_unique($labels)) >= 6, 'the strip visibly spans the desks: ' . implode(', ', $labels));

        // The label is the section, printed before the headline so it reads as a slug.
        assertContains('<span class="s">Politics</span>Politics story', $html, 'the desk precedes the headline');
        assertContains('<span class="s">Education</span>', $html, 'a thin desk still gets its turn and its label');
    },

    'a raw database row with no section_label still wears a readable desk name' => function (): void {
        tkInit();
        // Every page that is not the front page hands Render rows straight from
        // the database: they carry 'section', never the label Compose stamps.
        $html = Render::ticker([
            tkArt(1, 'technology', 'a', 1),
            tkArt(2, 'us', 'b', 2),
            tkArt(3, 'personal-finance', 'c', 3),
            tkArt(4, '', 'd', 4),
        ], tkCfg());

        assertSame(['Technology', 'U.S.', 'Personal Finance', 'News'], tkRenderedLabels($html));
    },

    'a section_label stamped by Compose wins over the raw slug' => function (): void {
        tkInit();
        $html = Render::ticker([tkArt(1, 'us', 'a', 1, ['section_label' => 'The Nation'])], tkCfg());

        assertSame(['The Nation'], tkRenderedLabels($html));
    },

    'the label is escaped like everything else' => function (): void {
        tkInit();
        $html = Render::ticker([
            tkArt(1, 'x', 'a', 1, ['section_label' => '<script>alert(1)</script>']),
        ], tkCfg());

        assertNotContains('<script>', $html, 'a section label is data, not markup');
        assertContains('&lt;script&gt;', $html);
    },

    'the CSS loop, the tab order and the fresh chip survive the reordering' => function (): void {
        tkInit();
        $html = Render::ticker([
            tkArt(1, 'politics', 'a', 1, ['fresh' => true]),
            tkArt(2, 'world', 'b', 2),
        ], tkCfg());

        assertSame(2, preg_match_all('/<ul[ >]/', $html), 'the -50% keyframe still needs exactly two lists');
        assertContains('<ul aria-hidden="true">', $html, 'the duplicate stays hidden from assistive tech');
        assertSame(2, preg_match_all('/tabindex="-1"/', $html), 'the copy stays out of the tab order');
        assertSame(2, preg_match_all('/class="chip"/', $html), 'one fresh item, rendered twice');
        assertSame('', Render::ticker([], tkCfg()), 'no headlines means no band at all');

        // Both lists carry the same stories in the same order — the CSS loop is
        // seamless only if the copy is a copy.
        preg_match_all('/<ul[^>]*>(.*?)<\/ul>/s', $html, $lists);
        assertSame(
            preg_replace('/ tabindex="-1"/', '', $lists[1][1]),
            $lists[1][0],
            'the mirrored list is the live list, minus the tab stops'
        );
    },

    // ------------------------------------------------------- the front page --

    'Compose fills the front-page ticker one desk at a time, not one clock' => function (): void {
        $m        = Compose::home(tkSlowMorning(), tkCfg(['ticker_count' => 12]), TK_NOW);
        $sections = tkSections($m['ticker']);

        assertCount(12, $m['ticker'], 'the strip fills');
        tkAssertVerticalsAlternate($sections, 'Compose ticker');
        assertTrue(count(array_unique($sections)) >= 6,
            'a 14-story politics flood must not own the strip: ' . implode(', ', $sections));

        // ...and it is a SELECTION difference, not just a reshuffle: newest-first
        // would have taken 12 politics stories and never reached another desk.
        $politics = count(array_filter($sections, static fn(string $s): bool => $s === 'politics'));
        assertLessThanOrEqual(4, $politics, 'the flooded desk gets its turn, not the strip');
    },

    'the front-page strip still opens on the newest story it was given' => function (): void {
        $m = Compose::home(tkSlowMorning(), tkCfg(['ticker_count' => 12]), TK_NOW);

        $newest = max(array_map(static fn(array $r): int => (int) $r['published_at'], $m['ticker']));
        assertSame($newest, (int) $m['ticker'][0]['published_at'],
            'interleaving reorders the desks, it does not bury the latest headline');
    },

    'ticker_count still decides the length, and zero still means no strip' => function (): void {
        $rows = tkSlowMorning();
        assertCount(4, Compose::home($rows, tkCfg(['ticker_count' => 4]), TK_NOW)['ticker'], 'four means four');
        assertCount(0, Compose::home($rows, tkCfg(['ticker_count' => 0]), TK_NOW)['ticker'], 'zero means none');

        // A strip shorter than the number of desks is all different desks.
        $four = tkSections(Compose::home($rows, tkCfg(['ticker_count' => 4]), TK_NOW)['ticker']);
        assertCount(4, array_unique($four), 'four slots, four desks: ' . implode(', ', $four));
    },

    'no story is placed twice, and the ticker takes only what the page left' => function (): void {
        $cfg = ['site' => ['name' => 'Fixture Wire', 'timezone' => 'UTC'],
                'compose' => ['ticker_count' => 12]];
        $m   = Compose::home(tkSlowMorning(), $cfg, TK_NOW);

        $ids = [];
        if (is_array($m['hero']['lead'] ?? null)) {
            $ids[] = (int) $m['hero']['lead']['id'];
        }
        foreach ($m['hero']['subs'] as $r) {
            $ids[] = (int) $r['id'];
        }
        foreach ($m['blocks'] as $b) {
            foreach ($b['items'] as $r) {
                $ids[] = (int) $r['id'];
            }
        }
        foreach ($m['markets'] as $r) {
            $ids[] = (int) $r['id'];
        }
        foreach ($m['ticker'] as $r) {
            $ids[] = (int) $r['id'];
        }

        assertTrue(count($m['ticker']) > 0, 'a real front page still fills its strip');
        assertSame(count($ids), count(array_unique($ids)), 'the interleave must not resurrect a placed story');
        tkAssertVerticalsAlternate(tkSections($m['ticker']), 'ticker under a full front page');
    },

    // ------------------------------------------------- behaviour that stays --

    'the strip still pauses on hover and focus, and stops dead under reduced motion' => function (): void {
        $css = (string) file_get_contents(teb_root() . '/assets/css/site.css');

        assertContains('.ticker:hover .ticker-track', $css, 'hover must pause the strip');
        assertContains('.ticker:focus-within .ticker-track', $css, 'keyboard focus must pause it too');
        assertMatches(
            '/\.ticker:hover \.ticker-track,\.ticker:focus-within \.ticker-track\{animation-play-state:paused\}/',
            $css,
            'both pauses are one rule, and it is a pause not a stop'
        );
        assertMatches(
            '/@media \(prefers-reduced-motion:reduce\)\{[^}]*\.ticker-track\{animation:none\}/s',
            $css,
            'reduced motion must leave the headlines static'
        );
        assertContains('.ticker-vp{overflow-x:auto}', $css, 'static means scrollable, not unreachable');

        $js = (string) file_get_contents(teb_root() . '/assets/js/app.js');
        assertContains('visibilitychange', $js, 'a hidden tab must pause the strip');
        assertContains('animationPlayState', $js, 'and it pauses by play-state, not by rebuilding the DOM');
        assertContains('prefers-reduced-motion', $js, 'JS must not restart what reduced motion stopped');
    },
];
