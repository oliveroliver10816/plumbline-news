<?php

declare(strict_types=1);

/**
 * Bootstrap — the single place where the application is assembled.
 *
 * Deliberately no autoloader: shared hosting is where clever autoloading goes to
 * die, and twelve explicit requires cost nothing and never surprise anyone.
 */

namespace TEB;

use PDO;
use Throwable;

if (!defined('TEB_ROOT')) {
    define('TEB_ROOT', dirname(__DIR__));
}

require_once TEB_ROOT . '/app/Config.php';
require_once TEB_ROOT . '/app/Paths.php';
require_once TEB_ROOT . '/app/Feeds.php';
require_once TEB_ROOT . '/app/Xml.php';
require_once TEB_ROOT . '/app/Images.php';
require_once TEB_ROOT . '/app/Placeholder.php';
require_once TEB_ROOT . '/app/Db.php';
require_once TEB_ROOT . '/app/Durable.php';
require_once TEB_ROOT . '/app/Auth.php';
require_once TEB_ROOT . '/app/Posts.php';
require_once TEB_ROOT . '/app/Media.php';
require_once TEB_ROOT . '/app/Studio.php';
require_once TEB_ROOT . '/app/Ingest.php';
require_once TEB_ROOT . '/app/Compose.php';
require_once TEB_ROOT . '/app/Render.php';
require_once TEB_ROOT . '/app/Seo.php';
require_once TEB_ROOT . '/app/Health.php';
require_once TEB_ROOT . '/app/Pages.php';
require_once TEB_ROOT . '/app/Rotate.php';
require_once TEB_ROOT . '/app/Router.php';

final class App
{
    /** @var array<string,mixed>|null */
    private static ?array $cfg = null;
    private static ?PDO $pdo = null;
    private static bool $booted = false;

    /**
     * Prepare config + paths. Safe to call from the web or from the CLI.
     *
     * @param array<string,mixed>|null $server
     * @return array<string,mixed>
     */
    public static function boot(?array $server = null): array
    {
        if (self::$booted) {
            return self::$cfg ?? [];
        }

        self::$cfg = Config::load(TEB_ROOT);

        $tz = (string) (self::$cfg['site']['timezone'] ?? 'America/New_York');
        // An invalid timezone in config must not take the site down.
        if (@timezone_open($tz) === false) {
            $tz = 'UTC';
        }
        date_default_timezone_set($tz);

        Paths::init($server ?? $_SERVER, TEB_ROOT);

        self::$booted = true;
        return self::$cfg;
    }

    /** @return array<string,mixed> */
    public static function config(): array
    {
        if (self::$cfg === null) {
            self::boot();
        }
        return self::$cfg ?? [];
    }

    /**
     * Connect and migrate. Migration is idempotent, so calling it per request is
     * safe; on SQLite it is a handful of "CREATE TABLE IF NOT EXISTS" statements.
     */
    public static function db(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $cfg = self::config();
        $pdo = Db::connect(is_array($cfg['db'] ?? null) ? $cfg['db'] : []);

        // Migration and the source refresh are WRITES. A read-only database —
        // a free plan that has hit its size cap and had INSERT revoked, a
        // failover, a full disk — must degrade to serving what is already
        // there, not take the whole site down with a 503. This exact failure
        // put the site off the air: JawsDB revoked INSERT at its 5 MB cap and
        // upsertSources() threw on every single request, including reads.
        try {
            Db::migrate($pdo);
        } catch (Throwable $e) {
            error_log('[teb] migrate skipped (database not writable): ' . $e->getMessage());
        }
        try {
            Posts::migrate($pdo);
        } catch (Throwable $e) {
            error_log('[teb] desk migrate skipped: ' . $e->getMessage());
        }
        try {
            Db::upsertSources($pdo, Feeds::all());
        } catch (Throwable $e) {
            error_log('[teb] source refresh skipped (database not writable): ' . $e->getMessage());
        }

        // The dyno's disk is wiped on restart, so an empty local store is the
        // normal state after a deploy — not an error. Refill it from the mirror
        // BEFORE anything reads, and keep the original article ids so no URL
        // that has been indexed or shared ever changes.
        try {
            Durable::ensureSchema($cfg);
            Durable::restore($pdo, $cfg);
            Durable::restoreDesk($pdo, $cfg);
        } catch (Throwable $e) {
            error_log('[teb] durable restore skipped: ' . $e->getMessage());
        }

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * First-run and staleness ingest.
     *
     * The client uploads a ZIP and opens the page; there is no cron yet and no
     * data. Rather than showing an empty site, pull a bounded set of feeds
     * inline — behind the lock, and never fatal. Cron then takes over.
     */
    public static function ensureContent(PDO $pdo, array $cfg): void
    {
        $ing = is_array($cfg['ingest'] ?? null) ? $cfg['ingest'] : [];
        if (empty($ing['enabled']) || empty($ing['auto_on_empty'])) {
            return;
        }

        $count = Db::countArticles($pdo);
        $staleAfter = max(1, (int) ($ing['stale_after_minutes'] ?? 20));
        $last = Db::lastIngestRun($pdo);
        $lastMs = is_array($last) ? (int) ($last['finished_at'] ?? 0) : 0;
        $ageMin = $lastMs > 0 ? (Db::nowMs() - $lastMs) / 60000 : PHP_INT_MAX;

        if ($count > 0 && $ageMin < $staleAfter) {
            return;
        }

        // Empty database: seed so that EVERY front-page section has something in
        // it. Seeding tier 1 alone looks fast but leaves the slower desks blank
        // on a fresh upload, because those publishers file a few times a day —
        // and the first thing anyone checks is the section that is empty. Take
        // the best few feeds per front-page section instead, which costs about
        // the same and produces a complete-looking page.
        //
        // ⚠ The desks are read from the registry, never typed here. An earlier
        // version carried a hardcoded list of section slugs; when the roster
        // changed, every name in it stopped matching, the seed silently shrank
        // to two feeds and a fresh install opened with one desk filled and the
        // rest empty — the exact failure this block exists to prevent. Ask
        // Feeds for the front page and the list can never drift again.
        $only = null;
        if ($count === 0) {
            $home   = Feeds::homeSections();
            $picked = [];
            foreach (array_values($home) as $i => $desk) {
                $section = (string) ($desk['slug'] ?? '');
                if ($section === '') {
                    continue;
                }
                // The two lead desks are the fast tier and carry the top of the
                // page, so they get the deeper seed.
                $want  = $i < 2 ? 3 : 2;
                $cands = array_values(array_filter(
                    Feeds::all(),
                    static fn(array $f): bool => ($f['section'] ?? '') === $section
                ));
                usort($cands, static function (array $a, array $b): int {
                    return ((int) ($a['tier'] ?? 3) <=> (int) ($b['tier'] ?? 3))
                        ?: ((float) ($b['weight'] ?? 1) <=> (float) ($a['weight'] ?? 1));
                });
                foreach (array_slice($cands, 0, $want) as $f) {
                    $picked[(string) $f['slug']] = $f;
                }
            }
            // A desk with no feed of its own contributes nothing above, so top
            // up from the whole roster rather than opening on a half-empty page.
            if (count($picked) < 6) {
                $rest = Feeds::all();
                usort($rest, static function (array $a, array $b): int {
                    return ((int) ($a['tier'] ?? 3) <=> (int) ($b['tier'] ?? 3))
                        ?: ((float) ($b['weight'] ?? 1) <=> (float) ($a['weight'] ?? 1));
                });
                foreach ($rest as $f) {
                    if (count($picked) >= 8) {
                        break;
                    }
                    $picked[(string) $f['slug']] = $f;
                }
            }
            // Empty would mean "no filter" to Ingest::run(), which would fetch
            // the whole roster inline on a first page view. null says the same
            // thing explicitly, and a non-empty list is the normal path.
            $only = $picked === [] ? null : array_values($picked);
        }

        // Heroku kills any request still open at 30 seconds (H12) and serves its
        // own 503. Filling an empty database from 18 full-text feeds takes longer
        // than that, so a cold boot with an empty mirror used to time out rather
        // than render. Never ingest more than a couple of feeds on a live request:
        // enough to put real stories on the page, then cron does the rest.
        if ($count === 0 && is_array($only)) {
            $only = array_slice($only, 0, 2);
        }

        $lock = Ingest::lock(Ingest::dataDir($cfg));
        if ($lock === null) {
            return; // another request is already doing it
        }
        try {
            Ingest::run($pdo, $cfg, $only);
        } catch (Throwable $e) {
            error_log('[teb] inline ingest failed: ' . $e->getMessage());
        } finally {
            Ingest::unlock();
        }
    }

    public static function reset(): void
    {
        self::$cfg = null;
        self::$pdo = null;
        self::$booted = false;
    }
}
