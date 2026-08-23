<?php
declare(strict_types=1);

namespace TEB;

// The desk roster, the tiers and the licences live in Feeds. Compose reads
// them and never restates them, so adding a newsroom there changes the front
// page here with no edit. The test harness loads this file directly, so the
// dependency is declared rather than left to bootstrap.
require_once __DIR__ . '/Feeds.php';

/**
 * Front-page composition.
 *
 * Compose::home() is a PURE function of (rows, config, now). No time(), no rand(),
 * no I/O, no static mutable state — so the front page is reproducible and testable.
 * Everything visible is either passed in, read from config, or read from the feed
 * registry; there is no brand literal and no absolute URL in this file. Block hrefs
 * are ROUTE PATHS ('/section/world'); the renderer turns them into URLs through
 * TEB\Paths.
 *
 * ---------------------------------------------------------------------------
 * THE ORDERING RULE THE CLIENT SET
 * ---------------------------------------------------------------------------
 * Feeds are graded into three tiers by how often they publish, and a desk
 * inherits the tier of its fastest feed (Feeds::sectionTier). The front page is
 * then built in two regions:
 *
 *   REGION_LEAD   the hero, and the blocks of every tier-1 desk. These are the
 *                 desks that actually move during the day, so they are what the
 *                 reader sees first and what the rotation endpoint draws from.
 *   REGION_DESKS  every slower desk, each under its own heading, below a band
 *                 that says what it is. Not an afterthought and not a dump: the
 *                 blocks are the same component, the region simply says out loud
 *                 that these are checked every half hour rather than every ten
 *                 minutes.
 *
 * The split is DATA, not markup: each block carries 'tier' and 'region', and the
 * model carries a 'regions' list in render order. A renderer that ignores it
 * still gets a flat, correctly ordered 'blocks' array.
 *
 * ---------------------------------------------------------------------------
 * WHY THE CAPS ARE SHAPED THE WAY THEY ARE
 * ---------------------------------------------------------------------------
 * This roster is nine feeds from ONE newsroom (The Conversation) plus ten
 * others. A flat "two per source per block" cap therefore does two wrong things
 * at once: it lets one publisher supply eighteen stories to the page through
 * nine different feeds, and it caps the fastest desk on the site at two cards
 * because that desk happens to have a single feed.
 *
 * So the cap is expressed twice, at the level each one is actually about:
 *
 *   per block      no source may supply more than 'per_source_cap_per_block'
 *                  UNLESS the desk has too few sources to fill the block at
 *                  that rate — a desk fed by one newsroom IS that newsroom, and
 *                  a two-card block on the fastest desk is not a cap working,
 *                  it is a bug. The relaxed cap is computed, not improvised:
 *                  ceil(want / distinct sources available).
 *   per page       no PUBLISHER may supply more than 'publisher_max_on_home'
 *                  cards to the whole front page. This is the rule that stops
 *                  one newsroom owning the page, and it is the reason
 *                  Feeds::publisherOf() exists.
 *
 * ---------------------------------------------------------------------------
 * THE FINANCE QUOTA
 * ---------------------------------------------------------------------------
 * The client's commercial requirement, not a style note: the desks Feeds marks
 * 'finance' are banned from the hero, from the rail and from every block, and
 * are capped at compose.finance_max_on_home across the WHOLE front page,
 * surfacing only in the low markets strip. He is buying ads to build a
 * general-news audience; a markets-heavy front page fights that.
 *
 * Model shape:
 *   ['ticker'  => [item, ...],
 *    'hero'    => ['lead' => item|null, 'subs' => [item, ...]],
 *    'rail'    => [item, ...],
 *    'blocks'  => [['id','label','href','note','grid','tier','region','items'], ...],
 *    'regions' => [['id','label','note','tiers','blocks' => [id, ...]], ...],
 *    'markets' => [item, ...]]
 *
 * 'markets' is deliberately NOT one of 'blocks': the design renders it as the
 * .markets-strip band after the last block, and keeping it in one place is what
 * guarantees no article id is ever emitted twice.
 */
final class Compose
{
    /** The strip that carries whatever finance survives the quota. */
    public const MARKETS_ID = 'markets';

    /** The two regions of the front page, in render order. */
    public const REGION_LEAD  = 'lead';
    public const REGION_DESKS = 'desks';

    private const DEFAULTS = [
        // These newsrooms publish a few times a day, not every ten minutes. A
        // 4.5-hour half-life — right for a wire — drives a two-day-old story to
        // 0.0006 of its opening score, which flattens every desk into "whatever
        // arrived last". Nine hours keeps recency decisive over a day and lets
        // source weight and desk priority still matter across the 96-hour window
        // the front page reads.
        'half_life_hours'          => 9.0,
        'image_bonus'              => 0.10,
        // The promise of this site is the whole article. A source we may only
        // ever quote at 400 characters is still worth carrying, but it should
        // not out-rank a piece we can publish in full at the same recency.
        'extract_penalty'          => 0.12,
        'repeat_source_penalty'    => 0.35,
        'finance_max_on_home'      => 2,
        'finance_blocked_blocks'   => [],
        'finance_sections'         => [],
        'hero_sub_count'           => 4,
        'rail_count'               => 6,
        'rail_source_cap'          => 1,
        'per_source_cap_per_block' => 2,
        'publisher_max_on_home'    => 16,
        'ticker_count'             => 12,
        'ticker_source_cap'        => 2,
        // A feed that publishes twice a day cannot mark anything "New" if the
        // window is 45 minutes. 90 keeps the chip rare and truthful.
        'fresh_minutes'            => 90,
        'undated_age_hours'        => 12,
        'block_counts'             => [],
        // How many cards a desk gets, by the tier of its fastest feed. Empty
        // 'block_counts' means these apply; a named desk in 'block_counts'
        // overrides its tier.
        //
        // 7 / 4 / 3 is not a taste call, it is the arithmetic of the client's
        // brief. Nine desks reach the front page, the hero takes five cards and
        // the markets strip two, so the blocks have to land between about 23
        // and 38 for the page to come out inside the 30–45 cards he asked for.
        // The worst case is what matters — a full database fills every block —
        // and this holds on BOTH editions, which put different numbers of desks
        // on each tier. It also makes the tier split visible without a label: a
        // fast desk is drawn more than twice the size of a slow one. And it
        // degrades on its own — a desk with three stories renders three.
        'tier_block_counts'        => [1 => 7, 2 => 4, 3 => 3],
        // Tiers that sit in the top region with the hero.
        'lead_tiers'               => [1],
    ];

    /** Every key options() understands — also how a bare compose array is recognised. */
    private const OPTION_KEYS = [
        'half_life_hours', 'image_bonus', 'extract_penalty', 'repeat_source_penalty',
        'finance_max_on_home', 'finance_blocked_blocks', 'finance_sections',
        'hero_sub_count', 'rail_count', 'rail_source_cap', 'per_source_cap_per_block',
        'publisher_max_on_home', 'ticker_count', 'ticker_source_cap', 'fresh_minutes',
        'undated_age_hours', 'block_counts', 'tier_block_counts', 'lead_tiers',
    ];

    /**
     * Section priority for a desk the registry does not know about. Below every
     * real desk, because an unrecognised section is either a leftover row from a
     * feed that has since been removed or a mis-filed one — it may appear, it
     * may not lead.
     */
    private const UNKNOWN_PRIORITY = 0.70;

    /** The share of a block's items that must carry a picture for a picture grid. */
    private const IMAGE_GRID_THRESHOLD = 0.34;

    /**
     * How many opening words are read when deciding whether a row is an article
     * at all, and how often one word may repeat inside that window.
     *
     * ⚠ THIS IS NOT A STYLE FILTER. Some feeds occasionally ship a page whose
     * "article" is the site's own navigation — measured on this roster, NASA's
     * APOD and Earth Observatory items arrive as "APOD Science APOD APOD: 2026
     * August 22 … Today's APOD Archive Submissions Index Search Calendar RSS"
     * and "Earth Observatory Science Earth Observatory … Earth Earth
     * Observatory Image". They are long, they carry a picture and a date, and
     * they therefore score well — one of them took the LEAD SLOT of the front
     * page. A menu is not a story and must not be the first thing a reader sees.
     *
     * The test is word repetition, not capitalisation. Capitalisation was tried
     * first and refused: at every threshold that caught the menus it also caught
     * real articles opening on a photo credit ("Illustration by Shoshana
     * Gordon/ProPublica. Source images: Air Force photo by Airman 1st Class …").
     * Repetition separates them cleanly. Measured over 718 stored articles
     * across both editions: five-or-more repeats of one word in the first forty
     * flags 4 rows, every one of them navigation chrome, and 0 real articles.
     * The highest any real article reaches is four — a piece about Lake Mead
     * saying "lake", and one about a Texas race saying "Texas".
     *
     * A row this rejects is dropped from the FRONT PAGE only. It keeps its
     * article page, its place on its desk index and its line in the sitemap;
     * this decides what is promoted, not what exists.
     */
    private const NAV_WINDOW       = 40;
    private const NAV_REPEAT_LIMIT = 5;

    /**
     * The smallest block worth printing under its own heading. Only used by
     * blockCap(): a desk whose sources cannot between them reach this many cards
     * at the configured cap has the cap raised just far enough that it can.
     */
    private const MIN_BLOCK_CARDS = 4;

    /**
     * Compose the front page.
     *
     * @param array $rows  article rows (Db::recentArticles shape); unknown keys are preserved
     * @param array $cfg   the whole config array (or just its 'compose' sub-array)
     * @param int   $nowMs epoch milliseconds — supplied by the caller so this stays pure
     */
    public static function home(array $rows, array $cfg, int $nowMs): array
    {
        $c    = self::options($cfg);
        $pool = self::prepare($rows, $c, $nowMs);

        /** @var array<int,bool> $used article ids already placed somewhere in the model */
        $used = [];
        /** @var array<string,bool> $leads sources that already lead a region — no source leads twice */
        $leads = [];
        /** @var array<string,int> $byPublisher cards taken from each newsroom, page-wide */
        $byPublisher = [];

        $editorial = self::filterFinance($pool, false);
        $finance   = self::filterFinance($pool, true);

        // --- hero ------------------------------------------------------------
        // Finance is banned here unconditionally, so the hero is picked from the
        // editorial pool only — and preferentially from the FAST desks, because
        // the top of the page is the part the client wants moving. If the fast
        // desks cannot fill it (a thin database, a fresh install) the whole
        // editorial pool is used rather than leaving a hole.
        $heroWant = 1 + max(0, $c['hero_sub_count']);
        $fast     = self::onTiers($editorial, $c['lead_tiers']);
        $heroPool = count($fast) >= $heroWant ? $fast : $editorial;

        $heroPick = self::takeScored(
            self::available($heroPool, $used),
            $heroWant,
            $c['per_source_cap_per_block'],
            $c['repeat_source_penalty'],
            [],
            false,
            $byPublisher,
            $c['publisher_max_on_home']
        );
        $lead = null;
        $subs = [];
        if ($heroPick) {
            $lead = self::stamp(array_shift($heroPick), 'hero', 'lead');
            self::claim($lead, $used, $byPublisher);
            $leads[$lead['source']] = true;
            foreach ($heroPick as $row) {
                $row = self::stamp($row, 'hero', 'medium');
                self::claim($row, $used, $byPublisher);
                $subs[] = $row;
            }
        }

        // --- the rail beside the hero ----------------------------------------
        // A differently-shaped list, not a third column of cards: one story per
        // source, newest first, so it reads as "what else is moving" rather than
        // as more of the same desk. It is drawn before the blocks because it
        // sits beside the hero on screen.
        $rail = [];
        if ($c['rail_count'] > 0) {
            $railCands = self::available($editorial, $used);
            usort($railCands, [self::class, 'byRecency']);
            foreach (
                self::takeInterleaved($railCands, $c['rail_count'], $c['rail_source_cap'])
                as $row
            ) {
                $row = self::stamp($row, 'rail', 'headline');
                self::claim($row, $used, $byPublisher);
                $rail[] = $row;
            }
        }

        // --- blocks ----------------------------------------------------------
        // One block per front-page desk, in tier order: every tier-1 desk, then
        // every tier-2 desk, then the rest. Feeds decides which desks exist and
        // how fast each one moves; this only reads it.
        $blocks     = [];
        $textBlocks = 0;                       // no-picture desks placed so far
        foreach (self::deskOrder() as $desk) {
            $id = (string) $desk['slug'];
            if (in_array($id, $c['finance_sections'], true)) {
                continue;                      // money is the strip, never a block
            }
            $want = self::blockWant($c, $id, (int) $desk['tier']);
            if ($want < 1) {
                continue;
            }
            $cands = [];
            foreach (self::available($editorial, $used) as $row) {
                if ($row['block'] === $id) {
                    $cands[] = $row;
                }
            }
            $picked = self::takeScored(
                $cands,
                $want,
                self::blockCap($cands, $want, $c['per_source_cap_per_block']),
                $c['repeat_source_penalty'],
                $leads,
                true,
                $byPublisher,
                $c['publisher_max_on_home']
            );
            if (!$picked) {
                continue;
            }
            $hasPictures = self::picturesShare($picked) >= self::IMAGE_GRID_THRESHOLD;
            $inLead      = in_array((int) $desk['tier'], $c['lead_tiers'], true);
            $grid = self::gridFor($picked, $hasPictures, count($blocks), $textBlocks, $inLead);
            if (!$hasPictures) {
                $textBlocks++;
            }
            $wire  = strpos($grid, 'block-grid--wire') !== false;
            $plain = $grid === 'block-grid';
            $items = [];
            foreach ($picked as $i => $row) {
                $size = $wire ? 'small' : (($plain && $i === 0) ? 'large' : 'medium');
                $row  = self::stamp($row, $id, $size);
                self::claim($row, $used, $byPublisher);
                $items[] = $row;
            }
            $leads[$items[0]['source']] = true;
            $blocks[] = [
                'id'     => $id,
                'label'  => (string) $desk['label'],
                'href'   => self::blockHref($id),
                'note'   => (string) $desk['note'],
                'grid'   => $grid,
                'tier'   => (int) $desk['tier'],
                'region' => in_array((int) $desk['tier'], $c['lead_tiers'], true)
                    ? self::REGION_LEAD
                    : self::REGION_DESKS,
                'items'  => $items,
            ];
        }

        // --- markets strip ---------------------------------------------------
        // The only place finance is allowed, and only up to the home-page cap.
        $markets = [];
        if (!in_array(self::MARKETS_ID, $c['finance_blocked_blocks'], true)) {
            $want   = min($c['finance_max_on_home'], self::marketsWant($c));
            $picked = $want > 0
                ? self::takeScored(
                    self::available($finance, $used),
                    $want,
                    $c['per_source_cap_per_block'],
                    $c['repeat_source_penalty'],
                    $leads,
                    true,
                    $byPublisher,
                    $c['publisher_max_on_home']
                )
                : [];
            foreach ($picked as $row) {
                $row = self::stamp($row, self::MARKETS_ID, 'small');
                self::claim($row, $used, $byPublisher);
                $markets[] = $row;
            }
            if ($markets) {
                $leads[$markets[0]['source']] = true;
            }
        }

        // --- ticker ----------------------------------------------------------
        // Headlines, never markets, and one desk after another rather than five
        // stories off the same one. The leftovers are sorted newest-first and
        // then taken a section at a time, wrapping round: the strip still opens
        // on the newest story of the day, but its second item comes from a
        // different vertical, and so does its third.
        $ticker = [];
        if ($c['ticker_count'] > 0) {
            $rest = self::available($editorial, $used);
            usort($rest, [self::class, 'byRecency']);
            foreach (self::takeInterleaved($rest, $c['ticker_count'], $c['ticker_source_cap'], true) as $row) {
                $row = self::stamp($row, 'ticker', 'ticker');
                $used[$row['id']] = true;
                $ticker[] = $row;
            }
        }

        return self::enforce([
            'ticker'  => $ticker,
            'hero'    => ['lead' => $lead, 'subs' => $subs],
            'rail'    => $rail,
            'blocks'  => $blocks,
            'markets' => $markets,
        ], $c);
    }

    // -------------------------------------------------------------------------
    // the desks
    // -------------------------------------------------------------------------

    /**
     * Front-page desks in the order they are composed and rendered.
     *
     * TIER FIRST — that is the client's rule and it is not negotiable — then the
     * editorial order the registry itself sets with its 'home' numbers. Sorting
     * purely by measured speed (Feeds::freshSections) would have opened the page
     * on whichever desk happened to publish most often, overruling the editor;
     * sorting purely by 'home' would have put a slow desk above a fast one.
     * Tier, then the editor, is both.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function deskOrder(): array
    {
        $desks = Feeds::homeSections();
        usort($desks, static function (array $a, array $b): int {
            return [(int) ($a['tier'] ?? 3), (int) ($a['home'] ?? 99)]
                <=> [(int) ($b['tier'] ?? 3), (int) ($b['home'] ?? 99)];
        });

        $out = [];
        foreach ($desks as $desk) {
            if (!is_array($desk) || ($desk['slug'] ?? '') === '') {
                continue;
            }
            $out[] = [
                'slug'  => (string) $desk['slug'],
                'label' => (string) ($desk['label'] ?? ucfirst((string) $desk['slug'])),
                'note'  => (string) ($desk['note'] ?? ''),
                'blurb' => (string) ($desk['blurb'] ?? ''),
                'tier'  => (int) ($desk['tier'] ?? 3),
            ];
        }

        return $out;
    }

    /**
     * The regions, in render order, each naming the blocks it holds.
     *
     * A page whose top region came out empty — every desk slow, or a fixture
     * with no recognised sections at all — would otherwise open on a band
     * announcing "more from the desks" with nothing above it. So when nothing
     * qualifies for the lead region the first two blocks are promoted into it:
     * deterministic, and it means the header always separates something from
     * something.
     *
     * @param  array<int,array<string,mixed>> $blocks
     * @return array<int,array<string,mixed>>
     */
    private static function regions(array $blocks, array $c): array
    {
        $lead = [];
        $rest = [];
        foreach ($blocks as $b) {
            if (($b['region'] ?? '') === self::REGION_LEAD) {
                $lead[] = (string) $b['id'];
            } else {
                $rest[] = (string) $b['id'];
            }
        }
        if ($lead === [] && $rest !== []) {
            $lead = array_slice($rest, 0, 2);
            $rest = array_slice($rest, 2);
        }

        $out = [];
        if ($lead !== []) {
            $out[] = [
                'id'     => self::REGION_LEAD,
                'label'  => '',
                'note'   => '',
                'tiers'  => self::tiersOf($blocks, $lead),
                'blocks' => $lead,
            ];
        }
        if ($rest !== []) {
            $out[] = [
                'id'     => self::REGION_DESKS,
                'label'  => 'More from the desks',
                'note'   => self::desksNote(self::tiersOf($blocks, $rest), $c),
                'tiers'  => self::tiersOf($blocks, $rest),
                'blocks' => $rest,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>> $blocks
     * @param  array<int,string>              $ids
     * @return array<int,int>
     */
    private static function tiersOf(array $blocks, array $ids): array
    {
        $t = [];
        foreach ($blocks as $b) {
            if (in_array((string) $b['id'], $ids, true)) {
                $t[(int) ($b['tier'] ?? 3)] = true;
            }
        }
        $t = array_keys($t);
        sort($t);

        return $t;
    }

    /**
     * The standfirst under the slower-desks band, built from the real fetch
     * cadence rather than written as a slogan. Feeds owns the minutes; if a tier
     * is re-timed there this sentence re-times itself.
     *
     * @param array<int,int> $tiers
     */
    private static function desksNote(array $tiers, array $c): string
    {
        $top = [];
        foreach ($c['lead_tiers'] as $t) {
            $top[] = Feeds::tierMinutes((int) $t);
        }
        $slow = [];
        foreach ($tiers as $t) {
            $slow[] = Feeds::tierMinutes((int) $t);
        }
        $top  = $top ? min($top) : 10;
        $slow = $slow ?: [30];

        $span = min($slow) === max($slow)
            ? 'every ' . min($slow) . ' minutes'
            : 'every ' . min($slow) . ' to ' . max($slow) . ' minutes';

        return 'The top of the page is rebuilt every ' . $top . ' minutes. These desks are checked '
            . $span . ', so they turn over more slowly — and the stories on them run longer.';
    }

    // -------------------------------------------------------------------------
    // config
    // -------------------------------------------------------------------------

    /** Reads compose options off the full config array (or off a bare compose array). */
    private static function options(array $cfg): array
    {
        $raw = [];
        if (isset($cfg['compose']) && is_array($cfg['compose'])) {
            $raw = $cfg['compose'];
        } else {
            // The caller may hand us the compose sub-array on its own. Recognise it by
            // ANY option key, not by two of them: probing only 'finance_max_on_home' and
            // 'block_counts' meant a bare ['ticker_count' => 3] was silently ignored and
            // the page was composed with the defaults instead. No top-level config key
            // (site, db, durable, ingest, compose, ads, cache) collides with this list.
            foreach (self::OPTION_KEYS as $k) {
                if (array_key_exists($k, $cfg)) {
                    $raw = $cfg;
                    break;
                }
            }
        }

        $d = self::DEFAULTS;
        $o = [
            'half_life_hours'          => self::num($raw, 'half_life_hours', $d['half_life_hours']),
            'image_bonus'              => self::num($raw, 'image_bonus', $d['image_bonus']),
            'extract_penalty'          => self::num($raw, 'extract_penalty', $d['extract_penalty']),
            'repeat_source_penalty'    => self::num($raw, 'repeat_source_penalty', $d['repeat_source_penalty']),
            'finance_max_on_home'      => self::int($raw, 'finance_max_on_home', $d['finance_max_on_home']),
            'hero_sub_count'           => self::int($raw, 'hero_sub_count', $d['hero_sub_count']),
            'rail_count'               => self::int($raw, 'rail_count', $d['rail_count']),
            'rail_source_cap'          => self::int($raw, 'rail_source_cap', $d['rail_source_cap']),
            'per_source_cap_per_block' => self::int($raw, 'per_source_cap_per_block', $d['per_source_cap_per_block']),
            'publisher_max_on_home'    => self::int($raw, 'publisher_max_on_home', $d['publisher_max_on_home']),
            'ticker_count'             => self::int($raw, 'ticker_count', $d['ticker_count']),
            'ticker_source_cap'        => self::int($raw, 'ticker_source_cap', $d['ticker_source_cap']),
            'fresh_minutes'            => self::int($raw, 'fresh_minutes', $d['fresh_minutes']),
            'undated_age_hours'        => self::num($raw, 'undated_age_hours', $d['undated_age_hours']),
            'finance_blocked_blocks'   => self::slugList($raw, 'finance_blocked_blocks', $d['finance_blocked_blocks']),
            // The finance desks are registry data, so Compose, Render and the
            // tests cannot drift apart on what "finance" means. Config may still
            // override the list, which is what the tests do.
            'finance_sections'         => self::slugList($raw, 'finance_sections', Feeds::financeSections()),
            'block_counts'             => is_array($raw['block_counts'] ?? null) ? $raw['block_counts'] : [],
            'tier_block_counts'        => is_array($raw['tier_block_counts'] ?? null)
                ? $raw['tier_block_counts'] + $d['tier_block_counts']
                : $d['tier_block_counts'],
            'lead_tiers'               => self::intList($raw, 'lead_tiers', $d['lead_tiers']),
        ];

        if ($o['half_life_hours'] <= 0.0) {
            $o['half_life_hours'] = $d['half_life_hours'];
        }
        $o['finance_max_on_home']      = max(0, $o['finance_max_on_home']);
        $o['hero_sub_count']           = max(0, $o['hero_sub_count']);
        $o['rail_count']               = max(0, $o['rail_count']);
        $o['rail_source_cap']          = max(1, $o['rail_source_cap']);
        $o['per_source_cap_per_block'] = max(1, $o['per_source_cap_per_block']);
        // 0 would compose an empty page; the guard is what makes the option safe
        // to expose in config.php at all.
        $o['publisher_max_on_home']    = max(1, $o['publisher_max_on_home']);
        $o['ticker_count']             = max(0, $o['ticker_count']);
        $o['ticker_source_cap']        = max(1, $o['ticker_source_cap']);
        $o['fresh_minutes']            = max(0, $o['fresh_minutes']);
        $o['undated_age_hours']        = max(0.0, $o['undated_age_hours']);
        $o['image_bonus']              = max(0.0, $o['image_bonus']);
        $o['extract_penalty']          = max(0.0, $o['extract_penalty']);
        $o['repeat_source_penalty']    = max(0.0, $o['repeat_source_penalty']);
        if ($o['lead_tiers'] === []) {
            $o['lead_tiers'] = $d['lead_tiers'];
        }

        return $o;
    }

    private static function blockWant(array $c, string $id, int $tier): int
    {
        if (array_key_exists($id, $c['block_counts'])) {
            $v = $c['block_counts'][$id];

            return is_numeric($v) ? max(0, (int) $v) : 0;
        }
        $v = $c['tier_block_counts'][$tier] ?? null;

        return is_numeric($v) ? max(0, (int) $v) : 0;
    }

    /**
     * The per-source cap this block will actually run with.
     *
     * The cap is there to stop one newsroom filling a block that several could
     * have filled, and where several CAN it is obeyed exactly — set it to one
     * and no source appears twice in a block, even if that leaves the block
     * short. What it must not do is turn a desk that is genuinely fed by a
     * single newsroom into a two-card stub: on this roster a desk can easily
     * have one feed, and "the cap worked" is not a good description of a
     * two-card block on a desk with forty stories waiting.
     *
     * So the cap is raised only when the sources available cannot between them
     * make a VIABLE block — MIN_BLOCK_CARDS — and then only far enough to reach
     * that, never far enough to fill the block. A desk with four sources and a
     * cap of one keeps its cap and comes out with four cards; a desk with one
     * source and a cap of two comes out with four rather than two.
     *
     * @param array<int,array<string,mixed>> $cands
     */
    private static function blockCap(array $cands, int $want, int $cap): int
    {
        $cap     = max(1, $cap);
        $sources = [];
        foreach ($cands as $row) {
            $sources[(string) $row['source']] = true;
        }
        $n = count($sources);
        if ($n < 1) {
            return $cap;
        }

        $floor = min($want, self::MIN_BLOCK_CARDS);
        if ($n * $cap >= $floor) {
            return $cap;                       // the configured cap can already fill a real block
        }

        return max($cap, (int) ceil($floor / $n));
    }

    /** Markets defaults to exactly the finance cap, so raising the cap really does surface more. */
    private static function marketsWant(array $c): int
    {
        $v = $c['block_counts'][self::MARKETS_ID] ?? null;
        if ($v === null || !is_numeric($v)) {
            return $c['finance_max_on_home'];
        }

        return max(0, (int) $v);
    }

    private static function blockHref(string $id): string
    {
        // Route paths, not URLs — the renderer runs these through TEB\Paths::url().
        // Every desk on this site is a section index; there is no desk with a
        // template of its own, so there is no exception to make here.
        return '/section/' . $id;
    }

    private static function num(array $a, string $k, float $d): float
    {
        return isset($a[$k]) && is_numeric($a[$k]) ? (float) $a[$k] : $d;
    }

    private static function int(array $a, string $k, int $d): int
    {
        return isset($a[$k]) && is_numeric($a[$k]) ? (int) $a[$k] : $d;
    }

    private static function slugList(array $a, string $k, array $d): array
    {
        if (!isset($a[$k]) || !is_array($a[$k])) {
            return $d;
        }
        $out = [];
        foreach ($a[$k] as $v) {
            if (is_string($v) || is_numeric($v)) {
                $s = self::slug((string) $v);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    /** @return array<int,int> */
    private static function intList(array $a, string $k, array $d): array
    {
        if (!isset($a[$k]) || !is_array($a[$k])) {
            return $d;
        }
        $out = [];
        foreach ($a[$k] as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // rows
    // -------------------------------------------------------------------------

    /** Normalise, score and rank the input rows. Malformed rows are skipped, never fatal. */
    private static function prepare(array $rows, array $c, int $nowMs): array
    {
        // Two rows may arrive carrying the SAME id but different content (a partial
        // re-ingest, a UNION in a hand-written query, a caller stitching two result
        // sets together). Keeping whichever happened to come first would make the
        // front page depend on database row order, which is exactly what this class
        // promises it does not do — so the winner is chosen by a total comparison
        // instead of by position.
        $byId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = self::normalize($row, $c, $nowMs);
            if ($n === null) {
                continue;
            }
            $prev = $byId[$n['id']] ?? null;
            if ($prev === null || self::betterRow($n, $prev)) {
                $byId[$n['id']] = $n;
            }
        }
        $out = array_values($byId);
        usort($out, [self::class, 'byScore']);

        return $out;
    }

    /**
     * Total, position-independent ordering used only to settle an id collision:
     * score, then published time, then title, then destination URL, and — when even
     * those are identical, so the two rows rank and render alike — the serialised
     * row, so the answer is still the same whichever one the database returned first.
     */
    private static function betterRow(array $a, array $b): bool
    {
        if ($a['score'] !== $b['score']) {
            return $a['score'] > $b['score'];
        }
        $pa = $a['published_at'] ?? 0;
        $pb = $b['published_at'] ?? 0;
        if ($pa !== $pb) {
            return $pa > $pb;
        }
        if ($a['title'] !== $b['title']) {
            return strcmp($a['title'], $b['title']) < 0;
        }
        if ($a['url'] !== $b['url']) {
            return strcmp($a['url'], $b['url']) < 0;
        }
        try {
            return strcmp(serialize($a), serialize($b)) < 0;
        } catch (\Throwable $e) {
            // Only reachable if a caller put something unserialisable (a closure, a
            // resource) into a row. Both rows already agree on every field the page
            // ranks, renders or links with, so keeping the incumbent is correct.
            return false;
        }
    }

    private static function normalize(array $row, array $c, int $nowMs): ?array
    {
        $id = self::firstOf($row, ['id', 'article_id']);
        $id = is_numeric($id) ? (int) $id : 0;
        if ($id <= 0) {
            return null;                       // unlinkable and undedupable — drop it
        }

        $title = self::str(self::firstOf($row, ['title', 'headline']));
        if ($title === '') {
            return null;
        }

        // A page whose text is the publisher's own navigation is not a story.
        // Judged on the body, because that is what a reader would be given; a
        // row with no body at all is judged on its summary instead.
        $prose = self::str($row['body'] ?? '');
        if ($prose === '') {
            $prose = self::str(self::firstOf($row, ['summary', 'description', 'excerpt']));
        }
        if (self::readsAsNavigation($prose)) {
            return null;
        }

        $section = self::slug(self::str(self::firstOf($row, ['section', 'section_slug', 'category'])));
        $source  = self::slug(self::str(self::firstOf($row, ['source_slug', 'source', 'slug_source'])));
        $srcName = self::str(self::firstOf($row, ['source_name', 'source_title', 'publisher_name']));
        if ($source === '') {
            $source = $srcName !== '' ? self::slug($srcName) : self::hostSlug(self::str($row['url'] ?? ''));
        }
        if ($source === '') {
            $source = 'unknown';
        }
        if ($srcName === '') {
            $srcName = self::firstOf($row, ['source']) !== null ? self::str($row['source']) : $source;
        }

        // One newsroom, many feeds. The registry knows which; a row from outside
        // it falls back to its own source, which is the same thing for a source
        // that publishes a single feed.
        $publisher = self::slug(self::str($row['publisher'] ?? ''));
        if ($publisher === '') {
            $publisher = self::slug(Feeds::publisherOf($source));
        }
        if ($publisher === '') {
            $publisher = $source;
        }

        $image = self::str(self::firstOf($row, ['image_url', 'image', 'thumbnail']));
        if (strcasecmp($image, 'null') === 0) {
            $image = '';
        }

        $pub = self::firstOf($row, ['published_at', 'published_ms', 'published', 'pubdate']);
        $pub = is_numeric($pub) ? (int) $pub : null;
        if ($pub !== null && $pub <= 0) {
            $pub = null;
        }
        if ($pub !== null && $pub < 100000000000) {
            $pub *= 1000;                      // caller handed us seconds, not milliseconds
        }

        $weight = self::firstOf($row, ['weight', 'source_weight', 'tier_weight']);
        $weight = is_numeric($weight) ? (float) $weight : 1.0;
        $weight = max(0.05, min(5.0, $weight));

        $isFinance = $section !== '' && in_array($section, $c['finance_sections'], true);

        // Extract-only is a LICENCE fact, read from the registry, never guessed
        // from the text. A row whose source the registry does not know is treated
        // as full — it is not one of the sources the licence rule is about, and
        // the renderer makes the same judgement independently.
        $known     = Feeds::bySlug($source) !== null;
        $isExtract = array_key_exists('extract', $row)
            ? (bool) $row['extract']
            : ($known && Feeds::isExtractOnly($source));

        // A photograph we are not licensed to republish is not a photograph as
        // far as this page is concerned: it earns no image bonus and it does not
        // make its desk a picture desk, because the renderer is going to draw the
        // house placeholder there instead. Nearly every newsroom in this roster
        // licenses its TEXT and withholds its PICTURES, so getting this wrong
        // would lay out most of the front page around images that never appear.
        // A row whose source the registry does not know is left alone.
        $mayShowImage = array_key_exists('images_allowed', $row)
            ? (bool) $row['images_allowed']
            : (!$known || Feeds::imagesAllowed($source));

        $tier = self::firstOf($row, ['section_tier']);
        $tier = is_numeric($tier) ? (int) $tier : ($section !== '' ? Feeds::sectionTier($section) : 3);

        $meta     = $section !== '' ? Feeds::section($section) : null;
        $priority = $meta !== null
            ? (float) ($meta['priority'] ?? 1.0)
            : self::UNKNOWN_PRIORITY;
        $onHome   = $meta !== null && ($meta['home'] ?? null) !== null;

        $ageHours = $pub === null
            ? $c['undated_age_hours']
            : max(0.0, ($nowMs - $pub) / 3600000.0);

        $decay    = pow(0.5, $ageHours / $c['half_life_hours']);
        $hasImage = $image !== '' && $mayShowImage;
        $score    = $decay * $weight * $priority
            + ($hasImage ? $c['image_bonus'] : 0.0)
            - ($isExtract ? $c['extract_penalty'] : 0.0);

        $freshMs = $c['fresh_minutes'] * 60000;

        // Original keys survive so the renderer keeps author, body, guid_hash, etc.
        return array_merge($row, [
            'id'            => $id,
            'title'         => $title,
            'url'           => self::str($row['url'] ?? ''),
            'summary'       => self::str(self::firstOf($row, ['summary', 'description', 'excerpt'])),
            'image_url'     => $image !== '' ? $image : null,
            'published_at'  => $pub,
            'section'       => $section,
            'section_label' => self::sectionLabel($section),
            'section_tier'  => $tier,
            'source'        => $source,
            'source_name'   => $srcName,
            'publisher'     => $publisher,
            'weight'        => $weight,
            'has_image'     => $hasImage,
            'images_allowed' => $mayShowImage,
            'is_extract'    => $isExtract,
            'is_finance'    => $isFinance,
            'block'         => ($isFinance || !$onHome) ? '' : $section,
            'age_hours'     => $ageHours,
            'fresh'         => $pub !== null && $freshMs > 0 && ($nowMs - $pub) <= $freshMs && ($nowMs - $pub) >= 0,
            'score'         => $score,
            'size'          => 'medium',
            'placement'     => '',
        ]);
    }

    /**
     * The desk's name, resolved by the registry — which also knows that 'us' is
     * an initialism and not the pronoun. Delegated so the front page and the
     * ticker on every other page cannot drift apart on what a desk is called.
     */
    private static function sectionLabel(string $section): string
    {
        return Feeds::labelFor($section);
    }

    private static function firstOf(array $row, array $keys)
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '') {
                return $row[$k];
            }
        }

        return null;
    }

    private static function str($v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_numeric($v)) {
            return trim((string) $v);
        }

        return '';
    }

    /**
     * Does the opening of this text read as a menu rather than a sentence?
     *
     * Only the first NAV_WINDOW words are read: a scraped navigation bar is at
     * the TOP of the document, and a long article that happens to repeat a term
     * later on is not what this is looking for. Words shorter than four letters
     * are ignored — "the", "and" and "for" repeat in ordinary prose and carry no
     * signal. Short texts are never judged: a two-line summary has no room for a
     * repetition to mean anything.
     *
     * See NAV_REPEAT_LIMIT for the measurement this threshold comes from.
     */
    private static function readsAsNavigation(string $text): bool
    {
        $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text), 'UTF-8');
        if ($t === '') {
            return false;
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 20) {
            return false;
        }

        $counts = [];
        foreach (array_slice($words, 0, self::NAV_WINDOW) as $w) {
            if (mb_strlen($w, 'UTF-8') >= 4) {
                $counts[$w] = ($counts[$w] ?? 0) + 1;
                if ($counts[$w] >= self::NAV_REPEAT_LIMIT) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';

        return trim($s, '-');
    }

    private static function hostSlug(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return self::slug(preg_replace('/^www\./', '', $host) ?? $host);
    }

    // -------------------------------------------------------------------------
    // selection
    // -------------------------------------------------------------------------

    /** @param array<int,bool> $used */
    private static function available(array $pool, array $used): array
    {
        $out = [];
        foreach ($pool as $row) {
            if (!isset($used[$row['id']])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @param array<int,int> $tiers */
    private static function onTiers(array $pool, array $tiers): array
    {
        $out = [];
        foreach ($pool as $row) {
            if (in_array((int) ($row['section_tier'] ?? 3), $tiers, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function filterFinance(array $pool, bool $wantFinance): array
    {
        $out = [];
        foreach ($pool as $row) {
            if ($row['is_finance'] === $wantFinance) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Mark a row placed, and charge it to its newsroom's page-wide budget.
     *
     * @param array<int,bool>   $used
     * @param array<string,int> $byPublisher
     */
    private static function claim(array $row, array &$used, array &$byPublisher): void
    {
        $used[$row['id']] = true;
        $p = (string) ($row['publisher'] ?? '');
        if ($p !== '') {
            $byPublisher[$p] = ($byPublisher[$p] ?? 0) + 1;
        }
    }

    /**
     * Greedy pick with the repeat-source penalty applied live, which is what makes
     * "- penalty for a source already used in the same block" change the ORDER and
     * not just the score. Candidates arrive in canonical rank order, and the winner
     * is chosen by a full scan on a TOTAL key (effective score, then published time,
     * then lowest id) — so an exact score tie, which is what a feed that publishes no
     * dates produces for every one of its rows, always resolves the same way and the
     * front page cannot reshuffle with the database's row order.
     *
     * Two caps apply. The per-source cap arrives already sized for this block by
     * blockCap() and is never widened here. The per-publisher budget is page-wide
     * and is the rule that stops one newsroom with nine feeds owning the front
     * page; it gives way only far enough to keep a region from coming out empty,
     * and then binds again — see the bounded relaxation below. The other rule that
     * gives way is "no source leads twice", and only when there is literally no
     * other source left to lead with.
     *
     * $byPublisher is taken BY VALUE on purpose: inside one block the running
     * total has to include the rows this call is picking, but the page-wide
     * ledger is only updated by claim(), once, for the rows that are actually
     * placed. Passing it by reference would charge the budget for a block that
     * enforce() later drops.
     *
     * @param array<string,bool> $leadSources sources that already lead somewhere
     * @param array<string,int>  $byPublisher cards already taken from each newsroom
     */
    private static function takeScored(
        array $cands,
        int $want,
        int $cap,
        float $penalty,
        array $leadSources,
        bool $applyLeadRule,
        array $byPublisher,
        int $publisherMax
    ): array {
        if ($want < 1 || !$cands) {
            return [];
        }

        $effCap = max(1, $cap);

        $picked   = [];
        $takenIds = [];
        $bySource = [];
        $leadRule = $applyLeadRule;
        // Set only while the publisher budget is being held open so a region
        // that would otherwise be empty can exist. See the comment below.
        $relaxUntil = 0;
        $savedMax   = $publisherMax;

        while (count($picked) < $want) {
            $best    = null;
            $bestKey = null;
            foreach ($cands as $row) {
                if (isset($takenIds[$row['id']])) {
                    continue;
                }
                $usedFrom = $bySource[$row['source']] ?? 0;
                if ($usedFrom >= $effCap) {
                    continue;
                }
                if (($byPublisher[$row['publisher']] ?? 0) >= $publisherMax) {
                    continue;                  // that newsroom has had its share of the page
                }
                if (!$picked && $leadRule && isset($leadSources[$row['source']])) {
                    continue;                  // no source leads twice
                }
                $eff = $row['score'] - $penalty * $usedFrom;
                $key = [$eff, $row['published_at'] ?? 0, -$row['id']];
                if ($bestKey === null || self::keyGreater($key, $bestKey)) {
                    $best    = $row;
                    $bestKey = $key;
                }
            }

            if ($best === null) {
                if (!$picked && $leadRule) {
                    $leadRule = false;         // nothing else can lead here — relax, never blank
                    continue;
                }
                if (!$picked && $publisherMax < PHP_INT_MAX) {
                    // THE BUDGET MUST NEVER BE THE REASON A REGION IS EMPTY —
                    // but it must go on binding the moment the region is viable.
                    //
                    // On this roster ONE newsroom feeds eight of the nine desks,
                    // so by the foot of the page it has usually spent its budget
                    // and is the only candidate left. Without a way through, the
                    // markets strip and half the slower desks silently vanished.
                    // The way through used to be unlimited, which meant a
                    // newsroom that had spent its budget could then fill a WHOLE
                    // block — every card of it — and "no publisher owns the front
                    // page" stopped being a rule at the exact moment it mattered.
                    //
                    // ⚠ On today's data the two behave the same, because the
                    // slower desks ask for four cards and four is the floor. The
                    // bound is for the case that is not today's: a fast desk, or
                    // a raised tier count, where the difference is a block of
                    // eight from one newsroom instead of four.
                    //
                    // So the relaxation is BOUNDED. It buys the region a viable
                    // block — MIN_BLOCK_CARDS, the same floor blockCap() uses —
                    // and then the budget comes back for the rest of it.
                    $relaxUntil  = count($picked) + self::MIN_BLOCK_CARDS;
                    $savedMax    = $publisherMax;
                    $publisherMax = PHP_INT_MAX;
                    continue;
                }
                break;
            }

            $picked[] = $best;
            $takenIds[$best['id']] = true;
            $bySource[$best['source']]  = ($bySource[$best['source']] ?? 0) + 1;
            $byPublisher[$best['publisher']] = ($byPublisher[$best['publisher']] ?? 0) + 1;

            // The bounded relaxation above expires here, not at the end of the
            // block: the region got the cards it needed to exist and the
            // page-wide budget owns every slot after that.
            if ($relaxUntil > 0 && count($picked) >= $relaxUntil) {
                $publisherMax = $savedMax;
                $relaxUntil   = 0;
            }
        }

        return $picked;
    }

    /**
     * Take $want rows one SECTION at a time: the head of the first desk, then
     * the head of the next desk, and so on, wrapping round until the strip is
     * full or every desk is spent. Feed it a recency-ordered list and that is
     * "the newest story from each vertical, in turn".
     *
     * A desk with nothing left is skipped, so a thin section costs the strip a
     * gap rather than a slot. The per-source cap still applies: inside a desk we
     * walk on to the next story rather than dropping that desk's turn.
     *
     * Guarantee: two neighbours share a section only when every other desk was
     * already empty, in which case the rest of the list is that one section.
     */
    private static function takeInterleaved(array $cands, int $want, int $cap, bool $everyDeskFirst = false): array
    {
        if ($want < 1) {
            return [];
        }

        /** @var array<string,array<int,array<string,mixed>>> $desks */
        $desks = [];
        foreach ($cands as $row) {
            // Prefixed so every desk key is a string: PHP turns a numeric slug
            // ('2026') into an int key, and one array with two key types is a
            // trap for anything that later reads array_keys() back out.
            $desks['s:' . (string) ($row['section'] ?? '')][] = $row;
        }

        $picked = [];
        $used   = [];
        $cap    = max(1, $cap);

        // ⚠ THE CAP IS COUNTED PER PUBLISHER, NOT PER FEED SLUG, and that is the
        // whole point of it. This roster carries EIGHT feeds from The
        // Conversation and two from NASA, so a cap counted per feed lets one
        // newsroom supply eight times its budget while looking like it is being
        // held to one. Measured on this build before the change: twelve of the
        // twenty-four stories in the rotation pool were The Conversation — half
        // the rotating top of the page, from a single newsroom.
        //
        // prepare() fills 'publisher' for every row and falls back to the source
        // slug when a feed declares none, so for a publisher with one feed this
        // counts exactly what it counted before.
        $keyOf = static function (array $row): string {
            $p = (string) ($row['publisher'] ?? '');

            return $p !== '' ? $p : (string) ($row['source'] ?? '');
        };

        // ⚠ AND FOR THE TICKER THE FIRST ROUND IS EXEMPT, for the reason the
        // class docblock already gives about blocks: a desk fed by one newsroom
        // IS that newsroom, and a desk vanishing is not a cap working, it is a
        // bug. Culture and education are single-publisher desks on this roster,
        // so a flat publisher cap deleted them from the strip outright — they
        // sat on the page and could never rotate onto it. Each desk therefore
        // places its best story and the cap governs everything after that.
        //
        // The RAIL does not get this and must not: its cap is 1 because the
        // config asks for one story per source, and with six slots against nine
        // publishers the cap can always be met without erasing anything. Lifting
        // it there just puts the same newsroom in the list twice.
        $round = 0;

        // The cap is lifted altogether only if it could not fill the list. A
        // short ticker is a visibly broken strip and a short rotation pool stops
        // the page rotating at all, so an over-represented publisher is the
        // lesser failure. Relaxing last rather than not capping at all means the
        // cap still shapes the result whenever there is material for it to.
        foreach ([$cap, PHP_INT_MAX] as $limit) {
            if (count($picked) >= $want) {
                break;
            }
            $keys = array_keys($desks);
            while (count($picked) < $want) {
                $progress = false;
                $round++;
                foreach ($keys as $k) {
                    if (count($picked) >= $want) {
                        break;
                    }
                    while ($desks[$k]) {
                        $row = array_shift($desks[$k]);
                        $key = $keyOf($row);
                        if ((!$everyDeskFirst || $round > 1) && ($used[$key] ?? 0) >= $limit) {
                            continue;          // that publisher is full — next story on this desk
                        }
                        $picked[]   = $row;
                        $used[$key] = ($used[$key] ?? 0) + 1;
                        $progress   = true;
                        break;
                    }
                }
                if (!$progress) {
                    break;                     // nothing left that this limit allows
                }
            }
        }

        return $picked;
    }

    /** The share of a block's rows carrying a picture we are licensed to show. */
    private static function picturesShare(array $items): float
    {
        $n = count($items);
        if ($n < 1) {
            return 0.0;
        }
        $withImage = 0;
        foreach ($items as $row) {
            if (!empty($row['has_image'])) {
                $withImage++;
            }
        }

        return $withImage / $n;
    }

    /**
     * Which grid a block is drawn in, decided by what the block actually holds
     * and where on the page it sits.
     *
     * Three decisions, in this order.
     *
     * SPEED. A tier-1 desk is drawn like the front of a section: a large opening
     * card and a four-column grid under it. That is the client's tier rule made
     * visible without a label — the desks that move during the day are simply
     * bigger on the page than the ones checked every half hour.
     *
     * PICTURES OR NOT. Almost every newsroom in this roster licenses its text and
     * withholds its photographs, so most desks have no picture the site may run
     * and are laid out as text. A desk that does have pictures — on this build
     * that is the agency reporting — gets a picture grid, and is visibly a
     * different kind of band because of it.
     *
     * RHYTHM. A column of six identical text bands is a wall. So the no-picture
     * desks ALTERNATE between two shapes: cards with the house placeholder and
     * the feed's own summary, then dense headline rows, then cards again. The
     * alternation is counted over the no-picture desks only, so a picture desk in
     * the middle of the page does not reset it, and it is deterministic — the
     * same roster always draws the same page.
     *
     * ⚠ This method is deliberately NOT the same in both editions. It is the
     * rhythm of the page, and the brief says the two sites must not look
     * related; the palette and the type are the stylesheet's half of that, and
     * the order and shape of the bands is this file's half.
     *
     * @param array<int,array<string,mixed>> $items
     * @param bool $hasPictures  the block's rows carry usable photographs
     * @param int  $position     blocks already placed on the page
     * @param int  $textBlocks   no-picture blocks already placed
     * @param bool $inLead       the desk sits in the fast region, beside the hero
     */
    private static function gridFor(
        array $items,
        bool $hasPictures,
        int $position,
        int $textBlocks,
        bool $inLead = false
    ): string {
        $n = count($items);
        if ($n < 1) {
            return 'block-grid';
        }
        // The plain grid is the one Render draws with a large opening card.
        if ($inLead || $position === 0) {
            return 'block-grid';
        }
        if (!$hasPictures) {
            return $textBlocks % 2 === 0
                ? 'block-grid block-grid--3'
                : 'block-grid block-grid--wire';
        }

        return $n >= 6 ? 'block-grid block-grid--6up' : 'block-grid block-grid--3';
    }

    /** @param array{0:float,1:int,2:int} $a @param array{0:float,1:int,2:int} $b */
    private static function keyGreater(array $a, array $b): bool
    {
        if ($a[0] !== $b[0]) {
            return $a[0] > $b[0];
        }
        if ($a[1] !== $b[1]) {
            return $a[1] > $b[1];
        }

        return $a[2] > $b[2];
    }

    private static function byScore(array $a, array $b): int
    {
        $c = $b['score'] <=> $a['score'];
        if ($c !== 0) {
            return $c;
        }
        $c = ($b['published_at'] ?? 0) <=> ($a['published_at'] ?? 0);
        if ($c !== 0) {
            return $c;
        }

        return $a['id'] <=> $b['id'];
    }

    private static function byRecency(array $a, array $b): int
    {
        $c = ($b['published_at'] ?? 0) <=> ($a['published_at'] ?? 0);
        if ($c !== 0) {
            return $c;
        }
        $c = $b['score'] <=> $a['score'];
        if ($c !== 0) {
            return $c;
        }

        return $a['id'] <=> $b['id'];
    }

    private static function stamp(array $row, string $placement, string $size): array
    {
        $row['placement'] = $placement;
        $row['size']      = $size;

        return $row;
    }

    // -------------------------------------------------------------------------
    // invariants
    // -------------------------------------------------------------------------

    /**
     * Last line of defence, run on the finished model: the finance ban, the
     * home-page finance cap and the no-duplicate-id rule hold no matter what the
     * selection path above did. Cheap, and it means a future change to scoring
     * cannot quietly put a markets story in the hero.
     */
    private static function enforce(array $model, array $c): array
    {
        $blocked = $c['finance_blocked_blocks'];
        $seen    = [];

        // A row may be kept only if its id is unplaced AND, when it is finance, the
        // region is the markets strip and the strip is not itself blocked by config.
        $keep = static function (array $row, string $region) use (&$seen, $blocked): bool {
            if (isset($seen[$row['id']])) {
                return false;                                     // never twice on one page
            }
            if (!empty($row['is_finance'])
                && ($region !== Compose::MARKETS_ID || in_array($region, $blocked, true))) {
                return false;                                     // finance lives in the strip only
            }
            $seen[$row['id']] = true;

            return true;
        };

        // hero first: the lead is the most valuable slot, so it wins any collision.
        $lead = $model['hero']['lead'] ?? null;
        if (is_array($lead) && !$keep($lead, 'hero')) {
            $lead = null;
        }
        $subs = [];
        foreach ($model['hero']['subs'] ?? [] as $row) {
            if (is_array($row) && $keep($row, 'hero')) {
                $subs[] = $row;
            }
        }
        $model['hero'] = ['lead' => $lead, 'subs' => $subs];

        $rail = [];
        foreach ($model['rail'] ?? [] as $row) {
            if (is_array($row) && $keep($row, 'rail')) {
                $rail[] = $row;
            }
        }
        $model['rail'] = $rail;

        $blocks = [];
        foreach ($model['blocks'] ?? [] as $block) {
            $items = [];
            foreach ($block['items'] ?? [] as $row) {
                if (is_array($row) && $keep($row, (string) ($block['id'] ?? ''))) {
                    $items[] = $row;
                }
            }
            if ($items) {
                $block['items'] = $items;
                $blocks[] = $block;
            }
        }
        $model['blocks'] = $blocks;

        $markets = [];
        $budget  = $c['finance_max_on_home'];
        foreach ($model['markets'] ?? [] as $row) {
            if (!is_array($row) || !$keep($row, self::MARKETS_ID)) {
                continue;
            }
            if (!empty($row['is_finance'])) {
                if ($budget <= 0) {
                    unset($seen[$row['id']]);   // not placed after all
                    continue;
                }
                $budget--;
            }
            $markets[] = $row;
        }
        $model['markets'] = $markets;

        $ticker = [];
        foreach ($model['ticker'] ?? [] as $row) {
            if (is_array($row) && $keep($row, 'ticker')) {
                $ticker[] = $row;
            }
        }
        $model['ticker'] = $ticker;

        return [
            'ticker'  => $model['ticker'],
            'hero'    => $model['hero'],
            'rail'    => $model['rail'],
            'blocks'  => $model['blocks'],
            'regions' => self::regions($model['blocks'], $c),
            'markets' => $model['markets'],
        ];
    }
}
