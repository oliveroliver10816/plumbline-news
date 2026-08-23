<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Storage layer — SQLite by default, MySQL by config, ONE logical schema either way.
 *
 * Design rules this file is built on, all of which have bitten a shared-hosting build before:
 *
 *  - Every statement is a prepared statement with bound parameters. Nothing is interpolated
 *    into SQL except identifiers that come from this file's own constants (table names,
 *    column names, IN() placeholder runs) — never a value, never a search term, never a LIMIT.
 *  - LIKE patterns escape the wildcards `%` and `_` in the BOUND VALUE and declare
 *    ESCAPE '!' in the SQL, so searching for "100%" or "foo_bar" means those literals.
 *  - SQLite gets WAL + a multi-second busy_timeout and every batch write runs inside one
 *    transaction. Without those three, two visitors landing during an inline ingest produce
 *    "database is locked" and the front page 500s.
 *  - Times are INTEGER milliseconds since the epoch, everywhere, both drivers.
 *  - migrate() is idempotent AND upgrade-safe: it creates what is missing (tables, columns,
 *    indexes) and never drops or rewrites what exists.
 *
 * There is exactly one source of truth for the schema — self::schema(). The driver only
 * decides how a type name and an auto-increment primary key are spelled.
 */
final class Db
{
    public const DRIVER_SQLITE = 'sqlite';
    public const DRIVER_MYSQL  = 'mysql';
    public const DRIVER_PGSQL  = 'pgsql';

    /** Soft dedup only looks this far back: an AP story resyndicated a week later is new. */
    public const SOFT_DEDUP_HOURS = 36;

    /** Bound-value escape character for LIKE. Declared as ESCAPE '!' in the SQL. */
    private const LIKE_ESCAPE = '!';

    /** Bind chunk for IN() lookups — SQLite's default variable ceiling is 999. */
    private const IN_CHUNK = 180;

    /** Anything older than this is a feed with a broken clock, not a real publication date. */
    private const MIN_PLAUSIBLE_MS = 946684800000; // 2000-01-01T00:00:00Z

    /** Query params dropped by canonicalUrl(). `utm_` is matched as a PREFIX. */
    private const STRIP_PARAM_PREFIXES = ['utm_'];
    private const STRIP_PARAMS         = ['fbclid', 'gclid', 'mc_cid', 'ref'];

    /** Dropped by titleKey() before the first 8 tokens are taken. Deliberately small. */
    private const STOPWORDS = [
        'a' => 1, 'an' => 1, 'and' => 1, 'are' => 1, 'as' => 1, 'at' => 1, 'be' => 1,
        'but' => 1, 'by' => 1, 'for' => 1, 'from' => 1, 'has' => 1, 'have' => 1, 'he' => 1,
        'her' => 1, 'his' => 1, 'in' => 1, 'is' => 1, 'it' => 1, 'its' => 1, 'of' => 1,
        'on' => 1, 'or' => 1, 'she' => 1, 'that' => 1, 'the' => 1, 'their' => 1, 'they' => 1,
        'this' => 1, 'to' => 1, 'was' => 1, 'were' => 1, 'will' => 1, 'with' => 1,
    ];

    /**
     * Columns returned for every article read. LEFT JOIN so a row whose source row was
     * deleted still renders: the denormalised source_slug/source_name on the article are
     * the fallback, and a missing source weight defaults to 1.0 rather than NULL.
     */
    private const ARTICLE_COLS =
        'a.id, a.source_id, '
        . "COALESCE(NULLIF(s.slug, ''), a.source_slug) AS source_slug, "
        . "COALESCE(NULLIF(s.name, ''), a.source_name) AS source_name, "
        . 'COALESCE(s.weight, 1.0) AS source_weight, '
        . 'COALESCE(s.tier, 3) AS source_tier, '
        . "COALESCE(NULLIF(s.homepage, ''), '') AS source_homepage, "
        . 'a.section, a.url, a.title, a.title_key, a.summary, a.image_url, '
        . 'a.image_width, a.image_height, a.author, a.published_at, a.fetched_at, a.guid_hash, a.body';

    // ---------------------------------------------------------------- connection

    /**
     * Open the database.
     *
     * Accepts either the whole config array (with a 'db' key) or just the db sub-array,
     * because both callers exist in this codebase and guessing wrong is a 500 on upload.
     *
     * SQLite: data/ is created if missing (plus a deny-all .htaccess inside it, since a
     * runtime-created directory would not otherwise carry one), WAL is enabled and a
     * busy_timeout is set. MySQL: utf8mb4, real prepared statements, exceptions on.
     */
    public static function connect(array $cfg): PDO
    {
        $db     = isset($cfg['db']) && is_array($cfg['db']) ? $cfg['db'] : $cfg;
        $driver = strtolower((string) ($db['driver'] ?? self::DRIVER_SQLITE));
        $root   = (string) ($cfg['root'] ?? $db['root'] ?? dirname(__DIR__));

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        if ($driver === self::DRIVER_MYSQL) {
            $host    = (string) ($db['host'] ?? '127.0.0.1');
            $port    = (int) ($db['port'] ?? 3306);
            $name    = (string) ($db['name'] ?? '');
            // utf8mb4 is not optional: publisher headlines carry emoji and a 3-byte
            // "utf8" column throws on the first one.
            $charset = (string) ($db['charset'] ?? 'utf8mb4');
            if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $charset)) {
                $charset = 'utf8mb4';
            }
            if ($name === '') {
                throw new RuntimeException('MySQL selected but db.name is empty in config.php');
            }
            $dsn  = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);
            $opts = $options + [PDO::ATTR_TIMEOUT => (int) ($db['timeout'] ?? 5)];

            return new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), $opts);
        }

        if ($driver === self::DRIVER_PGSQL) {
            $host = (string) ($db['host'] ?? '127.0.0.1');
            $port = (int) ($db['port'] ?? 5432);
            $name = (string) ($db['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('PostgreSQL selected but db.name is empty in config.php');
            }
            // Managed Postgres (Heroku, RDS, Neon) requires TLS and presents a cert
            // signed by a root the dyno does not carry, so verification is off but
            // the transport is still encrypted.
            $ssl = (string) ($db['sslmode'] ?? 'require');
            if (!preg_match('/^[a-z-]{4,12}$/', $ssl)) {
                $ssl = 'require';
            }
            $dsn  = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $name, $ssl);
            $opts = $options + [PDO::ATTR_TIMEOUT => (int) ($db['timeout'] ?? 5)];

            return new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), $opts);
        }

        if ($driver !== self::DRIVER_SQLITE) {
            throw new RuntimeException('Unsupported db.driver "' . $driver . '" — use sqlite, mysql or pgsql');
        }

        $path = (string) ($db['sqlite_path'] ?? 'data/teb.sqlite');
        $path = self::absolutePath($path, $root);
        self::ensureDataDir(dirname($path));

        $pdo = new PDO('sqlite:' . $path, null, null, $options + [PDO::ATTR_TIMEOUT => 8]);

        // WAL lets a reader and the ingest writer coexist; on hosts where the filesystem
        // cannot support it (some NFS mounts) SQLite silently keeps the rollback journal,
        // which is why this is best-effort and not an exception.
        try {
            $pdo->exec('PRAGMA busy_timeout = 8000');
            // Only takes effect on a database with no tables yet — i.e. a fresh upload —
            // which is exactly when it can be set. It is what lets pruneOld() give space
            // back to a host with a small quota instead of only marking pages free.
            $pdo->exec('PRAGMA auto_vacuum = INCREMENTAL');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            // A host that refuses a PRAGMA still gets a working (slower) database.
        }

        return $pdo;
    }

    public static function driver(PDO $p): string
    {
        return (string) $p->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** Milliseconds since the epoch — the one time unit this schema stores. */
    public static function nowMs(): int
    {
        return (int) round(microtime(true) * 1000);
    }

    private static function absolutePath(string $path, string $root): string
    {
        if ($path === '') {
            $path = 'data/teb.sqlite';
        }
        $isAbs = $path[0] === '/' || $path[0] === '\\' || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;

        return $isAbs ? $path : rtrim($root, '/\\') . '/' . ltrim($path, '/\\');
    }

    private static function ensureDataDir(string $dir): void
    {
        if (!is_dir($dir)) {
            // 0777 & umask — shared hosting runs PHP as a different user than FTP more often
            // than not, and a 0755 data/ owned by the wrong uid is the classic first-upload 500.
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create data directory: ' . $dir);
            }
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }
        if (!is_writable($dir)) {
            throw new RuntimeException('Data directory is not writable: ' . $dir . ' (chmod it to 777)');
        }
        // A data/ created at runtime ships no .htaccess, so the database would be web-readable.
        $ht = $dir . '/.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents(
                $ht,
                "# Nothing in here is ever served over HTTP: SQLite database, cache, lock files.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }
        if (!file_exists($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '');
        }
    }

    // ---------------------------------------------------------------- schema

    /**
     * THE source of truth for the schema. Types are abstract; sqlType() spells them per driver.
     *
     * type      sqlite                                  mysql
     * pk        INTEGER PRIMARY KEY AUTOINCREMENT       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
     * int       INTEGER                                 INT
     * bigint    INTEGER (ms epoch)                      BIGINT
     * real      REAL                                    DOUBLE
     * char:n    TEXT                                    CHAR(n)
     * varchar:n TEXT                                    VARCHAR(n)
     * text      TEXT                                    TEXT
     *
     * varchar lengths are chosen so every indexed column fits MySQL's 767-byte legacy index
     * limit under utf8mb4 (191 chars) — title_key is indexed, so it is varchar:191.
     *
     * @return array<string,array{columns:array<int,array{0:string,1:string,2?:string}>,indexes:array<string,array{0:bool,1:array<int,string>}>}>
     */
    private static function schema(): array
    {
        return [
            'sources' => [
                'columns' => [
                    ['id', 'pk'],
                    ['slug', 'varchar:64', "''"],
                    ['name', 'varchar:120', "''"],
                    ['feed_url', 'varchar:500', "''"],
                    ['homepage', 'varchar:500', "''"],
                    ['section', 'varchar:32', "''"],
                    ['country', 'varchar:8', "''"],
                    ['tier', 'int', '3'],
                    ['weight', 'real', '1'],
                    ['active', 'int', '1'],
                    ['fail_count', 'int', '0'],
                    ['last_error', 'varchar:500', "''"],
                    ['last_fetch_at', 'bigint', '0'],
                    ['last_ok_at', 'bigint', '0'],
                    ['created_at', 'bigint', '0'],
                    ['updated_at', 'bigint', '0'],
                ],
                'indexes' => [
                    'ux_sources_slug' => [true, ['slug']],
                ],
            ],
            'articles' => [
                'columns' => [
                    ['id', 'pk'],
                    ['source_id', 'int', '0'],
                    ['source_slug', 'varchar:64', "''"],
                    ['source_name', 'varchar:120', "''"],
                    ['section', 'varchar:32', "''"],
                    ['guid', 'varchar:500', "''"],
                    ['guid_hash', 'char:64', "''"],
                    ['url', 'varchar:1000', "''"],
                    ['title', 'varchar:400', "''"],
                    ['title_key', 'varchar:191', "''"],
                    ['summary', 'text'],
                    // The fullest text the feed itself supplies. Never scraped.
                    ['body', 'text'],
                    ['image_url', 'varchar:1000', "''"],
                    ['image_width', 'int', '0'],
                    ['image_height', 'int', '0'],
                    // How many times we have tried and failed to measure the
                    // image. A CDN that rate-limits us must not be re-hammered
                    // on every hourly pass forever.
                    ['image_tries', 'int', '0'],
                    ['author', 'varchar:160', "''"],
                    ['published_at', 'bigint', '0'],
                    ['fetched_at', 'bigint', '0'],
                ],
                'indexes' => [
                    // Hard dedup. The UNIQUE index is the guarantee; the pre-check in
                    // insertArticles is only an optimisation on top of it.
                    'ux_articles_guid_hash'   => [true, ['guid_hash']],
                    // Section index pages and every composed block.
                    'ix_articles_section_pub' => [false, ['section', 'published_at DESC']],
                    // Front page, ticker, feed, sitemap.
                    'ix_articles_pub'         => [false, ['published_at DESC']],
                    // Soft dedup lookup on ingest.
                    'ix_articles_title_key'   => [false, ['title_key']],
                    // Per-source pages, health, and the cap Compose applies.
                    'ix_articles_source'      => [false, ['source_id']],
                ],
            ],
            'ingest_runs' => [
                'columns' => [
                    ['id', 'pk'],
                    ['started_at', 'bigint', '0'],
                    ['finished_at', 'bigint', '0'],
                    ['run_mode', 'varchar:16', "''"],   // cron | cli | inline | token
                    ['feeds_ok', 'int', '0'],
                    ['feeds_failed', 'int', '0'],
                    ['inserted', 'int', '0'],
                    ['skipped', 'int', '0'],
                    ['errors', 'text'],
                    ['notes', 'varchar:500', "''"],
                ],
                'indexes' => [
                    'ix_runs_started' => [false, ['started_at DESC']],
                ],
            ],
        ];
    }

    private static function sqlType(string $abstract, string $driver): string
    {
        $len = 0;
        if (str_contains($abstract, ':')) {
            [$abstract, $lenStr] = explode(':', $abstract, 2);
            $len = (int) $lenStr;
        }
        $mysql = $driver === self::DRIVER_MYSQL;
        $pg    = $driver === self::DRIVER_PGSQL;

        switch ($abstract) {
            case 'pk':
                if ($pg) {
                    return 'BIGSERIAL PRIMARY KEY';
                }
                return $mysql
                    ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
                    : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            case 'int':
                return ($mysql || $pg) ? 'INTEGER NOT NULL' : 'INTEGER NOT NULL';
            case 'bigint':
                return ($mysql || $pg) ? 'BIGINT NOT NULL' : 'INTEGER NOT NULL';
            case 'real':
                if ($pg) { return 'DOUBLE PRECISION NOT NULL'; }
                return $mysql ? 'DOUBLE NOT NULL' : 'REAL NOT NULL';
            case 'char':
                if ($pg) { return 'CHAR(' . $len . ') NOT NULL'; }
                return $mysql ? 'CHAR(' . $len . ') NOT NULL' : 'TEXT NOT NULL';
            case 'varchar':
                if ($pg) { return 'VARCHAR(' . $len . ') NOT NULL'; }
                return $mysql ? 'VARCHAR(' . $len . ') NOT NULL' : 'TEXT NOT NULL';
            case 'text':
                // MySQL cannot give a TEXT column a DEFAULT, so it is the one nullable type.
                return 'TEXT NULL';
            default:
                throw new RuntimeException('Unknown abstract column type: ' . $abstract);
        }
    }

    private static function quoteIdent(string $ident, string $driver): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $ident) !== 1) {
            throw new RuntimeException('Refusing to quote suspicious identifier: ' . $ident);
        }

        // MySQL backticks; SQLite and PostgreSQL both use double quotes.
        return $driver === self::DRIVER_MYSQL ? '`' . $ident . '`' : '"' . $ident . '"';
    }

    /**
     * Idempotent, upgrade-safe migration. Run it on every request if you like; after the
     * first call it issues only cheap catalogue reads.
     */
    public static function migrate(PDO $p): void
    {
        $driver   = self::driver($p);
        $existing = self::existingTables($p, $driver);

        foreach (self::schema() as $table => $def) {
            if (!in_array($table, $existing, true)) {
                $p->exec(self::createTableSql($table, $def, $driver));
            } else {
                // Upgrade path: add any column this build knows about and the file does not.
                $have = self::existingColumns($p, $driver, $table);
                foreach ($def['columns'] as $col) {
                    [$name, $type] = [$col[0], $col[1]];
                    if ($type === 'pk' || in_array($name, $have, true)) {
                        continue;
                    }
                    $sql = 'ALTER TABLE ' . self::quoteIdent($table, $driver)
                        . ' ADD COLUMN ' . self::columnSql($col, $driver);
                    try {
                        $p->exec($sql);
                    } catch (PDOException $e) {
                        // Same race the index creation below guards: two first requests
                        // arriving together both read the catalogue, both decide the column
                        // is missing, and the loser gets "duplicate column name". Losing that
                        // race is a no-op, not a 500 on the front page.
                        if (!self::isDuplicateError($e)) {
                            throw $e;
                        }
                    }
                }
            }

            $haveIdx = self::existingIndexes($p, $driver, $table);
            foreach ($def['indexes'] as $idx => [$unique, $cols]) {
                if (in_array($idx, $haveIdx, true)) {
                    continue;
                }
                $parts = [];
                foreach ($cols as $c) {
                    $desc = false;
                    if (str_ends_with($c, ' DESC')) {
                        $desc = true;
                        $c    = substr($c, 0, -5);
                    }
                    $parts[] = self::quoteIdent($c, $driver) . ($desc ? ' DESC' : '');
                }
                $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX '
                    . self::quoteIdent($idx, $driver) . ' ON ' . self::quoteIdent($table, $driver)
                    . ' (' . implode(', ', $parts) . ')';
                try {
                    $p->exec($sql);
                } catch (PDOException $e) {
                    // Two concurrent first requests can both decide the index is missing.
                    if (!self::isDuplicateError($e)) {
                        throw $e;
                    }
                }
            }
        }
    }

    /** @param array{0:string,1:string,2?:string} $col */
    private static function columnSql(array $col, string $driver): string
    {
        $sql = self::quoteIdent($col[0], $driver) . ' ' . self::sqlType($col[1], $driver);
        if (isset($col[2]) && $col[1] !== 'text' && $col[1] !== 'pk') {
            $sql .= ' DEFAULT ' . $col[2];
        }

        return $sql;
    }

    private static function createTableSql(string $table, array $def, string $driver): string
    {
        $cols = [];
        foreach ($def['columns'] as $col) {
            $cols[] = '  ' . self::columnSql($col, $driver);
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::quoteIdent($table, $driver) . " (\n"
            . implode(",\n", $cols) . "\n)";
        if ($driver === self::DRIVER_MYSQL) {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';
        }

        return $sql;
    }

    /** @return string[] */
    private static function existingTables(PDO $p, string $driver): array
    {
        if ($driver === self::DRIVER_PGSQL) {
            $st = $p->prepare(
                "SELECT tablename AS n FROM pg_catalog.pg_tables WHERE schemaname = current_schema()"
            );
            $st->execute();
        } elseif ($driver === self::DRIVER_MYSQL) {
            $st = $p->prepare(
                'SELECT table_name AS n FROM information_schema.tables WHERE table_schema = DATABASE()'
            );
            $st->execute();
        } else {
            $st = $p->prepare("SELECT name AS n FROM sqlite_master WHERE type = 'table'");
            $st->execute();
        }

        return array_map(static fn ($r) => (string) $r['n'], $st->fetchAll());
    }

    /** @return string[] */
    private static function existingColumns(PDO $p, string $driver, string $table): array
    {
        if ($driver === self::DRIVER_PGSQL) {
            $st = $p->prepare(
                'SELECT column_name AS n FROM information_schema.columns '
                . 'WHERE table_schema = current_schema() AND table_name = ?'
            );
            $st->execute([$table]);

            return array_map(static fn ($r) => (string) $r['n'], $st->fetchAll());
        }
        if ($driver === self::DRIVER_MYSQL) {
            $st = $p->prepare(
                'SELECT column_name AS n FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);

            return array_map(static fn ($r) => (string) $r['n'], $st->fetchAll());
        }
        // PRAGMA takes no bound parameters; $table is one of our own constants, and
        // quoteIdent() re-validates it against [a-z_][a-z0-9_]* before it reaches SQL.
        $st = $p->query('PRAGMA table_info(' . self::quoteIdent($table, $driver) . ')');

        return $st === false ? [] : array_map(static fn ($r) => (string) $r['name'], $st->fetchAll());
    }

    /** @return string[] */
    private static function existingIndexes(PDO $p, string $driver, string $table): array
    {
        if ($driver === self::DRIVER_PGSQL) {
            $st = $p->prepare('SELECT indexname AS n FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?');
            $st->execute([$table]);

            return array_map(static fn ($r) => (string) $r['n'], $st->fetchAll());
        }
        if ($driver === self::DRIVER_MYSQL) {
            $st = $p->prepare(
                'SELECT DISTINCT index_name AS n FROM information_schema.statistics '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);

            return array_map(static fn ($r) => (string) $r['n'], $st->fetchAll());
        }
        $st = $p->query('PRAGMA index_list(' . self::quoteIdent($table, $driver) . ')');

        return $st === false ? [] : array_map(static fn ($r) => (string) $r['name'], $st->fetchAll());
    }

    /**
     * A normalised, driver-independent description of what is actually in the database.
     * Used by the tests to prove migrate() is idempotent and that both drivers land on the
     * same logical schema.
     *
     * @return array<string,array{columns:string[],indexes:string[]}>
     */
    public static function describe(PDO $p): array
    {
        $driver = self::driver($p);
        $out    = [];
        foreach (array_keys(self::schema()) as $table) {
            $cols = self::existingColumns($p, $driver, $table);
            $idx  = array_values(array_filter(
                self::existingIndexes($p, $driver, $table),
                static fn (string $n) => !str_starts_with($n, 'sqlite_autoindex') && $n !== 'PRIMARY'
            ));
            sort($cols);
            sort($idx);
            $out[$table] = ['columns' => $cols, 'indexes' => $idx];
        }
        ksort($out);

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    private static function isDuplicateError(PDOException $e): bool
    {
        if ($e->getCode() === '23000') {
            return true;
        }
        $m = strtolower($e->getMessage());

        return str_contains($m, 'unique') || str_contains($m, 'duplicate') || str_contains($m, 'already exists');
    }

    /**
     * Prepare + bind + execute. Binding is typed: an int parameter is bound as PARAM_INT so
     * `LIMIT ?` works on MySQL's native prepared statements, where a string-bound LIMIT errors.
     *
     * @param array<int,mixed> $params positional, in order
     */
    private static function run(PDO $p, string $sql, array $params = []): PDOStatement
    {
        $st = $p->prepare($sql);
        $i  = 1;
        foreach ($params as $v) {
            if (is_int($v)) {
                $st->bindValue($i, $v, PDO::PARAM_INT);
            } elseif (is_bool($v)) {
                $st->bindValue($i, $v ? 1 : 0, PDO::PARAM_INT);
            } elseif ($v === null) {
                $st->bindValue($i, null, PDO::PARAM_NULL);
            } else {
                // Floats included: PHP 8 renders them with a '.' regardless of locale,
                // and both drivers accept a string for a REAL/DOUBLE column.
                $st->bindValue($i, (string) $v, PDO::PARAM_STR);
            }
            $i++;
        }
        $st->execute();

        return $st;
    }

    /** `?, ?, ?` for an IN() list. Structural only — the values are still bound. */
    private static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, max(0, $n), '?'));
    }

    /** Escape LIKE wildcards in a bound value. Paired with ESCAPE '!' in the SQL. */
    private static function likeEscape(string $s): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $s
        );
    }

    /** Give Compose and Render honest PHP types on both drivers. */
    private static function hydrate(array $r): array
    {
        foreach (['id', 'source_id', 'image_width', 'image_height', 'published_at', 'fetched_at', 'source_tier'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (int) $r[$k];
            }
        }
        if (array_key_exists('source_weight', $r)) {
            $r['source_weight'] = (float) $r['source_weight'];
        }
        foreach (['source_slug', 'source_name', 'source_homepage', 'section', 'url', 'title',
                  'title_key', 'summary', 'image_url', 'author', 'guid_hash', 'guid'] as $k) {
            if (array_key_exists($k, $r)) {
                $r[$k] = (string) ($r[$k] ?? '');
            }
        }

        return $r;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private static function hydrateAll(array $rows): array
    {
        return array_map([self::class, 'hydrate'], $rows);
    }

    // ---------------------------------------------------------------- url + key normalisation

    /**
     * Strip tracking cruft so the same story fetched from two feeds hashes identically.
     * Removes utm_* (prefix), fbclid, gclid, mc_cid and ref, plus a trailing '?' or '#'.
     * Everything else — including the path, other params and their order — is preserved.
     */
    public static function canonicalUrl(string $u): string
    {
        $u = trim($u);
        if ($u === '') {
            return '';
        }
        // Strip control characters a feed occasionally smuggles into a link (a stray CR or
        // LF is common). Internal spaces are left alone — mangling them would silently
        // change which page the link points at.
        $u = preg_replace('/[\x00-\x1F\x7F]/', '', $u) ?? $u;

        $parts = @parse_url($u);
        if ($parts === false || $parts === null || (!isset($parts['host']) && !isset($parts['path']))) {
            return rtrim($u, '?#');
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $keep = [];
            foreach (explode('&', $parts['query']) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $eq   = strpos($pair, '=');
                $name = $eq === false ? $pair : substr($pair, 0, $eq);
                $key  = strtolower(rawurldecode($name));
                if (in_array($key, self::STRIP_PARAMS, true)) {
                    continue;
                }
                $pref = false;
                foreach (self::STRIP_PARAM_PREFIXES as $p) {
                    if (str_starts_with($key, $p)) {
                        $pref = true;
                        break;
                    }
                }
                if ($pref) {
                    continue;
                }
                $keep[] = $pair;
            }
            $query = implode('&', $keep);
        }

        // Scheme and host are case-insensitive per RFC 3986; lowering them makes two feeds
        // that disagree on capitalisation dedup against each other. Path is left alone.
        //
        // The '//' belongs to the AUTHORITY, not to the scheme. Gluing it onto the scheme
        // unconditionally rewrote every authority-less URL into a different one —
        // "javascript:alert(1)" became "javascript://alert(1)" and "mailto:a@b.c" became
        // "mailto://a@b.c" — and, worse, silently dropped it from a protocol-relative URL,
        // turning "//example.com/x" (absolute) into "example.com/x" (a relative path that
        // resolves against our own host). Emit it if and only if there is a host.
        $scheme = isset($parts['scheme']) && $parts['scheme'] !== '' ? strtolower($parts['scheme']) . ':' : '';
        $host   = isset($parts['host']) ? strtolower($parts['host']) : '';
        $port   = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $user   = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $path   = $parts['path'] ?? '';
        $frag   = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

        $authority = $host === '' ? '' : '//' . $user . $host . $port;

        $out = $scheme . $authority . $path;
        if ($query !== '') {
            $out .= '?' . $query;
        }
        $out .= $frag;

        // A URL that was nothing but tracking params now ends in a bare '?' or '#'.
        return rtrim($out, '?#') === '' ? $out : rtrim($out, '?#');
    }

    /** sha256 hex of the canonical URL — the hard-dedup key. */
    public static function guidHash(string $u): string
    {
        return hash('sha256', self::canonicalUrl($u));
    }

    /**
     * Soft-dedup key: lowercase, punctuation stripped, stopwords dropped, first 8 tokens.
     * "Trump's Tariffs Hit the E.U." and "Trump Tariffs hit the EU — officials say" collide;
     * two genuinely different stories do not.
     */
    public static function titleKey(string $t): string
    {
        $t = self::stripAccents(trim($t));
        $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        // Apostrophes are DELETED, not folded to a space, so "Fed's" keys the same as "Feds".
        $t = str_replace(["'", "\u{2019}", "\u{2018}", '`', "\u{00B4}"], '', $t);
        // Fold anything that is not a letter or a digit (any script) to a space.
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? '';
        $tokens = array_values(array_filter(explode(' ', $t), static fn ($x) => $x !== ''));
        if ($tokens === []) {
            return '';
        }
        // Rejoin a run of adjacent single letters, so "E.U." keys the same as "EU" and
        // "U.S. Open" the same as "US Open". A lone single letter is left alone.
        $merged = [];
        $run    = '';
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) === 1) {
                $run .= $tok;
                continue;
            }
            if ($run !== '') {
                $merged[] = $run;
                $run      = '';
            }
            $merged[] = $tok;
        }
        if ($run !== '') {
            $merged[] = $run;
        }
        $tokens = $merged;

        $kept = array_values(array_filter($tokens, static fn ($w) => !isset(self::STOPWORDS[$w])));
        // A headline made entirely of stopwords ("It Is What It Is") must not key to ''
        // — an empty key would soft-dedup it against every other empty-key headline.
        if ($kept === []) {
            $kept = $tokens;
        }

        return implode(' ', array_slice($kept, 0, 8));
    }

    /** "E.U." vs "EU" is not worth a dedup miss; fold accents before keying. */
    private static function stripAccents(string $s): string
    {
        if (preg_match('/[\x80-\xFF]/', $s) !== 1) {
            return $s;
        }
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return is_string($conv) && $conv !== '' ? $conv : $s;
    }

    // ---------------------------------------------------------------- sources

    /**
     * Insert or update the feed registry, and RETIRE anything that has left it.
     *
     * Runtime state (active, fail_count, last_error, timestamps) is deliberately
     * preserved on update: re-running upsertSources on every request must not
     * un-park a feed that ingest parked.
     *
     * ⚠ THE RETIREMENT IS NOT TIDINESS. Without it a database that outlives an
     * edition keeps every source it ever had marked active — and the /sources
     * page and the footer's credit column are built from exactly that column.
     * Measured on this build: both databases were still advertising ABC News,
     * ESPN, Fox News and a recipe blog as sources of a site that has never read
     * one of them. A page that names a newsroom we do not carry is a false
     * statement about provenance, on the page an advertising reviewer opens
     * first. Rows are deactivated rather than deleted, so a feed that comes back
     * keeps its fail history and its id.
     *
     * ⚠ 'active' now carries two meanings — "parked by ingest after eight
     * failures" and "no longer in the registry" — and they are deliberately not
     * separated. Both answer the only question every reader of this column asks:
     * may this newsroom be named as a source of the site today. A feed that
     * comes back is therefore still dark until one fetch succeeds, which is the
     * same rule that already governs an un-parking, and Ingest retries a parked
     * source on its own schedule, so nothing here can strand one.
     *
     * @param array<int,array<string,mixed>> $feeds rows from TEB\Feeds::all()
     */
    public static function upsertSources(PDO $p, array $feeds): void
    {
        $now     = self::nowMs();
        $inTrans = $p->inTransaction();
        if (!$inTrans) {
            $p->beginTransaction();
        }
        try {
            $sel = $p->prepare('SELECT id FROM sources WHERE slug = ?');
            $upd = $p->prepare(
                'UPDATE sources SET name = ?, feed_url = ?, homepage = ?, section = ?, '
                . 'country = ?, tier = ?, weight = ?, updated_at = ? WHERE id = ?'
            );
            $ins = $p->prepare(
                'INSERT INTO sources (slug, name, feed_url, homepage, section, country, tier, '
                . 'weight, active, fail_count, last_error, last_fetch_at, last_ok_at, created_at, updated_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0, '', 0, 0, ?, ?)"
            );

            foreach ($feeds as $f) {
                $slug = trim((string) ($f['slug'] ?? ''));
                $feed = trim((string) ($f['feed'] ?? $f['feed_url'] ?? ''));
                if ($slug === '' || $feed === '') {
                    continue;
                }
                $name     = (string) ($f['name'] ?? $slug);
                $homepage = (string) ($f['homepage'] ?? '');
                $section  = (string) ($f['section'] ?? '');
                $country  = (string) ($f['country'] ?? '');
                $tier     = (int) ($f['tier'] ?? 3);
                $weight   = (float) ($f['weight'] ?? 1.0);

                $sel->execute([$slug]);
                $id = $sel->fetchColumn();

                if ($id !== false && $id !== null) {
                    $upd->bindValue(1, $name);
                    $upd->bindValue(2, $feed);
                    $upd->bindValue(3, $homepage);
                    $upd->bindValue(4, $section);
                    $upd->bindValue(5, $country);
                    $upd->bindValue(6, $tier, PDO::PARAM_INT);
                    $upd->bindValue(7, (string) $weight);
                    $upd->bindValue(8, $now, PDO::PARAM_INT);
                    $upd->bindValue(9, (int) $id, PDO::PARAM_INT);
                    $upd->execute();
                    continue;
                }

                $ins->bindValue(1, $slug);
                $ins->bindValue(2, $name);
                $ins->bindValue(3, $feed);
                $ins->bindValue(4, $homepage);
                $ins->bindValue(5, $section);
                $ins->bindValue(6, $country);
                $ins->bindValue(7, $tier, PDO::PARAM_INT);
                $ins->bindValue(8, (string) $weight);
                $ins->bindValue(9, $now, PDO::PARAM_INT);
                $ins->bindValue(10, $now, PDO::PARAM_INT);
                try {
                    $ins->execute();
                } catch (PDOException $e) {
                    // Another request inserted the same slug between our SELECT and INSERT.
                    if (!self::isDuplicateError($e)) {
                        throw $e;
                    }
                }
            }

            // Everything the registry no longer lists stops being a source of
            // this site. Done inside the same transaction as the upserts, so a
            // reader can never see a moment with neither the old set nor the new.
            $live = [];
            foreach ($feeds as $f) {
                $slug = trim((string) ($f['slug'] ?? ''));
                $feed = trim((string) ($f['feed'] ?? $f['feed_url'] ?? ''));
                if ($slug !== '' && $feed !== '') {
                    $live[] = $slug;
                }
            }
            if ($live !== []) {
                // Placeholders, not an interpolated list: these slugs come from a
                // constant today, and the day one comes from anywhere else this
                // is still not a place a quote can be smuggled through.
                $marks = implode(',', array_fill(0, count($live), '?'));
                $off   = $p->prepare(
                    'UPDATE sources SET active = 0, updated_at = ? '
                    . 'WHERE active = 1 AND slug NOT IN (' . $marks . ')'
                );
                $off->execute(array_merge([$now], $live));
            }

            if (!$inTrans) {
                $p->commit();
            }
        } catch (\Throwable $e) {
            // \Throwable, not PDOException: a TypeError from a malformed feed row used to
            // escape with the transaction still OPEN, and an open SQLite write transaction
            // holds the database lock for the rest of the request — every other visitor then
            // meets "database is locked", which is the one failure this module exists to stop.
            if (!$inTrans && $p->inTransaction()) {
                $p->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function sourcesInner(PDO $p, bool $activeOnly = false): array
    {
        $sql = 'SELECT id, slug, name, feed_url, homepage, section, country, tier, weight, active, '
            . 'fail_count, last_error, last_fetch_at, last_ok_at FROM sources';
        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }
        $sql .= ' ORDER BY tier ASC, name ASC';

        $rows = self::run($p, $sql)->fetchAll();
        foreach ($rows as $i => $r) {
            foreach (['id', 'tier', 'active', 'fail_count', 'last_fetch_at', 'last_ok_at'] as $k) {
                $rows[$i][$k] = (int) $r[$k];
            }
            $rows[$i]['weight'] = (float) $r['weight'];
        }

        return $rows;
    }

    private static function sourceBySlugInner(PDO $p, string $slug): ?array
    {
        $r = self::run(
            $p,
            'SELECT id, slug, name, feed_url, homepage, section, country, tier, weight, active, '
            . 'fail_count, last_error, last_fetch_at, last_ok_at FROM sources WHERE slug = ? LIMIT 1',
            [$slug]
        )->fetch();
        if ($r === false || $r === null) {
            return null;
        }
        foreach (['id', 'tier', 'active', 'fail_count', 'last_fetch_at', 'last_ok_at'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['weight'] = (float) $r['weight'];

        return $r;
    }

    /**
     * Record the outcome of fetching one feed. A feed that fails $parkAfter times in a row is
     * deactivated (SPEC §4) — a success resets the counter and revives it.
     */
    public static function recordFeedResult(
        PDO $p,
        string $slug,
        bool $ok,
        string $error = '',
        ?int $nowMs = null,
        int $parkAfter = 8
    ): void {
        $now = $nowMs ?? self::nowMs();
        if ($ok) {
            self::run(
                $p,
                "UPDATE sources SET fail_count = 0, last_error = '', active = 1, "
                . 'last_fetch_at = ?, last_ok_at = ?, updated_at = ? WHERE slug = ?',
                [$now, $now, $now, $slug]
            );

            return;
        }
        // ORDER MATTERS AND IT IS NOT COSMETIC. MySQL evaluates SET assignments left to
        // right and later expressions see the ALREADY-UPDATED value; SQLite evaluates every
        // right-hand side against the pre-update row. So `active` must be decided BEFORE
        // fail_count is incremented, or MySQL parks a feed one failure early and SQLite does
        // not — the same config producing different behaviour on the two drivers.
        self::run(
            $p,
            'UPDATE sources SET active = CASE WHEN fail_count + 1 >= ? THEN 0 ELSE active END, '
            . 'fail_count = fail_count + 1, last_error = ?, last_fetch_at = ?, updated_at = ? '
            . 'WHERE slug = ?',
            [max(1, $parkAfter), mb_substr($error, 0, 480), $now, $now, $slug]
        );
    }

    // ---------------------------------------------------------------- articles: write

    /**
     * Insert a batch of parsed feed items.
     *
     * Dedup is two-stage and both stages also apply WITHIN the batch:
     *   hard — sha256 of the canonical URL, backed by a UNIQUE index;
     *   soft — title_key seen on any article published or fetched inside the recent window,
     *          which is what collapses one AP story syndicated to five outlets into one card.
     *
     * The whole batch runs in ONE transaction. On SQLite that is the difference between
     * 500 fsyncs and one, and it is what keeps a concurrent reader from meeting a locked
     * database halfway through an ingest.
     *
     * Counts are honest: inserted + skipped always equals count($rows), and the detail keys
     * (dup_guid, dup_title, invalid) always sum to skipped.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array{now_ms?:int,soft_window_hours?:int,soft_dedup?:bool} $opts
     * @return array{inserted:int,skipped:int,dup_guid:int,dup_title:int,invalid:int}
     */
    public static function insertArticles(PDO $p, array $rows, array $opts = []): array
    {
        $now        = (int) ($opts['now_ms'] ?? self::nowMs());
        $softHours  = (int) ($opts['soft_window_hours'] ?? self::SOFT_DEDUP_HOURS);
        $softOn     = (bool) ($opts['soft_dedup'] ?? true);
        $softCutoff = $now - ($softHours * 3600 * 1000);

        $res = ['inserted' => 0, 'skipped' => 0, 'dup_guid' => 0, 'dup_title' => 0, 'invalid' => 0];
        if ($rows === []) {
            return $res;
        }

        // 1. Normalise, and dedup inside the batch itself.
        $clean     = [];
        $seenHash  = [];
        $seenTitle = [];
        foreach ($rows as $raw) {
            $r = self::normaliseArticle(is_array($raw) ? $raw : [], $now);
            if ($r === null) {
                $res['invalid']++;
                continue;
            }
            if (isset($seenHash[$r['guid_hash']])) {
                $res['dup_guid']++;
                continue;
            }
            if ($softOn && $r['title_key'] !== '' && isset($seenTitle[$r['title_key']])) {
                $res['dup_title']++;
                continue;
            }
            $seenHash[$r['guid_hash']] = true;
            if ($r['title_key'] !== '') {
                $seenTitle[$r['title_key']] = true;
            }
            $clean[] = $r;
        }
        if ($clean === []) {
            $res['skipped'] = $res['dup_guid'] + $res['dup_title'] + $res['invalid'];

            return $res;
        }

        // 2. Which of those already exist? Two chunked, fully bound lookups.
        $haveHash = self::existingSet($p, 'guid_hash', array_column($clean, 'guid_hash'));
        $haveKey  = [];
        if ($softOn) {
            $keys = array_values(array_filter(array_column($clean, 'title_key'), static fn ($k) => $k !== ''));
            if ($keys !== []) {
                $haveKey = self::existingSet($p, 'title_key', $keys, $softCutoff);
            }
        }

        // 3. One transaction for the whole batch.
        $ownTrans = !$p->inTransaction();
        if ($ownTrans) {
            $p->beginTransaction();
        }
        try {
            $ins = $p->prepare(
                'INSERT INTO articles (source_id, source_slug, source_name, section, guid, guid_hash, '
                . 'url, title, title_key, summary, body, image_url, image_width, image_height, author, '
                . 'published_at, fetched_at) VALUES (' . self::placeholders(17) . ')'
            );

            foreach ($clean as $r) {
                if (isset($haveHash[$r['guid_hash']])) {
                    $res['dup_guid']++;
                    continue;
                }
                if ($softOn && $r['title_key'] !== '' && isset($haveKey[$r['title_key']])) {
                    $res['dup_title']++;
                    continue;
                }
                $ins->bindValue(1, $r['source_id'], PDO::PARAM_INT);
                $ins->bindValue(2, $r['source_slug']);
                $ins->bindValue(3, $r['source_name']);
                $ins->bindValue(4, $r['section']);
                $ins->bindValue(5, $r['guid']);
                $ins->bindValue(6, $r['guid_hash']);
                $ins->bindValue(7, $r['url']);
                $ins->bindValue(8, $r['title']);
                $ins->bindValue(9, $r['title_key']);
                $ins->bindValue(10, $r['summary']);
                $ins->bindValue(11, (string) ($r['body'] ?? ''));
                $ins->bindValue(12, $r['image_url']);
                $ins->bindValue(13, $r['image_width'], PDO::PARAM_INT);
                $ins->bindValue(14, $r['image_height'], PDO::PARAM_INT);
                $ins->bindValue(15, $r['author']);
                $ins->bindValue(16, $r['published_at'], PDO::PARAM_INT);
                $ins->bindValue(17, $r['fetched_at'], PDO::PARAM_INT);

                try {
                    $ins->execute();
                    $res['inserted']++;
                    $haveHash[$r['guid_hash']] = true;
                    if ($r['title_key'] !== '') {
                        $haveKey[$r['title_key']] = true;
                    }
                } catch (PDOException $e) {
                    // Lost a race with a concurrent ingest on the UNIQUE index. That is a
                    // skip, not a failure — a failed statement does not abort the
                    // transaction on either driver, so the rest of the batch still lands.
                    if (self::isDuplicateError($e)) {
                        $res['dup_guid']++;
                        continue;
                    }
                    throw $e;
                }
            }

            if ($ownTrans) {
                $p->commit();
            }
        } catch (\Throwable $e) {
            // See upsertSources(): anything that escapes with the transaction still open
            // leaves the SQLite write lock held for the rest of the request.
            if ($ownTrans && $p->inTransaction()) {
                $p->rollBack();
            }
            throw $e;
        }

        $res['skipped'] = $res['dup_guid'] + $res['dup_title'] + $res['invalid'];

        return $res;
    }

    /**
     * @param string[] $values
     * @return array<string,bool>
     */
    private static function existingSet(PDO $p, string $column, array $values, ?int $sinceMs = null): array
    {
        $out    = [];
        $values = array_values(array_unique($values));
        $col    = $column === 'guid_hash' ? 'guid_hash' : 'title_key'; // whitelist, never user input
        foreach (array_chunk($values, self::IN_CHUNK) as $chunk) {
            $sql = 'SELECT ' . $col . ' AS v FROM articles WHERE ' . $col
                . ' IN (' . self::placeholders(count($chunk)) . ')';
            $params = $chunk;
            if ($sinceMs !== null) {
                $sql     .= ' AND (published_at >= ? OR fetched_at >= ?)';
                $params[] = $sinceMs;
                $params[] = $sinceMs;
            }
            foreach (self::run($p, $sql, $params)->fetchAll() as $r) {
                $out[(string) $r['v']] = true;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null null = unusable row (no url or no title) */
    private static function normaliseArticle(array $r, int $now): ?array
    {
        $url = trim((string) ($r['url'] ?? $r['link'] ?? ''));
        $url = self::canonicalUrl($url);
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($r['title'] ?? '')) ?? '');
        if ($url === '' || $title === '') {
            return null;
        }

        $pub = $r['published_at'] ?? null;
        $pub = is_numeric($pub) ? (int) $pub : 0;
        // A feed with a broken clock must not sit at the top of the front page for ever,
        // and a 1970 timestamp must not be pruned the moment it arrives.
        if ($pub < self::MIN_PLAUSIBLE_MS || $pub > $now + 172800000) {
            $pub = $now;
        }

        $hash = trim((string) ($r['guid_hash'] ?? ''));
        if ($hash === '' || strlen($hash) !== 64) {
            $hash = self::guidHash($url);
        }

        // MySQL runs in strict mode on most cPanel hosts: an over-long value is an
        // exception, not a warning, and one such row would abort the whole ingest batch.
        // Everything below is capped to the column it lands in.
        $image = trim((string) ($r['image_url'] ?? ''));
        if (mb_strlen($image) > 1000) {
            // A truncated image URL is a guaranteed 404; the designed text card is better.
            $image = '';
        }
        if (mb_strlen($url) > 1000) {
            return null;
        }

        return [
            'source_id'    => (int) ($r['source_id'] ?? 0),
            'source_slug'  => mb_substr(trim((string) ($r['source_slug'] ?? '')), 0, 64),
            'source_name'  => mb_substr(trim((string) ($r['source_name'] ?? '')), 0, 120),
            'section'      => mb_substr(strtolower(trim((string) ($r['section'] ?? ''))), 0, 32),
            'guid'         => mb_substr(trim((string) ($r['guid'] ?? '')), 0, 500),
            'guid_hash'    => $hash,
            'url'          => $url,
            'title'        => mb_substr($title, 0, 400),
            'title_key'    => mb_substr(self::titleKey($title), 0, 191),
            'summary'      => mb_substr((string) ($r['summary'] ?? ''), 0, 4000),
            'image_url'    => $image,
            'body'         => (string) ($r['body'] ?? ''),
            'image_width'  => max(0, (int) ($r['image_width'] ?? 0)),
            'image_height' => max(0, (int) ($r['image_height'] ?? 0)),
            'author'       => mb_substr(trim((string) ($r['author'] ?? '')), 0, 160),
            'published_at' => $pub,
            'fetched_at'   => ((int) ($r['fetched_at'] ?? 0)) > 0 ? (int) $r['fetched_at'] : $now,
        ];
    }

    /**
     * Is this the database telling us a table is not there?
     *
     * ⚠ THIS IS NOT A CONVENIENCE. app/bootstrap.php deliberately CONTINUES when
     * Db::migrate() throws, because a host that has revoked writes — a free plan
     * at its size cap, a failover, a full disk — must still serve what is already
     * there rather than take the site down. That is a real incident, written up in
     * bootstrap.php: JawsDB revoked INSERT at 5 MB.
     *
     * The consequence nobody followed through on: when migrate() is skipped on a
     * database that never got its tables, EVERY read throws. Db::homeCandidates()
     * and TEB\Rotate already degrade, so the front page and the rotation feed
     * survived — and every other route answered 500, including the five standing
     * pages an advertising reviewer opens. A site that serves its homepage and
     * 500s on /about, /privacy, both sitemaps and every feed is worse than one
     * that is honestly empty, because that is exactly what a crawler indexes.
     *
     * So a MISSING TABLE degrades to "no rows". Nothing else does: a syntax error,
     * a bad column or a dead connection still throws, because those are our bugs
     * and swallowing them would hide them.
     *
     * MySQL raises SQLSTATE 42S02; SQLite has no distinct SQLSTATE and says so in
     * the message instead, so both are checked.
     */
    public static function isMissingTable(Throwable $e): bool
    {
        if (!$e instanceof PDOException) {
            return false;
        }

        $state = is_array($e->errorInfo ?? null) ? (string) ($e->errorInfo[0] ?? '') : '';
        if ($state === '42S02') {
            return true;                        // MySQL / MariaDB: base table not found
        }

        return stripos($e->getMessage(), 'no such table') !== false;   // SQLite
    }

    /** Log a skipped read once, in the same voice as the rest of the degradation. */
    private static function noteMissingTable(string $where, Throwable $e): void
    {
        error_log('[teb] ' . $where . ' skipped (schema not present): ' . $e->getMessage());
    }

    /**
     * The one read used by the front page, section pages, the ticker, the feed and
     * the sitemap. Degrades to no rows when the schema is not there; see
     * isMissingTable().
     *
     * @param array<string,mixed> $opts
     * @return array<int,array<string,mixed>>
     */
    public static function recentArticles(PDO $p, array $opts = []): array
    {
        try {
            return self::recentArticlesInner($p, $opts);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('recentArticles', $e);

            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function searchArticles(PDO $p, string $q, int $limit = 40): array
    {
        try {
            return self::searchArticlesInner($p, $q, $limit);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('searchArticles', $e);

            return [];
        }
    }

    /** How many articles are stored; 0 when the schema is not there. */
    public static function countArticles(PDO $p, ?int $sinceMs = null): int
    {
        try {
            return self::countArticlesInner($p, $sinceMs);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('countArticles', $e);

            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function relatedArticles(PDO $p, array $a, int $limit = 6): array
    {
        try {
            return self::relatedArticlesInner($p, $a, $limit);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('relatedArticles', $e);

            return [];
        }
    }

    /** One article, or null — including when the schema is not there. */
    public static function articleById(PDO $p, int $id): ?array
    {
        try {
            return self::articleByIdInner($p, $id);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('articleById', $e);

            return null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function sources(PDO $p, bool $activeOnly = false): array
    {
        try {
            return self::sourcesInner($p, $activeOnly);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('sources', $e);

            return [];
        }
    }

    /**
     * Story counts per desk. Empty when the schema is not there — the sitemap
     * asks for these before it asks for anything else, so an unguarded read here
     * was still answering 500 after the article reads were fixed.
     *
     * @return array<string,int>
     */
    public static function sectionCounts(PDO $p, ?int $sinceMs = null): array
    {
        try {
            return self::sectionCountsInner($p, $sinceMs);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('sectionCounts', $e);

            return [];
        }
    }

    /** The most recent ingest run, or null — including when the schema is absent. */
    public static function lastIngestRun(PDO $p): ?array
    {
        try {
            return self::lastIngestRunInner($p);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('lastIngestRun', $e);

            return null;
        }
    }

    /** One source row, or null — including when the schema is absent. */
    public static function sourceBySlug(PDO $p, string $slug): ?array
    {
        try {
            return self::sourceBySlugInner($p, $slug);
        } catch (PDOException $e) {
            if (!self::isMissingTable($e)) {
                throw $e;
            }
            self::noteMissingTable('sourceBySlug', $e);

            return null;
        }
    }

    // ---------------------------------------------------------------- articles: read

    /**
     * The one read used by the front page, section pages, the ticker, the feed and the sitemap.
     *
     * @param array{
     *   section?:string|string[], exclude_sections?:string[], limit?:int, offset?:int,
     *   since_ms?:int, until_ms?:int, window_hours?:int, has_image?:bool,
     *   source_id?:int, source_slug?:string, exclude_ids?:int[], order?:string
     * } $opts
     * @return array<int,array<string,mixed>>
     */
    private static function recentArticlesInner(PDO $p, array $opts = []): array
    {
        $where  = [];
        $params = [];

        $section = $opts['section'] ?? null;
        if (is_string($section) && $section !== '') {
            $section = [$section];
        }
        if (is_array($section) && $section !== []) {
            $section = array_values(array_unique(array_map(
                static fn ($s) => strtolower(trim((string) $s)),
                $section
            )));
            $where[] = 'a.section IN (' . self::placeholders(count($section)) . ')';
            foreach ($section as $s) {
                $params[] = $s;
            }
        }

        $not = $opts['exclude_sections'] ?? [];
        if (is_array($not) && $not !== []) {
            $not     = array_values(array_unique(array_map(
                static fn ($s) => strtolower(trim((string) $s)),
                $not
            )));
            $where[] = 'a.section NOT IN (' . self::placeholders(count($not)) . ')';
            foreach ($not as $s) {
                $params[] = $s;
            }
        }

        if (isset($opts['window_hours']) && (int) $opts['window_hours'] > 0) {
            $where[]  = 'a.published_at >= ?';
            $params[] = self::nowMs() - ((int) $opts['window_hours'] * 3600 * 1000);
        }
        if (isset($opts['since_ms'])) {
            $where[]  = 'a.published_at >= ?';
            $params[] = (int) $opts['since_ms'];
        }
        if (isset($opts['until_ms'])) {
            $where[]  = 'a.published_at <= ?';
            $params[] = (int) $opts['until_ms'];
        }
        if (!empty($opts['has_image'])) {
            $where[] = "a.image_url <> ''";
        }
        if (isset($opts['source_id']) && (int) $opts['source_id'] > 0) {
            $where[]  = 'a.source_id = ?';
            $params[] = (int) $opts['source_id'];
        }
        if (isset($opts['source_slug']) && (string) $opts['source_slug'] !== '') {
            $where[]  = 'a.source_slug = ?';
            $params[] = (string) $opts['source_slug'];
        }
        $ex = $opts['exclude_ids'] ?? [];
        if (is_array($ex) && $ex !== []) {
            $ex      = array_values(array_unique(array_map('intval', $ex)));
            $where[] = 'a.id NOT IN (' . self::placeholders(count($ex)) . ')';
            foreach ($ex as $id) {
                $params[] = $id;
            }
        }

        $order = ($opts['order'] ?? 'recent') === 'oldest'
            ? 'a.published_at ASC, a.id ASC'
            : 'a.published_at DESC, a.id DESC';

        // 5,000 is the sitemap ceiling, not a page-size ceiling: a caller that asks for
        // more is capped rather than served, but a legitimate sitemap build must not be
        // silently truncated at 500.
        $limit  = self::clampLimit($opts['limit'] ?? 60, 5000);
        $offset = max(0, (int) ($opts['offset'] ?? 0));

        $sql = 'SELECT ' . self::ARTICLE_COLS . ' FROM articles a LEFT JOIN sources s ON s.id = a.source_id'
            . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?';

        $params[] = $limit;
        $params[] = $offset;

        return self::hydrateAll(self::run($p, $sql, $params)->fetchAll());
    }

    private static function articleByIdInner(PDO $p, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $r = self::run(
            $p,
            'SELECT ' . self::ARTICLE_COLS . ', a.guid FROM articles a '
            . 'LEFT JOIN sources s ON s.id = a.source_id WHERE a.id = ? LIMIT 1',
            [$id]
        )->fetch();

        return ($r === false || $r === null) ? null : self::hydrate($r);
    }

    /**
     * Sidebar for an article page: same section first, then topped up with anything recent,
     * never the article itself and never its soft-dedup twin.
     *
     * @param array<string,mixed> $a an article row
     * @return array<int,array<string,mixed>>
     */
    private static function relatedArticlesInner(PDO $p, array $a, int $limit = 6): array
    {
        $limit = self::clampLimit($limit);
        $id    = (int) ($a['id'] ?? 0);
        $key   = (string) ($a['title_key'] ?? '');
        $sect  = strtolower((string) ($a['section'] ?? ''));

        $out  = [];
        $seen = [$id => true];

        $collect = function (array $rows) use (&$out, &$seen, $limit): void {
            foreach ($rows as $r) {
                if (count($out) >= $limit) {
                    return;
                }
                if (isset($seen[(int) $r['id']])) {
                    continue;
                }
                $seen[(int) $r['id']] = true;
                $out[]                = $r;
            }
        };

        $base = 'SELECT ' . self::ARTICLE_COLS . ' FROM articles a '
            . 'LEFT JOIN sources s ON s.id = a.source_id WHERE a.id <> ?';
        $keyClause = $key !== '' ? ' AND a.title_key <> ?' : '';

        if ($sect !== '') {
            $params = [$id];
            if ($key !== '') {
                $params[] = $key;
            }
            $params[] = $sect;
            $params[] = $limit;
            $collect(self::hydrateAll(self::run(
                $p,
                $base . $keyClause . ' AND a.section = ? ORDER BY a.published_at DESC, a.id DESC LIMIT ?',
                $params
            )->fetchAll()));
        }

        if (count($out) < $limit) {
            $params = [$id];
            if ($key !== '') {
                $params[] = $key;
            }
            $params[] = $limit + count($out) + 1;
            $collect(self::hydrateAll(self::run(
                $p,
                $base . $keyClause . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ?',
                $params
            )->fetchAll()));
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Search. Every term is a BOUND parameter with its LIKE wildcards escaped, so a query of
     * "100%_off' OR 1=1--" searches for that literal string and finds nothing rather than
     * matching everything or reaching the parser.
     *
     * Terms are ANDed; a term may match the title or the summary; title matches sort first.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function searchArticlesInner(PDO $p, string $q, int $limit = 40): array
    {
        $terms = self::searchTerms($q);
        if ($terms === []) {
            return [];
        }
        $limit = self::clampLimit($limit);

        $where  = [];
        $params = [];
        foreach ($terms as $t) {
            $like     = '%' . self::likeEscape($t) . '%';
            $where[]  = "(LOWER(a.title) LIKE ? ESCAPE '!' OR LOWER(a.summary) LIKE ? ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
        }

        // Rank: a hit in the headline beats a hit in the summary.
        $rankLike = '%' . self::likeEscape($terms[0]) . '%';
        $sql      = 'SELECT ' . self::ARTICLE_COLS . ', '
            . "CASE WHEN LOWER(a.title) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END AS rank_hit "
            . 'FROM articles a LEFT JOIN sources s ON s.id = a.source_id '
            . 'WHERE ' . implode(' AND ', $where)
            . ' ORDER BY rank_hit ASC, a.published_at DESC, a.id DESC LIMIT ?';

        $bound = array_merge([$rankLike], $params, [$limit]);
        $rows  = self::hydrateAll(self::run($p, $sql, $bound)->fetchAll());
        foreach ($rows as $i => $r) {
            unset($rows[$i]['rank_hit']);
        }

        return array_values($rows);
    }

    /**
     * Split a query into at most 6 lowercased terms. Kept here (not in the SQL) so the
     * caller can never influence the statement's shape, only its bound values.
     *
     * @return string[]
     */
    private static function searchTerms(string $q): array
    {
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        if ($q === '') {
            return [];
        }
        $q     = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
        $q     = mb_substr($q, 0, 120);
        $parts = array_values(array_filter(explode(' ', $q), static fn ($x) => $x !== ''));
        if ($parts === []) {
            return [];
        }
        $long = array_values(array_filter($parts, static fn ($x) => mb_strlen($x) >= 2));
        // A one-character query ("q") is still a legitimate search.
        $use = $long !== [] ? $long : [$parts[0]];

        return array_slice($use, 0, 6);
    }

    private static function clampLimit($limit, int $max = 500): int
    {
        $n = (int) $limit;

        return $n < 1 ? 1 : min($n, $max);
    }

    // ---------------------------------------------------------------- maintenance + health

    /**
     * Retention. An article is deleted only when BOTH its publication date and the moment we
     * fetched it are older than the cutoff — otherwise a feed that back-dates its items would
     * have them pruned the same hour they arrived.
     *
     * Old ingest_runs rows are trimmed on the same pass; the returned count is articles only.
     */
    public static function pruneOld(PDO $p, int $days, ?int $nowMs = null): int
    {
        $days = max(1, $days);
        $now  = $nowMs ?? self::nowMs();
        $cut  = $now - ($days * 86400000);

        $st = self::run($p, 'DELETE FROM articles WHERE published_at < ? AND fetched_at < ?', [$cut, $cut]);
        $n  = $st->rowCount();

        self::run($p, 'DELETE FROM ingest_runs WHERE started_at > 0 AND started_at < ?', [$cut]);

        if (self::driver($p) === self::DRIVER_SQLITE && $n > 0) {
            // Keep the file from growing without bound on a host with a small quota.
            try {
                $p->exec('PRAGMA incremental_vacuum');
            } catch (PDOException $e) {
                // Not enabled on this file; harmless.
            }
        }

        return $n;
    }

    /**
     * Delete every article whose feed is no longer in the registry.
     *
     * This is a LICENCE guard, not housekeeping. Attribution — the author, the
     * newsroom, the licence named in full and a link to its deed — is a term of
     * every licence in app/Feeds.php, and all of it is looked up from the
     * registry by source_slug. Once a feed is retired that lookup returns null,
     * so the article can no longer be attributed and therefore must no longer be
     * served. Retention alone does not cover this: a story fetched an hour before
     * its feed was dropped is well inside the window and would sit on the front
     * page for days, credited to nothing.
     *
     * $keep is the list of slugs the registry currently knows. An empty list is
     * treated as "the registry could not be read" and deletes NOTHING — the one
     * bug this must never have is emptying the table because a lookup failed.
     *
     * @param array<int,string> $keep source_slug values to preserve
     */
    public static function pruneRetiredSources(PDO $p, array $keep): int
    {
        $slugs = [];
        foreach ($keep as $slug) {
            $slug = trim((string) $slug);
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }
        if (!$slugs) {
            return 0;                    // never wipe the archive on an empty registry
        }

        $slugs = array_keys($slugs);
        $in    = implode(',', array_fill(0, count($slugs), '?'));
        $st    = self::run($p, 'DELETE FROM articles WHERE source_slug NOT IN (' . $in . ')', $slugs);
        $n     = $st->rowCount();

        if ($n > 0 && self::driver($p) === self::DRIVER_SQLITE) {
            try {
                $p->exec('PRAGMA incremental_vacuum');
            } catch (PDOException $e) {
                // Not enabled on this file; harmless.
            }
        }

        return $n;
    }

    /**
     * Write the ingest_runs row. Ingest owns the fetching; the table lives here, so the
     * INSERT does too.
     *
     * @param array{started_at?:int,finished_at?:int,run_mode?:string,feeds_ok?:int,
     *              feeds_failed?:int,inserted?:int,skipped?:int,errors?:array|string,notes?:string} $run
     */
    public static function recordIngestRun(PDO $p, array $run): int
    {
        $errors = $run['errors'] ?? [];
        if (is_array($errors)) {
            $errors = json_encode(array_slice($errors, 0, 40), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        self::run(
            $p,
            'INSERT INTO ingest_runs (started_at, finished_at, run_mode, feeds_ok, feeds_failed, '
            . 'inserted, skipped, errors, notes) VALUES (' . self::placeholders(9) . ')',
            [
                (int) ($run['started_at'] ?? self::nowMs()),
                (int) ($run['finished_at'] ?? self::nowMs()),
                mb_substr((string) ($run['run_mode'] ?? 'cron'), 0, 16),
                (int) ($run['feeds_ok'] ?? 0),
                (int) ($run['feeds_failed'] ?? 0),
                (int) ($run['inserted'] ?? 0),
                (int) ($run['skipped'] ?? 0),
                mb_substr((string) $errors, 0, 16000),
                mb_substr((string) ($run['notes'] ?? ''), 0, 480),
            ]
        );

        return (int) $p->lastInsertId();
    }

    private static function lastIngestRunInner(PDO $p): ?array
    {
        $r = self::run(
            $p,
            'SELECT id, started_at, finished_at, run_mode, feeds_ok, feeds_failed, inserted, skipped, '
            . 'errors, notes FROM ingest_runs ORDER BY started_at DESC, id DESC LIMIT 1'
        )->fetch();
        if ($r === false || $r === null) {
            return null;
        }
        foreach (['id', 'started_at', 'finished_at', 'feeds_ok', 'feeds_failed', 'inserted', 'skipped'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $decoded     = json_decode((string) ($r['errors'] ?? ''), true);
        $r['errors'] = is_array($decoded) ? $decoded : [];

        return $r;
    }

    private static function countArticlesInner(PDO $p, ?int $sinceMs = null): int
    {
        if ($sinceMs === null) {
            return (int) self::run($p, 'SELECT COUNT(*) AS c FROM articles')->fetchColumn();
        }

        return (int) self::run(
            $p,
            'SELECT COUNT(*) AS c FROM articles WHERE published_at >= ?',
            [$sinceMs]
        )->fetchColumn();
    }

    /** @return array<string,int> section => count, most recent 7 days */
    private static function sectionCountsInner(PDO $p, ?int $sinceMs = null): array
    {
        $sql    = 'SELECT section, COUNT(*) AS c FROM articles';
        $params = [];
        if ($sinceMs !== null) {
            $sql     .= ' WHERE published_at >= ?';
            $params[] = $sinceMs;
        }
        $sql .= ' GROUP BY section ORDER BY c DESC';

        $out = [];
        foreach (self::run($p, $sql, $params)->fetchAll() as $r) {
            $out[(string) $r['section']] = (int) $r['c'];
        }

        return $out;
    }

    /**
     * Everything /healthz needs, in one call. Never throws on an empty database.
     *
     * @return array<string,mixed>
     */
    public static function health(PDO $p): array
    {
        $now = self::nowMs();

        $newest = self::run($p, 'SELECT MAX(published_at) AS v FROM articles')->fetchColumn();
        $oldest = self::run($p, 'SELECT MIN(published_at) AS v FROM articles')->fetchColumn();

        $failing = [];
        foreach (self::run(
            $p,
            'SELECT slug, name, fail_count, active, last_error, last_fetch_at FROM sources '
            . 'WHERE fail_count > 0 ORDER BY fail_count DESC, slug ASC LIMIT ?',
            [50]
        )->fetchAll() as $r) {
            $failing[] = [
                'slug'          => (string) $r['slug'],
                'name'          => (string) $r['name'],
                'fail_count'    => (int) $r['fail_count'],
                'parked'        => ((int) $r['active']) === 0,
                'last_error'    => (string) $r['last_error'],
                'last_fetch_at' => (int) $r['last_fetch_at'],
            ];
        }

        $last = self::lastIngestRun($p);

        return [
            'driver'           => self::driver($p),
            'now_ms'           => $now,
            'articles'         => self::countArticles($p),
            'articles_24h'     => self::countArticles($p, $now - 86400000),
            'sources'          => (int) self::run($p, 'SELECT COUNT(*) AS c FROM sources')->fetchColumn(),
            'sources_active'   => (int) self::run($p, 'SELECT COUNT(*) AS c FROM sources WHERE active = 1')->fetchColumn(),
            'sources_failing'  => $failing,
            'newest_article_at' => $newest === false || $newest === null ? null : (int) $newest,
            'oldest_article_at' => $oldest === false || $oldest === null ? null : (int) $oldest,
            'sections'         => self::sectionCounts($p, $now - (7 * 86400000)),
            'last_ingest'      => $last,
            'stale_minutes'    => $last === null || $last['finished_at'] <= 0
                ? null
                : (int) floor(($now - $last['finished_at']) / 60000),
        ];
    }
    // ------------------------------------------------- front-page candidates

    /**
     * Window, in hours, for the flat read behind the front page and the
     * rotation endpoint.
     */
    public const HOME_WINDOW_HOURS = 96;

    /** Rows taken by the flat read before the per-desk top-up runs. */
    public const HOME_FLAT_LIMIT = 500;

    /** Top-up depth for a desk that appears on the front page, and for one that does not. */
    public const HOME_TOPUP_HOME  = 30;
    public const HOME_TOPUP_OTHER = 10;

    /**
     * Every article the front page is allowed to consider, in one place.
     *
     * ⚠ THIS EXISTS BECAUSE IT DRIFTED. The front page performed a flat read
     * plus a per-desk top-up; the rotation endpoint performed the flat read
     * alone while its own docblock claimed to be doing "the same read the front
     * page performs". It was not. Measured on this build, the page composed
     * from 209 rows and the rotation pool from 88 — and the 121 rows only the
     * page could see were exactly the quiet desks the top-up exists to rescue,
     * so education vanished from the rotation pool entirely while sitting on
     * the page. Both callers now come through here and cannot diverge again.
     *
     * THE TOP-UP, AND WHY IT IS NOT OPTIONAL. These are not wires. The fastest
     * feed on the roster files eleven times a day and the slowest twice a week,
     * so a flat "most recent 500 in four days" is dominated by two or three
     * newsrooms and the quiet desks — which still have real, long, perfectly
     * good articles — fall off the end of it. Every desk in the registry is
     * therefore asked for its own newest stories as well, with no window at
     * all, and the results merged. Compose still decides what is worth a slot;
     * this only makes sure it was shown the candidates.
     *
     * Never throws: a read that fails is an empty candidate list, and both
     * callers already treat that as a real state.
     *
     * @return array<int,array<string,mixed>> de-duplicated by article id
     */
    public static function homeCandidates(PDO $p): array
    {
        try {
            $rows = self::recentArticles($p, [
                'limit'        => self::HOME_FLAT_LIMIT,
                'window_hours' => self::HOME_WINDOW_HOURS,
            ]);
        } catch (Throwable $e) {
            error_log('[teb] home candidates: ' . $e->getMessage());

            return [];
        }

        $seen = [];
        foreach ($rows as $r) {
            $seen[(int) ($r['id'] ?? 0)] = true;
        }

        // Feeds is a static registry with no I/O, but a caller that loaded Db
        // on its own must still get the flat read rather than a fatal error.
        if (!class_exists(Feeds::class)) {
            return $rows;
        }

        foreach (Feeds::sections() as $slug => $desk) {
            $n = ($desk['home'] ?? null) !== null ? self::HOME_TOPUP_HOME : self::HOME_TOPUP_OTHER;
            try {
                $extra = self::recentArticles($p, ['section' => [(string) $slug], 'limit' => $n]);
            } catch (Throwable $e) {
                continue;                       // one bad desk must not empty the page
            }
            foreach ($extra as $r) {
                $id = (int) ($r['id'] ?? 0);
                if ($id > 0 && !isset($seen[$id])) {
                    $seen[$id] = true;
                    $rows[]    = $r;
                }
            }
        }

        return $rows;
    }
}
