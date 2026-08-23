<?php
declare(strict_types=1);

namespace TEB;

/**
 * Configuration loader.
 *
 * The client's config.php is merged over the defaults below, so a config file
 * that is missing a key — or missing entirely, or half edited — still produces
 * a complete, usable configuration instead of a blank page. Everything that
 * comes back out of here has been range-checked and type-coerced, which means
 * no other module has to defend itself against `'2'` where it expected `2` or
 * against a timezone the client typed from memory.
 *
 * ⚠ There is deliberately NO brand name and NO domain in this file. The brand
 * lives in config.php and nowhere else; tests/test_config.php greps every file
 * under app/ to prove it. The defaults here are generic on purpose.
 */
final class Config
{
    /**
     * Baseline configuration. Every key the application reads exists here with
     * a working value, so config.php only ever has to state what differs.
     */
    private const DEFAULTS = [
        'site' => [
            'name'        => 'News',
            'short_name'  => 'News',
            'domain'      => '',
            'tagline'     => '',
            'description' => '',
            // The city an edition is dated from. Masthead furniture only —
            // there is no weather desk on this site and nothing here is
            // fetched from anywhere. Empty simply prints no dateline city.
            'city'        => '',
            'timezone'    => 'UTC',
            'locale'      => 'en_US',
            'theme_color' => '#FBFAF7',
        ],
        'db' => [
            'driver'      => 'sqlite',
            'sqlite_path' => 'data/news.sqlite',
            'host'        => 'localhost',
            'port'        => 3306,
            'name'        => '',
            'user'        => '',
            'pass'        => '',
            'charset'     => 'utf8mb4',
        ],
        'ingest' => [
            'enabled'             => true,
            'auto_on_empty'       => true,
            'stale_after_minutes' => 20,
            'token'               => '',
            'timeout_seconds'     => 12,
            'batch'               => 14,
            'retention_days'      => 30,
        ],
        // ⚠ THESE ARE THE FALLBACK, AND THE FALLBACK HAS TO BE THIS EDITION'S
        // POLICY, not a generic one. They are what config.php degrades to when a
        // line there is deleted or mistyped, and finance_blocked_blocks is the
        // one guard rail the client asked for by name. It used to fall back to
        // ['hero', 'us', 'international'] — desks this edition does not have —
        // so a single typo in config.php quietly left every desk but the hero
        // open to money stories. They now mirror the shipped config.php.
        'compose' => [
            'finance_max_on_home'      => 1,
            'finance_blocked_blocks'   => ['hero', 'politics', 'environment', 'education'],
            'hero_sub_count'           => 4,
            'per_source_cap_per_block' => 3,
            'ticker_count'             => 14,
        ],
        'ads' => [
            'enabled' => false,
            'slots'   => [
                'leaderboard' => [970, 250],
                'rail'        => [300, 600],
                'inline'      => [728, 90],
            ],
        ],
        'cache' => [
            'home_seconds'    => 120,
            'section_seconds' => 300,
            'article_seconds' => 900,
        ],
    ];

    /**
     * Keys whose sub-arrays are REPLACED wholesale by config.php rather than
     * merged into. The ad slots are a list the client curates: if they delete
     * a slot it has to stay deleted, and a deep merge would quietly resurrect
     * it.
     */
    private const REPLACE_PATHS = ['ads.slots'];

    /** @var array<string,mixed>|null */
    private static ?array $config = null;

    private static string $rootDir = '';

    /**
     * Read config.php out of $rootDir, merge it over the defaults, sanitise the
     * result and remember it for get(). Safe to call more than once — the last
     * call wins, which is what the tests rely on.
     *
     * @return array<string,mixed>
     */
    public static function load(string $rootDir): array
    {
        $rootDir       = rtrim(str_replace('\\', '/', $rootDir), '/');
        self::$rootDir = $rootDir === '' ? '.' : $rootDir;

        $file = self::$rootDir . '/config.php';
        $user = [];

        if (is_file($file) && is_readable($file)) {
            // A config.php with a PHP syntax error is a fatal the language will
            // not let anyone catch; everything short of that is handled.
            $loaded = require $file;
            if (is_array($loaded)) {
                $user = $loaded;
            }
        }

        self::$config = self::sanitise(self::merge(self::DEFAULTS, $user), self::$rootDir);

        // Doing this here means every module — renderer, feeds, cron, sitemap —
        // agrees on what "today" and "6:42 p.m." mean, without each of them
        // having to remember to set it.
        @date_default_timezone_set((string) self::$config['site']['timezone']);

        return self::$config;
    }

    /**
     * Read one value with a dotted path: get('site.name'), get('ads.slots.rail'),
     * get('compose.finance_max_on_home'). Returns $default when the path does
     * not exist. Never throws, and works before load() by falling back to the
     * defaults, so a helper that reads config can be called from anywhere.
     *
     * @param  mixed $default
     * @return mixed
     */
    public static function get(string $dotPath, $default = null)
    {
        $node = self::$config ?? self::DEFAULTS;

        if ($dotPath === '') {
            return $node;
        }

        foreach (explode('.', $dotPath) as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                return $default;
            }
            $node = $node[$key];
        }

        return $node;
    }

    /** The whole configuration array. Loads defaults if load() has not run. */
    public static function all(): array
    {
        return self::$config ?? self::sanitise(self::DEFAULTS, self::$rootDir ?: '.');
    }

    /** True once load() has run. */
    public static function isLoaded(): bool
    {
        return self::$config !== null;
    }

    /** The directory load() was pointed at — the folder holding config.php. */
    public static function rootDir(): string
    {
        return self::$rootDir !== '' ? self::$rootDir : dirname(__DIR__);
    }

    /** Forget everything. Tests use this between fixtures. */
    public static function reset(): void
    {
        self::$config  = null;
        self::$rootDir = '';
    }

    /**
     * Recursive merge of $override onto $base.
     *
     * Associative arrays merge key by key. Lists (0,1,2… keys) replace outright
     * — a client who writes three sections means three, not three appended to
     * ours. Paths named in REPLACE_PATHS replace even though they are
     * associative.
     */
    private static function merge(array $base, array $override, string $path = ''): array
    {
        foreach ($override as $key => $value) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;

            // A scalar written where a whole section belongs — 'site' => 'The
            // Wire', 'compose' => 7, 'ads' => false — is a typo, not an
            // instruction. Taking it literally used to fatal on the next line
            // that indexed into it ("Cannot use a scalar value as an array",
            // "Cannot access offset of type string on string"), which is a
            // blank page on every URL, from the one file the client edits by
            // hand. Keep the shipped default instead: the same policy every
            // other unusable value here already follows.
            if (isset($base[$key]) && is_array($base[$key]) && !is_array($value)) {
                continue;
            }

            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !self::isList($value)
                && !in_array($here, self::REPLACE_PATHS, true)
            ) {
                $base[$key] = self::merge($base[$key], $value, $here);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /** PHP 8.0 has no array_is_list(). */
    private static function isList(array $a): bool
    {
        if ($a === []) {
            return true;
        }

        return array_keys($a) === range(0, count($a) - 1);
    }

    /**
     * Coerce types and clamp ranges. Everything downstream can then trust that
     * an int is an int and that a count is inside a sane band, however the
     * client typed it.
     */
    private static function sanitise(array $c, string $rootDir): array
    {
        // Every branch below indexes straight into these seven sections, so
        // each one has to BE a section before we start. merge() already keeps
        // a scalar from replacing an array; this is the second lock on the
        // same door, and it is what makes sanitise() total — it cannot be
        // handed a shape it fatals on.
        foreach (self::DEFAULTS as $section => $shipped) {
            if (!isset($c[$section]) || !is_array($c[$section])) {
                $c[$section] = $shipped;
            }
        }

        // ---- site -----------------------------------------------------------
        foreach (['name', 'short_name', 'tagline', 'description', 'city', 'locale', 'theme_color'] as $k) {
            $c['site'][$k] = self::str($c['site'][$k] ?? '');
        }
        $c['site']['name'] = $c['site']['name'] !== '' ? $c['site']['name'] : self::DEFAULTS['site']['name'];

        // A config.php that renames the site but says nothing about the short
        // name must not inherit the placeholder from the defaults — derive the
        // initials instead ('The Morning Wire' -> 'MW').
        if ($c['site']['short_name'] === '' || $c['site']['short_name'] === self::DEFAULTS['site']['short_name']) {
            $c['site']['short_name'] = self::initials($c['site']['name']);
        }

        // A domain typed as a URL, or with a trailing slash, or with www — all
        // reduced to a bare host. Empty is legitimate: absolute() then relies
        // entirely on the request, which is the better source anyway.
        $c['site']['domain'] = self::host(self::str($c['site']['domain'] ?? ''));

        $tz = self::str($c['site']['timezone'] ?? '');
        if ($tz === '' || !in_array($tz, \timezone_identifiers_list(), true)) {
            $tz = self::DEFAULTS['site']['timezone'];
        }
        $c['site']['timezone'] = $tz;

        if ($c['site']['locale'] === '') {
            $c['site']['locale'] = self::DEFAULTS['site']['locale'];
        }
        if (!preg_match('/^#[0-9A-Fa-f]{3,8}$/', $c['site']['theme_color'])) {
            $c['site']['theme_color'] = self::DEFAULTS['site']['theme_color'];
        }

        // ---- db -------------------------------------------------------------
        $driver = strtolower(self::str($c['db']['driver'] ?? 'sqlite'));
        $c['db']['driver'] = in_array($driver, ['sqlite', 'mysql'], true) ? $driver : 'sqlite';

        $sqlitePath = self::str($c['db']['sqlite_path'] ?? '');
        if ($sqlitePath === '') {
            $sqlitePath = self::DEFAULTS['db']['sqlite_path'];
        }
        // Resolved against the project root, never against the current working
        // directory: cron runs from somewhere else entirely and must open the
        // same file the web request does.
        if (!self::isAbsolutePath($sqlitePath)) {
            $sqlitePath = $rootDir . '/' . ltrim($sqlitePath, '/');
        }
        $c['db']['sqlite_path'] = $sqlitePath;

        $c['db']['port']    = self::int($c['db']['port'] ?? 3306, 1, 65535, 3306);
        $c['db']['charset'] = self::str($c['db']['charset'] ?? '') ?: 'utf8mb4';
        foreach (['host', 'name', 'user', 'pass'] as $k) {
            $c['db'][$k] = self::str($c['db'][$k] ?? '');
        }
        // MySQL selected but not filled in would fail on every page. Fall back
        // to the file database, which always works, rather than to a 500.
        if ($c['db']['driver'] === 'mysql' && ($c['db']['name'] === '' || $c['db']['user'] === '')) {
            $c['db']['driver'] = 'sqlite';
        }

        // ---- ingest ---------------------------------------------------------
        $c['ingest']['enabled']             = self::bool($c['ingest']['enabled'] ?? true);
        $c['ingest']['auto_on_empty']       = self::bool($c['ingest']['auto_on_empty'] ?? true);
        $c['ingest']['stale_after_minutes'] = self::int($c['ingest']['stale_after_minutes'] ?? 20, 1, 10080, 20);
        $c['ingest']['token']               = self::str($c['ingest']['token'] ?? '');
        $c['ingest']['timeout_seconds']     = self::int($c['ingest']['timeout_seconds'] ?? 12, 1, 120, 12);
        $c['ingest']['batch']               = self::int($c['ingest']['batch'] ?? 14, 1, 200, 14);
        $c['ingest']['retention_days']      = self::int($c['ingest']['retention_days'] ?? 30, 1, 3650, 30);

        // ---- compose --------------------------------------------------------
        $c['compose']['finance_max_on_home']      = self::int($c['compose']['finance_max_on_home'] ?? 1, 0, 100, 1);
        $c['compose']['hero_sub_count']           = self::int($c['compose']['hero_sub_count'] ?? 4, 0, 12, 4);
        $c['compose']['per_source_cap_per_block'] = self::int($c['compose']['per_source_cap_per_block'] ?? 3, 1, 50, 3);
        $c['compose']['ticker_count']             = self::int($c['compose']['ticker_count'] ?? 14, 0, 60, 14);

        $blocked = $c['compose']['finance_blocked_blocks'] ?? [];
        if (!is_array($blocked)) {
            $blocked = [];
        }
        $c['compose']['finance_blocked_blocks'] = array_values(array_unique(array_filter(
            array_map(static fn ($b): string => strtolower(self::str($b)), $blocked),
            static fn (string $b): bool => $b !== ''
        )));

        // ---- ads ------------------------------------------------------------
        $c['ads']['enabled'] = self::bool($c['ads']['enabled'] ?? false);
        $slots = is_array($c['ads']['slots'] ?? null) ? $c['ads']['slots'] : [];
        $clean = [];
        foreach ($slots as $name => $wh) {
            $name = self::str($name);
            if ($name === '' || !is_array($wh)) {
                continue;
            }
            $w = self::int($wh[0] ?? ($wh['width'] ?? 0), 1, 4000, 0);
            $h = self::int($wh[1] ?? ($wh['height'] ?? 0), 1, 4000, 0);
            if ($w > 0 && $h > 0) {
                $clean[$name] = [$w, $h];
            }
        }
        $c['ads']['slots'] = $clean !== [] ? $clean : self::DEFAULTS['ads']['slots'];

        // ---- cache ----------------------------------------------------------
        foreach (['home_seconds' => 120, 'section_seconds' => 300, 'article_seconds' => 900] as $k => $d) {
            $c['cache'][$k] = self::int($c['cache'][$k] ?? $d, 0, 86400, $d);
        }

        return $c;
    }

    /** @param mixed $v */
    private static function str($v): string
    {
        if (is_string($v)) {
            return trim($v);
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_bool($v)) {
            return $v ? '1' : '';
        }

        return '';
    }

    /** @param mixed $v */
    private static function int($v, int $min, int $max, int $fallback): int
    {
        if (is_bool($v) || (!is_int($v) && !is_float($v) && !(is_string($v) && preg_match('/^-?\d+$/', trim($v))))) {
            return $fallback;
        }
        $n = (int) $v;

        return max($min, min($max, $n));
    }

    /** Accepts true/false, 1/0, "1"/"0", "true"/"false", "yes"/"no", "on"/"off". @param mixed $v */
    private static function bool($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v === 1;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /** 'https://Www.Example.com/news/' -> 'example.com' */
    private static function host(string $v): string
    {
        $v = trim($v);
        if ($v === '') {
            return '';
        }
        if (strpos($v, '//') !== false) {
            $v = (string) (parse_url($v, PHP_URL_HOST) ?? $v);
        }
        $v = strtolower(trim($v, "/ \t\n\r\0\x0B"));
        $v = preg_replace('#[/?].*$#', '', $v) ?? $v;
        if (strpos($v, 'www.') === 0) {
            $v = substr($v, 4);
        }

        return preg_match('/^[a-z0-9.\-]+(:\d{1,5})?$/', $v) === 1 ? $v : '';
    }

    /** 'The Morning Wire' -> 'MW'; 'Gazette' -> 'Gazette'. */
    private static function initials(string $name): string
    {
        $skip  = ['the', 'a', 'an', 'of', 'and', 'for', 'de', 'la', 'le'];
        $words = preg_split('/[\s\-_]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter(
            $words,
            static fn (string $w): bool => !in_array(mb_strtolower($w), $skip, true) && preg_match('/\p{L}/u', $w) === 1
        ));

        if (count($words) < 2) {
            return $name;
        }

        $out = '';
        foreach (array_slice($words, 0, 4) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }

        return $out !== '' ? $out : $name;
    }

    private static function slug(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v) ?? '';

        return trim($v, '-');
    }

    private static function isAbsolutePath(string $p): bool
    {
        return $p !== '' && ($p[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1);
    }
}
