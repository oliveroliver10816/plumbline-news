<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * The desk — a small newsroom CMS.
 *
 * Deliberately self-contained: its own markup and its own stylesheet, so the
 * public design can be rewritten without breaking the editor, and the editor
 * can be restyled without touching a single reader-facing class. It is
 * responsive because it will be used from a phone as often as a laptop.
 *
 * Everything here is behind Auth. The routes live under a secret prefix that
 * comes from configuration, and an unauthenticated request to any of them
 * returns exactly the same 404 the rest of the site would give for a bad URL —
 * so probing for the desk tells an attacker nothing.
 */
final class Studio
{
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    // ---------------------------------------------------------------- routing

    /**
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public static function handle(PDO $p, array $cfg, string $rest, array $query): array
    {
        $user = Auth::user($p, $cfg);
        $rest = '/' . trim($rest, '/');
        $post = $_SERVER['REQUEST_METHOD'] === 'POST';

        // --- unauthenticated ------------------------------------------------
        if ($user === null) {
            if ($post && $rest === '/login') {
                return self::doLogin($p, $cfg);
            }

            return self::page(self::loginView($cfg, ''), $cfg, 'Sign in', 200);
        }

        // --- authenticated --------------------------------------------------
        if ($post) {
            $token = (string) ($_POST['csrf'] ?? '');
            if (!Auth::checkCsrf($cfg, $user, $token)) {
                return self::page('<div class="note bad">That form expired. Please try again.</div>'
                    . self::listView($p, $cfg, $user), $cfg, 'The desk', 400);
            }
            switch ($rest) {
                case '/save':   return self::doSave($p, $cfg, $user);
                case '/delete': return self::doDelete($p, $cfg, $user);
                case '/logout':
                    Auth::setCookie($cfg, '', true);

                    return self::redirect(Paths::url(self::prefix($cfg)));
                case '/password': return self::doPassword($p, $cfg, $user);
            }
        }

        if (preg_match('#^/edit/(\d+)$#', $rest, $m) === 1) {
            $row = Posts::byId($p, (int) $m[1]);
            if ($row === null) {
                return self::page('<div class="note bad">That post no longer exists.</div>' . self::listView($p, $cfg, $user), $cfg, 'The desk', 404);
            }

            return self::page(self::editView($p, $cfg, $user, $row), $cfg, 'Edit', 200);
        }
        if ($rest === '/new') {
            return self::page(self::editView($p, $cfg, $user, null), $cfg, 'New post', 200);
        }
        if ($rest === '/account') {
            return self::page(self::accountView($cfg, $user), $cfg, 'Account', 200);
        }

        return self::page(self::listView($p, $cfg, $user), $cfg, 'The desk', 200);
    }

    public static function prefix(array $cfg): string
    {
        return '/' . trim((string) ($cfg['admin']['path'] ?? 'desk'), '/');
    }

    // ---------------------------------------------------------------- actions

    private static function doLogin(PDO $p, array $cfg): array
    {
        $u  = (string) ($_POST['username'] ?? '');
        $pw = (string) ($_POST['password'] ?? '');
        $r  = Auth::attempt($p, $cfg, $u, $pw, Auth::clientIp());

        if (!$r['ok']) {
            // Deliberately slow: a wrong password costs the attacker a second.
            usleep(400000);

            return self::page(self::loginView($cfg, (string) ($r['error'] ?? 'Sign-in failed.')), $cfg, 'Sign in', 401);
        }
        Auth::setCookie($cfg, Auth::issue($cfg, $r['user']));

        return self::redirect(Paths::url(self::prefix($cfg)));
    }

    private static function doSave(PDO $p, array $cfg, array $user): array
    {
        $id = (int) ($_POST['id'] ?? 0) ?: null;

        $mediaId = (int) ($_POST['media_id'] ?? 0);
        $notice  = '';
        if (!empty($_FILES['image']['name'] ?? '')) {
            $up = Media::store($p, $_FILES['image'], (string) ($_POST['headline'] ?? ''));
            if (!empty($up['ok'])) {
                $mediaId = (int) $up['id'];
                $notice  = 'Picture uploaded and resized to ' . $up['width'] . '×' . $up['height'] . '.';
            } else {
                $row = $id !== null ? Posts::byId($p, $id) : null;

                return self::page('<div class="note bad">' . self::esc((string) $up['error']) . '</div>'
                    . self::editView($p, $cfg, $user, $row, $_POST), $cfg, 'New post', 400);
            }
        }

        $headline = trim((string) ($_POST['headline'] ?? ''));
        if ($headline === '') {
            $row = $id !== null ? Posts::byId($p, $id) : null;

            return self::page('<div class="note bad">A headline is required.</div>'
                . self::editView($p, $cfg, $user, $row, $_POST), $cfg, 'New post', 400);
        }

        $kind = ($_POST['kind'] ?? '') === Posts::KIND_SPONSORED ? Posts::KIND_SPONSORED : Posts::KIND_ARTICLE;
        if ($kind === Posts::KIND_SPONSORED && trim((string) ($_POST['sponsor'] ?? '')) === '') {
            $row = $id !== null ? Posts::byId($p, $id) : null;

            return self::page('<div class="note bad">A sponsored post must name the sponsor. '
                . 'Paid placement has to be identified — that is a legal requirement, not a preference.</div>'
                . self::editView($p, $cfg, $user, $row, $_POST), $cfg, 'New post', 400);
        }

        $pubAt = 0;
        $when  = trim((string) ($_POST['published_at'] ?? ''));
        if ($when !== '') {
            $ts = strtotime($when);
            if ($ts !== false) {
                $pubAt = $ts * 1000;
            }
        }

        try {
            $newId = Posts::save($p, [
                'slug'         => (string) ($_POST['slug'] ?? ''),
                'kind'         => $kind,
                'status'       => ($_POST['status'] ?? '') === Posts::STATUS_PUBLISHED ? Posts::STATUS_PUBLISHED : Posts::STATUS_DRAFT,
                'headline'     => $headline,
                'standfirst'   => (string) ($_POST['standfirst'] ?? ''),
                'body'         => (string) ($_POST['body'] ?? ''),
                'section'      => (string) ($_POST['section'] ?? ''),
                'author'       => (string) ($_POST['author'] ?? ($user['username'] ?? '')),
                'sponsor'      => (string) ($_POST['sponsor'] ?? ''),
                'sponsor_url'  => (string) ($_POST['sponsor_url'] ?? ''),
                'media_id'     => $mediaId,
                'pinned'       => !empty($_POST['pinned']),
                'slot'         => (int) ($_POST['slot'] ?? 0),
                'published_at' => $pubAt,
            ], $id);
        } catch (Throwable $e) {
            error_log('[studio] save failed: ' . $e->getMessage());
            $row = $id !== null ? Posts::byId($p, $id) : null;

            return self::page('<div class="note bad">The post could not be saved. The database may be read-only right now.</div>'
                . self::editView($p, $cfg, $user, $row, $_POST), $cfg, 'New post', 500);
        }

        self::mirror($p, $cfg);

        return self::redirect(Paths::url(self::prefix($cfg) . '/edit/' . $newId) . '?saved=1'
            . ($notice !== '' ? '&m=' . rawurlencode($notice) : ''));
    }

    private static function doDelete(PDO $p, array $cfg, array $user): array
    {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && (string) ($_POST['confirm'] ?? '') === 'delete') {
            try {
                Posts::delete($p, $id);
                // The mirror is authoritative after a restart, so a delete has to
                // reach it too — otherwise the post reappears on the next boot.
                Durable::query($cfg, 'DELETE FROM desk_posts WHERE id = ?', [$id]);
            } catch (Throwable $e) {
            }
        }

        return self::redirect(Paths::url(self::prefix($cfg)));
    }

    private static function doPassword(PDO $p, array $cfg, array $user): array
    {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify($cur, (string) $user['pass_hash'])) {
            return self::page('<div class="note bad">The current password is not right.</div>' . self::accountView($cfg, $user), $cfg, 'Account', 400);
        }
        if (strlen($new) < 12) {
            return self::page('<div class="note bad">Use at least 12 characters.</div>' . self::accountView($cfg, $user), $cfg, 'Account', 400);
        }
        Auth::setPassword($p, (int) $user['id'], $new);
        // Changing the password invalidates the cookie, so re-issue it.
        $fresh = Auth::findUser($p, (string) $user['username']);
        if (is_array($fresh)) {
            Auth::setCookie($cfg, Auth::issue($cfg, $fresh));
        }

        self::mirror($p, $cfg);

        return self::redirect(Paths::url(self::prefix($cfg) . '/account') . '?changed=1');
    }

    /** Copy the desk up to durable storage. Never fatal: the edit is saved either way. */
    private static function mirror(PDO $p, array $cfg): void
    {
        try {
            Durable::pushDesk($p, $cfg);
        } catch (Throwable $e) {
            error_log('[studio] mirror failed: ' . $e->getMessage());
        }
    }

    private static function redirect(string $to): array
    {
        return ['status' => 303, 'headers' => ['Location' => $to, 'Cache-Control' => 'no-store'], 'body' => ''];
    }

    // ---------------------------------------------------------------- views

    private static function loginView(array $cfg, string $error): string
    {
        $e = $error !== '' ? '<div class="note bad">' . self::esc($error) . '</div>' : '';

        return '<div class="signin">'
            . '<h1>' . self::esc((string) ($cfg['site']['name'] ?? 'The desk')) . '</h1>'
            . '<p class="sub">Editorial desk</p>'
            . $e
            . '<form method="post" action="' . self::esc(Paths::url(self::prefix($cfg) . '/login')) . '" autocomplete="on">'
            . '<label>Username<input name="username" autocomplete="username" autocapitalize="none" required autofocus></label>'
            . '<label>Password<input name="password" type="password" autocomplete="current-password" required></label>'
            . '<button type="submit">Sign in</button>'
            . '</form></div>';
    }

    private static function listView(PDO $p, array $cfg, array $user): string
    {
        $rows = Posts::all($p, 200);
        $pre  = Paths::url(self::prefix($cfg));
        $out  = '<div class="bar"><h1>The desk</h1><div class="actions">'
              . '<a class="btn primary" href="' . self::esc($pre . '/new') . '">New post</a>'
              . '<a class="btn" href="' . self::esc($pre . '/account') . '">Account</a>'
              . '<form method="post" action="' . self::esc($pre . '/logout') . '" style="display:inline">'
              . self::csrfField($cfg, $user) . '<button class="btn" type="submit">Sign out</button></form>'
              . '</div></div>';

        if ($rows === []) {
            return $out . '<div class="empty"><p>Nothing written yet.</p>'
                . '<p><a class="btn primary" href="' . self::esc($pre . '/new') . '">Write the first post</a></p></div>';
        }

        $out .= '<div class="cards">';
        foreach ($rows as $r) {
            $sponsored = ($r['kind'] ?? '') === Posts::KIND_SPONSORED;
            $live      = ($r['status'] ?? '') === Posts::STATUS_PUBLISHED;
            $future    = $live && (int) ($r['published_at'] ?? 0) > Db::nowMs();
            $mid       = (int) ($r['media_id'] ?? 0);
            $thumb     = $mid > 0
                ? '<img src="' . self::esc(Paths::url('/media/' . $mid . '.jpg')) . '" alt="" loading="lazy" decoding="async" width="160" height="90">'
                : '<span class="nothumb">no picture</span>';
            $out .= '<a class="row" href="' . self::esc($pre . '/edit/' . (int) $r['id']) . '">'
                . '<span class="thumb">' . $thumb . '</span>'
                . '<span class="meta">'
                . '<span class="tags">'
                . ($sponsored ? '<b class="tag sponsored">Sponsored</b>' : '')
                . '<b class="tag ' . ($future ? 'sched' : ($live ? 'live' : 'draft')) . '">'
                . ($future ? 'Scheduled ' . self::esc(date('j M H:i', (int) ($r['published_at'] / 1000))) : ($live ? 'Published' : 'Draft')) . '</b>'
                . (!empty($r['pinned']) ? '<b class="tag pin">Front page · slot ' . (int) $r['slot'] . '</b>' : '')
                . '</span>'
                . '<span class="hed">' . self::esc((string) $r['headline']) . '</span>'
                . '<span class="when">' . self::esc(self::when((int) ($r['published_at'] ?: $r['created_at']))) . '</span>'
                . '</span></a>';
        }

        return $out . '</div>';
    }

    private static function editView(PDO $p, array $cfg, array $user, ?array $row, ?array $post = null): string
    {
        $v = static function (string $k, $d = '') use ($row, $post) {
            if (is_array($post) && array_key_exists($k, $post)) {
                return (string) $post[$k];
            }

            return (string) ($row[$k] ?? $d);
        };
        $pre  = Paths::url(self::prefix($cfg));
        $id   = (int) ($row['id'] ?? 0);
        $mid  = (int) ($post['media_id'] ?? ($row['media_id'] ?? 0));
        $kind = $v('kind', Posts::KIND_ARTICLE);
        $sections = ['' => '— choose —'];
        foreach (Feeds::sections() as $s) {
            $slug = is_array($s) ? (string) ($s['slug'] ?? '') : (string) $s;
            if ($slug !== '') {
                $sections[$slug] = ucfirst($slug);
            }
        }

        $opts = '';
        foreach ($sections as $sv => $sl) {
            $opts .= '<option value="' . self::esc($sv) . '"' . ($v('section') === $sv ? ' selected' : '') . '>' . self::esc($sl) . '</option>';
        }
        $slots = '';
        for ($i = 1; $i <= Posts::SLOTS; $i++) {
            $slots .= '<option value="' . $i . '"' . ((int) $v('slot') === $i ? ' selected' : '') . '>Slot ' . $i . '</option>';
        }

        $pubMs    = (int) $v('published_at');
        $isFuture = $pubMs > Db::nowMs();
        $tz       = (string) ($cfg['site']['timezone'] ?? 'UTC');

        if (isset($_GET['saved'])) {
            $msg = 'Saved.' . (isset($_GET['m']) ? ' ' . self::esc((string) $_GET['m']) : '');
            if ($v('status') !== Posts::STATUS_PUBLISHED) {
                $saved = '<div class="note good">' . $msg . ' It is a draft, so it is not on the site yet.</div>';
            } elseif ($isFuture) {
                // The single most confusing thing the desk can do is accept a
                // publish and show nothing. Say exactly when it appears, in the
                // site's own clock, and offer the one-click fix.
                $saved = '<div class="note warn">' . $msg
                    . ' <b>It is scheduled, not live.</b> This post is dated '
                    . self::esc(date('j M Y, H:i T', (int) ($pubMs / 1000)))
                    . ' — about ' . self::esc(self::humanGap($pubMs - Db::nowMs()))
                    . ' from now — so it will not appear on the site until then.'
                    . ' Clear the publish date to put it live immediately.</div>';
            } else {
                $saved = '<div class="note good">' . $msg
                    . ' <a href="' . self::esc(Paths::url('/post/' . $v('slug'))) . '" target="_blank" rel="noopener">View it on the site →</a></div>';
            }
        } else {
            $saved = '';
        }

        $pv = $mid > 0
            ? '<div class="preview"><img src="' . self::esc(Paths::url('/media/' . $mid . '.jpg')) . '" alt="current picture"><span>Uploading a new file replaces this.</span></div>'
            : '';

        return $saved
            . '<div class="bar"><h1>' . ($id ? 'Edit post' : 'New post') . '</h1>'
            . '<div class="actions"><a class="btn" href="' . self::esc($pre) . '">All posts</a></div></div>'
            . '<form method="post" action="' . self::esc($pre . '/save') . '" enctype="multipart/form-data" class="editor">'
            . self::csrfField($cfg, $user)
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="media_id" value="' . $mid . '">'

            . '<div class="grid">'
            . '<div class="main">'
            . '<label class="big">Headline<input name="headline" value="' . self::esc($v('headline')) . '" maxlength="300" required placeholder="What happened, in one line"></label>'
            . '<label>Short version — the summary shown on the card'
            . '<textarea name="standfirst" rows="3" maxlength="2000" placeholder="Two or three sentences. This is what a reader sees before clicking.">' . self::esc($v('standfirst')) . '</textarea></label>'
            . '<label>Full article'
            . '<textarea name="body" rows="22" placeholder="The whole piece. Leave a blank line between paragraphs.">' . self::esc($v('body')) . '</textarea></label>'
            . '</div>'

            . '<aside class="side">'
            . '<div class="box"><h2>Publishing</h2>'
            . '<label>Status<select name="status">'
            . '<option value="draft"' . ($v('status') !== Posts::STATUS_PUBLISHED ? ' selected' : '') . '>Draft — only you can see it</option>'
            . '<option value="published"' . ($v('status') === Posts::STATUS_PUBLISHED ? ' selected' : '') . '>Published — live on the site</option>'
            . '</select></label>'
            . '<label class="check"><input type="checkbox" name="pinned" value="1"' . ($v('pinned') ? ' checked' : '') . '> Pin to the front page</label>'
            . '<label>Front-page position<select name="slot"><option value="0">Not pinned</option>' . $slots . '</select></label>'
            . '<label>Section<select name="section">' . $opts . '</select></label>'
            . '<label>Byline<input name="author" value="' . self::esc($v('author', (string) $user['username'])) . '" maxlength="120"></label>'
            . '<label>Publish date <span class="hint">blank = publish now · times are ' . self::esc($tz) . '</span>'
            . '<input name="published_at" type="datetime-local" value="' . self::esc(self::localInput((int) $v('published_at'))) . '"></label>'
            . ($isFuture ? '<p class="hint warnhint">Dated in the future — this post is scheduled and will not show on the site yet.</p>' : '')
            . '<label>URL slug <span class="hint">blank = from the headline</span><input name="slug" value="' . self::esc($v('slug')) . '" maxlength="220"></label>'
            . '</div>'

            . '<div class="box"><h2>Picture</h2>'
            . $pv
            . '<label class="file">Upload a picture<input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></label>'
            . '<p class="hint">Resized to ' . Media::MAX_WIDTH . 'px wide and re-encoded, which also strips location data from the file. Up to '
            . (int) (Media::MAX_UPLOAD_BYTES / 1048576) . ' MB.</p>'
            . '</div>'

            . '<div class="box"><h2>Sponsored</h2>'
            . '<label>Type<select name="kind" id="kind">'
            . '<option value="article"' . ($kind !== Posts::KIND_SPONSORED ? ' selected' : '') . '>Normal post</option>'
            . '<option value="sponsored"' . ($kind === Posts::KIND_SPONSORED ? ' selected' : '') . '>Sponsored post</option>'
            . '</select></label>'
            . '<label>Sponsor name<input name="sponsor" value="' . self::esc($v('sponsor')) . '" maxlength="160" placeholder="Who paid for it"></label>'
            . '<label>Sponsor link<input name="sponsor_url" type="url" value="' . self::esc($v('sponsor_url')) . '" maxlength="600" placeholder="https://"></label>'
            . '<p class="hint">A sponsored post is labelled on its card, labelled again on its own page, and its link is tagged '
            . '<code>rel="sponsored"</code>. It is kept out of the RSS feed and the news sitemap. This is required, not optional.</p>'
            . '</div>'
            . '</aside></div>'

            . '<div class="footbar"><button class="btn primary" type="submit">Save</button>'
            . ($id ? '<span class="spacer"></span>' : '')
            . '</form>'
            . ($id
                ? '<form method="post" action="' . self::esc($pre . '/delete') . '" class="danger" onsubmit="return confirm(\'Delete this post for good?\')">'
                  . self::csrfField($cfg, $user)
                  . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="confirm" value="delete">'
                  . '<button class="btn ghost" type="submit">Delete</button></form>'
                : '')
            . '</div>';
    }

    private static function accountView(array $cfg, array $user): string
    {
        $pre = Paths::url(self::prefix($cfg));
        $ok  = isset($_GET['changed']) ? '<div class="note good">Password changed. Other devices have been signed out.</div>' : '';

        return $ok . '<div class="bar"><h1>Account</h1><div class="actions">'
            . '<a class="btn" href="' . self::esc($pre) . '">All posts</a></div></div>'
            . '<div class="box narrow">'
            . '<p class="hint">Signed in as <b>' . self::esc((string) $user['username']) . '</b>'
            . ' · last sign-in ' . self::esc(self::when((int) ($user['last_login_at'] ?? 0))) . '</p>'
            . '<form method="post" action="' . self::esc($pre . '/password') . '">'
            . self::csrfField($cfg, $user)
            . '<label>Current password<input name="current" type="password" autocomplete="current-password" required></label>'
            . '<label>New password <span class="hint">12 characters or more</span><input name="new" type="password" minlength="12" autocomplete="new-password" required></label>'
            . '<button class="btn primary" type="submit">Change password</button>'
            . '</form></div>';
    }

    private static function csrfField(array $cfg, array $user): string
    {
        return '<input type="hidden" name="csrf" value="' . self::esc(Auth::csrf($cfg, $user)) . '">';
    }

    /** "8 hours", "12 minutes" — for telling the editor how far off a schedule is. */
    private static function humanGap(int $ms): string
    {
        $m = (int) round($ms / 60000);
        if ($m < 60) {
            return $m . ' minute' . ($m === 1 ? '' : 's');
        }
        $h = (int) round($m / 60);
        if ($h < 48) {
            return $h . ' hour' . ($h === 1 ? '' : 's');
        }

        return (int) round($h / 24) . ' days';
    }

    private static function when(int $ms): string
    {
        if ($ms <= 0) {
            return 'never';
        }

        return date('j M Y, H:i', (int) ($ms / 1000));
    }

    private static function localInput(int $ms): string
    {
        return $ms > 0 ? date('Y-m-d\TH:i', (int) ($ms / 1000)) : '';
    }

    // ---------------------------------------------------------------- chrome

    private static function page(string $body, array $cfg, string $title, int $status): array
    {
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow,noarchive">'
            . '<title>' . self::esc($title) . ' · ' . self::esc((string) ($cfg['site']['name'] ?? '')) . '</title>'
            . '<style>' . self::css() . '</style></head><body>'
            . '<main class="wrap">' . $body . '</main></body></html>';

        return [
            'status'  => $status,
            'headers' => [
                'Content-Type'            => 'text/html; charset=utf-8',
                'Cache-Control'           => 'no-store, private',
                'X-Robots-Tag'            => 'noindex, nofollow',
                'Referrer-Policy'         => 'no-referrer',
                'X-Content-Type-Options'  => 'nosniff',
                'X-Frame-Options'         => 'DENY',
            ],
            'body'    => $html,
        ];
    }

    private static function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}
:root{--ink:#15171a;--ink2:#5b6068;--line:#dfe2e6;--paper:#f6f7f8;--card:#fff;--accent:#7A1B26;--good:#12694a;--bad:#a3231c}
body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:1200px;margin:0 auto;padding:20px 16px 64px}
h1{font-size:22px;margin:0}
h2{font-size:13px;text-transform:uppercase;letter-spacing:.08em;color:var(--ink2);margin:0 0 12px}
a{color:var(--accent)}
.bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 20px;padding-bottom:14px;border-bottom:2px solid var(--ink)}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:0 16px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink);font:600 15px/1 inherit;text-decoration:none;cursor:pointer}
.btn:hover{border-color:var(--ink2)}
.btn.primary{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.ghost{background:transparent;border-color:var(--bad);color:var(--bad)}
.note{padding:12px 14px;border-radius:6px;margin:0 0 16px;font-size:15px}
.note.good{background:#e7f4ee;border:1px solid #b7dcc9;color:var(--good)}
.note.bad{background:#fdecea;border:1px solid #f3c2bd;color:var(--bad)}
.empty{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:40px;text-align:center}
.cards{display:flex;flex-direction:column;gap:8px}
.row{display:flex;gap:14px;align-items:center;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:10px;text-decoration:none;color:inherit}
.row:hover{border-color:var(--ink2)}
.thumb{flex:0 0 160px;height:90px;background:#eceef0;border-radius:4px;overflow:hidden;display:flex;align-items:center;justify-content:center}
.thumb img{width:100%;height:100%;object-fit:cover;display:block}
.nothumb{font-size:12px;color:var(--ink2)}
.meta{display:flex;flex-direction:column;gap:4px;min-width:0}
.hed{font-weight:650;font-size:17px;line-height:1.3}
.when{font-size:13px;color:var(--ink2)}
.tags{display:flex;gap:6px;flex-wrap:wrap}
.tag{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 7px;border-radius:3px}
.tag.live{background:#e7f4ee;color:var(--good)}
.tag.draft{background:#eceef0;color:var(--ink2)}
.tag.sponsored{background:#fff2d6;color:#8a5a00}
.tag.pin{background:#efe6e8;color:var(--accent)}
.tag.sched{background:#fff2d6;color:#8a5a00}
.note.warn{background:#fff8ea;border:1px solid #e9d3a3;color:#6b4700}
.warnhint{color:#8a5a00;font-weight:600}
.grid{display:grid;grid-template-columns:1fr;gap:20px}
@media(min-width:900px){.grid{grid-template-columns:minmax(0,1fr) 340px}}
label{display:block;margin:0 0 14px;font-size:13px;font-weight:600;color:var(--ink2)}
label.big{font-size:14px}
input,select,textarea{display:block;width:100%;margin-top:6px;padding:11px 12px;min-height:44px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink);font:400 16px/1.5 inherit}
label.big input{font:650 20px/1.35 inherit}
textarea{resize:vertical;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:15px}
input:focus,select:focus,textarea:focus{outline:2px solid var(--accent);outline-offset:1px;border-color:var(--accent)}
label.check{display:flex;align-items:center;gap:10px;font-weight:600}
label.check input{width:auto;min-height:auto;margin:0}
label.file input{padding:9px}
.hint{font-weight:400;color:var(--ink2);font-size:12.5px}
p.hint{margin:6px 0 0}
.box{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:16px;margin:0 0 16px}
.box.narrow{max-width:520px}
.preview{margin:0 0 12px}
.preview img{width:100%;border-radius:6px;display:block}
.preview span{display:block;font-size:12.5px;color:var(--ink2);margin-top:6px}
.footbar{display:flex;gap:10px;align-items:center;margin-top:8px;padding-top:16px;border-top:1px solid var(--line)}
.footbar .spacer{flex:1}
.danger{margin-left:auto}
code{background:#eceef0;padding:1px 5px;border-radius:3px;font-size:12.5px}
.signin{max-width:380px;margin:8vh auto;background:var(--card);border:1px solid var(--line);border-radius:10px;padding:28px}
.signin h1{font-size:24px;text-align:center}
.signin .sub{text-align:center;color:var(--ink2);font-size:13px;text-transform:uppercase;letter-spacing:.1em;margin:4px 0 22px}
.signin button{width:100%;min-height:46px;margin-top:6px;background:var(--accent);color:#fff;border:0;border-radius:6px;font:600 16px/1 inherit;cursor:pointer}
@media(max-width:560px){.row{flex-direction:column;align-items:stretch}.thumb{flex:none;width:100%;height:150px}.danger{margin-left:0}.footbar{flex-wrap:wrap}}
CSS;
    }
}
