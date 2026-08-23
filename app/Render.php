<?php
declare(strict_types=1);

namespace TEB;

// Render and Ingest both size publisher images through Images, and the test
// harness loads these files directly, so the dependency is declared here rather
// than relying on bootstrap having run.
require_once __DIR__ . '/Images.php';
require_once __DIR__ . '/Placeholder.php';
// The licence a story arrives under — whether it may be republished whole, and
// whether its photographs may be shown at all — is registry data, and this is
// the file that has to print it on the page. Declared here rather than left to
// bootstrap because the test harness loads this class directly.
require_once __DIR__ . '/Feeds.php';

/**
 * Server-side HTML rendering.
 *
 * Everything the browser receives is built here, as strings, with no template
 * engine and no client-side hydration: the page is complete and correct before
 * a byte of JavaScript runs. assets/js/app.js only sharpens it (a ticking
 * clock, relative timestamps, a theme toggle) — remove it and the site is
 * unchanged in substance.
 *
 * THREE RULES THIS FILE IS BUILT AROUND
 * -------------------------------------
 * 1. NO BRAND, NO DOMAIN. Nothing in here names the site. Every visible piece
 *    of identity is read out of the config array handed in by the caller, so
 *    renaming the site in config.php renames the whole build.
 *
 * 2. NO ABSOLUTE PATHS. Every internal URL goes through TEB\Paths, which knows
 *    whether the app is at a web root or inside /some/sub/folder/ and whether
 *    mod_rewrite is available. There is not one href="/..." in this file.
 *
 * 3. EVERYTHING INTERPOLATED IS ESCAPED. esc() covers & < > " ' so a hostile
 *    headline is inert in text, in an attribute, and inside the onerror
 *    handler. Only markup this class generated itself is concatenated raw.
 *
 * IMAGES (SPEC §0.6)
 * ------------------
 * The single hero image on a page is loading="eager" fetchpriority="high"
 * decoding="async". Every other image on the site is loading="lazy"
 * decoding="async". All of them carry explicit width and height (the stored
 * publisher dimensions when we have them, otherwise the nominal box for that
 * card size — design.css sets img{height:auto} so they scale instead of
 * squashing), alt set to the headline, referrerpolicy="no-referrer" because
 * we hotlink publisher CDNs, and an onerror handler that removes the broken
 * image and promotes the card to the designed text-only state. A row with no
 * image never emits an <img> element at all.
 *
 * The class names come from docs/design/FINAL.md and must match
 * assets/css/site.css exactly — markup and stylesheet are one contract.
 */
final class Render
{
    /**
     * Nominal image box per card size, used when the publisher did not tell us
     * the real dimensions. These are the aspect ratios the stylesheet crops to
     * (.card-media is 3/2, the lead 2/1), so the reserved space is right even
     * before the stylesheet has loaded.
     */
    private const BOX = [
        'lead'   => [1200, 600],
        'large'  => [800, 533],
        'medium' => [640, 427],
        'small'  => [480, 320],
        'text'   => [640, 427],
    ];

    /**
     * The image fallback. It lives inside an HTML attribute, so it is written
     * with single quotes only and escaped on the way out (esc turns ' into
     * &#039;, which the parser hands back to the JS engine as a quote).
     *
     * It does three things, in this order: drop the broken image, promote the
     * card to .card--text — the designed no-photo state, which is what reveals
     * the text-only treatment of the same card — and collapse the media box.
     * The class swap alone would hide the box through CSS; the inline display
     * is belt and braces, because .card-media sets display:block and would
     * therefore beat a bare [hidden] attribute.
     */
    private const ONERROR =
        "this.onerror=null;this.style.display='none';"
        . "var c=this.closest('.card');if(c){c.classList.add('card--text');}"
        . "var m=this.closest('.card-media');if(m){m.style.display='none';}"
        // The article page's photograph is not in a card — it is a <figure>
        // with a credit under it. Without this the broken image collapsed and
        // left the caption floating over an empty box.
        . "var f=this.closest('figure');if(f){f.style.display='none';}";

    /**
     * Primary navigation, when the feed registry has nothing to say. It never
     * should — navSections() builds the real one out of TEB\Feeds — but a nav
     * that renders nothing at all would be worse than a nav with a front-page
     * link on it.
     */
    private const NAV = [
        ['/', 'Front page'],
    ];

    /** The right-aligned tail of the nav, and the footer's "About" column. */
    private const NAV_TAIL = [
        ['/sources', 'Sources'],
        ['/about', 'About'],
        ['/search', 'Search'],
    ];

    /**
     * The standing pages. FOOTER ONLY — the top navigation stays a news
     * navigation, which is where a reader's eye expects sections and nothing
     * else. One array again, so a page can never be linked under two names.
     */
    private const NAV_LEGAL = [
        ['/editorial-standards', 'Editorial Standards'],
        ['/contact', 'Contact'],
        ['/privacy', 'Privacy Policy'],
        ['/terms', 'Terms of Use'],
    ];

    /**
     * Words a minute used for the reading time printed on an article page.
     * 220 is the middle of the measured adult range for prose read on screen;
     * the figure is computed from the words actually rendered, never from a
     * character count or a guess, so a 1,400-word piece says six minutes and a
     * 400-character extract says one.
     */
    private const READING_WPM = 220;

    /**
     * How much of a story may be quoted when its licence does not reach an
     * advertising-supported page. Four hundred characters, cut at the end of a
     * sentence — the treatment a wire story gets, and the same length the
     * editorial-standards page promises.
     */
    private const EXTRACT_CHARS = 400;

    /**
     * The line that keeps us honest about what this site republishes (SPEC §0.7).
     *
     * Rewritten when the roster moved to open-licensed newsrooms that publish
     * their whole article in the feed. The old wording promised "summaries and
     * source links only" and stopped being true the moment the article template
     * began rendering the publisher's full text; a standing line describing a
     * different site is worse than none. It has to agree with
     * /editorial-standards, which sets out the same rule at length.
     */
    private const STANDING =
        'Nothing on this site was reported by us. Each piece is carried under the licence its '
        . 'newsroom grants — whole where that is permitted, as a headline and a short extract '
        . 'where it is not — with the author credited and the original always one click away.';

    // =====================================================================
    //  escaping and small string helpers
    // =====================================================================

    /**
     * The only way text reaches the page. Escapes & < > " and ' so the same
     * call is safe in element content, in a double-quoted attribute, in a
     * single-quoted attribute and inside an inline event handler.
     *
     * ENT_SUBSTITUTE matters more than it looks: without it a single invalid
     * UTF-8 byte from a publisher's feed makes htmlspecialchars return an
     * empty string, and a headline silently vanishes instead of being repaired.
     */
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Any scalar to a trimmed string; arrays, objects and null become ''. */
    private static function s($v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v) || is_float($v)) {
            return trim((string) $v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }

        return '';
    }

    /**
     * Build an attribute list. null / false / '' drop the attribute entirely,
     * true renders it bare (boolean attributes), everything else is escaped.
     */
    private static function attrs(array $pairs): string
    {
        $out = '';
        foreach ($pairs as $name => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            if ($value === true) {
                $out .= ' ' . $name;
                continue;
            }
            $out .= ' ' . $name . '="' . self::esc((string) $value) . '"';
        }

        return $out;
    }

    /** An internal link. Never bypass this — see rule 2 in the class docblock. */
    private static function url(string $routePath): string
    {
        return Paths::url($routePath);
    }

    /**
     * A publisher's link, from a feed, i.e. hostile input. Only http and https
     * survive: a feed that hands us `javascript:` or a `data:` URL gets an
     * empty string and the link is not rendered at all.
     */
    public static function outbound(string $url): string
    {
        $url = trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $url));
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        if ((string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }

        return $url;
    }

    /** URL-safe slug. Byte-wise on purpose: it can never return null on bad UTF-8. */
    public static function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['&', '@'], [' and ', ' at '], $s);
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > 72) {
            $s = substr($s, 0, 72);
            $cut = strrpos($s, '-');
            if ($cut !== false && $cut > 24) {
                $s = substr($s, 0, $cut);
            }
            $s = trim($s, '-');
        }

        return $s;
    }

    /**
     * The route path for one article: /article/{slug}-{id}. The id is the
     * authority; the slug is decoration the router is free to correct.
     */
    public static function articleHref(array $a): string
    {
        $id = (int) ($a['id'] ?? 0);
        if ($id < 1) {
            return '/';
        }
        $slug = self::s($a['slug'] ?? '');
        if ($slug === '') {
            $slug = self::s($a['title'] ?? '');
        }
        $slug = self::slug($slug);

        return '/article/' . ($slug !== '' ? $slug . '-' : '') . $id;
    }

    // =====================================================================
    //  time
    // =====================================================================

    private static function tz(array $cfg): \DateTimeZone
    {
        $name = self::s($cfg['site']['timezone'] ?? '');
        if ($name !== '') {
            try {
                return new \DateTimeZone($name);
            } catch (\Throwable $e) {
                // A timezone typed from memory must not take the site down.
            }
        }

        return new \DateTimeZone('UTC');
    }

    /** '5:48 p.m.' — the newspaper form the design sets in the mono face. */
    private static function clockLabel(\DateTimeImmutable $dt): string
    {
        return $dt->format('g:i') . ($dt->format('A') === 'AM' ? ' a.m.' : ' p.m.');
    }

    /**
     * <time datetime="…">5:48 p.m.</time>.
     *
     * The ABSOLUTE time is rendered on the server, so a reader with no
     * JavaScript sees a correct timestamp. app.js later rewrites recent ones
     * to "12m ago" and puts the absolute value in the title attribute.
     */
    public static function timeTag(?int $ms, array $cfg, string $class = 't'): string
    {
        if ($ms === null || $ms <= 0) {
            return '';
        }
        $dt  = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(self::tz($cfg));
        $age = time() - intdiv($ms, 1000);
        $abs = $age > 86400 || $age < -3600
            ? $dt->format('M j') . ', ' . self::clockLabel($dt)
            : self::clockLabel($dt);

        return '<time' . self::attrs(['class' => $class, 'datetime' => $dt->format('c')]) . '>'
            . self::esc($abs) . '</time>';
    }

    // =====================================================================
    //  images
    // =====================================================================

    /**
     * May this story's photograph be shown at all?
     *
     * Nearly every newsroom in this roster licenses its TEXT and withholds its
     * PICTURES — Grist says it in one sentence ("you can't republish
     * photographs, collages ... or illustrations without written permission"),
     * KFF says its images are "available for republication for noncommercial
     * use only", The Conversation asks us to confirm a licence we cannot
     * confirm from a feed. So the picture is dropped and the house placeholder
     * is drawn instead. That is not a fallback for a missing image; it is the
     * licence being obeyed, and it is why the placeholder work matters.
     *
     * A row whose source the registry does not recognise is left alone: the
     * renderer is not the licensing authority for something it cannot identify,
     * and the rows this site builds for itself — the house placeholder among
     * them — are unknown to the registry by definition.
     */
    private static function mayShowImage(array $a): bool
    {
        if (array_key_exists('images_allowed', $a)) {
            return (bool) $a['images_allowed'];
        }
        $slug = self::s($a['source_slug'] ?? '');
        if ($slug === '') {
            $slug = self::s($a['source'] ?? '');
        }
        if ($slug === '' || Feeds::bySlug($slug) === null) {
            return true;
        }

        return Feeds::imagesAllowed($slug);
    }

    /** Stored publisher dimensions when we have them, else the card's nominal box. */
    private static function dims(array $a, string $size): array
    {
        $w = (int) ($a['image_width'] ?? 0);
        $h = (int) ($a['image_height'] ?? 0);
        if ($w > 0 && $h > 0 && $w <= 10000 && $h <= 10000) {
            return [$w, $h];
        }

        return self::BOX[$size] ?? self::BOX['medium'];
    }

    /**
     * One <img>, or '' when the row has no usable image — in which case the
     * caller emits the text-only card and no media element at all.
     *
     * $eager is true for the ONE hero image on the page and false everywhere
     * else. There is no third state.
     */
    private static function imgTag(array $a, string $size, bool $eager): string
    {
        $raw = self::s($a['image_url'] ?? '');
        if ($raw !== '' && !Placeholder::isPlaceholder($raw) && !self::mayShowImage($a)) {
            return '';                       // licensed text, unlicensed picture
        }

        // Our own placeholder is a site-relative path, and outbound() rejects
        // anything without a scheme and host — which is right for a publisher
        // URL and wrong for ours. Let ours through untouched.
        $isOwn = $raw !== '' && Placeholder::isPlaceholder($raw);
        $src   = $isOwn ? $raw : self::outbound($raw);
        if ($src === '') {
            return '';
        }

        // Refuse a picture that cannot fill this slot. Several publishers put a
        // sidebar thumbnail in their feed — CBS News ships every image at 60x60
        // — and stretching one into a lead photo produces unreadable mush. The
        // caller falls back to the designed text-only card, which is better.
        if (!$isOwn && !Images::usable($a, $size)) {
            return '';
        }

        [$w, $h] = self::dims($a, $size);

        $alt = self::s($a['image_alt'] ?? '');
        if ($alt === '') {
            $alt = self::s($a['title'] ?? '');
        }
        if ($alt === '') {
            $alt = self::s($a['source_name'] ?? '') . ' photograph';
            $alt = trim($alt) === 'photograph' ? 'News photograph' : $alt;
        }

        $attrs = [
            'src'            => $src,
            'alt'            => $alt,
            'width'          => (string) $w,
            'height'         => (string) $h,
            'decoding'       => 'async',
            'referrerpolicy' => 'no-referrer',
        ];
        if ($eager) {
            $attrs['loading']       = 'eager';
            $attrs['fetchpriority'] = 'high';
        } else {
            $attrs['loading'] = 'lazy';
        }
        $attrs['onerror'] = self::ONERROR;

        return '<img' . self::attrs($attrs) . '>';
    }

    // =====================================================================
    //  card — one component, five sizes (docs/design/FINAL.md)
    // =====================================================================

    /**
     * $o keys:
     *   size    'lead'|'large'|'medium'|'small'|'text'   default 'medium'
     *   lazy    bool   default true — false makes this the page's hero image
     *   cfg     array  the config, for the timezone on the timestamp
     *   hed     'h1'|'h2'|'h3'                            default h2 for lead
     *   link    bool   default true — false on the article page's own headline
     *   out     bool   show the outbound "Read the full story" link
     *   kline   bool   show the chip/kicker line above a lead headline
     *   class   string extra classes
     */
    public static function card(array $a, array $o = []): string
    {
        $cfg  = is_array($o['cfg'] ?? null) ? $o['cfg'] : [];
        $size = self::s($o['size'] ?? 'medium');
        if (!isset(self::BOX[$size])) {
            $size = 'medium';
        }
        $lazy   = array_key_exists('lazy', $o) ? (bool) $o['lazy'] : true;
        $link   = array_key_exists('link', $o) ? (bool) $o['link'] : true;

        $title = self::s($a['title'] ?? '');
        if ($title === '') {
            return '';                       // a card with no headline is not a card
        }

        $href    = self::url(self::articleHref($a));
        $out     = self::outbound(self::s($a['url'] ?? ''));
        $srcName = self::s($a['source_name'] ?? '');
        if ($srcName === '') {
            $srcName = self::s($a['source'] ?? '');
        }
        $kicker  = self::s($a['section_label'] ?? '');
        $summary = self::standfirst(self::s($a['summary'] ?? ''), $title);

        // .card--small and .card--text never carry media: the design hides it,
        // and emitting it anyway would download an image nobody can see.
        $img = ($size === 'small' || $size === 'text')
            ? ''
            : self::imgTag($a, $size, !$lazy);

        // Most of this roster ships no picture we may run: The Conversation
        // carries none in its feed at all, and Grist, KFF and The 19th license
        // the words but not the photographs. Rather than leave holes in the
        // grid, draw the house card so every story still looks like an item in
        // a newspaper.
        if ($img === '' && $size !== 'small' && $size !== 'text') {
            $ph = $a;
            $ph['image_url']    = Placeholder::url($a);
            $ph['image_width']  = 1200;
            $ph['image_height'] = 630;
            $ph['image_alt']    = self::s($a['title'] ?? '');
            $img = self::imgTag($ph, $size, !$lazy);
        }

        $classes = ['card', 'card--' . $size];
        if ($img === '' && $size !== 'small' && $size !== 'text') {
            // No photograph AND no placeholder drawn: the designed no-photo state.
            $classes[] = 'card--text';
        }
        $extra = self::s($o['class'] ?? '');
        if ($extra !== '') {
            $classes[] = $extra;
        }

        $hedTag = self::s($o['hed'] ?? '');
        if ($hedTag === '' || !in_array($hedTag, ['h1', 'h2', 'h3', 'h4'], true)) {
            $hedTag = $size === 'lead' ? 'h2' : 'h3';
        }

        // ---- pieces -----------------------------------------------------
        $media = '';
        if ($img !== '') {
            $credit = '';
            if ($srcName !== '' && ($size === 'lead' || $size === 'large')) {
                $credit = '<span class="credit">PHOTO · ' . self::esc(mb_strtoupper($srcName, 'UTF-8')) . '</span>';
            }
            // aria-hidden + tabindex="-1": the headline link below is the real
            // one, so this must not become a second tab stop or a second
            // announcement. The alt text still carries the headline for any
            // client that ignores ARIA.
            $media = '<a' . self::attrs([
                'class'       => 'card-media',
                'href'        => $href,
                'tabindex'    => '-1',
                'aria-hidden' => 'true',
            ]) . '>' . $img . $credit . '</a>';
        }

        $hed = '<' . $hedTag . ' class="card-hed">'
            . ($link ? '<a' . self::attrs(['href' => $href]) . '>' . self::esc($title) . '</a>' : self::esc($title))
            . '</' . $hedTag . '>';

        $sum = ($summary !== '' && $size !== 'small')
            ? '<p class="card-sum">' . self::esc($summary) . '</p>'
            : '';

        $time = self::timeTag(isset($a['published_at']) ? (int) $a['published_at'] : null, $cfg);
        $src  = '';
        if ($srcName !== '' || $time !== '') {
            $src = '<p class="card-src">' . self::esc($srcName)
                . ($srcName !== '' && $time !== '' ? ' · ' : '') . $time . '</p>';
        }

        // No card ever links off the site. Every route into a story goes through
        // our own article page first; the link to the publisher lives there, at
        // the end of the piece. A lead card that jumped straight to abcnews.com
        // was handing our traffic away on the front page.
        $outLink = '';
        $wantOut = array_key_exists('out', $o) ? (bool) $o['out'] : false;
        if ($wantOut && $out !== '') {
            $outLink = '<a' . self::attrs([
                'class' => 'card-out',
                'href'  => $out,
                'rel'   => 'noopener nofollow',
            ]) . '>' . self::esc($srcName !== '' ? 'Read the full story at ' . $srcName . ' →' : 'Read the full story →')
                . '</a>';
        }

        $kickerEl = $kicker !== '' ? '<p class="kicker">' . self::esc($kicker) . '</p>' : '';

        $open = '<article class="' . self::esc(implode(' ', $classes)) . '">';

        // ---- lead: kicker line, headline, then the picture ----------------
        if ($size === 'lead') {
            $kline = '';
            if (array_key_exists('kline', $o) ? (bool) $o['kline'] : true) {
                $chip = !empty($a['fresh']) ? '<span class="chip">New</span>' : '';
                if ($chip !== '' || $kicker !== '') {
                    $kline = '<div class="kline">' . $chip
                        . ($kicker !== '' ? '<span class="kicker">' . self::esc($kicker) . '</span>' : '')
                        . '</div>';
                }
            }

            return $open . $kline . $hed . $media . $sum . $src . $outLink . '</article>';
        }

        // ---- every other size: picture, kicker, headline -------------------
        return $open . $media . $kickerEl . $hed . $sum . $src . $outLink . '</article>';
    }

    // =====================================================================
    //  ticker — headlines, never markets (SPEC §7), one desk at a time
    // =====================================================================

    /**
     * The strip cycles the DESKS, not the clock. Handed the newest stories a
     * page has, it emits the newest item from one vertical, then the newest
     * from the next, and the next, and wraps round — so two neighbours in the
     * strip are never off the same desk while another desk still has a story
     * waiting. Which desks, and in what order, is read off the stories
     * themselves; there is no list of section names in this file.
     *
     * On a slow news morning the old strip was five headlines off one desk;
     * this one cannot be. A vertical with nothing in it is skipped, never left
     * as a gap, and every item wears its section, so the variety is visible
     * rather than merely claimed.
     *
     * The duplicate <ul> is not an accident: the CSS keyframe translates the
     * track by -50%, so the second copy is what makes the loop seamless. It is
     * aria-hidden and every link inside it is taken out of the tab order, so
     * the duplication is invisible to assistive tech and to the keyboard.
     *
     * Pausing on hover and on focus-within is pure CSS, prefers-reduced-motion
     * stops the animation outright, and app.js only adds the pause for a hidden
     * tab, which CSS cannot see. None of that is affected by the ordering.
     */
    public static function ticker(array $items, array $cfg): string
    {
        $clean = [];
        foreach ($items as $item) {
            if (is_array($item) && self::s($item['title'] ?? '') !== '') {
                $clean[] = $item;
            }
        }
        $clean = self::tickerOrder($clean);
        if (!$clean) {
            return '';
        }

        $live = '';
        $copy = '';
        foreach ($clean as $item) {
            $live .= self::tickerItem($item, false);
            $copy .= self::tickerItem($item, true);
        }

        return '<div class="ticker" aria-label="Latest headlines">'
            . '<div class="ticker-bug"><span class="dot" aria-hidden="true"></span>TODAY</div>'
            . '<div class="ticker-vp"><div class="ticker-track">'
            . '<ul>' . $live . '</ul>'
            . '<ul aria-hidden="true">' . $copy . '</ul>'
            . '</div></div></div>';
    }

    /**
     * Interleave items by section: newest of each desk, in turn, wrapping round.
     *
     * Desks are visited in the order they first appear in the input, so a
     * recency-ordered list still opens the strip on the newest story of the day
     * and then fans out across the other verticals. Order inside a desk is the
     * order it arrived in — the caller sorts, this only interleaves.
     *
     * The guarantee, and it is the whole point of the function: items[i] and
     * items[i + 1] share a section ONLY when every other section was already
     * empty, in which case everything from i on is that one section.
     *
     * Pure: no clock, no config, no I/O. $limit of 0 or less keeps everything.
     * Idempotent — running it over its own output returns that output unchanged.
     *
     * @param  array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public static function tickerOrder(array $items, int $limit = 0): array
    {
        /** @var array<string,array<int,array<string,mixed>>> $desks */
        $desks = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            // Prefixed so every desk key is a string: PHP turns a numeric slug
            // ('2026') into an int key, and one array with two key types is a
            // trap for anything that later reads array_keys() back out.
            $desks['s:' . self::tickerSectionKey($item)][] = $item;
        }
        if (!$desks) {
            return [];
        }

        $want = $limit > 0 ? min($limit, count($items)) : count($items);
        $keys = array_keys($desks);
        $out  = [];

        while (count($out) < $want) {
            $moved = false;
            foreach ($keys as $k) {
                if (!$desks[$k]) {
                    continue;                  // this desk is spent — skip it, no gap
                }
                $out[]  = array_shift($desks[$k]);
                $moved  = true;
                if (count($out) >= $want) {
                    break;
                }
            }
            if (!$moved) {
                break;                         // every desk is empty
            }
        }

        return $out;
    }

    /** The desk an item files to, normalised for grouping. '' when it has none. */
    private static function tickerSectionKey(array $item): string
    {
        $s = strtolower(trim(self::s($item['section'] ?? '')));
        if ($s === '') {
            $s = strtolower(trim(self::s($item['section_label'] ?? '')));
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    /**
     * What the item wears in the strip. Compose stamps section_label on every
     * row it places; a raw database row — which is what every page other than
     * the front page hands us — carries only the bare section slug.
     */
    private static function tickerSectionLabel(array $item): string
    {
        $label = self::s($item['section_label'] ?? '');
        if ($label !== '') {
            return $label;
        }
        return Feeds::labelFor(self::tickerSectionKey($item));
    }

    private static function tickerItem(array $item, bool $mirror): string
    {
        $title   = self::s($item['title'] ?? '');
        $section = self::tickerSectionLabel($item);
        $chip    = !empty($item['fresh']) ? '<span class="chip">New</span>' : '';

        // The desk is printed BEFORE the headline, the way a wire slug is: it is
        // what makes the spread of verticals readable as the strip goes past.
        return '<li><a' . self::attrs([
            'href'     => self::url(self::articleHref($item)),
            'tabindex' => $mirror ? '-1' : null,
        ]) . '>' . $chip
            . ($section !== '' ? '<span class="s">' . self::esc($section) . '</span>' : '')
            . self::esc($title)
            . '</a></li>';
    }

    // =====================================================================
    //  hero
    // =====================================================================

    /**
     * rail (2fr) | subs (4fr) | lead (6fr), with the lead placed top-right by
     * the grid. DOM order is rail → subs → lead so the markup still reads in
     * a sensible order with no stylesheet.
     *
     * Degrades on purpose: with no rail items and no secondary stories the
     * lead is rendered in a plain block instead, because an empty 2fr column
     * would otherwise sit there as a hole.
     */
    /**
     * The rotation knobs from config.php, as attributes on the hero.
     *
     * config.php ships a 'rotation' block — enabled, seconds, count — with a
     * comment telling the operator what each one does. Until this method
     * existed nothing read it: app.js held its own hardcoded 80–100s timer, so
     * turning rotation off or changing the interval in config changed nothing
     * on the page. A setting that does not settle anything is worse than no
     * setting, so the values are published here and app.js reads them.
     *
     * 'enabled' => false emits no interval at all, which is how app.js is told
     * not to start. 'seconds' is clamped to the 30–600 the client's own comment
     * describes as sane. 'count' is how many of the hero's cards are refreshed
     * on each turn; app.js caps it at the number of cards the page actually
     * has, so a value larger than the layout is harmless rather than a hole.
     *
     * @return array<string,string|null> for attrs(), which drops the nulls
     */
    private static function rotationAttrs(array $cfg): array
    {
        $r = is_array($cfg['rotation'] ?? null) ? $cfg['rotation'] : [];

        if (array_key_exists('enabled', $r) && !$r['enabled']) {
            return ['data-rotate-seconds' => '0'];
        }

        $seconds = (int) ($r['seconds'] ?? 0);
        $seconds = $seconds > 0 ? max(30, min(600, $seconds)) : 90;
        $count   = (int) ($r['count'] ?? 0);

        return [
            'data-rotate-seconds' => (string) $seconds,
            'data-rotate-count'   => $count > 0 ? (string) $count : null,
        ];
    }

    private static function hero(array $model, array $cfg): string
    {
        $hero = is_array($model['hero'] ?? null) ? $model['hero'] : [];
        $lead = is_array($hero['lead'] ?? null) ? $hero['lead'] : null;
        $subs = is_array($hero['subs'] ?? null) ? $hero['subs'] : [];

        $rail = is_array($model['rail'] ?? null) ? $model['rail'] : [];
        if (!$rail && is_array($model['ticker'] ?? null)) {
            $rail = array_slice($model['ticker'], 0, 6);
        }

        // card() refuses to build a card with no headline, so a lead row that
        // has lost its title would return an empty string here and leave the
        // hero grid holding a rail and a 6fr hole — the mirror image of the
        // empty-rail case this function already guards against. Test the same
        // condition card() tests, and take the same exit.
        if ($lead === null || self::s($lead['title'] ?? '') === '') {
            return '';
        }

        $railHtml = '';
        if ($rail) {
            $li = '';
            $n  = 0;
            foreach ($rail as $item) {
                if (!is_array($item) || self::s($item['title'] ?? '') === '') {
                    continue;
                }
                $n++;
                $src = self::s($item['source_name'] ?? '');
                if ($src === '') {
                    $src = self::s($item['source'] ?? '');
                }
                $li .= '<li><a' . self::attrs(['href' => self::url(self::articleHref($item))]) . '>'
                    . '<span class="n">' . self::esc((string) $n) . '</span>'
                    . '<span><span class="h">' . self::esc(self::s($item['title'])) . '</span>'
                    . ($src !== '' ? '<span class="s">' . self::esc($src) . '</span>' : '')
                    . '</span></a></li>';
                if ($n >= 6) {
                    break;
                }
            }
            if ($li !== '') {
                $nav  = self::navSections();
                $more = $nav[1] ?? ['/', 'Front page'];
                $railHtml = '<div class="hero-rail">'
                    . '<div class="rail-head"><p class="kicker">Also today</p>'
                    . '<p class="rail-note">Stories moving elsewhere while the top of the page holds.</p></div>'
                    . '<ol class="rail-list">' . $li . '</ol>'
                    . '<div class="rail-foot"><a' . self::attrs(['href' => self::url($more[0])])
                    . '>More headlines →</a></div>'
                    . '</div>';
            }
        }

        $subCards = '';
        foreach ($subs as $sub) {
            if (is_array($sub)) {
                $subCards .= self::card($sub, ['size' => 'medium', 'cfg' => $cfg]);
            }
        }

        // Without a rail the 2fr column of the hero grid would sit empty — a
        // 400px hole at 2560px. So a thin front page degrades to a plain block
        // instead: the lead, then whatever seconds exist, in the normal grid.
        // Same classes, no special case in the stylesheet.
        if ($railHtml === '') {
            return '<section' . self::attrs([
                    'class'      => 'block wrap',
                    'aria-label' => 'Top stories',
                ] + self::rotationAttrs($cfg)) . '>'
                . self::card($lead, ['size' => 'lead', 'lazy' => false, 'cfg' => $cfg])
                . ($subCards !== '' ? '<div class="block-grid">' . $subCards . '</div>' : '')
                . '</section>';
        }

        return '<section' . self::attrs([
                'class'      => 'hero wrap',
                'aria-label' => 'Top stories',
            ] + self::rotationAttrs($cfg)) . '>'
            . $railHtml
            . ($subCards !== '' ? '<div class="hero-subs">' . $subCards . '</div>' : '')
            // THE page hero: the only eager image anywhere in the document.
            . self::card($lead, ['size' => 'lead', 'lazy' => false, 'cfg' => $cfg, 'class' => 'hero-lead'])
            . '</section>';
    }

    // =====================================================================
    //  block
    // =====================================================================

    /**
     * A section band: a ruled header and a FLAT grid of cards. Nothing here
     * depends on how many cards there are or which column they land in — the
     * hairline is drawn by each card, so any count resolves cleanly.
     *
     * $b = ['id','label','href','note','grid','items'].
     */
    public static function block(array $b, array $cfg, array $o = []): string
    {
        $items = is_array($b['items'] ?? null) ? $b['items'] : [];
        $label = self::s($b['label'] ?? '');
        $note  = self::s($b['note'] ?? '');
        $grid  = self::s($b['grid'] ?? '');
        if ($grid === '' || strpos($grid, 'block-grid') !== 0) {
            $grid = 'block-grid';
        }
        $lazy   = array_key_exists('lazy', $o) ? (bool) $o['lazy'] : true;

        $cards = '';
        $first = true;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $size = self::s($item['size'] ?? '');
            if (!isset(self::BOX[$size])) {
                $size = $first ? 'large' : 'medium';
            }
            if (strpos($grid, 'block-grid--wire') !== false) {
                $size = 'small';          // the wire desk is text rows by design
            }
            $cards .= self::card($item, [
                'size'   => $size,
                'cfg'    => $cfg,
                'lazy'   => $first ? $lazy : true,
            ]);
            $first = false;
        }

        if ($cards === '' && empty($o['keep_empty'])) {
            return '';
        }

        // The wrapper around a block's label is a heading when the block IS the
        // page (a section index: 'h1') or a named region of it (a front-page
        // desk: 'h2'), and a plain <p> only where the label repeats something
        // already in the outline. Pages were reaching readers whose first
        // heading was an <h3> card or an <h5> in the footer, which leaves a
        // screen reader no way to find the top of the page and gives a news
        // section index no headline of its own. .block-h1/.block-h2 reset the
        // browser's own margins and size, so nothing moves on screen.
        $tag  = self::s($o['heading'] ?? '');
        $tag  = ($tag === 'h1' || $tag === 'h2') ? $tag : 'p';
        $open = $tag === 'p' ? '<p>' : '<' . $tag . ' class="block-' . $tag . '">';
        $head = '<div class="block-head">' . $open
            . ($label !== '' ? '<span class="block-label">' . self::esc($label) . '</span>' : '')
            . ($note !== '' ? ' <span class="block-note">— ' . self::esc($note) . '</span>' : '')
            . '</' . $tag . '>';
        $href = self::s($b['href'] ?? '');
        if ($href !== '' && empty($o['no_more'])) {
            $head .= '<a' . self::attrs(['class' => 'block-more', 'href' => self::url($href)]) . '>'
                . self::esc('More ' . ($label !== '' ? $label : 'stories') . ' →') . '</a>';
        }
        $head .= '</div>';

        return '<section class="block wrap"' . self::attrs(['aria-label' => $label !== '' ? $label : null]) . '>'
            . $head . '<div class="' . self::esc($grid) . '">' . $cards . '</div></section>';
    }

    // =====================================================================
    //  markets strip — once, low on the page, quiet (SPEC §0.5)
    // =====================================================================

    /**
     * Quotes are only printed when a caller actually supplies them. We have no
     * market data feed, and inventing an index level would be worse than
     * leaving the row bare.
     */
    public static function marketsStrip(array $items, array $cfg, array $quotes = []): string
    {
        $cards = '';
        $n = 0;
        foreach ($items as $item) {
            if (!is_array($item) || $n >= 2) {
                continue;
            }
            $cards .= self::card($item, ['size' => 'small', 'cfg' => $cfg]);
            $n++;
        }

        $quoteEl = '';
        if ($quotes) {
            $li = '';
            foreach ($quotes as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $name  = self::s($q['name'] ?? '');
                $value = self::s($q['value'] ?? '');
                $move  = self::s($q['change'] ?? '');
                $dir   = self::s($q['direction'] ?? '');
                if ($name === '' || $value === '') {
                    continue;
                }
                $li .= '<li><span class="nm">' . self::esc($name) . '</span>' . self::esc($value)
                    . ($move !== ''
                        ? ' <span class="' . ($dir === 'down' ? 'down' : 'up') . '">'
                          . self::esc(($dir === 'down' ? '▼ ' : '▲ ') . $move) . '</span>'
                        : '')
                    . '</li>';
            }
            if ($li !== '') {
                $quoteEl = '<ul class="mk-quotes">' . $li . '</ul>';
            }
        }

        if ($cards === '' && $quoteEl === '') {
            return '';
        }

        return '<section class="markets-strip" aria-label="Markets"><div class="wrap">'
            . '<div class="mk-row"><p class="mk-label">Money · after the close</p>'
            . $quoteEl
            . ($quoteEl !== '' ? '<span class="mk-fine">delayed at least 15 min</span>' : '')
            . '</div>'
            . ($cards !== '' ? '<div class="mk-cards">' . $cards . '</div>' : '')
            . '</div></section>';
    }

    // =====================================================================
    //  ad slot — height reserved, zero layout shift (SPEC §8)
    // =====================================================================

    /**
     * The box is drawn at the configured size whether ads are on or off, so
     * switching them on never moves the page. While they are off this emits
     * the reserved frame and nothing else: no script, no iframe, no request to
     * anybody. When they are on, the same reserved frame carries an empty
     * mount for the client's tag to fill, so the ad lands inside space that
     * was already there.
     */
    public static function adSlot(string $name, array $cfg): string
    {
        $slots = (is_array($cfg['ads'] ?? null) && is_array($cfg['ads']['slots'] ?? null))
            ? $cfg['ads']['slots'] : [];
        if (!isset($slots[$name]) || !is_array($slots[$name])) {
            return '';
        }
        $w = (int) ($slots[$name][0] ?? 0);
        $h = (int) ($slots[$name][1] ?? 0);
        if ($w < 1 || $h < 1) {
            return '';
        }
        $w = min($w, 2000);
        $h = min($h, 2000);

        $enabled = !empty($cfg['ads']['enabled']);
        $classes = 'adslot' . ($w <= 320 ? ' adslot--box' : '');

        $inner = $enabled
            ? '<div' . self::attrs([
                'class'        => 'adslot-mount',
                'id'           => 'ad-' . self::slug($name),
                'data-ad-slot' => $name,
              ]) . '></div>'
            : '<span class="adslot-label">Advertisement</span>'
              . '<span class="adslot-dim">' . self::esc($w . ' × ' . $h) . '</span>';

        return '<div' . self::attrs([
            'class'        => $classes,
            'style'        => '--ad-w:' . $w . 'px;--ad-h:' . $h . 'px',
            'aria-label'   => 'Advertisement slot',
            'data-ad-slot' => $name,
        ]) . '><div class="adslot-frame">' . $inner . '</div></div>';
    }

    // =====================================================================
    //  search bar and pagination
    // =====================================================================

    public static function searchbar(string $q, array $cfg): string
    {
        return '<form' . self::attrs([
            'class'  => 'searchbar',
            'action' => self::url('/search'),
            'method' => 'get',
            'role'   => 'search',
        ]) . '>'
            . self::rewriteCarrier('/search')
            . '<input' . self::attrs([
                'type'        => 'search',
                'name'        => 'q',
                'value'       => $q,
                'placeholder' => 'Search the headlines…',
                'aria-label'  => 'Search',
            ]) . '>'
            . '<button type="submit">Search</button></form>';
    }

    /**
     * When mod_rewrite is unavailable our links carry ?r=/search. A GET form
     * throws every existing query parameter away, so the route has to be
     * re-stated as a hidden field or the search button lands on the front page.
     */
    private static function rewriteCarrier(string $routePath): string
    {
        if (Paths::hasRewrite()) {
            return '';
        }

        return '<input' . self::attrs(['type' => 'hidden', 'name' => 'r', 'value' => $routePath]) . '>';
    }

    /**
     * $p = ['page'=>int, 'pages'=>int, 'template'=>string] where the template
     * carries a {page} placeholder, e.g. '/section/us?page={page}'.
     * Disabled links are not rendered — an unreachable link is a defect.
     */
    public static function pagination(array $p, array $cfg): string
    {
        $page  = max(1, (int) ($p['page'] ?? 1));
        $pages = max(1, (int) ($p['pages'] ?? 1));
        if ($pages < 2) {
            return '';
        }
        $template = self::s($p['template'] ?? '');
        if ($template === '' || strpos($template, '{page}') === false) {
            return '';
        }

        $link = static function (int $n, string $label, string $class) use ($template): string {
            return '<a' . self::attrs([
                'class' => $class,
                'href'  => self::url(str_replace('{page}', (string) $n, $template)),
            ]) . '>' . self::esc($label) . '</a>';
        };

        $out = '';
        if ($page > 1) {
            $out .= $link($page - 1, '← Newer', 'pg pg-prev');
        }

        $window = [];
        foreach ([1, $page - 1, $page, $page + 1, $pages] as $n) {
            if ($n >= 1 && $n <= $pages) {
                $window[$n] = true;
            }
        }
        ksort($window);
        $last = 0;
        foreach (array_keys($window) as $n) {
            if ($last > 0 && $n > $last + 1) {
                $out .= '<span class="pg pg-gap">…</span>';
            }
            $out .= $n === $page
                ? '<span class="pg pg-now" aria-current="page">' . self::esc((string) $n) . '</span>'
                : $link($n, (string) $n, 'pg');
            $last = $n;
        }

        if ($page < $pages) {
            $out .= $link($page + 1, 'Older →', 'pg pg-next');
        }

        return '<div class="wrap"><nav class="pagination" aria-label="Pages">' . $out . '</nav></div>';
    }

    // =====================================================================
    //  page chrome
    // =====================================================================

    /**
     * The masthead. Every word of identity in here comes out of $cfg — there
     * is no brand string in this file to find.
     *
     * The clock is rendered by the server so it is right with JavaScript off;
     * app.js finds it by id, reads data-tz, and makes it tick.
     */
    private static function masthead(array $cfg): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');
        $tag  = self::s($site['tagline'] ?? '');
        $tzName = self::s($site['timezone'] ?? '') !== '' ? self::s($site['timezone']) : 'UTC';
        $now  = (new \DateTimeImmutable('now'))->setTimezone(self::tz($cfg));

        // Left plate: the date, the city the edition is dated from, and the
        // edition stamp. The city is config, not a forecast — this site has no
        // weather desk and prints no conditions it did not report.
        $place = self::defaultPlace($cfg);

        $sideLines = '<strong>' . self::esc($now->format('l, F j, Y')) . '</strong>';
        if ($place !== '') {
            $sideLines .= '<br>' . self::esc($place);
        }
        $sideLines .= '<br><span class="m">' . self::esc('edition ' . $now->format('Y-m-d')) . '</span>';

        return '<header class="masthead wrap"><div class="masthead-grid">'
            . '<div class="masthead-side">' . $sideLines . '</div>'
            . '<div class="masthead-brand">'
            . '<p class="wordmark"><a' . self::attrs(['href' => self::url('/')]) . '>' . self::esc($name) . '</a></p>'
            . ($tag !== '' ? '<p class="tag">' . self::esc($tag) . '</p>' : '')
            . '</div>'
            . '<div class="masthead-plate" aria-label="Edition">'
            . '<p class="ed">Latest edition</p>'
            . '<p class="stars" aria-hidden="true">◆ ◆ ◆</p>'
            . '<p' . self::attrs([
                'class'       => 'clock',
                'id'          => 'clock',
                'data-tz'     => $tzName,
                'data-locale' => str_replace('_', '-', self::s($site['locale'] ?? '') !== '' ? self::s($site['locale']) : 'en_US'),
            ]) . '>'
            . self::esc($now->format('g:i:s A')) . '</p>'
            . '<p class="no">' . self::esc('Vol. I · No. ' . (int) $now->format('z') . ' · ' . $now->format('T')) . '</p>'
            . '</div></div></header><div class="oxford" aria-hidden="true"></div>';
    }

    /**
     * The city an edition is dated from — masthead furniture, and the same
     * word in the footer. One config value, so the two can never disagree, and
     * an edition that does not set one simply prints no dateline city.
     */
    private static function defaultPlace(array $cfg): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];

        return self::s($site['city'] ?? '');
    }

    /** One nav, built from one array, so a name can never differ between two places. */
    /**
     * The navigation, built from the desk registry.
     *
     * ONE array: the top nav and the footer's Sections column both read this,
     * so a desk cannot appear under two names or be linked from one place and
     * not the other. Every desk in TEB\Feeds gets a link — including the ones
     * that never take the front page, because they have real pages and a route
     * linked from nowhere is a defect.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function navSections(): array
    {
        $out = [['/', 'Front page']];
        foreach (Feeds::sections() as $slug => $desk) {
            $slug  = self::s($slug);
            $label = self::s($desk['label'] ?? '');
            if ($slug === '' || $label === '') {
                continue;
            }
            $out[] = ['/section/' . $slug, $label];
        }

        return count($out) > 1 ? $out : self::NAV;
    }

    private static function nav(string $route): string
    {
        $li = '';
        foreach (self::navSections() as $entry) {
            $li .= self::navItem($entry[0], $entry[1], $route, '');
        }
        $first = true;
        foreach (self::NAV_TAIL as $entry) {
            $li .= self::navItem($entry[0], $entry[1], $route, $first ? 'push' : '');
            $first = false;
        }
        $li .= '<li class="tt">'
            . '<button' . self::attrs([
                'type'         => 'button',
                'class'        => 'theme-toggle',
                'data-theme-toggle' => true,
                'aria-label'   => 'Colour theme: light. Switch to dark',
            ]) . '><span class="tt-icon" aria-hidden="true">☀</span><span class="tt-text">Light</span></button></li>';

        return '<nav class="nav" aria-label="Sections"><ul>' . $li . '</ul></nav>';
    }

    private static function navItem(string $path, string $label, string $route, string $liClass): string
    {
        $on = self::routeMatches($path, $route);

        return '<li' . self::attrs(['class' => $liClass !== '' ? $liClass : null]) . '>'
            . '<a' . self::attrs([
                'class'        => $on ? 'on' : null,
                'href'         => self::url($path),
                'aria-current' => $on ? 'page' : null,
            ]) . '>' . self::esc($label) . '</a></li>';
    }

    /**
     * An empty route means "no nav item is current" — which is what an error
     * page wants. Without that, '' would fall through to '/' and a 404 would
     * light up "Front page" as though the reader were on it.
     */
    private static function routeMatches(string $path, string $route): bool
    {
        if ($route === '') {
            return false;
        }
        if ($path === '/') {
            return $route === '/';
        }

        return $route === $path || strpos($route, $path . '/') === 0;
    }

    /**
     * The footer's About column: the nav tail plus every standing page.
     * Search is moved to the end so the five standing pages — the ones an
     * advertising reviewer and a publisher both come looking for — read as
     * one unbroken group.
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function footerAbout(): array
    {
        $out = [];
        foreach (self::NAV_TAIL as $entry) {
            if ($entry[0] !== '/search') {
                $out[] = $entry;
            }
        }
        foreach (self::NAV_LEGAL as $entry) {
            $out[] = $entry;
        }
        foreach (self::NAV_TAIL as $entry) {
            if ($entry[0] === '/search') {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The footer carries the standing line about what this site republishes.
     * It is not decoration: it is the only defensible position for a site that
     * shows a publisher's headline and summary (SPEC §0.7).
     */
    private static function footer(array $cfg, array $sources): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');
        $now  = (new \DateTimeImmutable('now'))->setTimezone(self::tz($cfg));
        $place = self::defaultPlace($cfg);

        $sectionLinks = '';
        foreach (self::navSections() as $entry) {
            if ($entry[0] === '/') {
                continue;
            }
            $sectionLinks .= '<li><a' . self::attrs(['href' => self::url($entry[0])]) . '>'
                . self::esc($entry[1]) . '</a></li>';
        }

        $aboutLinks = '';
        foreach (self::footerAbout() as $entry) {
            $aboutLinks .= '<li><a' . self::attrs(['href' => self::url($entry[0])]) . '>'
                . self::esc($entry[1]) . '</a></li>';
        }

        $sourceLinks = '';
        $shown = 0;
        foreach ($sources as $source) {
            if ($shown >= 12) {
                break;
            }
            $label = is_array($source) ? self::s($source['name'] ?? ($source['slug'] ?? '')) : self::s($source);
            $slug  = is_array($source) ? self::s($source['slug'] ?? '') : '';
            if ($label === '') {
                continue;
            }
            $sourceLinks .= '<li><a' . self::attrs([
                'href' => self::url($slug !== '' ? '/sources#' . self::slug($slug) : '/sources'),
            ]) . '>' . self::esc($label) . '</a></li>';
            $shown++;
        }
        if ($sourceLinks === '') {
            $sourceLinks = '<li><a' . self::attrs(['href' => self::url('/sources')])
                . '>Every source we read</a></li>';
        }

        $followLinks = '<li><a' . self::attrs(['href' => self::url('/feed.xml')]) . '>RSS feed</a></li>'
            . '<li><a' . self::attrs(['href' => self::url('/sitemap.xml')]) . '>Sitemap</a></li>'
            . '<li><a' . self::attrs(['href' => self::url('/sitemap-news.xml')]) . '>News sitemap</a></li>';

        return '<footer class="footer"><div class="oxford" aria-hidden="true"></div><div class="wrap">'
            . '<div class="footer-grid">'
            . '<div class="footer-brand">'
            . '<p class="fbrand">' . self::esc($name) . '</p>'
            . '<p class="standing">' . self::esc(self::STANDING) . '</p>'
            . '<p class="m">' . self::esc(($place !== '' ? $place . ' · ' : '') . 'Vol. I, No. ' . (int) $now->format('z')) . '</p>'
            . '</div>'
            . '<div><h5>Sections</h5><ul>' . $sectionLinks . '</ul></div>'
            . '<div><h5>Where this comes from</h5><ul class="two">' . $sourceLinks . '</ul></div>'
            . '<div><h5>About</h5><ul>' . $aboutLinks . '</ul></div>'
            . '<div><h5>Follow</h5><ul>' . $followLinks . '</ul></div>'
            . '</div>'
            . '<div class="footer-bar"><span>' . self::esc('© ' . $now->format('Y') . ' ' . $name) . '</span>'
            . '<span class="m">' . self::esc('Updated ' . self::clockLabel($now) . ' ' . $now->format('T')) . '</span>'
            . '</div></div></footer>';
    }

    // =====================================================================
    //  layout
    // =====================================================================

    /**
     * The whole document.
     *
     * $o keys: title, description, canonical (route path), body (the inner
     * HTML of <main>), jsonld (string|array), cfg, route, ticker (array or
     * pre-rendered string), sources, ogType, ogImage, noindex.
     *
     * Head order matters: the stylesheet is LINKED, not inlined, and app.js is
     * DEFERRED, so nothing in the head blocks the render except the four-line
     * theme snippet — which exists precisely so the page does not flash white
     * before a reader's saved dark theme is applied.
     */
    public static function layout(array $o): string
    {
        $cfg  = is_array($o['cfg'] ?? null) ? $o['cfg'] : [];
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = self::s($site['name'] ?? '');

        $title = self::s($o['title'] ?? '');
        if ($title === '') {
            $title = $name;
        } elseif ($name !== '' && stripos($title, $name) === false) {
            $title .= ' · ' . $name;
        }

        $description = self::s($o['description'] ?? '');
        if ($description === '') {
            $description = self::s($site['description'] ?? '');
        }

        $locale = self::s($site['locale'] ?? '');
        $locale = $locale !== '' ? $locale : 'en_US';
        $lang   = str_replace('_', '-', $locale);

        $canonicalPath = self::s($o['canonical'] ?? '/');
        $canonical     = Paths::absolute($canonicalPath === '' ? '/' : $canonicalPath);

        $route = array_key_exists('route', $o) ? self::s($o['route']) : Paths::currentRoute();

        $head = '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            // The design is light and only light. Saying "light dark" here
            // hands a dark-preferring OS the right to paint the canvas, the
            // scrollbars and every form control dark behind a white page —
            // which is the dark default the client refused. The explicit
            // toggle still works: the stylesheet sets color-scheme itself
            // under [data-theme], and CSS beats this meta.
            . '<meta name="color-scheme" content="light">'
            // Pre-paint: a saved theme is applied before the first pixel, so
            // choosing dark does not mean a white flash on every page load.
            . '<script>(function(){try{var t=localStorage.getItem("theme");'
            . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>'
            . '<title>' . self::esc($title) . '</title>';

        if ($description !== '') {
            $head .= '<meta' . self::attrs(['name' => 'description', 'content' => $description]) . '>';
        }
        $themeColor = self::s($site['theme_color'] ?? '');
        if ($themeColor !== '') {
            $head .= '<meta' . self::attrs(['name' => 'theme-color', 'content' => $themeColor]) . '>';
        }
        if (!empty($o['noindex'])) {
            $head .= '<meta name="robots" content="noindex,follow">';
        }

        $head .= '<link' . self::attrs(['rel' => 'canonical', 'href' => $canonical]) . '>';

        $ogImage = self::outbound(self::s($o['ogImage'] ?? ''));
        $ogType  = self::s($o['ogType'] ?? '');
        $head .= '<meta' . self::attrs(['property' => 'og:type', 'content' => $ogType !== '' ? $ogType : 'website']) . '>'
            . '<meta' . self::attrs(['property' => 'og:title', 'content' => $title]) . '>'
            . ($description !== '' ? '<meta' . self::attrs(['property' => 'og:description', 'content' => $description]) . '>' : '')
            . '<meta' . self::attrs(['property' => 'og:url', 'content' => $canonical]) . '>'
            . ($name !== '' ? '<meta' . self::attrs(['property' => 'og:site_name', 'content' => $name]) . '>' : '')
            . '<meta' . self::attrs(['property' => 'og:locale', 'content' => $locale]) . '>'
            . ($ogImage !== '' ? '<meta' . self::attrs(['property' => 'og:image', 'content' => $ogImage]) . '>' : '')
            . '<meta' . self::attrs(['name' => 'twitter:card', 'content' => $ogImage !== '' ? 'summary_large_image' : 'summary']) . '>'
            . '<meta' . self::attrs(['name' => 'twitter:title', 'content' => $title]) . '>'
            . ($description !== '' ? '<meta' . self::attrs(['name' => 'twitter:description', 'content' => $description]) . '>' : '')
            . ($ogImage !== '' ? '<meta' . self::attrs(['name' => 'twitter:image', 'content' => $ogImage]) . '>' : '');

        // The stylesheet pulls one Google Fonts sheet; warming both hosts saves
        // a full connection setup on the critical path. gstatic needs the
        // crossorigin flag because font files are fetched anonymously.
        $head .= '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link' . self::attrs([
                'rel'   => 'alternate',
                'type'  => 'application/rss+xml',
                'title' => $name !== '' ? $name : 'Feed',
                'href'  => self::url('/feed.xml'),
            ]) . '>'
            . '<link' . self::attrs(['rel' => 'stylesheet', 'href' => Paths::asset('css/site.css')]) . '>'
            . '<script' . self::attrs(['src' => Paths::asset('js/app.js'), 'defer' => true]) . '></script>';

        $jsonld = self::jsonLd($o['jsonld'] ?? null);
        if ($jsonld !== '') {
            $head .= '<script type="application/ld+json">' . $jsonld . '</script>';
        }

        $tickerHtml = '';
        if (isset($o['ticker'])) {
            $tickerHtml = is_array($o['ticker']) ? self::ticker($o['ticker'], $cfg) : (string) $o['ticker'];
        }

        $body = '<a class="skip" href="#top-stories">Skip to the reporting</a>'
            . $tickerHtml
            . self::masthead($cfg)
            . self::nav($route)
            . '<main' . self::attrs(['id' => 'top-stories', 'tabindex' => '-1']) . '>'
            . (string) ($o['body'] ?? '')
            . '</main>'
            . self::footer($cfg, is_array($o['sources'] ?? null) ? $o['sources'] : []);

        return '<!doctype html><html' . self::attrs(['lang' => $lang]) . '><head>' . $head . '</head>'
            . '<body>' . $body . '</body></html>';
    }

    /**
     * JSON-LD, from an array or a pre-built string.
     *
     * EVERY '<' becomes its \u003C escape, and that is not belt and braces —
     * neutralising only '</' is not enough, and the gap blanks the whole page.
     * HTML5 tokenises the contents of a <script> element as script data, and
     * the sequence '<!--<script' switches it into the script-data DOUBLE
     * escaped state, in which a later '</script>' is text rather than an end
     * tag. A headline carrying '<!--<script>' therefore swallows the rest of
     * the document and the browser paints an empty <body>. Verified against
     * Chrome and against a spec-compliant HTML5 parser: 112 body elements
     * became 1.
     *
     * The substitution is lossless. JSON has no '<' or '>' outside a string
     * literal, and inside one \u003C is exactly '<', so what a consumer parses
     * is unchanged — the block simply can no longer reach any script-data
     * state. JSON_HEX_TAG does the same job for the array form; the string
     * form needs it here, because a module that built its own JSON did not
     * necessarily set that flag.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE is the same call esc() makes with
     * ENT_SUBSTITUTE: without it one bad byte out of a publisher's feed makes
     * json_encode return false and the whole structured-data block silently
     * disappears.
     */
    private static function jsonLd($value): string
    {
        if (is_array($value) && $value) {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $value = $json === false ? '' : $json;
        }
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        return str_replace(['<', '>'], ['\u003C', '\u003E'], $value);
    }

    // =====================================================================
    //  pages
    // =====================================================================

    /**
     * The front page, from the model TEB\Compose::home() returns:
     *   ['ticker', 'hero' => ['lead','subs'], 'rail', 'blocks', 'regions', 'markets']
     * plus, optionally, 'quotes' and 'sources'.
     *
     * THE ORDER, AND WHY IT IS THIS ORDER
     * -----------------------------------
     * The client's rule is that the desks that move fastest sit at the top and
     * the slower ones sit lower down under their own headings — and that a
     * reader can SEE the difference rather than having to infer it from how
     * stale the stories look. So the page is:
     *
     *   hero            the lead story, the seconds beside it, the rail
     *   billboard       the one full-width ad slot
     *   the fast desks  every tier-1 block, straight after the hero
     *   inline ad       after the second block, where a reader pauses anyway
     *   THE BAND        "More from the desks" — a ruled heading, a sentence
     *                   saying how often these are checked, and a row of links
     *                   to every desk below it. This is the visible split.
     *   the slow desks  each under its own block heading, same component
     *   markets         last and quiet, because money never leads this page
     *
     * Compose decides which blocks are in which region and hands over a
     * 'regions' list. A model without one — a hand-built fixture, an older
     * caller — still renders: the blocks come out flat, in order, exactly as
     * they used to.
     */
    public static function home(array $model, array $cfg): string
    {
        $site    = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name    = self::s($site['name'] ?? '');
        $tag     = self::s($site['tagline'] ?? '');
        $blocks  = is_array($model['blocks'] ?? null) ? $model['blocks'] : [];
        $regions = is_array($model['regions'] ?? null) ? $model['regions'] : [];

        // The front page's own heading. The masthead already shows the wordmark,
        // but it shows it as furniture on every page, so the home page itself
        // was reaching a reader with no <h1> at all and an outline that began at
        // a card. This states what the page is, once, for a screen reader and a
        // search engine, without printing a second wordmark over the design.
        // Every word of it comes from config, like the masthead's.
        $h1 = $name !== '' ? $name : self::s($site['domain'] ?? '');
        if ($h1 !== '' && $tag !== '') {
            $h1 .= ' — ' . $tag;
        }
        $body  = $h1 !== '' ? '<h1 class="vh">' . self::esc($h1) . '</h1>' : '';
        $body .= self::hero($model, $cfg);
        $body .= self::adSlot('leaderboard', $cfg);

        $body .= self::blockRegions($blocks, $regions, $cfg);

        $body .= self::marketsStrip(
            is_array($model['markets'] ?? null) ? $model['markets'] : [],
            $cfg,
            is_array($model['quotes'] ?? null) ? $model['quotes'] : []
        );

        $title = $name;
        if ($name !== '' && $tag !== '') {
            $title = $name . ' — ' . $tag;
        }

        $jsonld = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $name,
            'url'      => Paths::absolute('/'),
        ];
        $searchTarget = Paths::absolute('/search') . (strpos(Paths::url('/search'), '?') !== false ? '&' : '?') . 'q={search_term_string}';
        $jsonld['potentialAction'] = [
            '@type'       => 'SearchAction',
            'target'      => $searchTarget,
            'query-input' => 'required name=search_term_string',
        ];

        $lead = is_array($model['hero']['lead'] ?? null) ? $model['hero']['lead'] : [];

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => self::s($site['description'] ?? ''),
            'canonical'   => '/',
            'route'       => '/',
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            // A picture we are not licensed to republish must not be handed to
            // Facebook and Twitter either — that is a republication too.
            'ogImage'     => ($lead && self::mayShowImage($lead)) ? self::s($lead['image_url'] ?? '') : '',
            'body'        => $body,
            'jsonld'      => $jsonld,
        ]);
    }

    /**
     * The section blocks, grouped into their regions, with the inline ad after
     * the second block that actually rendered.
     *
     * @param array<int,array<string,mixed>> $blocks
     * @param array<int,array<string,mixed>> $regions
     */
    private static function blockRegions(array $blocks, array $regions, array $cfg): string
    {
        $byId  = [];
        $order = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $id = self::s($block['id'] ?? '');
            if ($id === '') {
                $id = 'block-' . count($order);
            }
            $byId[$id] = $block;
            $order[]   = $id;
        }

        // No regions in the model: render the blocks flat, in order. This is the
        // path a hand-built fixture and any older caller take, and it must stay
        // working — the regions are a grouping OF the blocks, not a replacement
        // for them.
        if ($regions === []) {
            // No label, so regionHead() prints nothing and the blocks simply
            // follow the hero. Deliberately not TEB\Compose::REGION_LEAD: this
            // file must render a model it was handed without needing the class
            // that built it to be loaded.
            $regions = [['label' => '', 'note' => '', 'blocks' => $order]];
        }

        $out      = '';
        $rendered = 0;
        $adDone   = false;
        foreach ($regions as $region) {
            if (!is_array($region)) {
                continue;
            }
            $ids  = is_array($region['blocks'] ?? null) ? $region['blocks'] : [];
            $html = '';
            foreach ($ids as $id) {
                $id = self::s($id);
                if (!isset($byId[$id])) {
                    continue;
                }
                // A front-page desk is a named region of the page, so its label
                // is an h2 — without it the home page's outline started at the
                // <h3> of the first card and had no top level at all.
                $one = self::block($byId[$id], $cfg, ['heading' => 'h2']);
                if ($one === '') {
                    continue;
                }
                $html .= $one;
                $rendered++;
                if ($rendered === 2 && !$adDone) {
                    $html  .= self::adSlot('inline', $cfg);
                    $adDone = true;
                }
            }
            if ($html === '') {
                continue;             // a region with nothing in it prints no heading
            }
            $out .= self::regionHead($region, $byId) . $html;
        }

        return $out;
    }

    /**
     * The band that separates the fast desks from the slow ones.
     *
     * It is a heading, a sentence and a row of desk links — not a rule and a
     * shrug. The client's words were that the lower blocks must read as "more
     * from the desks", so the band says exactly that, says how often those
     * desks are checked, and gives the reader a way straight into each of them.
     * The lead region carries no band at all: the hero is its heading.
     *
     * @param array<string,array<string,mixed>> $byId
     */
    private static function regionHead(array $region, array $byId): string
    {
        $label = self::s($region['label'] ?? '');
        if ($label === '') {
            return '';
        }
        $note = self::s($region['note'] ?? '');

        $links = '';
        foreach (is_array($region['blocks'] ?? null) ? $region['blocks'] : [] as $id) {
            $block = $byId[self::s($id)] ?? null;
            if (!is_array($block)) {
                continue;
            }
            $href = self::s($block['href'] ?? '');
            $name = self::s($block['label'] ?? '');
            if ($href === '' || $name === '') {
                continue;
            }
            $links .= '<li><a' . self::attrs(['href' => self::url($href)]) . '>' . self::esc($name) . '</a></li>';
        }

        return '<section class="region-head wrap"' . self::attrs(['aria-label' => $label]) . '>'
            . '<h2 class="region-title">' . self::esc($label) . '</h2>'
            . ($note !== '' ? '<p class="region-note">' . self::esc($note) . '</p>' : '')
            . ($links !== '' ? '<ul class="region-desks">' . $links . '</ul>' : '')
            . '</section>';
    }

    /**
     * A section index, a search result page, or any other list of stories.
     *
     * $model: label, note, slug, href, items, grid, page, pages, template,
     *         search (bool), q, total, ticker, sources, description,
     *         canonical, noindex.
     *
     * The FIRST card on the page is the one eager image — a section index has
     * its own hero, and it is that card.
     */
    public static function section(array $model, array $cfg): string
    {
        $label = self::s($model['label'] ?? '');
        $items = is_array($model['items'] ?? null) ? $model['items'] : [];
        $isSearch = !empty($model['search']);
        $q = self::s($model['q'] ?? '');

        $body = '';

        if ($isSearch) {
            $note = $q === ''
                ? 'Type a word or a name to search every story we hold.'
                : ($items
                    ? 'Showing ' . (int) ($model['total'] ?? count($items)) . ' result'
                      . (((int) ($model['total'] ?? count($items))) === 1 ? '' : 's') . ' for “' . $q . '”.'
                    : 'Nothing matched “' . $q . '”.');
            $body .= '<section class="block wrap" aria-label="Search">'
                . '<div class="block-head"><h1 class="block-h1"><span class="block-label">Search</span></h1></div>'
                . self::searchbar($q, $cfg)
                . '<p class="result-note">' . self::esc($note) . '</p>'
                . '</section>';
        }

        $blockModel = [
            'id'    => self::s($model['slug'] ?? ''),
            'label' => $label,
            'note'  => self::s($model['note'] ?? ''),
            'grid'  => self::s($model['grid'] ?? 'block-grid'),
            'href'  => '',
            'items' => $items,
        ];
        if ($isSearch) {
            $blockModel['label'] = $label !== '' ? $label : 'Results';
        }

        // lazy=false on the first card: this page's single hero image.
        $listHtml = self::block($blockModel, $cfg, [
            'lazy'    => false,
            'no_more' => true,
            // On a section index this block is the page, so its label is the h1.
            // On /search the h1 is already printed above, by the search block.
            'heading' => $isSearch ? '' : 'h1',
        ]);
        if ($listHtml === '' && !$isSearch) {
            $listHtml = '<section class="block wrap"><div class="block-head">'
                . '<h1 class="block-h1"><span class="block-label">'
                . self::esc($label !== '' ? $label : 'Section') . '</span></h1></div>'
                . '<p class="result-note">Nothing here yet. The next fetch will fill it.</p></section>';
        }
        $body .= $listHtml;

        $body .= self::pagination([
            'page'     => (int) ($model['page'] ?? 1),
            'pages'    => (int) ($model['pages'] ?? 1),
            'template' => self::s($model['template'] ?? ''),
        ], $cfg);

        $canonical = self::s($model['canonical'] ?? '');
        if ($canonical === '') {
            $canonical = self::s($model['href'] ?? '');
        }
        if ($canonical === '') {
            $canonical = '/';
        }

        $title = $isSearch
            ? ($q !== '' ? 'Search: ' . $q : 'Search')
            : ($label !== '' ? $label : 'Section');

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => self::s($model['description'] ?? ''),
            'canonical'   => $canonical,
            'route'       => self::s($model['route'] ?? $canonical),
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            'noindex'     => $isSearch ? true : !empty($model['noindex']),
            'body'        => $body,
        ]);
    }

    /**
     * ONE STORY, SET AS A STORY.
     *
     * These feeds carry five to eleven thousand characters of body text — the
     * whole article, not the two-line teaser a wire supplies — and the licences
     * that come with them are the reason this page exists in the shape it does.
     *
     * WHAT IS ON THE PAGE, IN ORDER
     *   desk line       which desk, and a link to the rest of it
     *   kicker + flag   the section, and "Extract" when that is what this is
     *   headline        the h1, which does not link to itself
     *   dek             the feed's own summary, set as a standfirst — but only
     *                   when it IS one (see standfirst()) and when the body is
     *                   genuinely longer than it, so a short feed does not
     *                   print the same sentence twice
     *   byline          the author, exactly as the publisher filed it
     *   dateline        publication, date, reading time
     *   photograph      the page's ONE eager image, or the house placeholder
     *   the body        real paragraphs at a reading measure
     *   the link out    to the original, always
     *   attribution     author, publication, licence, original — the block the
     *                   licence actually requires, repeated below the text
     *   related         the same desk
     *
     * THE LICENCE RULE, WHICH IS NOT NEGOTIABLE
     * A source flagged 'extract' in TEB\Feeds is one whose licence does not
     * reach an advertising-supported page — ProPublica, The 19th, The Markup,
     * IEEE Spectrum. Those get a headline, roughly four hundred characters cut
     * at the end of a sentence, a plain sentence saying why, and a prominent
     * link. Everything else is published as it arrived, because the licences
     * that allow republication (CC BY, CC BY-ND, the publishers' own terms) all
     * forbid derivative works — shortening a piece is exactly what ND refuses.
     *
     * The one place a full-text piece can still stop short is the ingest cap in
     * app/Xml.php, which bounds a single pathological item. When that happens
     * the page says so in a sentence and points at the original, rather than
     * letting a cut article read as the whole one.
     *
     * $model: article (required), related, ticker, sources, jsonld.
     */
    public static function article(array $model, array $cfg): string
    {
        $a     = is_array($model['article'] ?? null) ? $model['article'] : $model;
        $title = self::s($a['title'] ?? '');
        if ($title === '') {
            return self::error(404, 'That story is no longer here.', $cfg);
        }

        $lic     = self::licenceOf($a);
        $srcName = $lic['name'];
        $desk    = self::deskOf($a);
        $out     = self::outbound(self::s($a['url'] ?? ''));

        // ---- the story text ------------------------------------------------
        // TWO different questions, and conflating them loses stories.
        //   $feedSummary  what the feed sent. When there is no body it is the
        //                 whole of what we have, so it is the article.
        //   $summary      the same text ONLY when it is really a standfirst —
        //                 see standfirst(). This is what may print as a dek and
        //                 what may become the meta description.
        $bodyText    = self::s($a['body'] ?? '');
        $feedSummary = self::s($a['summary'] ?? '');
        $summary     = self::standfirst($feedSummary, $title);
        if ($bodyText === '' || mb_strlen($bodyText) < mb_strlen($feedSummary)) {
            $bodyText = $feedSummary;
        }

        $isExtract = $lic['extract'];

        // A body that arrived at the ingest cap was cut on a boundary and marked
        // with an ellipsis (app/Xml.php::capBody). Saying so is not optional: most
        // of this roster is NoDerivatives, and presenting a cut piece as the whole
        // work is the one thing those licences forbid.
        $isCut = !$isExtract
            && mb_substr($bodyText, -1) === Xml::TRUNCATED
            && mb_strlen($bodyText) > (int) (Xml::BODY_MAX * 0.7);

        $shown     = $isExtract ? self::extractOf($bodyText, self::EXTRACT_CHARS) : $bodyText;
        $paras     = self::paragraphs($shown);
        if ($paras === '') {
            $paras = '<p>' . self::esc($feedSummary !== '' ? $feedSummary : $title) . '</p>';
            $shown = $feedSummary !== '' ? $feedSummary : $title;
        }
        $minutes = self::readingTime($shown);

        // ---- head ------------------------------------------------------------
        $head = '<div class="block-head"><p>'
            . ($desk['label'] !== '' ? '<span class="block-label">' . self::esc($desk['label']) . '</span>' : '')
            . ($desk['note'] !== '' ? ' <span class="block-note">— ' . self::esc($desk['note']) . '</span>' : '')
            . '</p>';
        if ($desk['slug'] !== '') {
            $head .= '<a' . self::attrs(['class' => 'block-more', 'href' => self::url($desk['href'])])
                . '>' . self::esc('More ' . ($desk['label'] !== '' ? $desk['label'] : 'stories') . ' →') . '</a>';
        }
        $head .= '</div>';

        $kline = '<div class="kline">'
            . (!empty($a['fresh']) ? '<span class="chip">New</span>' : '')
            . ($desk['label'] !== '' ? '<span class="kicker">' . self::esc($desk['label']) . '</span>' : '')
            . ($isExtract ? '<span class="story-flag">Extract</span>' : '')
            . '</div>';

        // The dek is the feed's own summary. It is only a dek when there is a
        // longer body behind it — otherwise the same sentence would appear as
        // the standfirst AND as the only paragraph of the piece, which is what
        // the wire-fed build used to do.
        $dek = '';
        if ($summary !== '' && !self::sameText($summary, $shown) && !self::opensWith($shown, $summary)) {
            $dek = '<p class="story-dek">' . self::esc($summary) . '</p>';
        }

        $author  = self::s($a['author'] ?? '');
        $byline  = $author !== ''
            ? '<p class="story-byline">' . self::esc('By ' . $author) . '</p>'
            : '';

        $when = self::articleDate($a, $cfg);
        $meta = [];
        if ($srcName !== '') {
            $meta[] = '<span class="story-src">' . self::esc($srcName) . '</span>';
        }
        if ($when !== '') {
            $meta[] = $when;
        }
        $meta[] = '<span class="story-read">' . self::esc($minutes . ' min read') . '</span>';
        $dateline = '<p class="story-dateline">' . implode('<span class="story-sep" aria-hidden="true">·</span>', $meta) . '</p>';

        $header = '<header class="story-head">' . $kline
            . '<h1 class="story-hed">' . self::esc($title) . '</h1>'
            . $dek . $byline . $dateline . '</header>';

        // ---- the one eager image on this page --------------------------------
        $figure = self::storyFigure($a, $cfg, $srcName);

        // ---- body ------------------------------------------------------------
        $article = '<div class="article-body">' . $paras . '</div>';

        if ($isExtract) {
            $article .= '<p class="story-extract-note">'
                . self::esc(
                    ($srcName !== '' ? $srcName : 'This newsroom') . ' publishes under '
                    . ($lic['license'] !== '' ? $lic['license'] : 'a licence')
                    . ', which does not permit republication on a page that carries advertising. '
                    . 'What you have just read is a short extract; the article itself is on their site, in full and free.'
                )
                . '</p>';
        }

        if ($isCut) {
            $article .= '<p class="story-extract-note">'
                . self::esc(
                    'This piece runs longer than the length this site stores for one article, '
                    . 'so it stops here rather than being edited down. The rest of it is on '
                    . ($srcName !== '' ? $srcName . "\u{2019}s" : 'the publisher\u{2019}s')
                    . ' own site, in full and free.'
                )
                . '</p>';
        }

        if ($out !== '') {
            $article .= '<p class="article-continue"><a' . self::attrs([
                'class'  => 'card-out',
                'href'   => $out,
                'rel'    => 'noopener nofollow',
                'target' => '_blank',
            ]) . '>' . self::esc(
                ($isExtract ? 'Read the full story at ' : ($isCut ? 'Read the rest at ' : 'Read this at '))
                . ($srcName !== '' ? $srcName : 'the publisher') . ' →'
            ) . '</a></p>';
        }

        $body = '<article class="block wrap story" aria-label="Story">'
            . $head . $header . $figure . $article
            . self::attribution($a, $lic, $author)
            . '</article>';

        $related = is_array($model['related'] ?? null) ? $model['related'] : [];
        if ($related) {
            $body .= self::block([
                'id'    => 'related',
                'label' => 'Related',
                'note'  => $desk['label'] !== '' ? 'more from ' . $desk['label'] : 'from the same desk',
                'grid'  => 'block-grid block-grid--3',
                'href'  => $desk['slug'] !== '' ? $desk['href'] : '',
                'items' => $related,
            ], $cfg);
        }

        $body .= self::adSlot('inline', $cfg);

        return self::layout([
            'cfg'         => $cfg,
            'title'       => $title,
            'description' => $summary !== '' ? $summary : self::extractOf($bodyText, 180),
            'canonical'   => self::articleHref($a),
            'route'       => $desk['slug'] !== '' ? $desk['href'] : '/',
            'ticker'      => is_array($model['ticker'] ?? null) ? $model['ticker'] : [],
            'sources'     => is_array($model['sources'] ?? null) ? $model['sources'] : [],
            'ogType'      => 'article',
            'ogImage'     => self::mayShowImage($a) ? self::s($a['image_url'] ?? '') : '',
            'body'        => $body,
            'jsonld'      => $model['jsonld'] ?? null,
        ]);
    }

    // =====================================================================
    //  the article page's own pieces
    // =====================================================================

    /**
     * The desk a story belongs to: its slug, its label, the one-line note the
     * registry gives it, and where "more of this" goes. Read from TEB\Feeds so
     * the article page and the navigation cannot disagree about what a desk is
     * called.
     *
     * @return array{slug:string,label:string,note:string,href:string}
     */
    private static function deskOf(array $a): array
    {
        $slug = self::slug(self::s($a['section'] ?? ''));
        $meta = $slug !== '' ? Feeds::section($slug) : null;

        $label = self::s($a['section_label'] ?? '');
        if ($label === '' && $meta !== null) {
            $label = self::s($meta['label'] ?? '');
        }
        if ($label === '' && $slug !== '') {
            $label = ucwords(str_replace('-', ' ', $slug));
        }

        return [
            'slug'  => $slug,
            'label' => $label,
            'note'  => $meta !== null ? self::s($meta['note'] ?? '') : '',
            'href'  => $slug === '' ? '/' : '/section/' . $slug,
        ];
    }

    /**
     * The licence a story arrives under.
     *
     * Read from TEB\Feeds by the source slug the ingester stamped on the row,
     * with anything the row itself carries winning — so a per-article override
     * (a publisher who asked for different wording) is one column away and
     * needs no code change.
     *
     * A source the registry does not know answers extract-only: the safe
     * default for something we cannot identify is to publish less of it, not
     * more.
     *
     * @return array{name:string,license:string,license_url:string,attribution:string,extract:bool,images:bool,homepage:string}
     */
    private static function licenceOf(array $a): array
    {
        $slug = self::s($a['source_slug'] ?? '');
        if ($slug === '') {
            $slug = self::s($a['source'] ?? '');
        }
        // An unregistered source is one whose licence we do not know, and
        // Feeds::isExtractOnly() answers the same way for the same reason: publish
        // LESS of something you cannot identify, not more. The picture rule is
        // deliberately NOT symmetrical — mayShowImage() lets an unknown row keep
        // its image, because the rows the site generates for itself are unknown to
        // the registry by definition and they are not a licence risk.
        $known = $slug !== '' && Feeds::bySlug($slug) !== null;
        $lic   = $known
            ? Feeds::licence($slug)
            : ['license' => '', 'license_url' => '', 'attribution' => '', 'extract' => true,
               'images' => true, 'name' => '', 'homepage' => '', 'notes' => ''];

        $name = self::s($a['source_name'] ?? '');
        if ($name === '') {
            $name = self::s($lic['name'] ?? '');
        }
        if ($name === '') {
            $name = self::s($a['source'] ?? '');
        }

        $home = self::s($a['source_homepage'] ?? '');
        if ($home === '') {
            $home = self::s($lic['homepage'] ?? '');
        }

        return [
            'name'        => $name,
            'license'     => self::s($a['license'] ?? ($lic['license'] ?? '')),
            'license_url' => self::s($a['license_url'] ?? ($lic['license_url'] ?? '')),
            'attribution' => self::s($a['attribution'] ?? ($lic['attribution'] ?? '')),
            'extract'     => array_key_exists('extract', $a) ? (bool) $a['extract'] : (bool) ($lic['extract'] ?? false),
            'images'      => self::mayShowImage($a),
            'homepage'    => $home,
        ];
    }

    /**
     * The story's photograph, or the house placeholder. Either way it is the
     * ONE eager image on this page — there is no second one, here or anywhere.
     *
     * The credit under it is not decoration: a picture we are licensed to run
     * still has to say whose it is.
     */
    private static function storyFigure(array $a, array $cfg, string $srcName): string
    {
        $img = self::imgTag($a, 'lead', true);
        if ($img === '') {
            $ph = $a;
            $ph['image_url']    = Placeholder::url($a);
            $ph['image_width']  = 1200;
            $ph['image_height'] = 630;
            $ph['image_alt']    = self::s($a['title'] ?? '');
            $img = self::imgTag($ph, 'lead', true);
            if ($img === '') {
                return '';
            }

            return '<figure class="story-media story-media--house">' . $img . '</figure>';
        }

        return '<figure class="story-media">' . $img
            . ($srcName !== ''
                ? '<figcaption class="story-figcaption">' . self::esc('Photograph · ' . $srcName) . '</figcaption>'
                : '')
            . '</figure>';
    }

    /**
     * THE ATTRIBUTION BLOCK — a licence condition, not a courtesy.
     *
     * CC BY, CC BY-ND and CC BY-NC-ND all require the author, the source and a
     * link back; the publishers running their own terms ask for the same. The
     * editorial-standards page promises this appears above the text and again
     * below it, so the byline and dateline carry it above and this carries it
     * below, in full, with the licence named and linked to its own deed.
     *
     * Where a publisher specifies its own wording, that wording is what prints:
     * 'attribution' comes straight out of the registry entry.
     */
    private static function attribution(array $a, array $lic, string $author): string
    {
        $rows = '';
        $add  = static function (string $term, string $value) use (&$rows): void {
            if ($value !== '') {
                $rows .= '<div><dt>' . Render::esc($term) . '</dt><dd>' . $value . '</dd></div>';
            }
        };

        $add('Author', $author !== '' ? self::esc($author) : '');

        $pub = self::esc($lic['name']);
        if ($lic['name'] !== '' && $lic['homepage'] !== '') {
            $out = self::outbound($lic['homepage']);
            if ($out !== '') {
                $pub = '<a' . self::attrs(['href' => $out, 'rel' => 'noopener nofollow', 'target' => '_blank'])
                    . '>' . self::esc($lic['name']) . '</a>';
            }
        }
        $add('Publication', $lic['name'] !== '' ? $pub : '');

        $licence = self::esc($lic['license']);
        if ($lic['license'] !== '' && $lic['license_url'] !== '') {
            $out = self::outbound($lic['license_url']);
            if ($out !== '') {
                $licence = '<a' . self::attrs(['href' => $out, 'rel' => 'noopener nofollow license', 'target' => '_blank'])
                    . '>' . self::esc($lic['license']) . '</a>';
            }
        }
        $add('Licence', $lic['license'] !== '' ? $licence : '');

        $url = self::outbound(self::s($a['url'] ?? ''));
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            $host = is_string($host) ? preg_replace('/^www\./', '', $host) : '';
            $add('Original', '<a' . self::attrs([
                'href' => $url, 'rel' => 'noopener nofollow external canonical', 'target' => '_blank',
            ]) . '>' . self::esc($host !== '' ? $host : 'the publisher') . '</a>');
        }

        if ($rows === '' && $lic['attribution'] === '') {
            return '';
        }

        return '<aside class="credit-block" aria-label="Attribution and licence">'
            . ($lic['attribution'] !== ''
                ? '<p class="credit-line">' . self::esc($lic['attribution']) . '</p>'
                : '')
            . ($rows !== '' ? '<dl class="credit-grid">' . $rows . '</dl>' : '')
            . '</aside>';
    }

    /**
     * The dateline's date. A full date, not a relative one: a republished
     * article can be a week old and "3d ago" reads as an evasion. The <time>
     * element still carries the machine-readable stamp.
     */
    private static function articleDate(array $a, array $cfg): string
    {
        $ms = isset($a['published_at']) ? (int) $a['published_at'] : 0;
        if ($ms <= 0) {
            return '';
        }
        $dt = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(self::tz($cfg));

        return '<time' . self::attrs(['class' => 'story-time', 'datetime' => $dt->format('c')]) . '>'
            . self::esc($dt->format('j F Y') . ', ' . self::clockLabel($dt)) . '</time>';
    }

    /**
     * Plain text to paragraphs.
     *
     * The ingester keeps the publisher's paragraph boundaries as blank lines
     * (TEB\Xml marks them before the tags are stripped), so a blank line is a
     * paragraph break and a single newline is a line inside one. Nothing is
     * reordered, shortened or rewritten: every one of these licences is ND or
     * asks for the piece whole, and a "tidied" article is a derivative work.
     */
    public static function paragraphs(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $out = '';
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $chunk) {
            $chunk = trim((string) $chunk);
            if ($chunk === '') {
                continue;
            }
            // A single newline inside a paragraph is a soft break in the
            // original — a verse line, an address, a list item the publisher
            // did not tag. Keep it visible rather than gluing the words
            // together, which is what a plain implode would do.
            $lines = array_filter(array_map('trim', preg_split('/\n/', $chunk) ?: []), static fn(string $l): bool => $l !== '');
            $out  .= '<p>' . implode('<br>', array_map([self::class, 'esc'], $lines)) . '</p>';
        }

        return $out;
    }

    /**
     * A short extract, cut at the end of a sentence.
     *
     * Cutting mid-word is what makes an extract look like a scrape. So the text
     * is taken to the limit and then walked BACK to the last sentence ending;
     * only when there is no sentence ending in reach does it fall back to a
     * word boundary and an ellipsis. Multibyte-safe throughout, because these
     * feeds carry curly quotes, em dashes and accented names.
     */
    public static function extractOf(string $text, int $chars): string
    {
        $text  = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $chars = max(40, $chars);
        if ($text === '' || mb_strlen($text) <= $chars) {
            return $text;
        }

        // A little past the limit, so a sentence that ends just after it can
        // still be the cut rather than being thrown away.
        $window = mb_substr($text, 0, $chars + 60);
        $best   = 0;
        foreach (['. ', '? ', '! ', '." ', '.” ', '.’ '] as $needle) {
            $at = mb_strrpos($window, $needle);
            if ($at !== false && $at + 1 > $best) {
                $best = $at + mb_strlen(rtrim($needle));
            }
        }
        if ($best >= (int) ($chars * 0.5)) {
            return rtrim(mb_substr($text, 0, $best));
        }

        $cut = mb_substr($text, 0, $chars);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > 0) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut, " ,;:—-") . '…';
    }

    /**
     * Reading time in whole minutes, from the words actually on the page.
     *
     * Never below one: "0 min read" is not a thing, and a very short extract
     * still takes a moment.
     */
    public static function readingTime(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        $n     = is_array($words) ? count($words) : 0;

        return max(1, (int) ceil($n / self::READING_WPM));
    }

    /**
     * The feed's summary, or nothing at all when what arrived is not a summary.
     *
     * Feeds ship three things in the same field and only one of them is a
     * standfirst. This throws away the other two before a single card or dek is
     * built, so the judgement is made once and both pages agree.
     *
     *  1. WORDPRESS WRAPPER TEXT. A WordPress site with no hand-written excerpt
     *     emits "The post <headline> appeared first on <Publication>." — every
     *     ProPublica item on this roster carries exactly that and nothing else.
     *     Printed as a dek it says nothing; printed under forty cards it is the
     *     same sentence forty times, with the headline already above it.
     *  2. THE HEADLINE AGAIN. Some feeds copy <title> into <description>.
     *
     * Anything else is the publisher's own sentence and is printed untouched:
     * this trims nothing and rewrites nothing, it only decides whether there is
     * a standfirst to print.
     */
    public static function standfirst(string $summary, string $title = ''): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', $summary) ?? $summary);
        if ($s === '') {
            return '';
        }
        // Anchored at both ends on purpose: this must catch the generated
        // wrapper and never a real sentence that happens to use the words.
        if (preg_match('/^the post\b.*\bappeared first on\b.*$/iu', $s) === 1) {
            return '';
        }
        if (preg_match('/^continue reading\b.*\bat\b.*$/iu', $s) === 1) {
            return '';
        }
        if ($title !== '' && self::sameText($s, $title)) {
            return '';
        }

        return $s;
    }

    /** Two strings the same once whitespace and case stop mattering. */
    private static function sameText(string $a, string $b): bool
    {
        $norm = static fn(string $x): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x) ?? $x));

        return $norm($a) !== '' && $norm($a) === $norm($b);
    }

    /**
     * Does the body simply open with the summary? Many feeds put the standfirst
     * in <description> AND as the first paragraph of the content, and printing
     * it twice is the tell of a page nobody read before shipping.
     */
    private static function opensWith(string $body, string $summary): bool
    {
        $norm = static fn(string $x): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x) ?? $x));
        $b    = $norm($body);
        $sm   = $norm($summary);
        if ($sm === '' || mb_strlen($sm) < 24) {
            return false;
        }

        return mb_strpos($b, $sm) === 0;
    }

    /** 404, 500 and everything else — a real page, with the nav and a way out. */
    public static function error(int $status, string $msg, array $cfg): string
    {
        $status = ($status >= 400 && $status <= 599) ? $status : 500;
        $msg    = self::s($msg);
        if ($msg === '') {
            $msg = $status === 404
                ? 'That page is not here.'
                : 'Something went wrong at our end.';
        }

        $body = '<section class="block wrap" aria-label="Error">'
            . '<div class="block-head"><h1 class="block-h1"><span class="block-label">' . self::esc((string) $status) . '</span>'
            . ' <span class="block-note">— ' . self::esc($status === 404 ? 'not found' : 'error') . '</span></h1>'
            . '<a' . self::attrs(['class' => 'block-more', 'href' => self::url('/')]) . '>Front page →</a></div>'
            . '<p class="result-note">' . self::esc($msg) . '</p>'
            . self::searchbar('', $cfg)
            . '</section>';

        return self::layout([
            'cfg'       => $cfg,
            'title'     => $status . ' · ' . ($status === 404 ? 'Not found' : 'Error'),
            'canonical' => '/',
            'route'     => '',
            'noindex'   => true,
            'body'      => $body,
        ]);
    }
}
