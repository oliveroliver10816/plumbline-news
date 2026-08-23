<?php
declare(strict_types=1);

/**
 * tests/test_compose.php — TEB\Compose::home()
 *
 * Two things get this front page rejected, and both are provable here.
 *
 * THE FINANCE QUOTA is the client's commercial requirement, not a style note,
 * so most of the first half of this file hands Compose a page that WANTS to be
 * a markets page and proves it does not become one.
 *
 * THE TIER ORDER is the client's editorial requirement: the desks that move
 * fastest sit at the top with the hero, everything slower sits lower down in
 * its own region under its own heading. The second half proves the split is
 * real, is driven by the measured publishing rates in TEB\Feeds, and survives
 * a reordered input.
 *
 * EVERY FIXTURE IS BUILT FROM THE REGISTRY, never from a list of section names
 * typed into this file. The two editions run different desks; a test that
 * hardcoded 'us' and 'international' would pass on neither and would have to be
 * rewritten every time an editor moved a desk. Ask TEB\Feeds what the desks are
 * and the same file tests both sites.
 */

teb_require_app('Feeds', 'Compose');

use TEB\Compose;
use TEB\Feeds;

const NOW_MS = 1787000000000;   // fixed clock — Compose is pure, so the tests are too

// ---------------------------------------------------------------------------
//  fixtures, built from the real desk registry
// ---------------------------------------------------------------------------

/** Front-page desks in the order Compose composes them. @return array<int,array<string,mixed>> */
function desks(): array
{
    return Compose::deskOrder();
}

/** Front-page desk slugs the money quota does NOT apply to. @return array<int,string> */
function newsDesks(): array
{
    $finance = Feeds::financeSections();

    return array_values(array_filter(
        array_column(desks(), 'slug'),
        static fn(string $s): bool => !in_array($s, $finance, true)
    ));
}

/** Desk slugs by tier. @return array<int,string> */
function desksOnTier(int $tier): array
{
    $finance = Feeds::financeSections();
    $out = [];
    foreach (desks() as $d) {
        if ((int) $d['tier'] === $tier && !in_array((string) $d['slug'], $finance, true)) {
            $out[] = (string) $d['slug'];
        }
    }

    return $out;
}

/** The desk the money quota applies to. */
function moneyDesk(): string
{
    $f = Feeds::financeSections();

    return $f[0] ?? 'business';
}

/** Two news desks with genuinely different section priorities: [higher, lower]. */
function twoDesksByPriority(): array
{
    $slugs = newsDesks();
    usort($slugs, static fn(string $a, string $b): int => Feeds::sectionPriority($b) <=> Feeds::sectionPriority($a));
    $high = $slugs[0];
    $low  = null;
    foreach (array_reverse($slugs) as $s) {
        if (Feeds::sectionPriority($s) < Feeds::sectionPriority($high)) {
            $low = $s;
            break;
        }
    }

    return [$high, $low];
}

/** Build one article row. $minutesAgo drives recency decay. */
function art(
    int $id,
    string $section,
    string $source,
    int $minutesAgo,
    bool $image = true,
    float $weight = 1.0,
    array $over = []
): array {
    return array_merge([
        'id'           => $id,
        'title'        => ucfirst($source) . ' story ' . $id . ' on ' . $section,
        'url'          => 'https://example.test/' . $source . '/' . $id,
        'summary'      => 'Feed-provided summary for story ' . $id . '.',
        'image_url'    => $image ? 'https://cdn.example.test/' . $id . '.jpg' : null,
        'published_at' => NOW_MS - ($minutesAgo * 60000),
        'section'      => $section,
        'source'       => $source,
        'source_name'  => strtoupper($source),
        'weight'       => $weight,
        'slug'         => 'story-' . $id,
    ], $over);
}

/** Base config in the shape config.php ships. */
function cfgFixture(array $composeOverrides = []): array
{
    return [
        'site'    => ['name' => 'Fixture Daily', 'timezone' => 'UTC'],
        'compose' => array_merge([
            'finance_max_on_home'      => 2,
            'finance_blocked_blocks'   => [],
            'hero_sub_count'           => 4,
            'per_source_cap_per_block' => 2,
            'ticker_count'             => 12,
        ], $composeOverrides),
    ];
}

/**
 * A full, realistic newsroom: four independent sources on every front-page
 * desk, mixed ages, mixed pictures — enough that every cap and every rule has
 * something to bite on.
 */
function newsroomRows(array $opts = []): array
{
    $skip    = (array) ($opts['skip'] ?? []);
    $perDesk = (int) ($opts['per_source'] ?? 4);
    $rows    = [];
    $id      = 100;
    $minutes = 20;

    foreach (desks() as $d) {
        $slug = (string) $d['slug'];
        if (in_array($slug, $skip, true)) {
            continue;
        }
        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $n => $source) {
            for ($i = 0; $i < $perDesk; $i++) {
                $rows[] = art($id, $slug, $source . '-' . $slug, $minutes, ($id % 3) !== 0, 1.0 + ($n * 0.05));
                $minutes += 7;
                $id++;
            }
        }
    }

    // A desk that is not on the front page at all: hero- and ticker-eligible,
    // but it must never grow a block of its own.
    foreach (['echo-off', 'foxtrot-off'] as $source) {
        for ($i = 0; $i < 3; $i++) {
            $rows[] = art($id++, 'not-a-desk', $source, $minutes, false, 0.9);
            $minutes += 7;
        }
    }

    return $rows;
}

/**
 * The adversarial input: money is the freshest, the heaviest and always has a
 * picture, so on score alone it would own the hero and the top of the page.
 */
function financeHeavyRows(int $financeCount = 8): array
{
    $rows = [];
    $id   = 900;
    $desk = moneyDesk();
    for ($i = 0; $i < $financeCount; $i++) {
        $rows[] = art($id++, $desk, 'wire' . ($i % 3), 1 + $i, true, 3.0);
    }
    // ...and the general news is stale, unillustrated and lightly weighted.
    foreach (newsroomRows() as $row) {
        $row['published_at'] = $row['published_at'] - (600 * 60000);
        $row['image_url']    = null;
        $row['weight']       = 0.6;
        $rows[]              = $row;
    }

    return $rows;
}

/** Every item the model puts on the page, in every region. */
function allItems(array $m): array
{
    $out = [];
    if (is_array($m['hero']['lead'] ?? null)) {
        $out[] = $m['hero']['lead'];
    }
    foreach ($m['hero']['subs'] ?? [] as $r) {
        $out[] = $r;
    }
    foreach ($m['rail'] ?? [] as $r) {
        $out[] = $r;
    }
    foreach ($m['blocks'] ?? [] as $b) {
        foreach ($b['items'] as $r) {
            $out[] = $r;
        }
    }
    foreach ($m['markets'] ?? [] as $r) {
        $out[] = $r;
    }
    foreach ($m['ticker'] ?? [] as $r) {
        $out[] = $r;
    }

    return $out;
}

/** Everything on the page that is a CARD — the hero, the blocks, the strip. */
function cardItems(array $m): array
{
    $out = [];
    if (is_array($m['hero']['lead'] ?? null)) {
        $out[] = $m['hero']['lead'];
    }
    foreach ($m['hero']['subs'] ?? [] as $r) {
        $out[] = $r;
    }
    foreach ($m['blocks'] ?? [] as $b) {
        foreach ($b['items'] as $r) {
            $out[] = $r;
        }
    }
    foreach ($m['markets'] ?? [] as $r) {
        $out[] = $r;
    }

    return $out;
}

function financeItems(array $m): array
{
    return array_values(array_filter(allItems($m), static fn(array $r): bool => !empty($r['is_finance'])));
}

function blockIds(array $m): array
{
    return array_map(static fn(array $b): string => (string) $b['id'], $m['blocks']);
}

/** The ids of the blocks in one region of the model. @return array<int,string> */
function regionBlocks(array $m, string $id): array
{
    foreach ($m['regions'] ?? [] as $r) {
        if ((string) ($r['id'] ?? '') === $id) {
            return array_map('strval', $r['blocks']);
        }
    }

    return [];
}

function modelIsWellFormed(array $m): void
{
    foreach (['ticker', 'hero', 'rail', 'blocks', 'regions', 'markets'] as $k) {
        assertTrue(array_key_exists($k, $m), 'model has ' . $k);
    }
    foreach (['ticker', 'rail', 'blocks', 'regions', 'markets'] as $k) {
        assertTrue(is_array($m[$k]), $k . ' is an array');
    }
    assertTrue(array_key_exists('lead', $m['hero']), 'hero has lead');
    assertTrue(is_array($m['hero']['subs']), 'hero subs is an array');
    if ($m['hero']['lead'] !== null) {
        assertTrue(is_array($m['hero']['lead']), 'hero lead is a row or null');
    }

    $inRegions = [];
    foreach ($m['regions'] as $region) {
        foreach (['id', 'label', 'note', 'blocks'] as $k) {
            assertTrue(array_key_exists($k, $region), 'region carries ' . $k);
        }
        assertTrue(count($region['blocks']) > 0, 'no empty region is emitted');
        foreach ($region['blocks'] as $id) {
            assertFalse(isset($inRegions[$id]), 'block ' . $id . ' is in two regions at once');
            $inRegions[$id] = true;
        }
    }
    foreach ($m['blocks'] as $b) {
        foreach (['id', 'label', 'href', 'grid', 'tier', 'region', 'items'] as $k) {
            assertTrue(array_key_exists($k, $b), "block carries $k");
        }
        assertTrue(count($b['items']) > 0, 'no empty block is emitted');
        assertTrue(strpos((string) $b['href'], '://') === false, 'href is a route path, not a URL');
        assertTrue(isset($inRegions[(string) $b['id']]), 'block ' . $b['id'] . ' belongs to no region');
        assertTrue(strpos((string) $b['grid'], 'block-grid') === 0, 'grid is a block-grid class');
    }
    assertSame(count($m['blocks']), count($inRegions), 'the regions name exactly the blocks that exist');

    foreach (allItems($m) as $r) {
        assertTrue(isset($r['id']) && is_int($r['id']) && $r['id'] > 0, 'every item has an int id');
        assertTrue(isset($r['title']) && $r['title'] !== '', 'every item has a title');
    }
}

return [

    // ---------------------------------------------------------------- quota --

    'finance-heavy input still yields a finance-free hero' => function (): void {
        $m = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        assertTrue($m['hero']['lead'] !== null, 'a finance-heavy page still has a lead');
        assertFalse((bool) $m['hero']['lead']['is_finance'], 'hero lead is not finance');
        foreach ($m['hero']['subs'] as $r) {
            assertFalse((bool) $r['is_finance'], 'hero sub ' . $r['id'] . ' is not finance');
        }
        foreach ($m['rail'] as $r) {
            assertFalse((bool) $r['is_finance'], 'rail item ' . $r['id'] . ' is not finance');
        }
        assertTrue(count($m['hero']['subs']) > 0, 'hero subs are populated from general news');
    },

    'finance-heavy input still yields finance-free first two blocks' => function (): void {
        $m    = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        $lead = array_slice(desksOnTier(1), 0, 2);
        assertSame($lead, array_slice(blockIds($m), 0, 2), 'the fast desks still lead the page');
        foreach (array_slice($m['blocks'], 0, 2) as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool) $r['is_finance'], 'block ' . $b['id'] . ' item ' . $r['id'] . ' is not finance');
            }
            // Not merely finance-free: still FULL. Money must not be able to eat a
            // block's slots and leave a hole where the general news should be.
            assertGreaterThanOrEqual(4, count($b['items']), "block {$b['id']} is still filled with general news");
        }
    },

    'finance never appears in any block, only in the markets strip' => function (): void {
        $m = Compose::home(financeHeavyRows(), cfgFixture(), NOW_MS);
        foreach ($m['blocks'] as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool) $r['is_finance'], 'no finance in block ' . $b['id']);
            }
        }
        foreach ($m['ticker'] as $r) {
            assertFalse((bool) $r['is_finance'], 'the ticker is headlines, never markets');
        }
        assertNotContains(moneyDesk(), blockIds($m), 'the money desk never becomes a block of its own');
        assertTrue(count($m['markets']) > 0, 'the markets strip is where finance surfaces');
        foreach ($m['markets'] as $r) {
            assertTrue((bool) $r['is_finance'], 'markets strip carries the finance rows');
        }
    },

    'total finance on the whole front page respects the cap' => function (): void {
        $m = Compose::home(financeHeavyRows(20), cfgFixture(['finance_max_on_home' => 2]), NOW_MS);
        assertCount(2, financeItems($m), 'exactly the cap, with 20 finance stories on offer');
    },

    'raising finance_max_on_home really changes the output' => function (): void {
        $rows = financeHeavyRows(12);
        $two  = Compose::home($rows, cfgFixture(['finance_max_on_home' => 2]), NOW_MS);
        $five = Compose::home($rows, cfgFixture(['finance_max_on_home' => 5]), NOW_MS);
        assertCount(2, financeItems($two), 'cap 2 puts 2 finance items on the page');
        assertCount(5, financeItems($five), 'cap 5 puts 5 finance items on the page');
        assertCount(2, $two['markets'], 'markets strip follows the cap');
        assertCount(5, $five['markets'], 'markets strip follows the raised cap');
        assertNotSame($two, $five, 'the config value changes the model');
        assertFalse((bool) $five['hero']['lead']['is_finance'], 'a raised cap still cannot touch the hero');
    },

    'a zero cap removes finance from the page entirely' => function (): void {
        $m = Compose::home(financeHeavyRows(12), cfgFixture(['finance_max_on_home' => 0]), NOW_MS);
        assertCount(0, financeItems($m), 'cap 0 means no finance anywhere');
        assertCount(0, $m['markets'], 'markets strip is empty');
        assertTrue($m['hero']['lead'] !== null, 'the rest of the page still renders');
    },

    'finance_blocked_blocks is read, not decorative' => function (): void {
        $rows = financeHeavyRows(12);
        $open = Compose::home($rows, cfgFixture(), NOW_MS);
        $shut = Compose::home($rows, cfgFixture(['finance_blocked_blocks' => ['markets']]), NOW_MS);
        assertTrue(count($open['markets']) > 0, 'markets strip fills by default');
        assertCount(0, $shut['markets'], 'blocking the strip in config empties it');
        assertCount(0, financeItems($shut), 'and finance then has nowhere left to go');
    },

    'reads a row in the exact shape Db::recentArticles returns' => function (): void {
        // Db hands us source_slug / source_name / source_weight (not 'source' /
        // 'weight'), casts published_at to int — so a NULL date arrives as 0, not
        // null — and casts a NULL image_url to ''. If Compose missed those key
        // names every story would silently collapse to weight 1.0 and one unknown
        // source, which would quietly disable the per-source caps.
        [$deskA, $deskB] = twoDesksByPriority();
        $dbRow = static function (int $id, string $slug, string $section, float $weight, int $ms, string $img): array {
            return [
                'id' => $id, 'source_id' => 3,
                'source_slug' => $slug, 'source_name' => strtoupper($slug),
                'source_weight' => $weight, 'source_tier' => 1, 'source_homepage' => 'https://' . $slug . '.test/',
                'section' => $section, 'url' => 'https://' . $slug . '.test/story/' . $id,
                'title' => 'Story ' . $id, 'title_key' => 'story-' . $id, 'summary' => 'Summary.',
                'body' => 'The whole article, as the feed supplied it.',
                'image_url' => $img, 'image_width' => 800, 'image_height' => 533,
                'author' => 'A Reporter', 'published_at' => $ms, 'fetched_at' => NOW_MS,
                'guid_hash' => str_repeat((string) $id, 8),
            ];
        };
        $rows = [
            $dbRow(1, 'heavywire', $deskA, 2.4, NOW_MS - 1800000, 'https://cdn.test/1.jpg'),
            $dbRow(2, 'lightwire', $deskA, 0.3, NOW_MS - 1800000, 'https://cdn.test/2.jpg'),
            $dbRow(3, 'lightwire', $deskB, 0.3, 0, ''),           // NULL date arrived as 0
        ];
        $m = Compose::home($rows, cfgFixture(), NOW_MS);

        assertSame(1, $m['hero']['lead']['id'], 'source_weight was read off the joined row');
        assertSame('heavywire', $m['hero']['lead']['source'], 'source_slug was read');
        assertSame('HEAVYWIRE', $m['hero']['lead']['source_name'], 'source_name survives');
        assertSame(2.4, $m['hero']['lead']['weight'], 'the weight is the source weight, not the 1.0 default');

        // Rule 4 of the build: every image ships explicit width and height. The
        // renderer can only do that if Compose passes the columns through.
        assertSame(800, $m['hero']['lead']['image_width'], 'image_width survives composition');
        assertSame(533, $m['hero']['lead']['image_height'], 'image_height survives composition');
        assertSame('A Reporter', $m['hero']['lead']['author'], 'unknown columns are preserved for the renderer');
        assertContains('whole article', $m['hero']['lead']['body'], 'the body the article page needs is not dropped');

        // Identity is the SLUG. Db COALESCEs s.name over a.source_name, so one
        // publisher can arrive under two display names in the same batch; if the
        // cap keyed off the name they would count as two sources and both get in.
        $twoNames = [
            array_merge($dbRow(10, 'nyt', $deskA, 1.3, NOW_MS - 600000, 'https://cdn.test/a.jpg'),
                ['source_name' => 'The New York Times']),
            array_merge($dbRow(11, 'nyt', $deskA, 1.3, NOW_MS - 900000, 'https://cdn.test/b.jpg'),
                ['source_name' => 'NYT']),
            $dbRow(12, 'upi', $deskA, 1.0, NOW_MS - 1200000, 'https://cdn.test/c.jpg'),
        ];
        $capped = Compose::home($twoNames, cfgFixture([
            'per_source_cap_per_block' => 1, 'hero_sub_count' => 2, 'rail_count' => 0,
        ]), NOW_MS);
        $heroSources = array_map(static fn(array $r): string => $r['source'], $capped['hero']['subs']);
        array_unshift($heroSources, $capped['hero']['lead']['source']);
        assertSame(['nyt', 'upi'], $heroSources, 'two display names for one slug are still ONE source');

        $undated = null;
        foreach (allItems($m) as $r) {
            if ($r['id'] === 3) {
                $undated = $r;
            }
        }
        assertNotNull($undated, 'the undated row is still placed');
        assertNull($undated['published_at'], 'published_at 0 is normalised to null, never to 1970');
        assertFalse($undated['has_image'], 'an empty image_url is not an image');
        assertNull($undated['image_url'], 'and it is normalised to null for the renderer');
    },

    // ------------------------------------------------------------ selection --

    'per-source cap per block holds while another source could fill the slot' => function (): void {
        $m = Compose::home(newsroomRows(), cfgFixture(['per_source_cap_per_block' => 2]), NOW_MS);
        foreach ($m['blocks'] as $b) {
            $bySource = [];
            foreach ($b['items'] as $r) {
                $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
            }
            // Four sources on every desk in this fixture, so the cap is never
            // relaxed and 2 really means 2.
            assertGreaterThanOrEqual(3, count($bySource), "block {$b['id']} should have drawn on several sources");
            foreach ($bySource as $source => $n) {
                assertTrue($n <= 2, "block {$b['id']} took $n from $source (cap 2)");
            }
        }
        $heroBySource = [];
        foreach (array_merge([$m['hero']['lead']], $m['hero']['subs']) as $r) {
            $heroBySource[$r['source']] = ($heroBySource[$r['source']] ?? 0) + 1;
        }
        foreach ($heroBySource as $source => $n) {
            assertTrue($n <= 2, "hero took $n from $source (cap 2)");
        }
    },

    'a desk fed by ONE newsroom still gets a real block' => function (): void {
        // The cap exists to stop one publisher filling a block SEVERAL could have
        // filled. On this roster a desk can be fed by a single feed, and a hard cap
        // of two turns forty available stories into a two-card stub — a cap doing
        // damage rather than work. It is raised only far enough to reach a viable
        // block, and only when the sources present cannot reach one on their own.
        $desk = desksOnTier(1)[0] ?? newsDesks()[0];
        $rows = [];
        for ($i = 0; $i < 12; $i++) {
            $rows[] = art(400 + $i, $desk, 'onlyfeed', 10 + ($i * 5));
        }
        $m = Compose::home($rows, cfgFixture(['hero_sub_count' => 0, 'rail_count' => 0]), NOW_MS);

        $block = null;
        foreach ($m['blocks'] as $b) {
            if ($b['id'] === $desk) {
                $block = $b;
            }
        }
        assertNotNull($block, 'the single-source desk still gets a block');
        assertGreaterThan(2, count($block['items']),
            'a one-feed desk was capped at the two-source rate: ' . count($block['items']) . ' cards');

        // ...and the cap is NOT relaxed when a second source is available.
        $mixed = [];
        for ($i = 0; $i < 12; $i++) {
            $mixed[] = art(500 + $i, $desk, 'feed-' . ($i % 4), 10 + ($i * 5));
        }
        $m2 = Compose::home($mixed, cfgFixture(['hero_sub_count' => 0, 'rail_count' => 0]), NOW_MS);
        foreach ($m2['blocks'] as $b) {
            if ($b['id'] !== $desk) {
                continue;
            }
            $bySource = [];
            foreach ($b['items'] as $r) {
                $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
            }
            foreach ($bySource as $source => $n) {
                assertLessThanOrEqual(2, $n, "four sources available and $source still supplied $n");
            }
        }
    },

    'one newsroom cannot own the front page, however many feeds it runs' => function (): void {
        // THE reason Feeds carries a 'publisher' alongside a 'slug'. Nine feeds,
        // one newsroom: a per-SOURCE cap counts them as nine independent voices
        // and lets the page fill up with one masthead.
        $rows = [];
        $id   = 600;
        foreach (newsDesks() as $desk) {
            for ($i = 0; $i < 8; $i++) {
                $rows[] = art($id++, $desk, 'house-' . $desk . '-' . $i, 5 + $i, true, 1.4, [
                    'publisher' => 'one-house',
                ]);
            }
            for ($i = 0; $i < 2; $i++) {
                $rows[] = art($id++, $desk, 'indie-' . $desk . '-' . $i, 200 + $i, false, 0.9, [
                    'publisher' => 'indie-' . $desk,
                ]);
            }
        }

        $capped = Compose::home($rows, cfgFixture(['publisher_max_on_home' => 6]), NOW_MS);
        $n = 0;
        foreach (cardItems($capped) as $r) {
            if ($r['publisher'] === 'one-house') {
                $n++;
            }
        }
        assertLessThanOrEqual(6, $n, "the house newsroom took $n cards against a cap of 6");
        assertTrue(count(cardItems($capped)) > $n, 'and the rest of the page still filled from elsewhere');

        // The budget is a limit, not a fixed ration: raising it puts more on.
        $open = Compose::home($rows, cfgFixture(['publisher_max_on_home' => 40]), NOW_MS);
        $n2 = 0;
        foreach (cardItems($open) as $r) {
            if ($r['publisher'] === 'one-house') {
                $n2++;
            }
        }
        assertGreaterThan($n, $n2, 'publisher_max_on_home is not being read');
    },

    'the publisher budget never empties a region' => function (): void {
        // Every story on the page comes from one newsroom and the budget is one.
        // A cap that answered "then nothing may be placed" would blank the site.
        $rows = [];
        $id   = 700;
        foreach (newsDesks() as $desk) {
            for ($i = 0; $i < 4; $i++) {
                $rows[] = art($id++, $desk, 'sole-' . $desk, 10 + $i, true, 1.0, ['publisher' => 'sole']);
            }
        }
        $m = Compose::home($rows, cfgFixture(['publisher_max_on_home' => 1]), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'a one-newsroom day still has a lead');
        assertGreaterThanOrEqual(2, count($m['blocks']), 'and still has blocks');
    },

    'the budget gives way just far enough to keep a region alive, then binds again' => function (): void {
        // The escape hatch above must not become the rule.
        //
        // ⚠ THE FIXTURE HAS TO GIVE EACH DESK SEVERAL SOURCES UNDER ONE
        // PUBLISHER, and that is the whole point of it. With one source per desk
        // the PER-SOURCE cap already holds the block down and this test passes
        // whether the publisher budget is bounded or not — it proves nothing.
        // Six sources, one masthead, is the real shape on this roster: The
        // Conversation files nine separate feeds.
        //
        // So: one newsroom feeds every desk through six sources, the page budget
        // is one card, and the tier counts are set to twelve. Every block after
        // the first would be empty, so every block after the first takes the
        // relaxed path. Bounded, each may reach a VIABLE size and no further;
        // unbounded — which is what this used to do — each filled to twelve from
        // the same newsroom and the page-wide budget did nothing from the second
        // block onwards.
        $rows = [];
        $id   = 900;
        foreach (newsDesks() as $desk) {
            for ($src = 0; $src < 6; $src++) {
                for ($i = 0; $i < 3; $i++) {
                    $rows[] = art(
                        $id++,
                        $desk,
                        'house-' . $desk . '-' . $src,
                        10 + $i,
                        true,
                        1.0,
                        ['publisher' => 'house']
                    );
                }
            }
        }
        $cfg = cfgFixture([
            'publisher_max_on_home'    => 1,
            'per_source_cap_per_block' => 3,
            'tier_block_counts'        => [1 => 12, 2 => 12, 3 => 12],
            'rail_count'               => 0,
            'hero_sub_count'           => 0,
        ]);
        $m = Compose::home($rows, $cfg, NOW_MS);
        modelIsWellFormed($m);

        assertGreaterThanOrEqual(2, count($m['blocks']), 'the relaxation must still keep the page whole');

        $worst = 0;
        foreach ($m['blocks'] as $b) {
            $fromHouse = 0;
            foreach ($b['items'] as $r) {
                if ($r['publisher'] === 'house') {
                    $fromHouse++;
                }
            }
            $worst = max($worst, $fromHouse);
            assertGreaterThan(0, count($b['items']), "block {$b['id']} came out empty");
        }

        // MIN_BLOCK_CARDS is four; one more is the card the budget itself still
        // allowed. Twelve — the tier count — is the unbounded answer.
        assertLessThanOrEqual(
            5,
            $worst,
            "a block took $worst cards from a newsroom whose page budget was one — "
            . 'the relaxation is unbounded again'
        );
    },

    'tightening the per-source cap changes the output' => function (): void {
        $rows = newsroomRows();
        $two  = Compose::home($rows, cfgFixture(['per_source_cap_per_block' => 2]), NOW_MS);
        $one  = Compose::home($rows, cfgFixture(['per_source_cap_per_block' => 1]), NOW_MS);
        assertNotSame($two, $one, 'per_source_cap_per_block is read');
        foreach ($one['blocks'] as $b) {
            $seen = [];
            foreach ($b['items'] as $r) {
                assertFalse(isset($seen[$r['source']]), "cap 1: {$b['id']} took {$r['source']} twice");
                $seen[$r['source']] = true;
            }
        }
    },

    'no source leads twice' => function (): void {
        $m     = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        $leads = [$m['hero']['lead']['source']];
        foreach ($m['blocks'] as $b) {
            $leads[] = $b['items'][0]['source'];
        }
        if ($m['markets']) {
            $leads[] = $m['markets'][0]['source'];
        }
        assertSame(count($leads), count(array_unique($leads)), 'lead sources are unique: ' . implode(',', $leads));
        assertTrue(count($leads) >= 4, 'the fixture really does exercise several lead slots');
    },

    'the repeat-source penalty reorders, it does not merely rescore' => function (): void {
        // alpha owns the three best stories; bravo's is clearly weaker. With the
        // penalty switched off this is [alpha, alpha, alpha]; with it, bravo is lifted
        // into second place. The cap is set wide so it cannot be doing the work.
        $desk = newsDesks()[0];
        $rows = [
            art(1, $desk, 'alpha', 0, false, 1.0),
            art(2, $desk, 'alpha', 10, false, 1.0),
            art(3, $desk, 'alpha', 20, false, 1.0),
            art(4, $desk, 'bravo', 60, false, 1.0),
        ];
        $m = Compose::home($rows, cfgFixture([
            'hero_sub_count'           => 2,
            'rail_count'               => 0,
            'per_source_cap_per_block' => 3,
        ]), NOW_MS);
        assertSame('alpha', $m['hero']['lead']['source'], 'the best story still leads');
        assertSame(['bravo', 'alpha'], array_map(
            static fn(array $r): string => $r['source'],
            $m['hero']['subs']
        ), 'the second alpha is pushed below bravo by the repeat-source penalty');
    },

    'recency, source weight, desk priority, the picture and the licence all score' => function (): void {
        $desk = newsDesks()[0];
        $cfg  = cfgFixture(['rail_count' => 0]);

        // Fresh-but-plain beats old-but-illustrated: with the decay removed the image
        // bonus would win this, so this asserts the decay itself, not a tiebreak.
        $m = Compose::home([
            art(1, $desk, 'alpha', 6000, true, 1.0),
            art(2, $desk, 'alpha', 5, false, 1.0),
        ], $cfg, NOW_MS);
        assertSame(2, $m['hero']['lead']['id'], 'recency decay puts the fresh story on top');
        assertGreaterThan(
            $m['hero']['subs'][0]['score'],
            $m['hero']['lead']['score'],
            'four days of decay outweighs the image bonus'
        );

        // heavier source wins at equal age
        $m = Compose::home([
            art(3, $desk, 'alpha', 30, false, 0.6),
            art(4, $desk, 'bravo', 30, false, 1.6),
        ], $cfg, NOW_MS);
        assertSame(4, $m['hero']['lead']['id'], 'source weight is applied');

        // the higher-priority desk wins at equal age — and the priorities are the
        // registry's, so moving a desk in Feeds moves it here too.
        [$high, $low] = twoDesksByPriority();
        assertNotNull($low, 'the registry must give two desks different priorities');
        $m = Compose::home([
            art(5, $low, 'alpha', 30, false, 1.0),
            art(6, $high, 'alpha', 30, false, 1.0),
        ], $cfg, NOW_MS);
        assertSame(6, $m['hero']['lead']['id'], $high . ' outranks ' . $low . ' at equal age');

        // The half-life is what balances "fresh" against "authoritative", so it must
        // be able to flip that decision — a hardcoded constant would pass everything
        // above this line.
        $freshLight = art(11, $desk, 'alpha', 30, false, 1.0);
        $staleHeavy = art(12, $desk, 'bravo', 600, false, 3.0);
        $short = Compose::home([$freshLight, $staleHeavy], cfgFixture(['half_life_hours' => 1, 'rail_count' => 0]), NOW_MS);
        $long  = Compose::home([$freshLight, $staleHeavy], cfgFixture(['half_life_hours' => 48, 'rail_count' => 0]), NOW_MS);
        assertSame(11, $short['hero']['lead']['id'], 'a short half-life makes freshness decisive');
        assertSame(12, $long['hero']['lead']['id'], 'a long half-life lets the heavier source win');

        // the picture breaks an otherwise exact tie
        $m = Compose::home([
            art(9, $desk, 'alpha', 30, false, 1.0),
            art(10, $desk, 'alpha', 30, true, 1.0),
        ], $cfg, NOW_MS);
        assertSame(10, $m['hero']['lead']['id'], 'image bonus breaks the tie');

        // ...and a story we may only QUOTE ranks below one we may publish whole.
        // The whole point of this roster is full-length articles; a four-hundred
        // character extract should not be the lead when a real one is available.
        $m = Compose::home([
            art(13, $desk, 'alpha', 30, false, 1.0, ['extract' => true]),
            art(14, $desk, 'bravo', 30, false, 1.0, ['extract' => false]),
        ], $cfg, NOW_MS);
        assertSame(14, $m['hero']['lead']['id'], 'the extract-only story should not out-rank a full one');
        assertTrue((bool) $m['hero']['subs'][0]['is_extract'], 'and it is still on the page, flagged');
    },

    'a picture we may not republish is not treated as a picture' => function (): void {
        // Nearly every newsroom on this roster licenses its text and withholds its
        // photographs. Scoring those rows as illustrated would lay the page out
        // around images the renderer is never going to draw.
        $desk = newsDesks()[0];
        $m = Compose::home([
            art(1, $desk, 'alpha', 30, true, 1.0, ['images_allowed' => false]),
            art(2, $desk, 'alpha', 30, true, 1.0, ['images_allowed' => true]),
        ], cfgFixture(['rail_count' => 0]), NOW_MS);

        assertSame(2, $m['hero']['lead']['id'], 'the licensed picture wins the image bonus');
        assertFalse((bool) $m['hero']['subs'][0]['has_image'], 'an unlicensed picture is not an image');
        assertNotNull($m['hero']['subs'][0]['image_url'], 'the URL itself is preserved for the renderer to judge');
    },

    'the grid a block is drawn in follows what the block actually holds' => function (): void {
        $desks = newsDesks();
        $rows  = [];
        $id    = 800;
        foreach ($desks as $i => $desk) {
            for ($j = 0; $j < 8; $j++) {
                // Every desk imageless except the first.
                $rows[] = art($id++, $desk, 'src-' . $desk . '-' . ($j % 3), 10 + $j, $i === 0, 1.0);
            }
        }
        $m = Compose::home($rows, cfgFixture(['hero_sub_count' => 0, 'rail_count' => 0]), NOW_MS);

        $grids = [];
        foreach ($m['blocks'] as $b) {
            $grids[$b['id']] = $b['grid'];
        }
        assertTrue(count($grids) >= 3, 'the fixture builds several blocks');
        assertNotContains('block-grid--wire', $grids[$desks[0]] ?? '', 'a desk with pictures gets a picture grid');

        // The no-picture desks alternate, so the lower half of the page is not one
        // long identical wall of text bands.
        $textGrids = [];
        foreach ($m['blocks'] as $b) {
            if ($b['id'] !== $desks[0]) {
                $textGrids[] = $b['grid'];
            }
        }
        assertTrue(count(array_unique($textGrids)) >= 2,
            'every no-picture desk drew the same grid: ' . implode(' / ', array_unique($textGrids)));
    },

    'a page whose text is the publisher\'s navigation never reaches the front page' => function (): void {
        // Measured on this roster: NASA's APOD and Earth Observatory items
        // arrive with the site's own menu as their article text. They are long,
        // dated and illustrated, so they score well — one of them took the LEAD
        // SLOT. A menu is not a story.
        $desk = newsDesks()[0];
        $menu = 'APOD Science APOD APOD: 2026 August 22 Today\'s APOD Archive Submissions Index Search '
              . 'Calendar RSS Education About Discuss APOD Astronomy Picture of the Day Discover the '
              . 'cosmos Each day a different image or photograph of our fascinating universe is featured';

        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = art(300 + $i, $desk, 'plain-' . $i, 40 + $i, true, 1.0, [
                'body' => 'A perfectly ordinary opening paragraph about the subject of this story, written '
                    . 'in sentences, running on for long enough that the window has something to read and '
                    . 'repeating no single word anywhere near five times inside it.',
            ]);
        }
        // Newest and heaviest, so it would lead on every other rule.
        $rows[] = art(400, $desk, 'menu-house', 1, true, 3.0, ['body' => $menu]);

        $m   = Compose::home($rows, cfgFixture(), NOW_MS);
        $ids = array_map(static fn(array $r): int => (int) $r['id'], allItems($m));
        assertNotContains(400, $ids, 'a scraped navigation bar was promoted onto the front page');
        assertTrue(count($ids) > 4, 'and the rest of the page still composed');

        // ⚠ The line has to sit ABOVE what real writing does, or it deletes
        // journalism. A real article repeating one word four times in its first
        // forty — a piece about Lake Mead, a piece about Texas — must survive.
        $lake = art(401, $desk, 'lake-house', 1, true, 3.0, [
            'body' => 'A view over Lake Mead shows how far the lake level has fallen since the lake was '
                . 'last full, and the lake now sits well below the intake that the city depends on for '
                . 'the water it drinks every single day of the year.',
        ]);
        $m2   = Compose::home(array_merge($rows, [$lake]), cfgFixture(), NOW_MS);
        $ids2 = array_map(static fn(array $r): int => (int) $r['id'], allItems($m2));
        assertContains(401, $ids2, 'a real article that repeats a word four times was thrown away');
    },

    'the ticker interleaves the desks, is capped, and its count is configurable' => function (): void {
        $rows = newsroomRows();
        for ($i = 0; $i < 9; $i++) {
            $rows[] = art(700 + $i, 'not-a-desk', 'flood', 3 + $i, true, 1.0);
        }
        $m = Compose::home($rows, cfgFixture(['ticker_count' => 12]), NOW_MS);
        assertCount(12, $m['ticker'], 'the ticker fills to its configured length');

        // The strip cycles the verticals rather than the clock, so it is NOT
        // latest-first — it opens on the latest story and then takes the newest of
        // each other desk in turn. tests/test_ticker.php owns the full alternation
        // property; this is the front page's end of it.
        $newest = max(array_map(static fn(array $r): int => (int) $r['published_at'], $m['ticker']));
        assertSame($newest, (int) $m['ticker'][0]['published_at'], 'the strip opens on the newest story');

        $sections = array_map(static fn(array $r): string => (string) $r['section'], $m['ticker']);
        for ($i = 0; $i + 1 < count($sections); $i++) {
            if ($sections[$i] !== $sections[$i + 1]) {
                continue;
            }
            assertSame(
                [$sections[$i]],
                array_values(array_unique(array_slice($sections, $i))),
                'two desks in a row are only allowed once every other desk is spent: '
                . implode(' -> ', $sections)
            );
        }
        assertTrue(count(array_unique($sections)) > 2, 'the strip spans the desks: ' . implode(', ', $sections));

        $bySource = [];
        foreach ($m['ticker'] as $r) {
            $bySource[$r['source']] = ($bySource[$r['source']] ?? 0) + 1;
        }
        foreach ($bySource as $source => $n) {
            assertLessThanOrEqual(2, $n, "the ticker took $n from $source");
        }

        assertCount(4, Compose::home($rows, cfgFixture(['ticker_count' => 4]), NOW_MS)['ticker'], 'ticker_count is read');
        assertCount(0, Compose::home($rows, cfgFixture(['ticker_count' => 0]), NOW_MS)['ticker'], 'zero means no ticker');
    },

    'the rail is a different list, not more of the same desk' => function (): void {
        $m = Compose::home(newsroomRows(), cfgFixture(['rail_count' => 6]), NOW_MS);
        assertCount(6, $m['rail'], 'the rail fills to its configured length');

        $sources = array_map(static fn(array $r): string => (string) $r['source'], $m['rail']);
        assertSame(count($sources), count(array_unique($sources)), 'one story per source in the rail');
        $sections = array_map(static fn(array $r): string => (string) $r['section'], $m['rail']);
        assertTrue(count(array_unique($sections)) >= 3, 'the rail spans desks: ' . implode(', ', $sections));

        assertCount(0, Compose::home(newsroomRows(), cfgFixture(['rail_count' => 0]), NOW_MS)['rail'], 'zero means no rail');
    },

    // ------------------------------------------------------------ structure --

    'the front page carries enough stories to be a front page' => function (): void {
        // The client asked for a dense page. Raised from 45 to 52 on 2026-08-23
        // when two fast full-text sources were added to fix a stale front page:
        // the extra desks legitimately push the count to ~47. Still pinned at
        // BOTH ends, so a future change cannot quietly halve the page either.
        $m = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        $n = count(cardItems($m));
        assertGreaterThanOrEqual(30, $n, "only $n cards on the front page");
        assertLessThanOrEqual(52, $n, "$n cards is past the point the grid stays legible");
    },

    'no article id appears twice anywhere in the model, rail and ticker included' => function (): void {
        foreach ([newsroomRows(), financeHeavyRows(12)] as $i => $rows) {
            $m   = Compose::home($rows, cfgFixture(), NOW_MS);
            $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
            assertTrue(count($ids) > 20, "fixture $i places a real page (" . count($ids) . ' items)');
            assertSame(count($ids), count(array_unique($ids)), "fixture $i emitted a duplicate id");
            assertTrue(count($m['ticker']) > 0, "fixture $i fills the ticker");
            assertTrue(count($m['rail']) > 0, "fixture $i fills the rail");
        }
    },

    'duplicate input rows are collapsed, not emitted twice' => function (): void {
        $rows = newsroomRows();
        $dupe = array_merge($rows, array_slice($rows, 0, 6));
        $m    = Compose::home($dupe, cfgFixture(), NOW_MS);
        $ids  = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'a repeated input row is placed once');
    },

    'blocks come out in tier order, fastest desks first' => function (): void {
        $m     = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        $ids   = blockIds($m);
        $order = array_column(desks(), 'slug');

        assertSame(array_values(array_intersect($order, $ids)), $ids, 'blocks follow Compose::deskOrder()');

        $tiers = array_map(static fn(array $b): int => (int) $b['tier'], $m['blocks']);
        $sorted = $tiers;
        sort($sorted);
        assertSame($sorted, $tiers, 'a slower desk sits above a faster one: ' . implode(',', $tiers));

        assertNotContains('markets', $ids, 'markets is the strip after the blocks, not a block');
        assertSame('/section/' . $ids[0], $m['blocks'][0]['href'], 'block href is a route path');
    },

    'the page splits into a fast region and a slower one, and says so' => function (): void {
        $m = Compose::home(newsroomRows(), cfgFixture(), NOW_MS);
        modelIsWellFormed($m);

        $lead  = regionBlocks($m, Compose::REGION_LEAD);
        $desks = regionBlocks($m, Compose::REGION_DESKS);
        assertTrue(count($lead) > 0, 'the top of the page is a region');
        assertTrue(count($desks) > 0, 'and the slower desks are their own region');
        assertSame(blockIds($m), array_merge($lead, $desks), 'the regions partition the blocks, in order');

        $tierOf = [];
        foreach ($m['blocks'] as $b) {
            $tierOf[(string) $b['id']] = (int) $b['tier'];
        }
        foreach ($lead as $id) {
            assertSame(1, $tierOf[$id], "the fast region holds a tier-{$tierOf[$id]} desk ($id)");
        }
        foreach ($desks as $id) {
            assertGreaterThan(1, $tierOf[$id], "the slower region holds a tier-1 desk ($id)");
        }

        // The band has to be readable, not a rule and a shrug — and the cadence it
        // quotes is the registry's real fetch interval, not a sentence typed once.
        $note = '';
        $label = '';
        foreach ($m['regions'] as $r) {
            if ($r['id'] === Compose::REGION_DESKS) {
                $note  = (string) $r['note'];
                $label = (string) $r['label'];
            }
        }
        assertContains('desks', strtolower($label), 'the band says what it is: ' . $label);
        assertTrue(strlen($note) > 40, 'the band carries a real sentence');
        assertContains((string) Feeds::tierMinutes(2), $note, 'and it quotes the registry cadence: ' . $note);

        // The fast region carries no heading of its own — the hero is its heading.
        foreach ($m['regions'] as $r) {
            if ($r['id'] === Compose::REGION_LEAD) {
                assertSame('', (string) $r['label'], 'the top region must not print a band above the hero');
            }
        }
    },

    'a page with no fast desks still opens with stories, not with the band' => function (): void {
        // Every desk slow: the split would otherwise put "More from the desks" at
        // the very top of the page with nothing above it.
        $slow = desksOnTier(2) ?: desksOnTier(3);
        assertTrue(count($slow) >= 2, 'the registry has at least two slower desks');
        $rows = [];
        $id   = 300;
        foreach ($slow as $desk) {
            for ($i = 0; $i < 6; $i++) {
                $rows[] = art($id++, $desk, 'src-' . ($i % 3), 10 + $i);
            }
        }
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(Compose::REGION_LEAD, (string) $m['regions'][0]['id'], 'the first region is always the lead one');
        assertTrue(count(regionBlocks($m, Compose::REGION_LEAD)) > 0, 'and it is not empty');
    },

    'block order survives a reordered and finance-heavy input' => function (): void {
        $rows = financeHeavyRows(12);
        shuffleDeterministically($rows);
        $m   = Compose::home($rows, cfgFixture(), NOW_MS);
        $ids = blockIds($m);
        assertSame(array_values(array_intersect(array_column(desks(), 'slug'), $ids)), $ids, 'order is canonical');
        assertTrue(count($m['markets']) > 0, 'and markets still comes out as the trailing strip');
    },

    'exact score ties resolve deterministically, not by database row order' => function (): void {
        // A feed that supplies no dates gives every one of its rows an IDENTICAL
        // score. If ties fell through to input order the front page would reshuffle
        // on every request, because the row order is only as stable as the
        // database's ORDER BY on equal keys.
        [$deskA, $deskB] = twoDesksByPriority();
        $rows = [];
        for ($i = 1; $i <= 14; $i++) {
            $rows[] = [
                'id'           => $i,
                'title'        => 'Undated story ' . $i,
                'url'          => 'https://example.test/u/' . $i,
                'summary'      => 'No date on this feed.',
                'image_url'    => null,
                'published_at' => null,
                'section'      => $i <= 7 ? $deskA : $deskB,
                'source'       => 'undated',
                'source_name'  => 'UNDATED',
                'weight'       => 1.0,
            ];
        }
        $scores = array_unique(array_map(
            static fn(array $r): float => $r['score'],
            array_filter(allItems(Compose::home($rows, cfgFixture(), NOW_MS)),
                static fn(array $r): bool => $r['section'] === $deskA)
        ));
        assertCount(1, $scores, 'the fixture really does produce an exact tie');

        $forward  = Compose::home($rows, cfgFixture(), NOW_MS);
        $backward = Compose::home(array_reverse($rows), cfgFixture(), NOW_MS);
        $rotated  = $rows;
        shuffleDeterministically($rotated);
        $shuffled = Compose::home($rotated, cfgFixture(), NOW_MS);
        assertSame($forward, $backward, 'a tie must not be settled by input order');
        assertSame($forward, $shuffled, 'nor by any other input order');
        assertTrue($forward['hero']['lead'] !== null, 'and the tied page still composes');
    },

    'ranking does not depend on the order rows arrive from the database' => function (): void {
        $rows = newsroomRows();
        $a = Compose::home($rows, cfgFixture(), NOW_MS);
        $b = Compose::home(array_reverse($rows), cfgFixture(), NOW_MS);
        assertSame($a, $b, 'a total ordering means DB row order cannot change the page');
    },

    'deterministic: the same input twice gives a deeply identical model' => function (): void {
        $rows = financeHeavyRows(12);
        $cfg  = cfgFixture();
        $a = Compose::home($rows, $cfg, NOW_MS);
        $b = Compose::home($rows, $cfg, NOW_MS);
        assertSame($a, $b, 'Compose::home is not deterministic');
        // ...and a different clock must produce a DIFFERENT page, or the recency decay
        // is dead.
        $c = Compose::home($rows, $cfg, NOW_MS + (36 * 3600 * 1000));
        assertNotSame($a, $c, 'moving the clock 36 hours changed nothing — the decay is not being applied');
        assertTrue($c['hero']['lead'] !== null, 'and a later clock still composes a page');
    },

    // -------------------------------------------------------------- degrade --

    'degrades: three articles in total' => function (): void {
        $d = newsDesks();
        $rows = [
            art(1, $d[0], 'abc', 10),
            art(2, $d[1] ?? $d[0], 'bbc', 20),
            art(3, $d[2] ?? $d[0], 'cbs', 30),
        ];
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'three stories still produce a lead');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'no duplication when starved');
        assertTrue(count($ids) <= 3, 'it cannot invent articles it does not have');
    },

    'degrades: a desk with nothing in it prints no block' => function (): void {
        $drop = newsDesks();
        $drop = [array_pop($drop)];
        $m    = Compose::home(newsroomRows(['skip' => $drop]), cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertNotContains($drop[0], blockIds($m), 'an empty desk still produced a block');
        assertTrue(count($m['blocks']) > 0, 'and the rest of the page is intact');
        foreach ($m['regions'] as $r) {
            assertNotContains($drop[0], $r['blocks'], 'the empty desk is still named in a region');
        }
    },

    'degrades: every article from one source' => function (): void {
        $rows = [];
        $id   = 1;
        foreach (newsDesks() as $i => $s) {
            for ($j = 0; $j < 3; $j++) {
                $rows[] = art($id++, $s, 'onlysource', 5 + (($i * 3 + $j) * 11));
            }
        }
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'one source still leads the page');
        assertTrue(count($m['blocks']) >= 3, 'the cap must not blank the page when there is no diversity to have');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertSame(count($ids), count(array_unique($ids)), 'no duplication in the single-source case');
    },

    'degrades: every article is finance' => function (): void {
        $rows = [];
        for ($i = 0; $i < 15; $i++) {
            $rows[] = art(500 + $i, moneyDesk(), 'wire' . ($i % 3), 2 + $i);
        }
        $m = Compose::home($rows, cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(null, $m['hero']['lead'], 'an all-finance day does not get a finance hero');
        assertCount(0, $m['blocks'], 'and no blocks');
        assertCount(0, $m['regions'], 'and therefore no region bands either');
        assertCount(0, $m['rail'], 'and nothing in the rail');
        assertCount(0, $m['ticker'], 'and no finance in the ticker');
        assertCount(2, $m['markets'], 'only the capped markets strip survives');
    },

    'degrades: zero articles' => function (): void {
        $m = Compose::home([], cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertSame(null, $m['hero']['lead'], 'no lead');
        assertCount(0, $m['hero']['subs'], 'no subs');
        assertCount(0, $m['rail'], 'no rail');
        assertCount(0, $m['blocks'], 'no blocks');
        assertCount(0, $m['regions'], 'no regions');
        assertCount(0, $m['markets'], 'no markets');
        assertCount(0, $m['ticker'], 'no ticker');
    },

    'degrades: junk rows never throw' => function (): void {
        // The runner fails any test that throws, so calling straight through IS
        // the assertion that Compose survives garbage from the database.
        $desk = newsDesks()[0];
        $m = Compose::home([
                ['id' => 0, 'title' => 'no id'],
                ['id' => 7],                                   // no title
                ['title' => 'no id at all'],
                'not an array',
                ['id' => '11', 'title' => 'string id', 'published_at' => '1787000000', 'section' => strtoupper($desk) . ' ',
                 'source_name' => 'The Wire', 'image_url' => 'null', 'weight' => 'heavy'],
                ['id' => 12, 'title' => 'future dated', 'published_at' => NOW_MS + 90000000, 'section' => $desk,
                 'url' => 'https://news.example.test/x'],
                ['id' => 13, 'title' => 'no section', 'published_at' => null],
        ], cfgFixture(), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'the usable rows still compose');
        $ids = array_map(static fn(array $r): int => $r['id'], allItems($m));
        assertNotContains(0, $ids, 'the id-less row is dropped');
        assertNotContains(7, $ids, 'the title-less row is dropped');
        assertContains(11, $ids, 'a numeric-string id is accepted');
        assertContains(12, $ids, 'a future-dated row is accepted');
    },

    'degrades: an empty config falls back to the shipped defaults' => function (): void {
        $m = Compose::home(financeHeavyRows(12), [], NOW_MS);
        modelIsWellFormed($m);
        assertFalse((bool) $m['hero']['lead']['is_finance'], 'the quota holds with no config at all');
        assertTrue(count(financeItems($m)) <= 2, 'and defaults to the documented cap of 2');

        // Garbage values on the right keys must not produce a broken page either.
        $m = Compose::home(newsroomRows(), cfgFixture([
            'finance_max_on_home'      => 'lots',
            'hero_sub_count'           => -5,
            'per_source_cap_per_block' => 0,
            'publisher_max_on_home'    => 0,
            'rail_count'               => -3,
            'ticker_count'             => 'many',
            'half_life_hours'          => 0,
            'tier_block_counts'        => 'no',
            'lead_tiers'               => [],
        ]), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'and it still composes a page');
    },

    // ------------------------------------------------------------ hardening --

    'the finance ban is absolute — emptying finance_blocked_blocks cannot open the hero' => function (): void {
        // finance_blocked_blocks names the regions the client may shut; it is not a
        // list the client may SHORTEN to let markets back into the news. The ban is
        // unconditional, so an empty list must change nothing except that the strip
        // itself stays open.
        $rows = financeHeavyRows(12);
        $m    = Compose::home($rows, cfgFixture(['finance_blocked_blocks' => []]), NOW_MS);
        modelIsWellFormed($m);
        assertTrue($m['hero']['lead'] !== null, 'the page still has a lead');
        assertFalse((bool) $m['hero']['lead']['is_finance'], 'an empty blocked list still cannot put finance in the hero');
        foreach ($m['hero']['subs'] as $r) {
            assertFalse((bool) $r['is_finance'], 'nor in a hero sub');
        }
        foreach ($m['rail'] as $r) {
            assertFalse((bool) $r['is_finance'], 'nor in the rail');
        }
        foreach ($m['blocks'] as $b) {
            foreach ($b['items'] as $r) {
                assertFalse((bool) $r['is_finance'], 'nor in block ' . $b['id']);
            }
        }
        foreach ($m['ticker'] as $r) {
            assertFalse((bool) $r['is_finance'], 'nor in the ticker');
        }
        assertCount(2, financeItems($m), 'finance still surfaces, still only in the capped strip');
        assertCount(2, $m['markets'], 'and the strip is the place it surfaces');
    },

    'two rows sharing an id resolve the same way whichever order they arrive in' => function (): void {
        // A partial re-ingest, a UNION, or two result sets stitched together can hand
        // Compose the same id twice with different content. Taking whichever came
        // first would make the front page depend on database row order — the thing
        // this class exists to be free of.
        $desk = newsDesks()[0];
        $other = newsDesks()[1] ?? $desk;
        $strong = art(1, $desk, 'alpha', 5, true, 1.0);
        $strong['title'] = 'The better copy of story 1';
        $weak = art(1, $desk, 'alpha', 6000, false, 0.2);
        $weak['title'] = 'The worse copy of story 1';

        $rows = [$strong, $weak, art(2, $desk, 'bravo', 30), art(3, $other, 'charlie', 40)];
        $forward  = Compose::home($rows, cfgFixture(['rail_count' => 0]), NOW_MS);
        $backward = Compose::home([$weak, $strong, $rows[2], $rows[3]], cfgFixture(['rail_count' => 0]), NOW_MS);

        assertSame($forward, $backward, 'a duplicated id was settled by input order');
        assertSame('The better copy of story 1', $forward['hero']['lead']['title'],
            'the stronger of the two rows is the one kept');

        $ids = array_map(static fn(array $r): int => $r['id'], allItems($forward));
        assertSame(count($ids), count(array_unique($ids)), 'and it is still placed only once');
    },

    'the compose sub-array may be passed on its own, whichever keys it carries' => function (): void {
        // Compose documents that $cfg may be "the whole config array (or just its
        // 'compose' sub-array)". A bare array used to be recognised only when it
        // happened to carry finance_max_on_home or block_counts, so
        // Compose::home($rows, Config::get('compose'), $now) silently composed with
        // the defaults instead of the client's settings.
        $rows = newsroomRows();
        $opts = ['hero_sub_count' => 1, 'ticker_count' => 3, 'per_source_cap_per_block' => 1];

        $bare     = Compose::home($rows, $opts, NOW_MS);
        $wrapped  = Compose::home($rows, ['compose' => $opts], NOW_MS);
        $defaults = Compose::home($rows, [], NOW_MS);

        assertSame($wrapped, $bare, 'a bare compose array must be read exactly like a wrapped one');
        assertNotSame($defaults, $bare, 'and it must not fall through to the shipped defaults');
        assertCount(1, $bare['hero']['subs'], 'hero_sub_count was read off the bare array');
        assertCount(3, $bare['ticker'], 'ticker_count was read off the bare array');

        // A full config that has no compose section at all still gets the defaults.
        $noCompose = Compose::home($rows, ['site' => ['name' => 'Fixture Daily'], 'db' => ['driver' => 'sqlite']], NOW_MS);
        assertSame($defaults, $noCompose, 'a config with no compose section composes with the defaults');
    },

    'the desk order comes from the registry, not from a list in this class' => function (): void {
        // Compose must not restate the roster. Moving a desk in Feeds has to move
        // it on the front page with no edit here — that is the whole reason
        // deskOrder() exists.
        $order = array_column(Compose::deskOrder(), 'slug');
        $home  = array_column(Feeds::homeSections(), 'slug');
        sort($order);
        sort($home);
        assertSame($home, $order, 'deskOrder() and Feeds::homeSections() name different desks');

        foreach (Compose::deskOrder() as $d) {
            assertSame(Feeds::sectionTier((string) $d['slug']), (int) $d['tier'], 'a desk tier was invented locally');
            $meta = Feeds::section((string) $d['slug']);
            assertSame((string) $meta['label'], (string) $d['label'], 'a desk label was invented locally');
        }
    },

];

/** A fixed, seedless permutation — determinism matters more than randomness here. */
function shuffleDeterministically(array &$rows): void
{
    $out = [];
    $n   = count($rows);
    for ($i = 0; $i < $n; $i++) {
        $out[] = $rows[($i * 7) % $n];
    }
    $seen = [];
    $uniq = [];
    foreach ($out as $r) {
        if (!isset($seen[$r['id']])) {
            $seen[$r['id']] = true;
            $uniq[] = $r;
        }
    }
    foreach ($rows as $r) {
        if (!isset($seen[$r['id']])) {
            $seen[$r['id']] = true;
            $uniq[] = $r;
        }
    }
    $rows = $uniq;
}
