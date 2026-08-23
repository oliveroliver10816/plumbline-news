<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * Our own editorial posts — the pieces the desk writes, as opposed to the
 * syndicated articles the ingester pulls in.
 *
 * A post carries a headline, a short version (the standfirst that appears on a
 * card) and a long version (the article body), plus an optional uploaded
 * picture. It can be pinned to a slot on the front page, and it can be marked
 * as sponsored — which is not cosmetic: a sponsored post is labelled on the
 * card, labelled again on its own page, carries rel="sponsored" on any outbound
 * link, and is excluded from the RSS feed and the news sitemap. Both the FTC
 * and Google require paid placement to be obvious, and an unlabelled advertorial
 * is the fastest way to lose an AdSense account.
 */
final class Posts
{
    public const KIND_ARTICLE   = 'article';
    public const KIND_SPONSORED = 'sponsored';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';

    /** How many pinned slots the front page offers. */
    public const SLOTS = 6;

    public static function migrate(PDO $p): void
    {
        $driver = Db::driver($p);
        $pk = $driver === Db::DRIVER_MYSQL
            ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
            : ($driver === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $txt = $driver === Db::DRIVER_SQLITE ? 'TEXT' : 'TEXT';
        $str = static fn (int $n): string => $driver === Db::DRIVER_SQLITE ? 'TEXT' : 'VARCHAR(' . $n . ')';

        $p->exec(
            'CREATE TABLE IF NOT EXISTS desk_posts ('
            . 'id ' . $pk . ', '
            . 'slug ' . $str(220) . " NOT NULL DEFAULT '', "
            . 'kind ' . $str(20) . " NOT NULL DEFAULT 'article', "
            . 'status ' . $str(20) . " NOT NULL DEFAULT 'draft', "
            . 'headline ' . $str(300) . " NOT NULL DEFAULT '', "
            . 'standfirst ' . $txt . ', '
            . 'body ' . $txt . ', '
            . 'section ' . $str(40) . " NOT NULL DEFAULT '', "
            . 'author ' . $str(120) . " NOT NULL DEFAULT '', "
            . 'sponsor ' . $str(160) . " NOT NULL DEFAULT '', "
            . 'sponsor_url ' . $str(600) . " NOT NULL DEFAULT '', "
            . 'media_id INTEGER NOT NULL DEFAULT 0, '
            . 'pinned INTEGER NOT NULL DEFAULT 0, '
            . 'slot INTEGER NOT NULL DEFAULT 0, '
            . 'published_at BIGINT NOT NULL DEFAULT 0, '
            . 'created_at BIGINT NOT NULL DEFAULT 0, '
            . 'updated_at BIGINT NOT NULL DEFAULT 0)'
        );
        $p->exec('CREATE TABLE IF NOT EXISTS desk_users ('
            . 'id ' . $pk . ', '
            . 'username ' . $str(80) . " NOT NULL DEFAULT '', "
            . 'pass_hash ' . $str(255) . " NOT NULL DEFAULT '', "
            . 'role ' . $str(20) . " NOT NULL DEFAULT 'editor', "
            . 'created_at BIGINT NOT NULL DEFAULT 0, '
            . 'last_login_at BIGINT NOT NULL DEFAULT 0, '
            . 'fail_count INTEGER NOT NULL DEFAULT 0)');
        $p->exec('CREATE TABLE IF NOT EXISTS desk_login_fails ('
            . 'id ' . $pk . ', '
            . 'username ' . $str(80) . " NOT NULL DEFAULT '', "
            . 'ip ' . $str(64) . " NOT NULL DEFAULT '', "
            . 'at BIGINT NOT NULL DEFAULT 0)');
        $p->exec('CREATE TABLE IF NOT EXISTS desk_media ('
            . 'id ' . $pk . ', '
            . 'mime ' . $str(40) . " NOT NULL DEFAULT 'image/jpeg', "
            . 'width INTEGER NOT NULL DEFAULT 0, '
            . 'height INTEGER NOT NULL DEFAULT 0, '
            . 'bytes INTEGER NOT NULL DEFAULT 0, '
            . 'alt ' . $str(300) . " NOT NULL DEFAULT '', "
            . 'data ' . $txt . ', '
            . 'created_at BIGINT NOT NULL DEFAULT 0)');

        foreach ([
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_desk_posts_slug ON desk_posts (slug)',
            'CREATE INDEX IF NOT EXISTS ix_desk_posts_pub ON desk_posts (published_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS ux_desk_users_name ON desk_users (username)',
            'CREATE INDEX IF NOT EXISTS ix_desk_fails_at ON desk_login_fails (at)',
        ] as $sql) {
            try {
                $p->exec($sql);
            } catch (Throwable $e) {
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(PDO $p, int $limit = 200): array
    {
        try {
            $st = $p->prepare('SELECT * FROM desk_posts ORDER BY COALESCE(NULLIF(published_at,0), created_at) DESC LIMIT ?');
            $st->bindValue(1, $limit, PDO::PARAM_INT);
            $st->execute();

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function byId(PDO $p, int $id): ?array
    {
        try {
            $st = $p->prepare('SELECT * FROM desk_posts WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            return $r === false ? null : $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function bySlug(PDO $p, string $slug): ?array
    {
        try {
            $st = $p->prepare('SELECT * FROM desk_posts WHERE slug = ? LIMIT 1');
            $st->execute([$slug]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            return $r === false ? null : $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Published posts pinned to the front page, in slot order. */
    public static function pinned(PDO $p, int $limit = self::SLOTS): array
    {
        try {
            $st = $p->prepare(
                "SELECT * FROM desk_posts WHERE pinned = 1 AND status = 'published' "
                . 'AND published_at <= ? ORDER BY slot ASC, published_at DESC LIMIT ?'
            );
            $st->bindValue(1, Db::nowMs(), PDO::PARAM_INT);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
            $st->execute();

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function published(PDO $p, int $limit = 60): array
    {
        try {
            $st = $p->prepare(
                "SELECT * FROM desk_posts WHERE status = 'published' AND published_at <= ? "
                . 'ORDER BY published_at DESC LIMIT ?'
            );
            $st->bindValue(1, Db::nowMs(), PDO::PARAM_INT);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
            $st->execute();

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function save(PDO $p, array $in, ?int $id = null): int
    {
        $now  = Db::nowMs();
        $slug = self::uniqueSlug($p, (string) ($in['slug'] ?? ''), (string) ($in['headline'] ?? ''), $id);

        $cols = [
            'slug'         => $slug,
            'kind'         => in_array($in['kind'] ?? '', [self::KIND_ARTICLE, self::KIND_SPONSORED], true) ? $in['kind'] : self::KIND_ARTICLE,
            'status'       => ($in['status'] ?? '') === self::STATUS_PUBLISHED ? self::STATUS_PUBLISHED : self::STATUS_DRAFT,
            'headline'     => mb_substr(trim((string) ($in['headline'] ?? '')), 0, 300),
            'standfirst'   => mb_substr(trim(self::normaliseText((string) ($in['standfirst'] ?? ''))), 0, 2000),
            // Browsers submit CRLF from a textarea. Every paragraph split in the
            // renderer looks for \n{2,}, which never matches \r\n\r\n — so an
            // article typed with blank lines came out as one glued block.
            'body'         => mb_substr(self::normaliseText((string) ($in['body'] ?? '')), 0, 120000),
            'section'      => mb_substr(trim((string) ($in['section'] ?? '')), 0, 40),
            'author'       => mb_substr(trim((string) ($in['author'] ?? '')), 0, 120),
            'sponsor'      => mb_substr(trim((string) ($in['sponsor'] ?? '')), 0, 160),
            'sponsor_url'  => mb_substr(trim((string) ($in['sponsor_url'] ?? '')), 0, 600),
            'media_id'     => max(0, (int) ($in['media_id'] ?? 0)),
            'pinned'       => !empty($in['pinned']) ? 1 : 0,
            'slot'         => max(0, min(self::SLOTS, (int) ($in['slot'] ?? 0))),
            'published_at' => (int) ($in['published_at'] ?? 0) ?: $now,
            'updated_at'   => $now,
        ];

        if ($id === null) {
            $cols['created_at'] = $now;
            $names  = array_keys($cols);
            $sql    = 'INSERT INTO desk_posts (' . implode(',', $names) . ') VALUES ('
                    . implode(',', array_fill(0, count($names), '?')) . ')';
            $st     = $p->prepare($sql);
            $st->execute(array_values($cols));

            return (int) $p->lastInsertId();
        }

        $set = implode(' = ?, ', array_keys($cols)) . ' = ?';
        $st  = $p->prepare('UPDATE desk_posts SET ' . $set . ' WHERE id = ?');
        $st->execute(array_merge(array_values($cols), [$id]));

        return $id;
    }

    public static function delete(PDO $p, int $id): void
    {
        $p->prepare('DELETE FROM desk_posts WHERE id = ?')->execute([$id]);
    }

    /** CRLF and lone CR to LF, and trim trailing space on each line. */
    public static function normaliseText(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = (string) preg_replace('/[ \t]+\n/', "\n", $s);

        return trim($s);
    }

    public static function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($conv) && $conv !== '') {
            $s = $conv;
        }
        $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s);

        return trim(substr($s, 0, 90), '-');
    }

    private static function uniqueSlug(PDO $p, string $given, string $headline, ?int $id): string
    {
        $base = self::slugify($given !== '' ? $given : $headline);
        if ($base === '') {
            $base = 'post';
        }
        $slug = $base;
        for ($i = 2; $i < 200; $i++) {
            $found = self::bySlug($p, $slug);
            if ($found === null || (int) $found['id'] === (int) $id) {
                return $slug;
            }
            $slug = $base . '-' . $i;
        }

        return $base . '-' . substr((string) Db::nowMs(), -5);
    }

    /**
     * Shape a post so the existing card and article renderers can draw it with
     * no special cases — same keys an ingested article carries.
     */
    public static function asArticle(array $post, array $cfg): array
    {
        $sponsored = ($post['kind'] ?? '') === self::KIND_SPONSORED;
        $label     = $sponsored ? 'Sponsored' : ucfirst((string) ($post['section'] ?? 'Desk'));
        $mediaId   = (int) ($post['media_id'] ?? 0);

        return [
            'id'            => 'p' . (int) $post['id'],
            'desk_post'     => true,
            'sponsored'     => $sponsored,
            'sponsor'       => (string) ($post['sponsor'] ?? ''),
            'sponsor_url'   => (string) ($post['sponsor_url'] ?? ''),
            'section'       => (string) ($post['section'] ?? ''),
            'section_label' => $label,
            'title'         => (string) ($post['headline'] ?? ''),
            'summary'       => (string) ($post['standfirst'] ?? ''),
            'body'          => (string) ($post['body'] ?? ''),
            'url'           => '',
            'source_name'   => (string) ($cfg['site']['name'] ?? ''),
            'source_slug'   => 'desk',
            'author'        => (string) ($post['author'] ?? ''),
            'image_url'     => $mediaId > 0 ? Paths::url('/media/' . $mediaId . '.jpg') : '',
            'image_width'   => $mediaId > 0 ? (int) ($post['media_width'] ?? 1600) : 0,
            'image_height'  => $mediaId > 0 ? (int) ($post['media_height'] ?? 900) : 0,
            'images_allowed' => true,
            'published_at'  => (int) ($post['published_at'] ?? 0),
            'href'          => '/post/' . (string) ($post['slug'] ?? ''),
        ];
    }
}
