<?php
// Dev-only: emulates the .htaccess internal rewrite for PHP's built-in server,
// which has no mod_rewrite. Never shipped in the ZIP.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$root = $_SERVER['DOCUMENT_ROOT'];
$file = realpath($root . $path);
if ($file && is_file($file) && strncmp($file, realpath($root), strlen(realpath($root))) === 0) {
    return false; // serve the real file
}

// Apache's internal rewrite lands every unmatched path on index.php and says so
// in SCRIPT_NAME. php -S does NOT: for a path that carries a file extension —
// /api/top.json, /sitemap.xml, /robots.txt, /feed.xml, /placeholder.svg — it
// reports SCRIPT_NAME as the REQUEST PATH and SCRIPT_FILENAME as this router.
// Paths::deriveBase() reads both, so all of those routes were silently deriving
// a base of '/scripts' and emitting links nobody could follow. Local-only, but
// exactly the kind of illusion that gets "verified" and then shipped.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';

$_SERVER['TEB_REWRITE'] = '1';
require $root . '/index.php';
