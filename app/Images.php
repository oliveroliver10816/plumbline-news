<?php

declare(strict_types=1);

namespace TEB;

/**
 * Publisher images, measured before we trust them.
 *
 * Feeds advertise an image without saying how big it is, and some of them hand
 * out a thumbnail meant for a sidebar. CBS News publishes every picture in its
 * RSS at 60x60; BBC News at 240x135. Rendered into a lead slot those become
 * unreadable mush — which is exactly what shipped, because nothing ever asked
 * the image how big it was.
 *
 * So: upgrade the URL where a publisher's CDN is known to serve a larger copy,
 * measure what actually comes back, store it, and refuse to place an image in a
 * slot it cannot fill. The design already has a proper text-only card; an
 * honest text card beats a blurred thumbnail every time.
 */
final class Images
{
    /**
     * Smallest intrinsic width worth putting in each card size. A picture that
     * would have to be stretched past roughly its own width is not used.
     */
    public const MIN_WIDTH = [
        'lead'   => 620,
        'large'  => 440,
        'medium' => 290,
        'small'  => 190,
        'text'   => 0,
    ];

    /** Under this, an image is no use in any slot on the site. */
    public const ABSOLUTE_MIN_WIDTH  = 190;
    public const ABSOLUTE_MIN_HEIGHT = 120;

    /** Most in-flight requests to any one publisher's CDN. */
    private const PER_HOST_CONCURRENCY = 2;

    /**
     * How much of a file we read to find its header.
     *
     * 64 KB is not enough: NPR ships 4032x3024 JPEGs whose SOF marker sits past
     * that, and they measured as unreadable. 256 KB covers every real case and
     * the transfer is aborted the moment the header is in hand anyway.
     */
    private const SNIFF_BYTES = 262144;

    /**
     * Rewrite a URL to a larger rendition where the publisher's CDN is KNOWN to
     * serve one. Every rule here was verified by fetching both sizes and
     * reading the real dimensions back — a guessed rule silently produces 404s
     * and an imageless site, so nothing goes in on assumption.
     */
    public static function upgradeUrl(string $url): string
    {
        if ($url === '') {
            return $url;
        }

        // BBC ichef, two path shapes, both verified by fetching each size back:
        //   /ace/standard/240/cpsprodpb/...   -> /ace/standard/1024/cpsprodpb/...
        //   /images/ic/240x135/xxxx.jpg       -> /images/ic/1024x576/xxxx.jpg
        // (240, 480, 800, 1024 and 1536 all return their true size.)
        if (stripos($url, 'ichef.bbci.co.uk') !== false) {
            if (strpos($url, '/cpsprodpb/') !== false) {
                $up = preg_replace('#/(\d{2,4})/cpsprodpb/#', '/1024/cpsprodpb/', $url, 1);
                if (is_string($up) && $up !== '') {
                    return $up;
                }
            }
            $up = preg_replace_callback(
                '#/images/ic/(\d{2,4})x(\d{2,4})/#',
                static function (array $m): string {
                    $w = (int) $m[1];
                    $h = (int) $m[2];
                    if ($w >= 1024 || $w <= 0 || $h <= 0) {
                        return $m[0];
                    }
                    $nh = (int) round($h * (1024 / $w));

                    return '/images/ic/1024x' . max(1, $nh) . '/';
                },
                $url,
                1
            );
            if (is_string($up) && $up !== '') {
                return $up;
            }
        }

        // NPR Brightspot: the resize segment is honoured as given, and the feed
        // asks for the original — 4032x3024 at 3.3 MB, hotlinked straight onto
        // the page. Capping it at 1200 wide returns the same picture at 573 KB,
        // a 5.8x saving, and makes it measurable too.
        //   /resize/4032x3024!/  ->  /resize/1200x900!/
        if (stripos($url, 'brightspotcdn.com') !== false) {
            $up = preg_replace_callback(
                '#/resize/(\d{3,5})x(\d{3,5})(!?)/#',
                static function (array $m): string {
                    $w = (int) $m[1];
                    $h = (int) $m[2];
                    if ($w <= 1200 || $w <= 0 || $h <= 0) {
                        return $m[0];                  // already sensible
                    }
                    $nh = (int) round($h * (1200 / $w));

                    return '/resize/1200x' . max(1, $nh) . $m[3] . '/';
                },
                $url,
                1
            );
            if (is_string($up) && $up !== '') {
                return $up;
            }
        }

        // Deliberately NOT handled:
        //  - CBS (assets*.cbsnewsstatic.com) publishes only /thumbnail/60x60/;
        //    every larger path 404s. Verified. Those images get dropped instead.
        //  - The Guardian (i.guim.co.uk) signs its width= parameter, so changing
        //    it returns 401. Its 700px default is fine as it is.
        return $url;
    }

    /**
     * Find the picture a publisher chose for an article, from the article page.
     *
     * Some feeds carry no image even though the story has one — The 19th, Grist
     * and Mother Jones all do this. The page itself always declares one, in the
     * `og:image` tag that exists precisely so other sites can show it when
     * linking: it is the same tag Facebook, Slack and iMessage read to build a
     * link preview, and it is the publisher's own choice of picture for that
     * exact story.
     *
     * ⚠ This is deliberately NOT a stock-photo search. Matching a headline to
     * some unrelated library photograph would put a picture of the wrong person,
     * place or event beside a news story — which is misleading whatever the
     * licence says, and on a story about real people it is worse than a
     * placeholder. Only the publisher's own image for that article is used.
     *
     * @param  array<int,string> $urls  article page URLs
     * @return array<string,string>     article url => image url
     */
    public static function discoverFromPages(array $urls, array $cfg, float $budgetSeconds = 25.0, int $concurrency = 6): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn($u): bool => is_string($u) && $u !== '')));
        if ($urls === [] || !function_exists('curl_multi_init')) {
            return [];
        }

        $ua       = self::userAgent($cfg);
        $deadline = microtime(true) + max(3.0, $budgetSeconds);
        $out      = [];
        $queue    = $urls;
        $mh       = curl_multi_init();
        $active   = [];

        $start = function () use (&$queue, &$active, $mh, $ua): bool {
            if ($queue === []) {
                return false;
            }
            $url = array_shift($queue);
            $ch  = curl_init($url);
            $buf = '';
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 4,
                CURLOPT_USERAGENT      => $ua,
                CURLOPT_ENCODING       => '',
                // The tags we want live in <head>, so a byte cap is enough — and
                // aborting the moment </head> appears made curl_multi drop the
                // handle before the buffer was readable. Cap only.
                CURLOPT_RANGE          => '0-262143',
                CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$buf): int {
                    $buf .= $chunk;
                    if (strlen($buf) >= 262144) {
                        return -1;
                    }
                    return strlen($chunk);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = ['url' => $url, 'ch' => $ch, 'buf' => &$buf];
            return true;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            if (!$start()) {
                break;
            }
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $key = (int) $ch;
                $rec = $active[$key] ?? null;
                if ($rec !== null) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $eff  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                    if (($code === 200 || $code === 206 || $code === 0) && $rec['buf'] !== '') {
                        $img = self::metaImage($rec['buf'], $eff !== '' ? $eff : $rec['url']);
                        if ($img !== '') {
                            $out[$rec['url']] = $img;
                        }
                    }
                    unset($active[$key]);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if (microtime(true) < $deadline) {
                    $start();
                }
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        } while ($running > 0 || $active !== []);

        foreach ($active as $rec) {
            curl_multi_remove_handle($mh, $rec['ch']);
            curl_close($rec['ch']);
        }
        curl_multi_close($mh);

        return $out;
    }

    /** Pull og:image / twitter:image out of a page head, resolved to absolute. */
    public static function metaImage(string $html, string $pageUrl = ''): string
    {
        $head = stripos($html, '</head>') !== false ? substr($html, 0, stripos($html, '</head>')) : $html;

        foreach (['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src'] as $prop) {
            $q = preg_quote($prop, '#');
            foreach ([
                '#<meta[^>]+(?:property|name)\s*=\s*["\']' . $q . '["\'][^>]*content\s*=\s*["\']([^"\']+)#i',
                '#<meta[^>]+content\s*=\s*["\']([^"\']+)["\'][^>]*(?:property|name)\s*=\s*["\']' . $q . '["\']#i',
            ] as $re) {
                if (preg_match($re, $head, $m) === 1) {
                    $u = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
                    $u = self::absolutise($u, $pageUrl);
                    if ($u !== '') {
                        return $u;
                    }
                }
            }
        }

        return '';
    }

    /** A meta tag may hold a protocol-relative or root-relative URL. */
    private static function absolutise(string $u, string $base): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        if (strpos($u, '//') === 0) {
            return 'https:' . $u;
        }
        if (preg_match('#^https?://#i', $u) === 1) {
            return $u;
        }
        $b = parse_url($base);
        if (!isset($b['scheme'], $b['host'])) {
            return '';
        }
        if (strpos($u, '/') === 0) {
            return $b['scheme'] . '://' . $b['host'] . $u;
        }

        return '';
    }

    /**
     * Measure a batch of image URLs concurrently.
     *
     * Returns [url => ['w'=>int,'h'=>int]] for those that could be read. A URL
     * missing from the result could not be measured — the caller decides what
     * that means; this never guesses a size.
     *
     * @param  array<int,string> $urls
     * @return array<string,array{w:int,h:int}>
     */
    public static function measure(array $urls, array $cfg, float $budgetSeconds = 20.0, int $concurrency = 10): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn($u): bool => is_string($u) && $u !== '')));
        if ($urls === [] || !function_exists('curl_multi_init')) {
            return [];
        }

        $ua       = self::userAgent($cfg);
        $timeout  = max(4, min(15, (int) ($cfg['ingest']['timeout_seconds'] ?? 12)));
        $deadline = microtime(true) + max(2.0, $budgetSeconds);
        $out      = [];
        $queue    = $urls;

        $mh      = curl_multi_init();
        $active  = [];   // handle-id => ['url'=>string, 'ch'=>resource, 'buf'=>string]

        // Requests in flight per host. UPI's CDN answers a burst with HTTP 429
        // (Cloudflare 1015) and then every later image from it fails to measure,
        // so no single publisher gets more than a couple of sockets at a time.
        $perHost = [];
        $hostOf  = static function (string $u): string {
            $h = parse_url($u, PHP_URL_HOST);

            return is_string($h) ? strtolower($h) : '';
        };

        $start = function () use (&$queue, &$active, &$perHost, $hostOf, $mh, $ua, $timeout): bool {
            if ($queue === []) {
                return false;
            }
            // Take the first queued URL whose host has spare capacity.
            $pick = null;
            foreach ($queue as $i => $candidate) {
                $h = $hostOf($candidate);
                if (($perHost[$h] ?? 0) < self::PER_HOST_CONCURRENCY) {
                    $pick = $i;
                    break;
                }
            }
            if ($pick === null) {
                return false;                  // every host is busy; try again shortly
            }
            $url = $queue[$pick];
            unset($queue[$pick]);
            $queue = array_values($queue);
            $perHost[$hostOf($url)] = ($perHost[$hostOf($url)] ?? 0) + 1;
            $ch  = curl_init($url);
            $buf = '';
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => $ua,
                // Publishers block hotlinks by referer; we send none, exactly as
                // the page does with referrerpolicy="no-referrer".
                CURLOPT_REFERER        => '',
                CURLOPT_RANGE          => '0-' . (self::SNIFF_BYTES - 1),
                CURLOPT_SSL_VERIFYPEER => true,
                // Stop the transfer as soon as we have the header bytes, so a
                // CDN that ignores Range cannot make us pull a 5 MB photo.
                CURLOPT_WRITEFUNCTION  => function ($c, string $chunk) use (&$buf): int {
                    $buf .= $chunk;
                    if (strlen($buf) >= self::SNIFF_BYTES) {
                        return -1;
                    }
                    return strlen($chunk);
                },
            ]);
            curl_multi_add_handle($mh, $ch);
            $active[(int) $ch] = ['url' => $url, 'ch' => $ch, 'buf' => &$buf];
            return true;
        };

        for ($i = 0; $i < $concurrency; $i++) {
            if (!$start()) {
                break;
            }
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.2);

            while (($info = curl_multi_info_read($mh)) !== false) {
                $ch  = $info['handle'];
                $key = (int) $ch;
                $rec = $active[$key] ?? null;
                if ($rec !== null) {
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    // 206 for a served Range, 200 for a CDN that ignored it.
                    if (($code === 200 || $code === 206) && $rec['buf'] !== '') {
                        $d = self::dimensionsFromBytes($rec['buf']);
                        if ($d !== null) {
                            $out[$rec['url']] = $d;
                        }
                    }
                    $h = $hostOf($rec['url']);
                    $perHost[$h] = max(0, ($perHost[$h] ?? 1) - 1);
                    unset($active[$key]);
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);

                if (microtime(true) < $deadline) {
                    $start();
                }
            }

            if (microtime(true) >= $deadline) {
                break;    // out of budget: report what we have, guess nothing
            }
        } while ($running > 0 || $active !== []);

        foreach ($active as $rec) {
            curl_multi_remove_handle($mh, $rec['ch']);
            curl_close($rec['ch']);
        }
        curl_multi_close($mh);

        return $out;
    }

    /**
     * Read width and height out of the first bytes of a file.
     *
     * getimagesize() wants a path, and a truncated file makes it warn, so the
     * bytes go to a temp file and warnings are suppressed. PNG, GIF, WebP and
     * BMP declare their size in the first few dozen bytes; JPEG needs its SOF
     * marker, which is inside 64 KB for every real-world photo.
     *
     * @return array{w:int,h:int}|null
     */
    public static function dimensionsFromBytes(string $bytes): ?array
    {
        if (strlen($bytes) < 24) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'teb_img_');
        if ($tmp === false) {
            return null;
        }
        try {
            if (@file_put_contents($tmp, $bytes) === false) {
                return null;
            }
            $info = @getimagesize($tmp);
            if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
                return null;
            }
            return ['w' => (int) $info[0], 'h' => (int) $info[1]];
        } finally {
            @unlink($tmp);
        }
    }

    /** Is this article's picture big enough for that card size? */
    public static function usable(array $a, string $size): bool
    {
        if ((string) ($a['image_url'] ?? '') === '') {
            return false;
        }
        $w = (int) ($a['image_width'] ?? 0);
        $h = (int) ($a['image_height'] ?? 0);

        // Never measured. We do not know it is good, so we do not gamble a lead
        // slot on it — but it is fine in the smallest card, where even a modest
        // thumbnail reads correctly.
        if ($w <= 0 || $h <= 0) {
            return $size === 'small';
        }

        if ($w < self::ABSOLUTE_MIN_WIDTH || $h < self::ABSOLUTE_MIN_HEIGHT) {
            return false;
        }

        return $w >= (self::MIN_WIDTH[$size] ?? self::MIN_WIDTH['medium']);
    }

    /** The largest card this picture can honestly fill. */
    public static function bestSize(int $w, int $h): string
    {
        if ($w < self::ABSOLUTE_MIN_WIDTH || $h < self::ABSOLUTE_MIN_HEIGHT) {
            return 'text';
        }
        foreach (['lead', 'large', 'medium', 'small'] as $size) {
            if ($w >= self::MIN_WIDTH[$size]) {
                return $size;
            }
        }
        return 'text';
    }

    /**
     * The User-Agent publishers see when we measure one of their pictures.
     *
     * ⚠ The fallback used to be a literal 'TheEveningBrief/1.0' — the brand of
     * the site this engine was copied from. Ingest is loaded in every normal
     * request so the fallback almost never fired, but when it did it told
     * ProPublica, NASA and everyone else on the roster that a different
     * masthead was fetching their images, and it put a brand name in app/ where
     * the rule is that the name lives in config.php and nowhere else. It is now
     * built from config exactly as Ingest builds it, and the last-resort string
     * carries no brand at all.
     */
    private static function userAgent(array $cfg): string
    {
        if (class_exists(__NAMESPACE__ . '\\Ingest') && method_exists(Ingest::class, 'userAgent')) {
            return Ingest::userAgent($cfg);
        }

        $site  = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name  = trim((string) ($site['short_name'] ?? '')) ?: trim((string) ($site['name'] ?? ''));
        $token = (string) preg_replace('/[^A-Za-z0-9]+/', '', $name);
        $token = $token === '' ? 'NewsAggregator' : substr($token, 0, 40);

        return 'Mozilla/5.0 (compatible; ' . $token . 'Bot/1.0; image measurement) PHP/'
            . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
