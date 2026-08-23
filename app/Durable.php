<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * Durable storage on Cloudflare D1, mirroring the local SQLite database.
 *
 * The problem this solves: a dyno's filesystem is wiped on every restart, so a
 * SQLite database there loses everything roughly daily. The obvious answer — a
 * hosted MySQL — is 5 MB on the only free plan, and a news site full of
 * full-length articles fills that in two days. When it fills, the provider
 * revokes INSERT, and on the last site that turned a write limit into a
 * site-wide outage.
 *
 * So: SQLite stays the live store, because reads must be local and instant.
 * D1 is the durable copy — 5 GB on the free plan, roughly a thousand times the
 * room, on an account we already hold. On boot we restore; after each ingest we
 * push. Reads never touch the network.
 *
 * ⚠ Article ids are mirrored EXPLICITLY in both directions. If ids were
 * regenerated on restore, every article URL would change each time the dyno
 * restarted, which would wreck indexing and any link anyone had shared.
 */
final class Durable
{
    private const API   = 'https://api.cloudflare.com/client/v4/accounts/%s/d1/database/%s/query';
    private const PAGE  = 250;
    /** Bound parameters per D1 statement. Measured: 200 fails, 50 is safe. */
    private const PARAM_CHUNK = 50;
    private const COLS  = 'id, source_id, source_slug, source_name, section, guid, guid_hash, url, '
                        . 'title, title_key, summary, body, image_url, image_width, image_height, '
                        . 'author, published_at, fetched_at';

    public static function enabled(array $cfg): bool
    {
        $d = $cfg['durable'] ?? [];

        return !empty($d['enabled'])
            && (string) ($d['account_id'] ?? '') !== ''
            && (string) ($d['database_id'] ?? '') !== ''
            && (string) ($d['token'] ?? '') !== '';
    }

    /**
     * One D1 query. Returns the rows, or null when the call failed — the caller
     * always treats null as "carry on without the mirror", never as fatal.
     *
     * @param  array<int,mixed> $params
     * @return array<int,array<string,mixed>>|null
     */
    public static function query(array $cfg, string $sql, array $params = []): ?array
    {
        if (!self::enabled($cfg)) {
            return null;
        }
        $d   = $cfg['durable'];
        $url = sprintf(self::API, (string) $d['account_id'], (string) $d['database_id']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => (int) ($d['timeout'] ?? 20),
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . (string) $d['token'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => (string) json_encode(['sql' => $sql, 'params' => array_values($params)]),
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        // curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5,
        // which Heroku now runs; the handle is freed when it goes out of scope.

        if (!is_string($body) || $code < 200 || $code >= 300) {
            error_log('[durable] query failed HTTP ' . $code . ' ' . $err . ' ' . substr((string) $body, 0, 200));

            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j) || empty($j['success'])) {
            error_log('[durable] query rejected: ' . substr($body, 0, 250));

            return null;
        }

        return $j['result'][0]['results'] ?? [];
    }

    /** Create the mirror table. Idempotent, and safe to call on every boot. */
    public static function ensureSchema(array $cfg): bool
    {
        if (!self::enabled($cfg)) {
            return false;
        }
        $ok = self::query($cfg,
            'CREATE TABLE IF NOT EXISTS articles ('
            . 'id INTEGER PRIMARY KEY, source_id INTEGER, source_slug TEXT, source_name TEXT, '
            . 'section TEXT, guid TEXT, guid_hash TEXT UNIQUE, url TEXT, title TEXT, title_key TEXT, '
            . 'summary TEXT, body TEXT, image_url TEXT, image_width INTEGER, image_height INTEGER, '
            . 'author TEXT, published_at INTEGER, fetched_at INTEGER)');
        if ($ok === null) {
            return false;
        }
        self::query($cfg, 'CREATE INDEX IF NOT EXISTS ix_pub ON articles (published_at DESC)');

        return true;
    }

    /**
     * Refill an empty local database from the mirror.
     *
     * Returns the number of rows restored. Does nothing when the local store
     * already has content, so it is safe on every request.
     */
    public static function restore(PDO $p, array $cfg): int
    {
        if (!self::enabled($cfg)) {
            return 0;
        }
        try {
            if ((int) $p->query('SELECT COUNT(*) FROM articles')->fetchColumn() > 0) {
                return 0;
            }
        } catch (Throwable $e) {
            return 0;
        }

        $days   = max(1, (int) ($cfg['ingest']['retention_days'] ?? 3));
        $since  = (Db::nowMs() - $days * 86400000);
        $total  = 0;
        $offset = 0;

        $ins = $p->prepare(
            'INSERT OR IGNORE INTO articles (' . self::COLS . ') VALUES ('
            . implode(',', array_fill(0, 18, '?')) . ')'
        );

        while (true) {
            $rows = self::query($cfg,
                'SELECT ' . self::COLS . ' FROM articles WHERE published_at >= ? '
                . 'ORDER BY id LIMIT ? OFFSET ?',
                [$since, self::PAGE, $offset]);
            if ($rows === null || $rows === []) {
                break;
            }
            $p->beginTransaction();
            foreach ($rows as $r) {
                $ins->execute([
                    (int) $r['id'], (int) $r['source_id'], (string) $r['source_slug'],
                    (string) $r['source_name'], (string) $r['section'], (string) $r['guid'],
                    (string) $r['guid_hash'], (string) $r['url'], (string) $r['title'],
                    (string) $r['title_key'], (string) $r['summary'], (string) ($r['body'] ?? ''),
                    (string) $r['image_url'], (int) $r['image_width'], (int) $r['image_height'],
                    (string) $r['author'], (int) $r['published_at'], (int) $r['fetched_at'],
                ]);
                $total++;
            }
            $p->commit();

            if (count($rows) < self::PAGE) {
                break;
            }
            $offset += self::PAGE;
        }

        if ($total > 0) {
            error_log('[durable] restored ' . $total . ' articles from the mirror');
        }

        return $total;
    }

    /**
     * Push everything the mirror does not already have.
     *
     * Driven off guid_hash, so re-running it is harmless and a failed push is
     * simply retried on the next ingest.
     */
    public static function push(PDO $p, array $cfg, int $limit = 400): int
    {
        if (!self::enabled($cfg)) {
            return 0;
        }

        $days  = max(1, (int) ($cfg['ingest']['retention_days'] ?? 3));
        $since = Db::nowMs() - $days * 86400000;

        try {
            $st = $p->prepare('SELECT ' . self::COLS . ' FROM articles WHERE published_at >= ? ORDER BY id DESC LIMIT ?');
            $st->bindValue(1, $since, PDO::PARAM_INT);
            $st->bindValue(2, max(1, $limit), PDO::PARAM_INT);
            $st->execute();
            $local = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return 0;
        }
        if ($local === []) {
            return 0;
        }

        // Ask the mirror which of these it already holds, in one call.
        $hashes = array_map(static fn (array $r): string => (string) $r['guid_hash'], $local);
        $known  = [];
        // D1 caps bound parameters per statement well below SQLite's own 999 —
        // a 200-item IN() list comes back as "too many SQL variables" and the
        // whole existence check silently fails, so every row is re-pushed.
        foreach (array_chunk($hashes, self::PARAM_CHUNK) as $chunk) {
            $rows = self::query($cfg,
                'SELECT guid_hash FROM articles WHERE guid_hash IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk);
            foreach (($rows ?? []) as $r) {
                $known[(string) $r['guid_hash']] = true;
            }
        }

        $sent = 0;
        foreach ($local as $r) {
            if (isset($known[(string) $r['guid_hash']])) {
                continue;
            }
            $ok = self::query($cfg,
                'INSERT OR IGNORE INTO articles (' . self::COLS . ') VALUES ('
                . implode(',', array_fill(0, 18, '?')) . ')',
                [
                    (int) $r['id'], (int) $r['source_id'], (string) $r['source_slug'],
                    (string) $r['source_name'], (string) $r['section'], (string) $r['guid'],
                    (string) $r['guid_hash'], (string) $r['url'], (string) $r['title'],
                    (string) $r['title_key'], (string) $r['summary'], (string) ($r['body'] ?? ''),
                    (string) $r['image_url'], (int) $r['image_width'], (int) $r['image_height'],
                    (string) $r['author'], (int) $r['published_at'], (int) $r['fetched_at'],
                ]);
            if ($ok === null) {
                break;                      // mirror unreachable; try again next run
            }
            $sent++;
        }

        return $sent;
    }

    /** Drop anything past the retention window from the mirror. */
    public static function prune(array $cfg): int
    {
        if (!self::enabled($cfg)) {
            return 0;
        }
        $days = max(1, (int) ($cfg['ingest']['retention_days'] ?? 3));
        $r = self::query($cfg, 'DELETE FROM articles WHERE published_at < ?', [Db::nowMs() - $days * 86400000]);

        return $r === null ? 0 : 1;
    }

    /** @return array<string,mixed> */
    public static function status(array $cfg): array
    {
        if (!self::enabled($cfg)) {
            return ['enabled' => false];
        }
        $rows = self::query($cfg, 'SELECT COUNT(*) AS n, MAX(published_at) AS newest FROM articles');
        if ($rows === null) {
            return ['enabled' => true, 'reachable' => false];
        }

        return [
            'enabled'   => true,
            'reachable' => true,
            'articles'  => (int) ($rows[0]['n'] ?? 0),
            'newest_at' => (int) ($rows[0]['newest'] ?? 0),
        ];
    }
}
