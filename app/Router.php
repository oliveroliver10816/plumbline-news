<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * The router. Every route works both as a pretty URL (/section/us) and through
 * the ?r= fallback, because a host that ignores .htaccess must still serve a
 * working site rather than a wall of 404s.
 */
final class Router
{
    /**
     * How far back the front page reads. Four days: this roster publishes a few
     * dozen articles a day between eighteen feeds, and a twenty-four-hour window
     * leaves half the desks empty on a Sunday.
     */
    private const HOME_WINDOW_HOURS = 96;

    /** Extra rows read per desk so a quiet desk still has candidates. */
    private const TOPUP_HOME  = 30;
    private const TOPUP_OTHER = 10;

    /**
     * Stories per section page. Enough to be worth paginating, few enough that
     * the page stays legible on a phone.
     */
    private const SECTION_PER_PAGE = 24;

    /** @return array{status:int,headers:array<string,string>,body:string} */
    public static function dispatch(PDO $pdo, array $cfg, string $route, array $query): array
    {
        $route = '/' . trim($route, '/');
        if ($route === '/') {
            return self::home($pdo, $cfg);
        }

        // /article/{slug}-{id}
        if (preg_match('#^/article/(.+?)-(\d+)$#', $route, $m) === 1) {
            return self::article($pdo, $cfg, (int) $m[2], $m[1]);
        }
        // Bare /article/{id} — tolerated, canonicalised.
        if (preg_match('#^/article/(\d+)$#', $route, $m) === 1) {
            return self::article($pdo, $cfg, (int) $m[1], null);
        }

        // The editorial desk. Only routed when a path AND a signing key are
        // configured; otherwise these URLs fall through to the 404 every other
        // unknown path gets, so probing for it reveals nothing.
        $deskPrefix = trim((string) ($cfg['admin']['path'] ?? ''), '/');
        if ($deskPrefix !== '' && Auth::configured($cfg)) {
            if ($route === '/' . $deskPrefix || strpos($route, '/' . $deskPrefix . '/') === 0) {
                $rest = substr($route, strlen('/' . $deskPrefix));

                return Studio::handle($pdo, $cfg, $rest === '' ? '/' : $rest, $query);
            }
        }

        // An uploaded picture. Immutable: the id never points at different bytes.
        if (preg_match('#^/media/(\d+)\.jpg$#', $route, $m) === 1) {
            $img = Media::fetch($pdo, (int) $m[1]);
            if ($img === null) {
                return self::notFound($pdo, $cfg);
            }

            return [
                'status'  => 200,
                'headers' => [
                    'Content-Type'  => $img['mime'],
                    'Cache-Control' => 'public, max-age=31536000, immutable',
                    'Content-Length'=> (string) strlen($img['data']),
                ],
                'body'    => $img['data'],
            ];
        }

        // A post written on the desk.
        if (preg_match('#^/post/([a-z0-9-]+)$#', $route, $m) === 1) {
            return self::deskPost($pdo, $cfg, $m[1]);
        }

        if (preg_match('#^/section/([a-z0-9-]+)$#', $route, $m) === 1) {
            return self::section($pdo, $cfg, $m[1], max(1, (int) ($query['p'] ?? 1)));
        }

        switch ($route) {
            case '/search':
                return self::search($pdo, $cfg, trim((string) ($query['q'] ?? '')));
            case '/sources':
                return self::sources($pdo, $cfg);
            case '/about':
                return self::standing($pdo, $cfg, Pages::about($cfg), '/about');
            case '/editorial-standards':
                return self::standing($pdo, $cfg, Pages::standards($cfg), '/editorial-standards');
            case '/contact':
                return self::standing($pdo, $cfg, Pages::contact($cfg), '/contact');
            case '/privacy':
                return self::standing($pdo, $cfg, Pages::privacy($cfg), '/privacy');
            case '/terms':
                return self::standing($pdo, $cfg, Pages::terms($cfg), '/terms');
            case '/placeholder.svg':
                return [
                    'status'  => 200,
                    'headers' => [
                        'Content-Type'  => 'image/svg+xml; charset=utf-8',
                        'Cache-Control' => 'public, max-age=31536000, immutable',
                    ],
                    'body'    => Placeholder::svg(
                        (string) ($query['s'] ?? ''),
                        (int) ($query['v'] ?? 0),
                        $cfg
                    ),
                ];
            case Rotate::ROUTE:
                // The rotation feed the front page polls. Deliberately the only
                // route that cannot fail: Rotate::response() answers 200 with an
                // empty list on every error path, because a hero that stops
                // rotating is invisible and a 500 here would repeat every 90
                // seconds in every open tab.
                return Rotate::response($pdo, $cfg);
            case '/healthz':
                return self::healthz($pdo, $cfg);
            case '/admin/ingest':
                return self::adminIngest($pdo, $cfg, $query);
            case '/robots.txt':
                return self::text(Seo::robotsTxt($cfg), 'text/plain; charset=utf-8', 3600);
            case '/sitemap.xml':
                return self::text(Seo::sitemap($pdo, $cfg), 'application/xml; charset=utf-8', 3600);
            case '/sitemap-news.xml':
                return self::text(Seo::newsSitemap($pdo, $cfg), 'application/xml; charset=utf-8', 600);
            case '/feed.xml':
                return self::text(Seo::rss($pdo, $cfg, null), 'application/rss+xml; charset=utf-8', 900);
        }

        if (preg_match('#^/sitemap-(\d+)\.xml$#', $route, $m) === 1) {
            return self::text(Seo::sitemap($pdo, $cfg, (int) $m[1]), 'application/xml; charset=utf-8', 3600);
        }
        if (preg_match('#^/feed/([a-z0-9-]+)\.xml$#', $route, $m) === 1) {
            // A per-desk feed for a desk that does not exist is a 404, exactly as
            // /section/{slug} already is. Without this check Seo::rss() ignored the
            // unknown name and served the WHOLE site feed under it, so every string
            // anyone typed — /feed/sports.xml, /feed/weather.xml, /feed/anything.xml —
            // answered 200 with the same fifty items. That is an unbounded space of
            // duplicate URLs pointing at one document, which is the thing a sitemap
            // and a canonical exist to prevent.
            if (Feeds::section($m[1]) === null) {
                return self::notFound($pdo, $cfg);
            }

            return self::text(Seo::rss($pdo, $cfg, $m[1]), 'application/rss+xml; charset=utf-8', 900);
        }

        return self::notFound($pdo, $cfg);
    }

    // ---------------------------------------------------------------- pages

    private static function home(PDO $pdo, array $cfg): array
    {
        // The flat read plus the per-desk top-up, both of them, in one place.
        // The rotation endpoint composes from the same call, so the pool it
        // offers is drawn from the same candidates this page was built from.
        $rows = Db::homeCandidates($pdo);

        $model = Compose::home($rows, $cfg, Db::nowMs());

        // Posts written on the desk are spliced in AFTER composition, at the
        // slot the editor chose. Doing it here rather than feeding them through
        // the scorer means a pinned post lands exactly where it was put and
        // cannot be demoted by a freshness rule it was never meant to compete in.
        $model = self::pinDeskPosts($pdo, $cfg, $model);

        $model['sources'] = self::sourceNames($pdo);

        $body = Render::home($model, $cfg);
        return self::html($body, 200, (int) ($cfg['cache']['home_seconds'] ?? 120));
    }

    private static function section(PDO $pdo, array $cfg, string $slug, int $page): array
    {
        $meta = Feeds::section($slug);
        if ($meta === null) {
            return self::notFound($pdo, $cfg);
        }

        $per = self::SECTION_PER_PAGE;
        $rows = Db::recentArticles($pdo, [
            'section'  => [$slug],
            'limit'    => $per + 1,
            'offset'   => ($page - 1) * $per,
        ]);
        $hasMore = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $rows = self::gradeSection($rows);

        $model = [
            'slug'        => $slug,
            'label'       => (string) ($meta['label'] ?? ucfirst($slug)),
            'note'        => (string) ($meta['note'] ?? ''),
            'items'       => $rows,
            'page'        => $page,
            'pages'       => $hasMore ? $page + 1 : $page,
            'template'    => '/section/' . $slug,
            'canonical'   => Paths::absolute('/section/' . $slug . ($page > 1 ? '?p=' . $page : '')),
            'route'       => '/section/' . $slug,
            'href'        => '/section/' . $slug,
            'description' => (string) ($meta['blurb'] ?? ($meta['note'] ?? '')),
            'ticker'      => self::ticker($pdo, $cfg),
            'sources'     => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, (int) ($cfg['cache']['section_seconds'] ?? 300));
    }

    private static function article(PDO $pdo, array $cfg, int $id, ?string $slug): array
    {
        $a = Db::articleById($pdo, $id);
        if ($a === null) {
            return self::notFound($pdo, $cfg);
        }

        // Canonical slug redirect. This is the ONE intentional redirect in the
        // project and it lives here, at the application level — never in .htaccess.
        $want = Render::slug((string) ($a['title'] ?? ''));
        if ($want !== '' && $slug !== $want) {
            return [
                'status'  => 301,
                'headers' => ['Location' => Paths::url(Seo::articlePath($a)), 'Cache-Control' => 'public, max-age=600'],
                'body'    => '',
            ];
        }

        $model = [
            'article'   => $a,
            'related'   => Db::relatedArticles($pdo, $a, 6),
            'jsonld'    => Seo::articleJsonLd($a, $cfg),
            'canonical' => Paths::absolute(Seo::articlePath($a)),
            'route'     => Seo::articlePath($a),
            'ticker'    => self::ticker($pdo, $cfg),
            'sources'   => self::sourceNames($pdo),
        ];

        return self::html(Render::article($model, $cfg), 200, (int) ($cfg['cache']['article_seconds'] ?? 600));
    }

    private static function search(PDO $pdo, array $cfg, string $q): array
    {
        $items = $q === '' ? [] : Db::searchArticles($pdo, $q, 40);

        $model = [
            'slug'      => 'search',
            'label'     => 'Results',
            'search'    => true,
            'q'         => $q,
            'items'     => $items,
            'total'     => count($items),
            'template'  => '/search',
            'canonical' => Paths::absolute('/search'),
            'route'     => '/search',
            'href'      => '/search',
            'noindex'   => true,
            'ticker'    => self::ticker($pdo, $cfg),
            'sources'   => self::sourceNames($pdo),
        ];

        return self::html(Render::section($model, $cfg), 200, 0);
    }

    private static function sources(PDO $pdo, array $cfg): array
    {
        $rows = Db::sources($pdo, true);
        $li = '';
        foreach ($rows as $s) {
            $name = Render::esc((string) ($s['name'] ?? ''));
            $sec  = Render::esc((string) ($s['section'] ?? ''));
            $url  = (string) ($s['url'] ?? $s['feed_url'] ?? '');
            $li .= '<li><strong>' . $name . '</strong> <span class="card-src">' . $sec . '</span>'
                . ($url !== '' ? ' — <a class="card-out" href="' . Render::esc($url) . '" rel="noopener nofollow external" target="_blank">' . Render::esc(parse_url($url, PHP_URL_HOST) ?: $url) . '</a>' : '')
                . '</li>';
        }

        // The label is this page's <h1>: /sources is a page in its own right and
        // was reaching a reader with no top-level heading at all, so its outline
        // began at the <h5>s in the footer. The wrapper tag is the only change —
        // .block-label still carries every visual property.
        $body = '<section class="block wrap"><div class="block-head">'
            . '<h1 class="block-h1"><span class="block-label">Sources</span></h1></div>'
            . '<p class="result-note">' . Render::esc(Pages::SOURCES_INTRO) . '</p>'
            . '<ul class="source-list">' . $li . '</ul></section>';

        return self::html(Render::layout([
            'cfg'         => $cfg,
            'title'       => 'Sources',
            'description' => Pages::SOURCES_DESCRIPTION,
            'canonical'   => Paths::absolute('/sources'),
            'route'       => '/sources',
            'body'        => $body,
            'ticker'      => self::ticker($pdo, $cfg),
            'sources'     => self::sourceNames($pdo),
        ]), 200, 3600);
    }

    /**
     * The standing pages — About, Editorial Standards, Contact, Privacy and
     * Terms. Their copy lives in app/Pages.php; this only wraps it in the
     * site furniture, so the two never drift apart.
     *
     * @param array{title:string,description:string,body:string,jsonld?:mixed} $page
     */
    private static function standing(PDO $pdo, array $cfg, array $page, string $route): array
    {
        return self::html(Render::layout([
            'cfg'         => $cfg,
            'title'       => (string) ($page['title'] ?? ''),
            'description' => (string) ($page['description'] ?? ''),
            'canonical'   => Paths::absolute($route),
            'route'       => $route,
            'body'        => (string) ($page['body'] ?? ''),
            'jsonld'      => $page['jsonld'] ?? null,
            'ticker'      => self::ticker($pdo, $cfg),
            'sources'     => self::sourceNames($pdo),
        ]), 200, 3600);
    }

    /**
     * Fetch trigger for an external scheduler.
     *
     * Guarded by ingest.token. With no token set the route is CLOSED, not open —
     * an unauthenticated endpoint that makes eighteen outbound requests is a free
     * amplifier for anyone who finds it.
     */
    private static function adminIngest(PDO $pdo, array $cfg, array $query): array
    {
        $want = (string) ($cfg['ingest']['token'] ?? '');
        if ($want === '') {
            return self::json(503, ['ok' => false, 'error' => 'ingest token not configured']);
        }

        $given = (string) ($query['token'] ?? '');
        if ($given === '') {
            $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (stripos($hdr, 'bearer ') === 0) {
                $given = trim(substr($hdr, 7));
            }
        }
        // Constant-time compare so the token cannot be guessed a byte at a time.
        if (!hash_equals($want, $given)) {
            return self::json(403, ['ok' => false, 'error' => 'forbidden']);
        }

        $lock = Ingest::lock(Ingest::dataDir($cfg));
        if ($lock === null) {
            // Already running. Not an error — the previous tick is still going.
            return self::json(200, ['ok' => true, 'skipped' => 'already running']);
        }

        try {
            $r = Ingest::run($pdo, $cfg, null);
            $pruned = 0;
            $backfill = ['checked' => 0, 'measured' => 0, 'dropped' => 0];
            if ((int) ($query['prune'] ?? 0) === 1) {
                $pruned = Db::pruneOld($pdo, (int) ($cfg['ingest']['retention_days'] ?? 30));
                // Drain the measuring backlog a batch at a time on the hourly
                // tick, so images that missed their run still get sized.
                $backfill = Ingest::backfillImages($pdo, $cfg, 120, 15.0);
            }
            return self::json(200, [
                'ok'       => true,
                'feeds_ok' => (int) ($r['feeds_ok'] ?? 0),
                'failed'   => (int) ($r['feeds_failed'] ?? 0),
                'inserted' => (int) ($r['inserted'] ?? 0),
                'skipped'  => (int) ($r['skipped'] ?? 0),
                'pruned'   => $pruned,
                'images'   => $backfill,
                'articles' => Db::countArticles($pdo),
            ]);
        } catch (Throwable $e) {
            error_log('[teb] admin ingest: ' . $e->getMessage());
            return self::json(500, ['ok' => false, 'error' => 'ingest failed']);
        } finally {
            Ingest::unlock();
        }
    }

    private static function json(int $status, array $payload): array
    {
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            'body'    => (string) json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Splice pinned desk posts into a composed front page.
     *
     * Slot 1 takes the lead position and pushes the existing lead down into the
     * secondary row, so nothing is lost. Slots 2-5 go to the top of that
     * secondary row; anything beyond goes to the head of the first block. A
     * pinned post whose picture failed to store still appears — it simply
     * renders as a text card, which is better than a hole where the editor put
     * something deliberately.
     *
     * @param  array<string,mixed> $model
     * @return array<string,mixed>
     */
    private static function pinDeskPosts(PDO $pdo, array $cfg, array $model): array
    {
        try {
            $pinned = Posts::pinned($pdo, Posts::SLOTS);
        } catch (Throwable $e) {
            return $model;
        }
        if ($pinned === []) {
            return $model;
        }

        $subs = is_array($model['hero']['subs'] ?? null) ? $model['hero']['subs'] : [];
        foreach ($pinned as $row) {
            $mid = (int) ($row['media_id'] ?? 0);
            if ($mid > 0) {
                $m = Media::meta($pdo, $mid);
                if (is_array($m)) {
                    $row['media_width']  = (int) $m['width'];
                    $row['media_height'] = (int) $m['height'];
                }
            }
            $a    = Posts::asArticle($row, $cfg);
            $slot = (int) ($row['slot'] ?? 0);

            if ($slot === 1) {
                $old = $model['hero']['lead'] ?? null;
                $model['hero']['lead'] = $a;
                if (is_array($old)) {
                    array_unshift($subs, $old);
                }
                continue;
            }
            if ($slot >= 2 && $slot <= 5) {
                array_unshift($subs, $a);
                continue;
            }
            if (isset($model['blocks'][0]['items']) && is_array($model['blocks'][0]['items'])) {
                array_unshift($model['blocks'][0]['items'], $a);
            } else {
                $subs[] = $a;
            }
        }
        $model['hero']['subs'] = $subs;

        return $model;
    }

    /** A post written on the desk, rendered as an ordinary article page. */
    private static function deskPost(PDO $pdo, array $cfg, string $slug): array
    {
        $row = Posts::bySlug($pdo, $slug);
        if ($row === null || ($row['status'] ?? '') !== Posts::STATUS_PUBLISHED) {
            return self::notFound($pdo, $cfg);
        }
        $media = (int) ($row['media_id'] ?? 0) > 0 ? Media::meta($pdo, (int) $row['media_id']) : null;
        if (is_array($media)) {
            $row['media_width']  = (int) $media['width'];
            $row['media_height'] = (int) $media['height'];
        }

        $a = Posts::asArticle($row, $cfg);

        $model = [
            'article'   => $a,
            'related'   => Db::recentArticles($pdo, ['limit' => 6]),
            'jsonld'    => Seo::articleJsonLd($a, $cfg),
            'canonical' => Paths::absolute('/post/' . $slug),
            'route'     => '/post/' . $slug,
            'ticker'    => self::ticker($pdo, $cfg),
            'sources'   => self::sourceNames($pdo),
        ];

        // A sponsored post is never cached as hard as editorial, so pulling one
        // down takes effect quickly.
        $ttl = $a['sponsored'] ? 60 : (int) ($cfg['cache']['article_seconds'] ?? 600);

        return self::html(Render::article($model, $cfg), 200, $ttl);
    }

    private static function healthz(PDO $pdo, array $cfg): array
    {
        $report = Health::report($pdo, $cfg);
        return [
            'status'  => Health::statusCode($report),
            'headers' => [
                'Content-Type'  => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            'body'    => Health::json($report),
        ];
    }

    public static function notFound(PDO $pdo, array $cfg): array
    {
        return self::html(
            Render::error(404, Pages::NOT_FOUND_MESSAGE, $cfg),
            404,
            0
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Headlines for the ticker on pages that are not the front page.
     *
     * The strip shows one desk after another (SPEC §6), and interleaving can
     * only reach a vertical the query actually returned — on a quiet morning
     * the newest $n rows are routinely all off the same desk, which is the
     * complaint this fixes. So read a WIDER pool than the strip holds and let
     * Render::tickerOrder() pick the newest of each section out of it, in turn.
     * The pool is bounded: a 12-item strip reads 96 rows, never the archive.
     */
    private static function ticker(PDO $pdo, array $cfg): array
    {
        $n    = max(1, (int) ($cfg['compose']['ticker_count'] ?? 12));
        $pool = Db::recentArticles($pdo, ['limit' => min(240, $n * 8)]);

        return Render::tickerOrder($pool, $n);
    }

    /**
     * A section index is a page, not a list.
     *
     * Twenty-four identical cards is a wall, and on this roster most of them
     * would be twenty-four copies of the house placeholder, because nearly every
     * newsroom here licenses its text and withholds its pictures. So the page is
     * graded the way a newspaper section front is: one lead, three seconds, then
     * headline rows. Render::block honours a row's own 'size', so this needs no
     * change in the renderer and no second grid class.
     *
     * @param  array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function gradeSection(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['size'] = $i === 0 ? 'large' : ($i < 4 ? 'medium' : 'small');
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<int,string> */
    private static function sourceNames(PDO $pdo): array
    {
        try {
            $out = [];
            foreach (Db::sources($pdo, true) as $s) {
                $n = trim((string) ($s['name'] ?? ''));
                if ($n !== '') {
                    // "ABC News — Top Stories" reads as "ABC News" in a credit line.
                    $out[explode(' — ', $n)[0]] = true;
                }
            }
            return array_slice(array_keys($out), 0, 24);
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function html(string $body, int $status, int $sMaxAge): array
    {
        $cc = $sMaxAge > 0
            ? 'public, max-age=0, s-maxage=' . $sMaxAge . ', stale-while-revalidate=600'
            : 'no-store';
        return [
            'status'  => $status,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => $cc],
            'body'    => $body,
        ];
    }

    private static function text(string $body, string $type, int $maxAge): array
    {
        return [
            'status'  => 200,
            'headers' => [
                'Content-Type'  => $type,
                'Cache-Control' => 'public, max-age=' . max(0, $maxAge),
            ],
            'body'    => $body,
        ];
    }
}
