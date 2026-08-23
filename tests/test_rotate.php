<?php

declare(strict_types=1);

/**
 * app/Rotate.php — the /api/top.json payload the front page polls.
 *
 * The endpoint's whole contract is that it CANNOT fail. The front page is
 * already rendered and correct by the time a browser calls this; a 500 here
 * buys nothing and costs a repeating error every 90 seconds in every open tab.
 * So the failure paths get as much attention below as the happy one: a missing
 * table, an unwritable cache directory, a headline carrying invalid UTF-8, and
 * a database with nothing in it at all must each answer 200 with a list.
 *
 * Everything runs against a REAL temporary SQLite file. data/ is never touched.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Config', 'Paths', 'Feeds', 'Images', 'Placeholder', 'Db', 'Compose', 'Render', 'Rotate');

use TEB\Db;
use TEB\Config;
use TEB\Paths;
use TEB\Placeholder;
use TEB\Render;
use TEB\Rotate;

// ---------------------------------------------------------------- fixtures

function teb_rot_now(): int
{
    static $t = 0;
    if ($t === 0) {
        $t = (int) floor(microtime(true) * 1000);
    }

    return $t;
}

/**
 * Config pointed at a scratch directory, with Paths pinned so no test can make
 * a network call to probe for mod_rewrite.
 *
 * @return array{0:array<string,mixed>,1:PDO}
 */
function teb_rot_boot(bool $rewrite = true, string $script = '/index.php'): array
{
    Config::reset();
    $cfg = Config::load(teb_root());

    $dir = teb_tmp_dir('teb-rot');
    $cfg['root']          = teb_root();
    $cfg['paths']['data'] = $dir;
    $cfg['db'] = ['driver' => 'sqlite', 'sqlite_path' => $dir . '/rot.sqlite'];

    Paths::init([
        'HTTP_HOST'   => 'brief.example',
        'SCRIPT_NAME' => $script,
        'REQUEST_URI' => '/api/top.json',
        'HTTPS'       => 'on',
    ], teb_root());
    Paths::allowProbe(false);
    Paths::forceRewrite($rewrite);

    $pdo = Db::connect(['db' => $cfg['db']]);
    Db::migrate($pdo);

    return [$cfg, $pdo];
}

/** @return array<string,mixed> */
function teb_rot_row(int $n, array $over = []): array
{
    return $over + [
        'source_id'    => 0,
        'source_slug'  => 'src-' . ($n % 5),
        'source_name'  => ['ProPublica', 'KFF Health News', 'The Conversation', 'Global Voices', 'MIT News'][$n % 5],
        'section'      => ['us', 'us', 'world', 'international', 'politics', 'health', 'technology', 'science'][$n % 8],
        'guid'         => 'rot-guid-' . $n,
        'url'          => 'https://example.org/story-' . $n,
        'title'        => 'Council votes ' . $n . ' on the harbour plan after a long debate',
        'summary'      => 'The vote followed three hours of testimony, story ' . $n . '.',
        'image_url'    => 'https://img.example.org/' . $n . '.jpg',
        'image_width'  => 1600,
        'image_height' => 900,
        'author'       => 'Staff Reporter',
        'published_at' => teb_rot_now() - ($n * 300000),
        'fetched_at'   => teb_rot_now(),
    ];
}

function teb_rot_fill(PDO $pdo, int $count = 90, array $over = []): void
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = teb_rot_row($i, $over);
    }
    Db::insertArticles($pdo, $rows);
}

/** @return array<string,mixed> */
function teb_rot_payload(PDO $pdo, array $cfg): array
{
    Rotate::forget($cfg);
    $res = Rotate::response($pdo, $cfg);
    assertSame(200, (int) $res['status'], 'the endpoint always answers 200');
    $d = json_decode($res['body'], true);
    assertTrue(is_array($d), 'body is JSON');

    return $d;
}

// ---------------------------------------------------------------- tests

return [

'an empty database answers 200 with an empty list, never a 500' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();

    $res = Rotate::response($pdo, $cfg);

    assertSame(200, (int) $res['status']);
    assertSame('application/json; charset=utf-8', $res['headers']['Content-Type']);
    assertSame('public, max-age=60', $res['headers']['Cache-Control']);
    assertSame('nosniff', $res['headers']['X-Content-Type-Options']);

    $d = json_decode($res['body'], true);
    assertTrue(is_array($d), 'the empty answer is still JSON');
    assertTrue($d['ok'], 'ok is true even with nothing to send');
    assertSame(0, $d['count']);
    assertSame([], $d['stories']);
},

'a database with no articles table still answers 200 with an empty list' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 30);
    Rotate::forget($cfg);

    $pdo->exec('DROP TABLE articles');

    $res = Rotate::response($pdo, $cfg);
    assertSame(200, (int) $res['status'], 'a missing table is not a 500');
    $d = json_decode($res['body'], true);
    assertSame(0, $d['count']);
    assertTrue($d['ok']);
},

'an unwritable cache directory degrades to composing every time, not to an error' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 40);

    $cfg['paths']['data'] = '/proc/teb-cannot-write-here';

    $res = Rotate::response($pdo, $cfg);
    assertSame(200, (int) $res['status']);
    $d = json_decode($res['body'], true);
    assertGreaterThan(0, $d['count'], 'the stories still come back, just uncached');
},

'the pool is longer than the hero, so the page visibly changes' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 90);

    $d = teb_rot_payload($pdo, $cfg);

    // The hero shows a lead plus compose.hero_sub_count seconds. Rotating
    // through only that many stories would show the same page again.
    $onScreen = 1 + (int) ($cfg['compose']['hero_sub_count'] ?? 4);
    assertGreaterThan($onScreen, $d['count'], 'more stories than the hero shows');
    assertLessThanOrEqual(Rotate::POOL, $d['count']);
},

'no story appears twice in the pool' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 90);

    $ids = array_column(teb_rot_payload($pdo, $cfg)['stories'], 'id');

    assertSame(count($ids), count(array_unique($ids)), 'ids are unique');
    assertTrue(count($ids) > 0);
},

'every story carries the whole contract, correctly typed' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 60);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        foreach (['id', 'href', 'headline', 'summary', 'section', 'section_label',
                  'source', 'published', 'published_iso', 'published_label', 'fresh', 'image'] as $k) {
            assertArrayHasKey($k, $s, 'story is missing ' . $k);
        }
        assertTrue(is_int($s['id']) && $s['id'] > 0, 'id is a positive int');
        assertTrue(is_string($s['headline']) && $s['headline'] !== '', 'headline is a non-empty string');
        assertTrue(is_string($s['href']) && $s['href'] !== '', 'href is a non-empty string');
        assertTrue(is_int($s['published']), 'published is an int');
        assertTrue(is_bool($s['fresh']), 'fresh is a bool');

        assertTrue(is_array($s['image']), 'image is an object');
        foreach (['url', 'width', 'height', 'alt', 'placeholder'] as $k) {
            assertArrayHasKey($k, $s['image'], 'image is missing ' . $k);
        }
        assertTrue($s['image']['url'] !== '', 'every rotation candidate has a picture');
        assertGreaterThan(0, $s['image']['width']);
        assertGreaterThan(0, $s['image']['height']);
        assertTrue(is_bool($s['image']['placeholder']));
    }
},

'href is the same link the renderer would have printed' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 30);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        $row  = Db::articleById($pdo, (int) $s['id']);
        $want = Paths::url(Render::articleHref($row));
        assertSame($want, $s['href'], 'the rotated card links where a rendered card would');
    }
},

'href follows the ?r= form on a host with no mod_rewrite' => function (): void {
    [$cfg, $pdo] = teb_rot_boot(false);
    teb_rot_fill($pdo, 30);

    $s = teb_rot_payload($pdo, $cfg)['stories'][0];
    assertContains('index.php?r=/article/', $s['href'], 'a rewrite-less host still gets a working link');
},

'the timestamp reads exactly as the server-rendered one' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 30);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        $tag = Render::timeTag((int) $s['published'], $cfg);
        assertContains('datetime="' . $s['published_iso'] . '"', $tag, 'ISO matches the rendered <time>');
        assertContains('>' . Render::esc($s['published_label']) . '<', $tag, 'the label matches too');
    }
},

'a picture too small for the lead slot becomes the masthead placeholder' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    // 60x60 is what CBS ships; stretched into a lead photo it is unreadable.
    teb_rot_fill($pdo, 40, ['image_width' => 60, 'image_height' => 60]);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        assertTrue($s['image']['placeholder'], 'a 60x60 thumbnail never becomes a lead photo');
        assertTrue(Placeholder::isPlaceholder($s['image']['url']));
        assertSame(1200, $s['image']['width']);
        assertSame(630, $s['image']['height']);
    }
},

'a publisher that ships no picture at all still fills the grid' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    // The Conversation carries 0 images in its feed, on every section.
    teb_rot_fill($pdo, 40, ['image_url' => '', 'image_width' => 0, 'image_height' => 0]);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        assertTrue($s['image']['placeholder']);
        assertTrue($s['image']['url'] !== '');
        assertTrue($s['image']['alt'] !== '', 'the placeholder still describes the story');
    }
},

'an unmeasured picture is not gambled on the lead slot' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 40, ['image_width' => 0, 'image_height' => 0]);

    foreach (teb_rot_payload($pdo, $cfg)['stories'] as $s) {
        assertTrue($s['image']['placeholder'], 'never measured means never in the lead');
    }
},

'money never rotates into the top of the page' => function (): void {
    // The client's rule, and the one Compose enforces at the other end: a
    // markets story may sit in the strip at the foot of the page and nowhere
    // else. Rotation is the one path that could put one at the top ninety
    // seconds after the reader arrived, so it is filtered here too.
    [$cfg, $pdo] = teb_rot_boot();
    $rows = [];
    for ($i = 1; $i <= 60; $i++) {
        $rows[] = teb_rot_row($i, ['section' => $i % 2 ? 'business' : 'markets']);
    }
    for ($i = 61; $i <= 120; $i++) {
        $rows[] = teb_rot_row($i);
    }
    Db::insertArticles($pdo, $rows);

    $seen = teb_rot_payload($pdo, $cfg)['stories'];
    assertGreaterThan(0, count($seen), 'the fixture still fills the rotation');
    foreach ($seen as $s) {
        assertNotContains($s['section'], ['business', 'markets'], 'money stays in the strip');
    }
},

'the second call inside the TTL is served from the cache' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 60);
    Rotate::forget($cfg);

    $first = json_decode(Rotate::response($pdo, $cfg)['body'], true);
    assertSame('live', $first['state']);

    $second = json_decode(Rotate::response($pdo, $cfg)['body'], true);
    assertSame('cached', $second['state'], 'the second caller pays nothing');

    // Same stories, in the same order — only the state label differs.
    assertSame(
        array_column($first['stories'], 'id'),
        array_column($second['stories'], 'id')
    );
    assertFileExists(Rotate::cacheFile($cfg));
},

'a cache file older than the TTL is ignored, not served' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 60);
    Rotate::forget($cfg);

    Rotate::response($pdo, $cfg);
    $file = Rotate::cacheFile($cfg);
    assertFileExists($file);
    touch($file, time() - (Rotate::TTL + 5));
    clearstatcache(true, $file);

    $d = json_decode(Rotate::response($pdo, $cfg)['body'], true);
    assertSame('live', $d['state'], 'a stale file is recomposed');
},

'a truncated cache file is ignored rather than served as broken JSON' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 60);
    Rotate::forget($cfg);

    Rotate::response($pdo, $cfg);
    file_put_contents(Rotate::cacheFile($cfg), 'not json at all');

    $res = Rotate::response($pdo, $cfg);
    assertSame(200, (int) $res['status']);
    $d = json_decode($res['body'], true);
    assertTrue(is_array($d), 'we never hand back the junk');
    assertGreaterThan(0, $d['count']);
},

'two installs of the same upload do not share one cache file' => function (): void {
    [$cfgA] = teb_rot_boot(true, '/index.php');
    $a = Rotate::cacheFile($cfgA);

    [$cfgB] = teb_rot_boot(true, '/staging/index.php');
    $b = Rotate::cacheFile($cfgB);

    assertNotSame(basename($a), basename($b), 'the base path is part of the cache key');
},

'a headline carrying invalid UTF-8 does not blank the endpoint' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 40);

    // Straight into the table: Db normalises this away on the way in, so the
    // only way to reproduce the feed that once broke json_encode is to write it.
    $pdo->exec("UPDATE articles SET title = 'Bad byte ' || char(1) || x'ff' || ' here' WHERE id = 1");
    Rotate::forget($cfg);

    $res = Rotate::response($pdo, $cfg);
    assertSame(200, (int) $res['status']);
    $d = json_decode($res['body'], true);
    assertTrue(is_array($d), 'the payload is still valid JSON');
    assertGreaterThan(0, $d['count'], 'one bad row does not empty the list');
},

'the route is wired into the router, both URL forms' => function (): void {
    teb_require_app('Seo', 'Health', 'Ingest', 'Xml', 'Durable', 'Router');

    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 60);
    Rotate::forget($cfg);

    $res = \TEB\Router::dispatch($pdo, $cfg, Rotate::ROUTE, []);
    assertSame(200, (int) $res['status']);
    assertSame('public, max-age=60', $res['headers']['Cache-Control']);
    $d = json_decode($res['body'], true);
    assertGreaterThan(0, $d['count']);

    // Paths::currentRoute() hands the same string through for /index.php?r=...
    $res2 = \TEB\Router::dispatch($pdo, $cfg, '/api/top.json', []);
    assertSame(200, (int) $res2['status']);
},

'the payload carries no brand, domain or feed URL' => function (): void {
    $src = file_get_contents(teb_root() . '/app/Rotate.php');

    assertNotContains('http://', $src, 'no absolute URL is hardcoded here');
    assertNotContains('https://', $src);
    assertNotContains('.com', $src);
    assertNotContains('Evening', $src, 'the brand name lives in config.php only');
},

'the whole endpoint is cheap enough to poll' => function (): void {
    [$cfg, $pdo] = teb_rot_boot();
    teb_rot_fill($pdo, 400);

    Rotate::forget($cfg);
    $t0 = microtime(true);
    Rotate::response($pdo, $cfg);
    $cold = (microtime(true) - $t0) * 1000;

    $t0 = microtime(true);
    Rotate::response($pdo, $cfg);
    $warm = (microtime(true) - $t0) * 1000;

    assertLessThan(400.0, $cold, 'composing 400 rows stays well under a page render');
    assertLessThan($cold + 1.0, $warm, 'the cached answer is never slower than the cold one');
},

'app.js stays under 10 KB and needs no framework' => function (): void {
    $js = teb_root() . '/assets/js/app.js';
    assertFileExists($js);

    $bytes = filesize($js);
    assertLessThan(10240, $bytes, 'app.js is ' . $bytes . ' bytes');

    $src = file_get_contents($js);
    assertNotContains('import ', $src, 'no module imports');
    assertNotContains('require(', $src, 'no bundler');
    assertContains('/api/top.json', $src, 'the client really calls the endpoint');
},

];
