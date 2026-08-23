<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * Authentication for the editorial desk.
 *
 * Design notes, because each of these is a deliberate choice:
 *
 *  - **Sessions are a signed cookie, not a server-side table.** The dyno's disk
 *    is wiped on restart, so a session table would log the editor out every
 *    time the app redeployed. The cookie carries the user id and an expiry,
 *    signed with HMAC-SHA256 using a secret that never leaves the server. It
 *    also carries a fingerprint of the password hash, so changing the password
 *    invalidates every outstanding session immediately.
 *  - **Argon2id** for the password itself, which is what PHP recommends and
 *    what resists GPU cracking. bcrypt is the fallback if the build lacks it.
 *  - **Lockout is per username AND per IP.** Locking only the username lets
 *    anyone lock the editor out of their own site; locking only the IP is
 *    trivially defeated. Both, and the counter lives in the database.
 *  - **The login page never says which half was wrong.** "Username or password
 *    is incorrect" for both, and the same work is done either way so the reply
 *    takes the same time whether or not the account exists.
 */
final class Auth
{
    public const COOKIE      = 'pl_desk';
    private const TTL        = 43200;   // 12 hours
    private const MAX_FAILS  = 5;
    private const LOCK_SECS  = 900;     // 15 minutes

    /** @var array<string,mixed>|null */
    private static ?array $user = null;
    private static bool $resolved = false;

    // ------------------------------------------------------------------ setup

    public static function secret(array $cfg): string
    {
        $s = (string) ($cfg['admin']['secret'] ?? '');

        return $s;
    }

    /** The desk is unreachable unless a real secret is configured. */
    public static function configured(array $cfg): bool
    {
        return strlen(self::secret($cfg)) >= 32;
    }

    public static function hash(string $password): string
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return (string) password_hash($password, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost'   => 4,
                'threads'     => 2,
            ]);
        }

        return (string) password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    // ------------------------------------------------------------------ users

    public static function findUser(PDO $p, string $username): ?array
    {
        try {
            $st = $p->prepare('SELECT * FROM desk_users WHERE username = ? LIMIT 1');
            $st->execute([strtolower(trim($username))]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            return $r === false ? null : $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function createUser(PDO $p, string $username, string $password, string $role = 'editor'): int
    {
        $st = $p->prepare(
            'INSERT INTO desk_users (username, pass_hash, role, created_at, last_login_at, fail_count) '
            . 'VALUES (?, ?, ?, ?, 0, 0)'
        );
        $st->execute([strtolower(trim($username)), self::hash($password), $role, Db::nowMs()]);

        return (int) $p->lastInsertId();
    }

    public static function setPassword(PDO $p, int $uid, string $password): void
    {
        $st = $p->prepare('UPDATE desk_users SET pass_hash = ? WHERE id = ?');
        $st->execute([self::hash($password), $uid]);
    }

    // ------------------------------------------------------------------ login

    /**
     * @return array{ok:bool,user?:array<string,mixed>,error?:string,retry_after?:int}
     */
    public static function attempt(PDO $p, array $cfg, string $username, string $password, string $ip): array
    {
        $username = strtolower(trim($username));
        $now      = Db::nowMs();

        $locked = self::lockedFor($p, $username, $ip, $now);
        if ($locked > 0) {
            return ['ok' => false, 'error' => 'Too many attempts. Try again in ' . ceil($locked / 60) . ' minutes.', 'retry_after' => $locked];
        }

        $user = self::findUser($p, $username);

        // Always run a verify, even with no such user, so the response time does
        // not reveal whether the account exists.
        $hash = is_array($user) ? (string) $user['pass_hash'] : '$argon2id$v=19$m=65536,t=4,p=2$YWJjZGVmZ2hpamts$0000000000000000000000000000000000000000000';
        $ok   = password_verify($password, $hash) && is_array($user);

        if (!$ok) {
            self::recordFail($p, $username, $ip, $now);

            return ['ok' => false, 'error' => 'Username or password is incorrect.'];
        }

        self::clearFails($p, $username, $ip);
        try {
            $p->prepare('UPDATE desk_users SET last_login_at = ? WHERE id = ?')->execute([$now, (int) $user['id']]);
        } catch (Throwable $e) {
            // a read-only database must not block a valid login
        }

        return ['ok' => true, 'user' => $user];
    }

    private static function lockedFor(PDO $p, string $username, string $ip, int $now): int
    {
        try {
            $st = $p->prepare(
                'SELECT COUNT(*) FROM desk_login_fails WHERE at > ? AND (username = ? OR ip = ?)'
            );
            $st->execute([$now - self::LOCK_SECS * 1000, $username, $ip]);
            $n = (int) $st->fetchColumn();
            if ($n < self::MAX_FAILS) {
                return 0;
            }
            $st2 = $p->prepare('SELECT MAX(at) FROM desk_login_fails WHERE at > ? AND (username = ? OR ip = ?)');
            $st2->execute([$now - self::LOCK_SECS * 1000, $username, $ip]);
            $last = (int) $st2->fetchColumn();

            return max(1, (int) ((($last + self::LOCK_SECS * 1000) - $now) / 1000));
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function recordFail(PDO $p, string $username, string $ip, int $now): void
    {
        try {
            $p->prepare('INSERT INTO desk_login_fails (username, ip, at) VALUES (?, ?, ?)')
              ->execute([$username, $ip, $now]);
            $p->prepare('DELETE FROM desk_login_fails WHERE at < ?')->execute([$now - 86400000]);
        } catch (Throwable $e) {
        }
    }

    private static function clearFails(PDO $p, string $username, string $ip): void
    {
        try {
            $p->prepare('DELETE FROM desk_login_fails WHERE username = ? OR ip = ?')->execute([$username, $ip]);
        } catch (Throwable $e) {
        }
    }

    // ------------------------------------------------------------------ cookie

    public static function issue(array $cfg, array $user): string
    {
        $payload = [
            'u' => (int) $user['id'],
            'e' => time() + self::TTL,
            'f' => substr(hash('sha256', (string) $user['pass_hash']), 0, 16),
        ];
        $body = self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '');
        $sig  = self::b64(hash_hmac('sha256', $body, self::secret($cfg), true));

        return $body . '.' . $sig;
    }

    public static function setCookie(array $cfg, string $value, bool $clear = false): void
    {
        if (headers_sent()) {
            return;
        }
        $secure = Paths::scheme() === 'https';
        setcookie(self::COOKIE, $clear ? '' : $value, [
            'expires'  => $clear ? time() - 3600 : time() + self::TTL,
            'path'     => Paths::base() === '' ? '/' : Paths::base() . '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /** The signed-in user, or null. Resolved once per request. */
    public static function user(PDO $p, array $cfg): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        self::$user     = null;

        if (!self::configured($cfg)) {
            return null;
        }
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($raw === '' || substr_count($raw, '.') !== 1) {
            return null;
        }
        [$body, $sig] = explode('.', $raw, 2);
        $expect = self::b64(hash_hmac('sha256', $body, self::secret($cfg), true));
        if (!hash_equals($expect, $sig)) {
            return null;
        }
        $data = json_decode((string) self::unb64($body), true);
        if (!is_array($data) || (int) ($data['e'] ?? 0) < time()) {
            return null;
        }
        try {
            $st = $p->prepare('SELECT * FROM desk_users WHERE id = ? LIMIT 1');
            $st->execute([(int) ($data['u'] ?? 0)]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($u)) {
            return null;
        }
        // A changed password invalidates every session that was already issued.
        if (!hash_equals(substr(hash('sha256', (string) $u['pass_hash']), 0, 16), (string) ($data['f'] ?? ''))) {
            return null;
        }

        return self::$user = $u;
    }

    // ------------------------------------------------------------------ CSRF

    public static function csrf(array $cfg, ?array $user): string
    {
        $uid = (int) ($user['id'] ?? 0);
        $day = (int) floor(time() / 3600);

        return self::b64(hash_hmac('sha256', 'csrf|' . $uid . '|' . $day, self::secret($cfg), true));
    }

    public static function checkCsrf(array $cfg, ?array $user, string $given): bool
    {
        if ($given === '') {
            return false;
        }
        $uid = (int) ($user['id'] ?? 0);
        // Accept this hour and the previous one, so a form left open briefly
        // still submits instead of throwing the editor's work away.
        foreach ([0, -1] as $off) {
            $day = (int) floor(time() / 3600) + $off;
            $t   = self::b64(hash_hmac('sha256', 'csrf|' . $uid . '|' . $day, self::secret($cfg), true));
            if (hash_equals($t, $given)) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ misc

    public static function clientIp(): string
    {
        // Heroku and every CDN put the real address first in X-Forwarded-For.
        $fwd = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($fwd !== '') {
            $first = trim(explode(',', $fwd)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $ra = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

        return filter_var($ra, FILTER_VALIDATE_IP) ? $ra : '0.0.0.0';
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
    }
}
