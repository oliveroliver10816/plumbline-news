<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * /api/top.json — the payload the front page polls so its top stories rotate.
 *
 * The client asked for the stories at the top of the home page to keep changing
 * "after a while, lets say 1-2 minutes per change", from an endpoint the page
 * calls rather than a page reload. This is that endpoint. app.js polls it on a
 * jittered ~90s timer and swaps the hero cards in place.
 *
 * Three properties matter more than anything else here:
 *
 *  1. IT MUST NEVER 500. The front page is already rendered and correct by the
 *     time this is called; a failure here is a cosmetic non-event, not an
 *     outage. Every path — a dead database, a broken cache directory, a feed
 *     that smuggled invalid UTF-8 into a headline — lands on 200 with an empty
 *     list. The client is written to keep whatever is on screen when the list
 *     is empty, so an empty 200 is a complete, safe answer.
 *
 *  2. IT MUST BE CHEAP. Polled by every open tab, so the work is done at most
 *     once a minute per install and shared by everyone: the composed payload is
 *     written to a small JSON file in the data directory and re-served for TTL
 *     seconds, and the response carries Cache-Control: public, max-age=60 so
 *     browsers and any CDN in front of us hold it too.
 *
 *  3. IT MUST OFFER MORE STORIES THAN THE HERO SHOWS. Rotating five cards
 *     through five stories is not rotation. The pool is the hero itself plus
 *     everything the front page composed but did not place, so the reader sees
 *     stories that are genuinely not on the page yet.
 *
 * Composition is delegated to Compose::home() rather than reimplemented. That is
 * deliberate: Compose is the single source of truth for section labels, freshness,
 * scoring and the finance ban, and a second ranking implementation here would
 * drift away from the page it is supposed to be rotating. The one thing this
 * class does differently is raise compose.ticker_count on a COPY of the config —
 * Compose's ticker is, by construction, the recency-ordered remainder that the
 * front page has not used anywhere, which is exactly the rotation pool we want.
 *
 * Carries no brand, no domain and no feed URL: the name lives in config.php only.
 */
final class Rotate
{
    /** The one route this class answers. Router::dispatch() matches on it. */
    public const ROUTE = '/api/top.json';

    /** Seconds a composed payload is re-served before it is rebuilt. Also the max-age. */
    public const TTL = 60;

    /** How many stories the client may rotate through. Hero shows ~5. */
    public const POOL = 24;

    /**
     * The window and depth of the read are NOT set here — Db::homeCandidates()
     * owns them, because the front page uses the same call and the two must not
     * be able to drift apart again.
     */

    /** A thin database (a fresh install mid-ingest) falls back to this, with no window. */
    private const FALLBACK_LIMIT = 120;

    /**
     * Most stories one publisher may contribute to the pool. Higher than the
     * ticker's default of 2 because the pool is four times longer; without it a
     * quiet news day returns eight items instead of twenty-four.
     */
    private const SOURCE_CAP = 4;

    /** Nominal lead-photo box, used when a publisher shipped no dimensions. */
    private const BOX = [1200, 600];

    /** The masthead placeholder is drawn at this size. */
    private const PLACEHOLDER_BOX = [1200, 630];

    /**
     * Sections that never rotate into the hero.
     *
     * Money is the one the client named: the markets strip owns it, and a
     * finance story must not appear at the top of the page ninety seconds
     * after the reader arrived. The list is matched against a story's section
     * slug, so a desk added later is opted in by naming it here.
     */
    private const NEVER_ROTATE = ['business', 'markets', 'finance', 'money'];

    // ------------------------------------------------------------------ route

    /**
     * The HTTP response, in the shape Router::dispatch() returns.
     *
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function response(PDO $pdo, array $cfg): array
    {
        $body = '';
        try {
            $body = self::body($pdo, $cfg);
        } catch (Throwable $e) {
            // Swallowed on purpose — see the class note. A rotation that stops
            // is invisible; a 500 on a route the page polls every 90 seconds is
            // a stream of errors in the host's log and, on some hosts, a rate
            // limit. Log it once and answer honestly with nothing.
            error_log('[teb] rotate: ' . $e->getMessage());
        }

        if ($body === '') {
            $body = self::encode(self::envelope([], 'unavailable'));
        }

        return [
            'status'  => 200,
            'headers' => [
                'Content-Type'           => 'application/json; charset=utf-8',
                'Cache-Control'          => 'public, max-age=' . self::TTL,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body'    => $body,
        ];
    }

    // ---------------------------------------------------------------- payload

    /**
     * The JSON body: cache first, compose only on a miss.
     *
     * Returns '' if it could not produce anything at all, which response()
     * turns into the empty envelope.
     */
    public static function body(PDO $pdo, array $cfg): string
    {
        $file = self::cacheFile($cfg);

        $hit = self::readCache($file, self::TTL);
        if ($hit !== null) {
            return $hit;
        }

        $json = self::encode(self::envelope(self::stories($pdo, $cfg), 'live'));
        if ($json !== '') {
            self::writeCache($file, $json);
        }

        return $json;
    }

    /**
     * The decoded payload. Used by the tests and by anything that wants the
     * data rather than the wire format.
     *
     * @return array<string,mixed>
     */
    public static function payload(PDO $pdo, array $cfg): array
    {
        $decoded = json_decode(self::body($pdo, $cfg), true);

        return is_array($decoded) ? $decoded : self::envelope([], 'unavailable');
    }

    /**
     * The rotation pool, best story first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function stories(PDO $pdo, array $cfg, int $want = self::POOL): array
    {
        $want = max(1, min(60, $want));

        $rows = self::read($pdo);
        if ($rows === []) {
            return [];
        }

        // A COPY of the config: the front page's own ticker settings must not
        // change because the rotation endpoint wanted a longer list.
        $composeCfg = $cfg;
        $compose    = is_array($cfg['compose'] ?? null) ? $cfg['compose'] : [];
        $compose['ticker_count']      = $want + count(self::NEVER_ROTATE) + 8; // headroom for the filter below
        $compose['ticker_source_cap'] = self::SOURCE_CAP;
        $composeCfg['compose']        = $compose;

        $model = Compose::home($rows, $composeCfg, Db::nowMs());

        $hero   = is_array($model['hero'] ?? null) ? $model['hero'] : [];
        $ticker = is_array($model['ticker'] ?? null) ? $model['ticker'] : [];

        // Hero first, so the client can find where the page currently sits in
        // the pool and step past it rather than "rotating" to what is already
        // on screen. Then the unplaced remainder: stories the front page
        // composed but had no room for, which is the point of the exercise.
        $ordered = [];
        if (is_array($hero['lead'] ?? null)) {
            $ordered[] = $hero['lead'];
        }
        foreach (is_array($hero['subs'] ?? null) ? $hero['subs'] : [] as $sub) {
            if (is_array($sub)) {
                $ordered[] = $sub;
            }
        }
        foreach ($ticker as $row) {
            if (is_array($row)) {
                $ordered[] = $row;
            }
        }

        $out  = [];
        $seen = [];
        foreach ($ordered as $row) {
            if (count($out) >= $want) {
                break;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id < 1 || isset($seen[$id])) {
                continue;
            }
            if (in_array(strtolower((string) ($row['section'] ?? '')), self::NEVER_ROTATE, true)) {
                continue;
            }
            $item = self::item($row, $cfg);
            if ($item === null) {
                continue;
            }
            $seen[$id] = true;
            $out[]     = $item;
        }

        return $out;
    }

    // ---------------------------------------------------------------- reading

    /**
     * The same read the front page performs, so the pool is composed from the
     * same evidence and the unplaced remainder really is unplaced.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function read(PDO $pdo): array
    {
        try {
            // THE SAME CALL THE FRONT PAGE MAKES. Not a re-implementation of it:
            // Db::homeCandidates() is the flat window read AND the per-desk
            // top-up, and Router::home() composes the page from that identical
            // list. This used to be the flat read alone while this docblock
            // claimed otherwise — measured, the page saw 209 rows and this saw
            // 88, and the 121 rows only the page could see were the quiet desks
            // the top-up exists to rescue. Education was on the page and absent
            // from the rotation pool because of it.
            $rows = Db::homeCandidates($pdo);
            if ($rows !== []) {
                return $rows;
            }

            // Nothing at all. That is a real state — a site whose cron has been
            // off for a week and whose every desk has aged out — and it should
            // still rotate through what it holds rather than going quiet.
            return Db::recentArticles($pdo, ['limit' => self::FALLBACK_LIMIT]);
        } catch (Throwable $e) {
            // An empty, unmigrated or read-only database is not an error here.
            error_log('[teb] rotate read: ' . $e->getMessage());

            return [];
        }
    }

    // ------------------------------------------------------------------ shape

    /**
     * One story, in the shape app.js swaps into a card.
     *
     * @param array<string,mixed> $row a Compose-normalised article row
     * @return array<string,mixed>|null null when the row cannot make a card
     */
    private static function item(array $row, array $cfg): ?array
    {
        $id       = (int) ($row['id'] ?? 0);
        $headline = trim((string) ($row['title'] ?? ''));
        if ($id < 1 || $headline === '') {
            return null;                       // the same test Render::card applies
        }

        $source = trim((string) ($row['source_name'] ?? ''));
        if ($source === '') {
            $source = trim((string) ($row['source'] ?? ''));
        }

        [$iso, $label] = self::published($row, $cfg);

        return [
            'id'            => $id,
            'href'          => Paths::url(Render::articleHref($row)),
            'headline'      => $headline,
            'summary'       => trim((string) ($row['summary'] ?? '')),
            'section'       => (string) ($row['section'] ?? ''),
            'section_label' => (string) ($row['section_label'] ?? ''),
            'source'        => $source,
            'published'     => (int) ($row['published_at'] ?? 0),
            'published_iso' => $iso,
            'published_label' => $label,
            'fresh'         => !empty($row['fresh']),
            'image'         => self::image($row),
        ];
    }

    /**
     * The timestamp, taken from Render::timeTag() rather than formatted again.
     *
     * Parsing our own renderer's output looks indirect, and it is — but it is
     * the only way the swapped-in timestamp is guaranteed to read identically
     * to the one the server printed ("5:48 p.m.", and "M j, 5:48 p.m." once a
     * story is over a day old). A second formatter here would drift the first
     * time anyone edits the newspaper form in Render.
     *
     * @return array{0:string,1:string} [ISO-8601, human label]; ['',''] if undated
     */
    private static function published(array $row, array $cfg): array
    {
        $ms = (int) ($row['published_at'] ?? 0);
        if ($ms <= 0) {
            return ['', ''];
        }

        $tag = Render::timeTag($ms, $cfg);
        if ($tag === '' || preg_match('#datetime="([^"]*)"[^>]*>(.*?)</time>#s', $tag, $m) !== 1) {
            return ['', ''];
        }

        return [
            html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'),
            html_entity_decode($m[2], ENT_QUOTES, 'UTF-8'),
        ];
    }

    /**
     * The picture, judged at LEAD size for every story in the pool.
     *
     * Any story here may land in the lead slot on the next tick, so a picture
     * that cannot honestly fill the biggest box on the page is replaced by the
     * masthead placeholder rather than stretched. That also gives the client a
     * useful invariant — every rotation candidate has a usable image, so a swap
     * can never leave a hole in the hero grid. Publishers who ship no picture at
     * all (The Conversation ships none, on any section) rely on this.
     *
     * @return array{url:string,width:int,height:int,alt:string,placeholder:bool}
     */
    private static function image(array $row): array
    {
        $raw = (string) ($row['image_url'] ?? '');
        $alt = trim((string) ($row['image_alt'] ?? ''));
        if ($alt === '') {
            $alt = trim((string) ($row['title'] ?? ''));
        }
        if ($alt === '') {
            $alt = 'News photograph';
        }

        $own = $raw !== '' && Placeholder::isPlaceholder($raw);
        $url = $own ? $raw : Render::outbound($raw);

        if ($url !== '' && ($own || Images::usable($row, 'lead'))) {
            $w = (int) ($row['image_width'] ?? 0);
            $h = (int) ($row['image_height'] ?? 0);
            if ($w < 1 || $h < 1 || $w > 10000 || $h > 10000) {
                [$w, $h] = $own ? self::PLACEHOLDER_BOX : self::BOX;
            }

            return [
                'url'         => $url,
                'width'       => $w,
                'height'      => $h,
                'alt'         => $alt,
                'placeholder' => $own,
            ];
        }

        return [
            'url'         => Placeholder::url($row),
            'width'       => self::PLACEHOLDER_BOX[0],
            'height'      => self::PLACEHOLDER_BOX[1],
            'alt'         => $alt,
            'placeholder' => true,
        ];
    }

    // --------------------------------------------------------------- envelope

    /**
     * @param array<int,array<string,mixed>> $stories
     * @return array<string,mixed>
     */
    private static function envelope(array $stories, string $state): array
    {
        return [
            'ok'        => true,          // always true: an empty list is a valid answer
            'state'     => $state,        // 'live' | 'cached' | 'unavailable'
            'generated' => Db::nowMs(),
            'ttl'       => self::TTL,
            'count'     => count($stories),
            'stories'   => $stories,
        ];
    }

    /**
     * JSON_INVALID_UTF8_SUBSTITUTE is the load-bearing flag: one headline with a
     * truncated multi-byte character would otherwise make json_encode return
     * false and silently blank the endpoint for a whole TTL.
     */
    private static function encode(array $payload): string
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '';
    }

    // ------------------------------------------------------------------ cache

    /**
     * Where the composed payload is parked.
     *
     * The filename carries a hash of the base path and the URL style, because
     * every href in the payload is absolute-from-root and both of those change
     * it. The same upload reachable at / and at /staging/ must not serve one
     * the other's links out of a shared cache file.
     */
    public static function cacheFile(array $cfg): string
    {
        $key = substr(sha1(Paths::base() . '|' . (Paths::hasRewrite() ? 'pretty' : 'query')), 0, 12);

        return self::dataDir($cfg) . '/rotate-' . $key . '.json';
    }

    private static function dataDir(array $cfg): string
    {
        // Ingest owns this decision (it also honours db.sqlite_path). Fall back
        // only when Rotate is loaded on its own, as a test may do.
        if (class_exists(Ingest::class, false)) {
            return Ingest::dataDir($cfg);
        }

        $dir = trim((string) ($cfg['paths']['data'] ?? ''));
        if ($dir === '' || $dir === '.') {
            $dir = 'data';
        }
        if (preg_match('#^([A-Za-z]:[\\\\/]|/)#', $dir) !== 1) {
            $dir = rtrim((string) ($cfg['root'] ?? dirname(__DIR__)), '/\\') . '/' . ltrim($dir, '/\\');
        }

        return rtrim($dir, '/\\');
    }

    /** The cached body if it is still warm, else null. Never throws. */
    private static function readCache(string $file, int $ttl): ?string
    {
        try {
            if ($file === '' || !is_file($file)) {
                return null;
            }
            $age = time() - (int) @filemtime($file);
            if ($age < 0 || $age >= max(1, $ttl)) {
                return null;
            }
            $raw = @file_get_contents($file);
            if (!is_string($raw) || $raw === '' || $raw[0] !== '{') {
                return null;
            }

            // Say so honestly: this body was composed up to TTL seconds ago.
            return str_replace('"state":"live"', '"state":"cached"', $raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Write through a temporary file and rename, so a reader never sees half a
     * document and two concurrent writers cannot interleave. A failure is
     * ignored: an uncacheable install is slower, not broken.
     */
    private static function writeCache(string $file, string $json): void
    {
        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                return;
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
                @unlink($tmp);
                return;
            }
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        } catch (Throwable $e) {
            // Nothing to do and nothing worth saying.
        }
    }

    /** Drop the cached payload. Used by the tests and after a manual ingest. */
    public static function forget(array $cfg): void
    {
        $file = self::cacheFile($cfg);
        if ($file !== '' && is_file($file)) {
            @unlink($file);
        }
    }
}
