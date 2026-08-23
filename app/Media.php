<?php

declare(strict_types=1);

namespace TEB;

use PDO;
use Throwable;

/**
 * Uploaded pictures.
 *
 * The dyno's filesystem is wiped on restart, so an uploaded image written to
 * disk would disappear — and unlike a syndicated article we cannot re-fetch it
 * from anywhere. Uploads are therefore resized, re-encoded, stored in the
 * database as base64 and mirrored to D1 alongside the articles. They are served
 * from /media/{id}.jpg with an immutable cache header, so the round trip
 * happens once per reader per image.
 *
 * Re-encoding is not only about size: it strips EXIF (including GPS), and it
 * guarantees the bytes we serve really are an image, because they came out of
 * the image library rather than out of the upload.
 */
final class Media
{
    public const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;   // what we accept
    public const MAX_WIDTH        = 1600;              // what we store
    public const JPEG_QUALITY     = 82;
    /** Refuse anything that would not fit a D1 row once base64-encoded. */
    public const MAX_STORED_BYTES = 1200 * 1024;

    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function available(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * @param  array<string,mixed> $file  one entry from $_FILES
     * @return array{ok:bool,id?:int,error?:string,width?:int,height?:int,bytes?:int}
     */
    public static function store(PDO $p, array $file, string $alt = ''): array
    {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'error' => 'No file was chosen.'];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'The upload did not complete (code ' . $err . '). The file may be too large for the server.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'error' => 'That upload could not be read.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            return ['ok' => false, 'error' => 'Pictures must be smaller than ' . (int) (self::MAX_UPLOAD_BYTES / 1048576) . ' MB.'];
        }

        // Trust the bytes, never the filename or the browser's content-type.
        $info = @getimagesize($tmp);
        if (!is_array($info) || !in_array((string) ($info['mime'] ?? ''), self::ALLOWED, true)) {
            return ['ok' => false, 'error' => 'That file is not a JPEG, PNG, WebP or GIF image.'];
        }
        if (!self::available()) {
            return ['ok' => false, 'error' => 'Image processing is unavailable on this server (the GD extension is missing).'];
        }

        $raw = @file_get_contents($tmp);
        if (!is_string($raw) || $raw === '') {
            return ['ok' => false, 'error' => 'That upload could not be read.'];
        }
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return ['ok' => false, 'error' => 'That image could not be decoded.'];
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 1 || $h < 1) {
            imagedestroy($img);

            return ['ok' => false, 'error' => 'That image has no dimensions.'];
        }

        if ($w > self::MAX_WIDTH) {
            $nh  = (int) round($h * (self::MAX_WIDTH / $w));
            $dst = imagecreatetruecolor(self::MAX_WIDTH, max(1, $nh));
            // Flatten transparency onto white — we encode to JPEG, which has none.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, self::MAX_WIDTH, max(1, $nh), $white);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, self::MAX_WIDTH, max(1, $nh), $w, $h);
            imagedestroy($img);
            $img = $dst;
            $w   = self::MAX_WIDTH;
            $h   = max(1, $nh);
        } elseif (in_array((string) $info['mime'], ['image/png', 'image/webp', 'image/gif'], true)) {
            $dst   = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $w, $h, $white);
            imagecopy($dst, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $dst;
        }

        $quality = self::JPEG_QUALITY;
        $bytes   = '';
        // Step the quality down rather than reject a picture for being a few
        // kilobytes over the row limit.
        for ($try = 0; $try < 4; $try++) {
            ob_start();
            imagejpeg($img, null, $quality);
            $bytes = (string) ob_get_clean();
            if (strlen($bytes) <= self::MAX_STORED_BYTES) {
                break;
            }
            $quality -= 12;
            if ($quality < 40) {
                break;
            }
        }
        imagedestroy($img);

        if ($bytes === '' || strlen($bytes) > self::MAX_STORED_BYTES) {
            return ['ok' => false, 'error' => 'That picture is too large to store even after compression. Try a smaller one.'];
        }

        $st = $p->prepare('INSERT INTO desk_media (mime, width, height, bytes, alt, data, created_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute(['image/jpeg', $w, $h, strlen($bytes), mb_substr($alt, 0, 300), base64_encode($bytes), Db::nowMs()]);
        $id = (int) $p->lastInsertId();

        return ['ok' => true, 'id' => $id, 'width' => $w, 'height' => $h, 'bytes' => strlen($bytes)];
    }

    /** @return array{mime:string,data:string,width:int,height:int}|null */
    public static function fetch(PDO $p, int $id): ?array
    {
        try {
            $st = $p->prepare('SELECT mime, width, height, data FROM desk_media WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($r)) {
            return null;
        }
        $bin = base64_decode((string) $r['data'], true);
        if ($bin === false || $bin === '') {
            return null;
        }

        return ['mime' => (string) $r['mime'], 'data' => $bin, 'width' => (int) $r['width'], 'height' => (int) $r['height']];
    }

    public static function meta(PDO $p, int $id): ?array
    {
        try {
            $st = $p->prepare('SELECT id, mime, width, height, bytes, alt, created_at FROM desk_media WHERE id = ? LIMIT 1');
            $st->execute([$id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);

            return $r === false ? null : $r;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function recent(PDO $p, int $limit = 40): array
    {
        try {
            $st = $p->prepare('SELECT id, width, height, bytes, alt, created_at FROM desk_media ORDER BY id DESC LIMIT ?');
            $st->bindValue(1, $limit, PDO::PARAM_INT);
            $st->execute();

            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
