<?php

declare(strict_types=1);

/**
 * Read a database out of the environment, if the host put one there.
 *
 * Returns null when there is nothing to find, which is the normal case on
 * shared hosting — the settings in the returned array below are then used.
 *
 * Guarded because this file is a `require`d expression, not an include-once:
 * anything that loads the config twice would otherwise die on a redeclare.
 */
if (!function_exists('teb_db_from_env')) {
    function teb_db_from_env(): ?array
    {
        // JawsDB is the usual MySQL add-on on Heroku; ClearDB is the older one.
        // DATABASE_URL is the generic convention used by most other platforms.
        foreach (['JAWSDB_URL', 'JAWSDB_MARIA_URL', 'CLEARDB_DATABASE_URL', 'DATABASE_URL', 'MYSQL_URL'] as $key) {
            $url = getenv($key);
            if (!is_string($url) || trim($url) === '') {
                continue;
            }
            $u = parse_url(trim($url));
            if (!is_array($u) || !isset($u['host'])) {
                continue;
            }

            $scheme = strtolower((string) ($u['scheme'] ?? 'mysql'));
            // Postgres is not supported by this build; say so rather than failing
            // later with an unreadable PDO error.
            if (str_starts_with($scheme, 'postgres')) {
                error_log('[teb] ' . $key . ' is a PostgreSQL database; this build supports MySQL or SQLite. Ignoring it.');
                continue;
            }

            return [
                'driver'  => 'mysql',
                'host'    => (string) $u['host'],
                'port'    => (int) ($u['port'] ?? 3306),
                'name'    => ltrim((string) ($u['path'] ?? ''), '/'),
                'user'    => isset($u['user']) ? rawurldecode((string) $u['user']) : '',
                'pass'    => isset($u['pass']) ? rawurldecode((string) $u['pass']) : '',
                'charset' => 'utf8mb4',
            ];
        }

        // Discrete variables, the other common convention.
        $host = getenv('MYSQL_HOST') ?: getenv('DB_HOST');
        $name = getenv('MYSQL_DATABASE') ?: getenv('DB_NAME');
        if (is_string($host) && $host !== '' && is_string($name) && $name !== '') {
            return [
                'driver'  => 'mysql',
                'host'    => $host,
                'port'    => (int) (getenv('MYSQL_PORT') ?: getenv('DB_PORT') ?: 3306),
                'name'    => $name,
                'user'    => (string) (getenv('MYSQL_USER') ?: getenv('DB_USER') ?: ''),
                'pass'    => (string) (getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: ''),
                'charset' => 'utf8mb4',
            ];
        }

        return null;
    }
}

/**
 * ============================================================================
 *  THE ONLY FILE YOU EVER NEED TO EDIT.
 * ============================================================================
 *
 *  Everything the site shows — its name, its domain, its clock, its database,
 *  its ad slots, its ingest schedule — is set here. Nothing is hardcoded
 *  anywhere else: rename the site in this one file and the whole thing,
 *  including the page titles, the RSS feed, the sitemap and the copyright
 *  line, renames itself.
 *
 *  HOW TO USE IT
 *  -------------
 *  1. Upload the ZIP and unzip it. The site works immediately — you do not
 *     have to touch anything to see it running.
 *  2. Come back here and change 'name', 'domain' and 'tagline' to yours.
 *  3. That's it. Everything below has a working default.
 *
 *  RULES OF THE FILE
 *  -----------------
 *  · It is PHP, so every line ends with a comma and text goes inside 'quotes'.
 *  · If you break the syntax the site shows a blank page — undo your last edit.
 *  · true / false are typed WITHOUT quotes.
 *  · Numbers are typed WITHOUT quotes.
 *  · Keep a copy before you edit. Upgrades never overwrite this file.
 *
 *  WHICH NEWSROOMS ARE READ is the one thing that is NOT here: that is
 *  app/Feeds.php, because each entry carries a licence and a credit line that
 *  has to travel with it.
 * ============================================================================
 */

return [

    /* ------------------------------------------------------------------ site
     | Identity. This is the only place the brand exists.
     |
     | The name is the promise. A plumb line is a weighted string that hangs
     | dead straight and shows what is truly vertical; builders have used one
     | for four thousand years to check that a wall is honest. Everything on
     | this site — the masthead, the About page, the note under every section
     | heading — is written as something held against that line.
     */
    'site' => [

        // Shown in the masthead, the browser tab, the RSS feed and the footer.
        'name' => 'Plumbline News',

        // A 2–4 letter version for tight spaces (mobile tab title, app icon).
        'short_name' => 'PN',

        // Your domain, WITHOUT https:// and WITHOUT a trailing slash.
        //
        // The site does NOT use this to build links — it reads the real
        // address out of the browser request, so the same ZIP works on your
        // live domain, on a staging subdomain, on a raw IP, and inside a
        // subfolder, with no edit. This value is only used when there is no
        // browser to ask: the cron job, the sitemap built from the command
        // line, and anywhere the domain is printed as text.
        'domain' => 'plumblinenews.com',

        // Sits under the masthead. One short line.
        'tagline' => 'A straight line through the day\'s news.',

        // Used for the homepage <meta name="description"> and the RSS feed.
        // Aim for 140–160 characters.
        'description' => 'Politics, the environment, education and public '
            . 'health — full-length reporting from the newsrooms that publish '
            . 'their whole story, not three lines and a link.',

        // The city this edition is dated from. It is printed in the masthead
        // and in the footer and nothing else — no forecast, no lookup, no
        // outside call. Blank it and the dateline simply carries the date.
        'city' => 'Washington',

        // The clock, the edition date and every timestamp use this zone.
        // Washington sets the day on a paper that leads with government.
        // Full list: https://www.php.net/manual/en/timezones.php
        'timezone' => 'America/New_York',

        // Language of the site, used in <html lang> and in the feeds.
        'locale' => 'en_US',

        // The colour mobile browsers paint their toolbar with. Matches the
        // paper the page is printed on — this edition is light, always.
        'theme_color' => '#FFFFFF',
    ],

    /* -------------------------------------------------------------------- db
     | Where the stories are stored.
     |
     | LEAVE THIS ALONE unless you have a reason. The default 'sqlite' needs
     | no database, no username, no password and no cPanel setup: the site
     | creates a single file under data/ on the first page view.
     |
     | Switch to MySQL only if you expect heavy traffic or your host has a
     | slow disk. Create the database and user in cPanel first, then set
     | 'driver' => 'mysql' and fill in the four lines below it.
     */
    // If the host hands us a database in the environment, USE IT and ignore the
    // settings below. Heroku, Railway, Render and friends all work this way, and
    // their local disk is wiped on every restart — so a file-based SQLite
    // database there would lose every story each time the dyno cycles. Adding a
    // MySQL add-on sets one of these variables and this picks it up with no edit.
    // On normal cPanel hosting none of these exist and the values below are used.
    'db' => teb_db_from_env() ?? [

        'driver' => 'sqlite',            // 'sqlite' (no setup) or 'mysql'

        // SQLite only. Relative to this folder. Must stay inside data/,
        // which is blocked from the web by data/.htaccess.
        'sqlite_path' => 'data/news.sqlite',

        // MySQL only — ignored entirely while driver is 'sqlite'.
        'host'    => 'localhost',        // cPanel is nearly always 'localhost'
        'port'    => 3306,
        'name'    => '',                 // e.g. 'cpaneluser_news'
        'user'    => '',                 // e.g. 'cpaneluser_news'
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],

    // The editorial desk. Both values come from the environment: the path is a
    // secret (the desk is only findable if you know it) and the key signs the
    // session cookie and the CSRF tokens. With either missing the desk is not
    // routed at all and every URL under it 404s exactly like any other bad path.
    'admin' => [
        'path'   => (string) (getenv('ADMIN_PATH') ?: ''),
        'secret' => (string) (getenv('ADMIN_SECRET') ?: ''),
    ],

    // Durable mirror. The live store is a local SQLite file, which is fast and
    // needs no service; this is the copy that survives a restart. Cloudflare D1
    // free tier is 5 GB — a thousand times a free hosted MySQL — and article ids
    // are mirrored so URLs stay stable across a wipe.
    'durable' => [
        'enabled'     => (bool) (getenv('D1_TOKEN') && getenv('D1_DATABASE_ID')),
        'account_id'  => (string) (getenv('D1_ACCOUNT_ID') ?: ''),
        'database_id' => (string) (getenv('D1_DATABASE_ID') ?: ''),
        'token'       => (string) (getenv('D1_TOKEN') ?: ''),
        'timeout'     => 20,
    ],

    /* ---------------------------------------------------------------- ingest
     | Fetching the news.
     |
     | Best practice is a cPanel cron job every 10 minutes:
     |     /usr/local/bin/php /home/USER/public_html/cron/ingest.php
     |
     | You do not have to set that up to see the site work — with
     | 'auto_on_empty' on, the first visitor triggers a fetch.
     |
     | ⚠ THE THREE NUMBERS THAT KEEP THE DATABASE SMALL are retention_days,
     | max_items_per_feed and the 24,000-character body cap in app/Xml.php.
     | A free MySQL plan is capped by SIZE, not by traffic, and this site
     | stores whole articles rather than one-line summaries — so three days of
     | eighteen feeds is the entire archive, on purpose. Raising retention to
     | thirty days multiplies the database by ten.
     */
    'ingest' => [

        // Master switch. false = stop fetching entirely (the site keeps
        // serving whatever it already has).
        'enabled' => true,

        // Fetch inline when the database is empty or the newest story is
        // older than 'stale_after_minutes'. This is what makes the very
        // first page view show real news with no cron job configured.
        'auto_on_empty' => true,

        // How old the newest story may get before a page view triggers a
        // fetch of its own. Raise it if your host is slow.
        'stale_after_minutes' => 20,

        // Optional. Set a long random word here and you can trigger a fetch
        // from a browser or an uptime monitor:
        //     https://yourdomain.com/index.php?__ingest=YOURWORD
        // Empty means that URL is switched off, which is the safe default.
        // A scheduler calls /admin/ingest?token=... on a timetable. Set it here,
        // or leave it blank and set an INGEST_TOKEN environment variable — which
        // is how platform hosts do it. Blank in both places CLOSES the route.
        'token' => (string) (getenv('INGEST_TOKEN') ?: ''),

        // Seconds allowed per run for measuring new images. Measuring is what
        // stops a 60x60 thumbnail being blown up into a lead photo. 0 disables
        // it, and then unmeasured images are only ever used in small cards.
        'image_measure_seconds' => 20,

        // Seconds to wait on any one publisher before giving up on it.
        // A slow feed must never hold up the others.
        'timeout_seconds' => 12,

        // How many feeds to fetch in a single run. The registry holds 18 and
        // they are rotated most-overdue-first, so the three ten-minute feeds
        // always win a slot and the hourly ones still get their turn.
        'batch' => 12,

        // Most stories taken from any one feed in a single run. These
        // publishers run 10–50 items per feed and the fastest posts eleven
        // stories a day, so thirty is several days of everything — and it is
        // the difference between a small database and a large one.
        'max_items_per_feed' => 30,

        // Delete stories older than this many days. Three days of full-length
        // articles is what keeps a free database plan inside its size limit.
        // 60 days, not 3. These sources publish over WEEKS — a measured 300 of 359
        // articles were older than three days — so a short window silently threw
        // away 83% of the site. The durable mirror is 5 GB and the whole corpus
        // is a few megabytes, so there is nothing to save by pruning early.
        'retention_days' => 60,
    ],

    /* --------------------------------------------------------------- compose
     | How the front page is built.
     |
     | ⚠ The finance limits are a business decision, not decoration. This is
     | a general-news site bought with general-news advertising; a front page
     | that opens with markets reads as a finance site and turns that audience
     | away. Money stories still get their own section page and a quiet strip
     | at the bottom of the front page — they just never lead.
     */
    'compose' => [

        // Most business stories allowed anywhere on the front page. This
        // edition leads on government and the environment, so it is one.
        'finance_max_on_home' => 1,

        // Areas of the front page money stories may never appear in, no
        // matter how big the news. 'hero' is the top of the page; the rest
        // are the four desks that lead this edition.
        'finance_blocked_blocks' => ['hero', 'politics', 'environment', 'education'],

        // How many secondary stories sit beside the lead story up top.
        'hero_sub_count' => 4,

        // Most stories one FEED may supply to a single block. Several desks
        // here are fed by a single newsroom, so a cap of two would leave them
        // permanently half empty; three fills a block without letting one
        // feed own it.
        'per_source_cap_per_block' => 3,

        // Headlines in the scrolling ticker across the top. It is deliberately
        // longer than the number of desks so the ticker cycles several of
        // them rather than repeating one.
        'ticker_count' => 14,
    ],

    /* -------------------------------------------------------------- rotation
     | The top of the front page changes on its own, without reloading: the
     | page asks the site for a fresh set of lead stories on this timetable
     | and swaps them in.
     |
     | 'enabled' => false stops it dead: the page is served as it is and never
     | polls. 'seconds' is how long each set is held, clamped to 30-600 — under
     | 60 is distracting, over 120 and a reader never sees it happen. 'count'
     | is how many of the top cards change on each turn, capped at however many
     | the layout actually has, so setting it high is safe.
     |
     | All three are read by app/Render.php and published on the hero, which is
     | what assets/js/app.js times itself on. Change them here and nowhere else.
     */
    'rotation' => [
        'enabled' => true,
        'seconds' => 90,
        'count'   => 5,
    ],

    /* ------------------------------------------------------------------- ads
     | Advertising slots.
     |
     | Every slot is drawn at its exact size from the moment the page loads,
     | whether ads are on or off, so switching them on never makes the page
     | jump around. Nothing is requested from any ad network until you turn
     | this on AND paste a real ad tag in.
     |
     | ⚠ Turning this on has a licence consequence. Some newsrooms in
     | app/Feeds.php licence their work for non-commercial use only, and an
     | ad-supported page is a commercial use — those sources are already set
     | to run as a headline, a short extract and a link, and they must stay
     | that way for as long as this is true.
     */
    'ads' => [

        'enabled' => false,              // true when your ad code is ready

        // name => [width, height] in pixels.
        'slots' => [
            'leaderboard' => [970, 250], // wide banner under the masthead
            'rail'        => [300, 600], // tall unit beside the stories
            'inline'      => [728, 90],  // between two blocks of stories
        ],
    ],

    /* ----------------------------------------------------------------- cache
     | How long a built page may be reused before it is built again, in
     | seconds. Higher numbers = a faster, cheaper site that updates a little
     | less often. 0 switches caching off for that page type.
     |
     | The front page is cached for less time than the fastest feed's fetch
     | interval, so a new lead story is never held back by the cache.
     */
    'cache' => [
        'home_seconds'    => 120,        // 2 minutes — the front page moves
        'section_seconds' => 300,        // 5 minutes
        'article_seconds' => 900,        // 15 minutes — these barely change
    ],
];
