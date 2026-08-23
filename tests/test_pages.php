<?php
declare(strict_types=1);

/**
 * tests/test_pages.php — TEB\Pages, the five standing pages.
 *
 * These pages exist to be read by two audiences who both punish a lie: a
 * publisher checking whether their licence is being honoured, and an
 * advertising reviewer checking whether the site is what it says it is. So the
 * assertions here are about TRUTH as much as about markup:
 *
 *   · the brand appears only because config.php put it there
 *   · every contact address is on the site's own domain
 *   · no {TOKEN} placeholder survives into the page
 *   · the contents rail never points at an anchor that does not exist
 *   · the contact page never claims a message was sent
 *   · the privacy page describes the storage this build actually uses
 *
 * Everything is rendered for real through Pages + Render::layout, and the
 * output is read back and interrogated. A fixed expected string would only
 * prove the page still says what it said yesterday.
 */

teb_require_app('Config', 'Paths', 'Feeds', 'Render', 'Pages');

use TEB\Pages;
use TEB\Paths;
use TEB\Render;

// ---------------------------------------------------------------------------
//  fixtures
// ---------------------------------------------------------------------------

function pgInit(): void
{
    Paths::init([
        'REQUEST_URI'    => '/',
        'SCRIPT_NAME'    => '/index.php',
        'PHP_SELF'       => '/index.php',
        'HTTP_HOST'      => 'pages.example.test',
        'SERVER_NAME'    => 'pages.example.test',
        'REQUEST_METHOD' => 'GET',
        'HTTPS'          => 'on',
    ], teb_root());
    Paths::allowProbe(false);
    Paths::forceRewrite(true);
}

function pgCfg(array $over = []): array
{
    return array_replace_recursive([
        'site' => [
            'name'     => 'Fixture Gazette',
            'domain'   => 'fixture-gazette.test',
            'locale'   => 'en_US',
            'timezone' => 'UTC',
        ],
    ], $over);
}

/** @return array<string,array{title:string,description:string,body:string,jsonld:array}> */
function pgAll(array $cfg): array
{
    return [
        '/about'                => Pages::about($cfg),
        '/editorial-standards'  => Pages::standards($cfg),
        '/contact'              => Pages::contact($cfg),
        '/privacy'              => Pages::privacy($cfg),
        '/terms'                => Pages::terms($cfg),
    ];
}

return [

    // ---------------------------------------------------------------- shape

    'every standing page returns a title, a description and a body' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            assertTrue(is_array($p), $route . ' must return an array');
            foreach (['title', 'description', 'body'] as $k) {
                assertArrayHasKey($k, $p, $route . ' is missing ' . $k);
                assertTrue(is_string($p[$k]) && trim($p[$k]) !== '', $route . ': ' . $k . ' is empty');
            }
            assertGreaterThan(2500, strlen($p['body']), $route . ' is too thin to be a real page');
            // A meta description that overflows is a description nobody reads.
            assertLessThanOrEqual(200, strlen($p['description']), $route . ' description is too long');
        }
    },

    'no placeholder token survives into the rendered page' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            assertNotMatches('/\{[A-Z][A-Z_]*\}/', $p['body'], $route . ' still carries a {TOKEN}');
        }
    },

    'nothing on these pages says lorem, TODO or coming soon' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            $hay = strtolower($p['body'] . ' ' . $p['title'] . ' ' . $p['description']);
            foreach (['lorem ipsum', 'coming soon', 'todo', 'placeholder text', '[your ', 'xxx-xxx'] as $bad) {
                assertNotContains($bad, $hay, $route . ' contains "' . $bad . '"');
            }
        }
    },

    // ------------------------------------------------------- brand + domain

    'app/Pages.php does not hardcode the brand or the domain' => function (): void {
        $cfg    = require teb_root() . '/config.php';
        $brand  = (string) ($cfg['site']['name'] ?? '');
        $domain = (string) ($cfg['site']['domain'] ?? '');
        $src    = (string) file_get_contents(teb_root() . '/app/Pages.php');

        assertNotSame('', $src, 'app/Pages.php is empty');
        if ($brand !== '') {
            assertNotContains($brand, $src, 'app/Pages.php hardcodes the brand name');
        }
        if ($domain !== '') {
            assertNotContains($domain, $src, 'app/Pages.php hardcodes the domain');
        }
    },

    'the brand on the page is whatever config says it is' => function (): void {
        pgInit();
        $p = Pages::about(pgCfg(['site' => ['name' => 'Zephyr Register']]));
        assertContains('Zephyr Register', $p['body'], 'the configured name must reach the About page');
        assertNotContains('Fixture Gazette', $p['body'], 'no other name may leak in');
    },

    'a brand carrying markup is escaped, not executed' => function (): void {
        pgInit();
        $p = Pages::about(pgCfg(['site' => ['name' => '<script>alert(1)</script>&"\'']]));
        assertNotContains('<script>alert(1)</script>', $p['body'], 'the brand was written in raw');
        assertContains('&lt;script&gt;', $p['body'], 'the brand must arrive escaped');
    },

    'every contact address is on the site own domain' => function (): void {
        pgInit();
        $cfg = pgCfg(['site' => ['domain' => 'https://www.Example-News.COM/']]);
        foreach (pgAll($cfg) as $route => $p) {
            preg_match_all('/mailto:([^"\'\s>]+)/', $p['body'], $m);
            foreach ($m[1] as $addr) {
                // www., the scheme, the trailing slash and the case are all
                // stripped: a mailbox carries none of them.
                assertMatches(
                    '/^[a-z]+@example-news\.com$/',
                    $addr,
                    $route . ' has an address off the configured domain: ' . $addr
                );
            }
            assertGreaterThan(0, count($m[1]), $route . ' offers no way to write to us');
        }
    },

    'a blank domain in config falls back to the live host, never to nothing' => function (): void {
        pgInit();
        $p = Pages::contact(pgCfg(['site' => ['domain' => '']]));
        assertContains('mailto:', $p['body'], 'the contact page still needs a working address');
        assertNotContains('mailto:@', $p['body'], 'an address with no domain is broken');
        assertNotContains('@example.com', $p['body'], 'the last-resort domain must not be reached here');
    },

    // ------------------------------------------------------- contents rail

    'every standing page is a balanced tree that closes what it opens' => function (): void {
        pgInit();
        // A browser silently repairs a stray </div>, and so does DOMDocument, so
        // neither can be the instrument here: this walks the tags itself. It was
        // written after the contents rail shipped an extra </div> AFTER the
        // closing </section> — invisible on screen, wrong in the document.
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                 'link', 'meta', 'param', 'source', 'track', 'wbr'];

        foreach (pgAll(pgCfg()) as $route => $p) {
            preg_match_all('#<(/?)([a-z0-9]+)[^>]*?(/?)>#i', $p['body'], $m, PREG_SET_ORDER);
            $stack = [];
            foreach ($m as $tag) {
                $name = strtolower($tag[2]);
                if (in_array($name, $void, true) || $tag[3] === '/') {
                    continue;
                }
                if ($tag[1] === '') {
                    $stack[] = $name;
                    continue;
                }
                assertTrue($stack !== [], $route . ': </' . $name . '> closes nothing');
                assertSame(
                    array_pop($stack) ?? '',
                    $name,
                    $route . ': </' . $name . '> closed the wrong element'
                );
            }
            assertSame([], $stack, $route . ': left ' . implode(', ', $stack) . ' open');
        }
    },

    'the contents rail sits inside the page body, before the prose' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            $body = strpos($p['body'], '<div class="page-body">');
            $nav  = strpos($p['body'], '<nav class="page-toc"');
            $pro  = strpos($p['body'], '<div class="page-prose">');
            assertTrue($body !== false, $route . ' has no page body wrapper');
            assertTrue($body < $nav && $nav < $pro, $route . ': rail must open the body and precede the prose');
            // …and the whole thing closes before the section does, so the rail
            // cannot escape into the page.
            assertTrue(
                strrpos($p['body'], '</div>') < strrpos($p['body'], '</section>'),
                $route . ': a div closes after the section'
            );
        }
    },

    'the contents rail never points at an anchor that does not exist' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            preg_match_all('#<nav class="page-toc".*?</nav>#s', $p['body'], $navs);
            assertCount(1, $navs[0], $route . ' should have exactly one contents rail');

            preg_match_all('/href="#([a-z0-9-]+)"/', $navs[0][0], $links);
            assertGreaterThan(1, count($links[1]), $route . ' contents rail is empty');

            preg_match_all('/ id="([a-z0-9-]+)"/', $p['body'], $ids);
            foreach ($links[1] as $anchor) {
                assertContains($anchor, $ids[1], $route . ' contents rail points at a missing #' . $anchor);
            }
            // and every id is unique, or the browser jumps to the wrong one
            assertSame(count($ids[1]), count(array_unique($ids[1])), $route . ' has a duplicate id');
        }
    },

    // -------------------------------------------------------------- content

    'the About page says what the site is not' => function (): void {
        pgInit();
        $body = strtolower(Pages::about(pgCfg())['body']);
        assertContains('not the original publisher', $body, 'the About page must disclaim authorship');
        assertContains('aggregat', $body . ' ', 'the About page must say what this is');
        foreach (['licen', 'feed', 'link'] as $word) {
            assertContains($word, $body, 'the About page must explain ' . $word);
        }
    },

    'the standards page names all three Creative Commons licences it relies on' => function (): void {
        pgInit();
        $body = Pages::standards(pgCfg())['body'];
        foreach (['CC BY', 'BY-ND', 'BY-NC-ND'] as $lic) {
            assertContains($lic, $body, 'the standards page must name ' . $lic);
        }
        foreach (['NoDerivatives', 'NonCommercial'] as $term) {
            assertContains($term, $body, 'the standards page must explain ' . $term);
        }
        // Sentence case varies between the two mastheads; the fact does not.
        assertContains('public domain', strtolower($body), 'the standards page must explain public domain');
        // The three NC newsrooms must never be described as reproduced in full.
        foreach (['ProPublica', 'The Markup', 'The 19th'] as $nc) {
            assertContains($nc, $body, $nc . ' is NC-licensed and must be listed as such');
        }
        assertContains('within one working day', $body, 'a removal promise needs a timescale');
    },

    'the contact page offers a form and never claims a message was sent' => function (): void {
        pgInit();
        $body = Pages::contact(pgCfg())['body'];

        assertContains('<form', $body, 'there must be a contact form');
        foreach (['name="name"', 'name="email"', 'name="subject"', 'name="message"'] as $field) {
            assertContains($field, $body, 'the form is missing ' . $field);
        }
        assertContains('name="website"', $body, 'the form needs its honeypot');
        assertContains('method="post"', $body, 'a form carrying an email address must never be a GET');
        assertNotContains('method="get"', $body, 'GET would put the message in the URL and the logs');

        // The one thing this page must never do on a build with no handler.
        foreach (['thank you for your message', 'message sent', 'we have received', 'successfully sent'] as $lie) {
            assertNotContains($lie, strtolower($body), 'the contact page fakes a success message');
        }
    },

    'the developer is told in the source that the form posts nowhere' => function (): void {
        $src = (string) file_get_contents(teb_root() . '/app/Pages.php');
        assertMatches('/NO SERVER-SIDE HANDLER|HAS NO SERVER-SIDE HANDLER/i', $src, 'the note to the developer is missing');
        assertContains('privacy', strtolower($src), 'the note must point at the privacy page');
    },

    'the privacy page describes the storage this build actually uses' => function (): void {
        pgInit();
        $body = Pages::privacy(pgCfg())['body'];

        // The renderer really does write this key, so the policy must name it.
        $layout = (string) file_get_contents(teb_root() . '/app/Render.php');
        assertContains('localStorage', $layout, 'the pre-paint snippet should still use localStorage');
        assertContains('localStorage', $body, 'the policy must disclose localStorage');
        assertContains('theme', $body, 'the policy must name the key it stores');

        // Third-party requests the page genuinely makes.
        $css = (string) file_get_contents(teb_root() . '/assets/css/site.css');
        if (strpos($css, 'fonts.googleapis.com') !== false) {
            assertContains('fonts.googleapis.com', $body, 'the stylesheet calls Google Fonts; the policy must say so');
        }
        assertContains('referrerpolicy', $body, 'hot-linked images and their referrer policy must be disclosed');

        // The advertising clause has to be true in both states.
        assertMatches('/if (and when )?advertising is switched on|if advertising is switched on/i', $body, 'the ads clause must be conditional');
        assertContains('google.com/settings/ads', $body, 'the ads clause needs the opt-out link');
        assertMatches('/rewrit|revisit/i', $body, 'the policy must say it has to be revisited when ads go live');
    },

    'the terms page is honest about aggregation and third-party rights' => function (): void {
        pgInit();
        $body = strtolower(Pages::terms(pgCfg())['body']);
        foreach (['aggregat', 'copyright', 'licence', 'third-party', 'no warranty', 'liab'] as $term) {
            assertContains($term, $body, 'the terms page must cover ' . $term);
        }
        assertContains('removed', $body, 'the terms must state the publisher removal right');
    },


    'every class the standing pages emit actually exists in the stylesheet' => function (): void {
        pgInit();
        $css = (string) file_get_contents(teb_root() . '/assets/css/site.css');
        assertTrue($css !== '', 'assets/css/site.css is missing');

        $used = [];
        foreach (pgAll(pgCfg()) as $p) {
            preg_match_all('/\sclass="([^"]+)"/', $p['body'], $m);
            foreach ($m[1] as $group) {
                foreach (preg_split('/\s+/', trim($group)) as $class) {
                    if ($class !== '') {
                        $used[$class] = true;
                    }
                }
            }
        }
        assertGreaterThan(12, count($used), 'suspiciously few classes — did the parse work?');

        // Bounded on purpose: a plain substring search finds ".lic" inside
        // ".lic-name" and would pass for a class the stylesheet never defines.
        $missing = [];
        foreach (array_keys($used) as $class) {
            if (!preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css)) {
                $missing[] = $class;
            }
        }
        assertSame([], $missing, 'classes emitted but never styled: ' . implode(', ', $missing));
    },

    // ------------------------------------------------------------- wiring

    'all five standing pages are reachable and linked from the footer' => function (): void {
        pgInit();
        $cfg = pgCfg();
        $html = Render::layout([
            'cfg'   => $cfg,
            'title' => 'Front page',
            'body'  => '<p>x</p>',
            'route' => '/',
        ]);
        foreach (['/about', '/editorial-standards', '/contact', '/privacy', '/terms'] as $route) {
            assertContains('href="' . Render::esc(Paths::url($route)) . '"', $html, $route . ' is not linked from the chrome');
        }
    },

    'the structured data asserts nothing the site cannot back up' => function (): void {
        pgInit();
        foreach (pgAll(pgCfg()) as $route => $p) {
            assertArrayHasKey('jsonld', $p, $route . ' has no structured data');
            $j = $p['jsonld'];
            assertTrue(is_array($j) && $j !== [], $route . ' structured data is empty');
            $flat = strtolower(json_encode($j) ?: '');
            // No invented postal address, telephone number, founder or logo.
            foreach (['telephone', 'streetaddress', 'postaladdress', 'logo', 'founder', 'award'] as $claim) {
                assertNotContains($claim, $flat, $route . ' asserts a "' . $claim . '" that does not exist');
            }
            assertContains('fixture gazette', $flat, $route . ' should name the configured publisher');
        }
    },
];
