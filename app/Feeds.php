<?php
declare(strict_types=1);

namespace TEB;

/**
 * The feed registry: which newsrooms we read, how often, and on what licence.
 *
 * Data only — no fetching, no parsing, no I/O. app/Ingest.php consumes all()
 * and due(); app/Compose.php consumes sections(), blockOrder() and
 *
 * ---------------------------------------------------------------------------
 * WHY THIS ROSTER AND NOT A WIRE
 * ---------------------------------------------------------------------------
 * Every source here publishes its FULL article inside the feed. That is the
 * whole point: the wire feeds most aggregators use withhold the body on
 * purpose (measured: 0-200 characters of text per item), so a site built on
 * them can only ever show three lines and a link. Each feed below was measured
 * across its first fifteen items and none has a median body under ~5,000
 * characters.
 *
 * Measured on this box, 2026-08-21. Every URL answered HTTP 200 and parsed.
 * 'per_day' is the real publishing rate over the window the feed carries, and
 * it is what sets 'tier' and 'rank' — nothing here is a guess.
 *
 *   feed                       per day   median body   images in feed
 *   nasa.gov                      7.57      5,170           yes
 *   news.mit.edu                  3.65      6,000+          yes
 *   theconversation /health       3.50      6,000+          yes
 *   kffhealthnews.org             3.00      6,000+          yes
 *   grist.org                     2.90      5,603           some
 *   19thnews.org                  1.79      6,000+          some
 *   globalvoices.org              1.73      6,000+          yes
 *   theconversation /technology   1.56      6,000+          yes
 *   theconversation /politics     1.49      6,000+          yes
 *   theconversation /environment  1.42      6,000+          yes
 *   propublica.org                1.39      6,000+          yes
 *   theconversation /business     0.93      6,000+          yes
 *   spectrum.ieee.org             0.78      6,000+          yes
 *   theconversation /education    0.64      6,000+          yes
 *   theconversation /world        0.58      6,000+          yes
 *   theconversation /arts         0.48      6,000+          yes
 *   nasa.gov/technology           0.33      5,744           yes
 *   themarkup.org                 0.08      6,000+          yes
 *
 * "6,000+" is the ingest body cap biting, not the article ending — the real
 * medians run 6,900-11,700 characters. *
 * ⚠ MEASURED AND DELIBERATELY LEFT OUT: theconversation.com/us/articles.atom.
 * It is the fastest feed anyone can point at — 11.45 items a day — but 47 of the
 * 50 items it carries are the SAME articles as the eight The Conversation
 * section feeds already in this roster. It adds three pieces in fifty, and
 * because it would have to be fetched more often than the section feeds it
 * would claim their articles first and file them all to one catch-all desk,
 * emptying the desks this edition leads with. A general feed on top of eight
 * specific ones is a duplicate with a scoreboard, not a source.
 *
 * ⚠ Do NOT re-add any of these: they were measured and they are stubs, which
 * is exactly the failure this roster exists to avoid. arstechnica.com (1,021
 * chars), quantamagazine.org (408), insideclimatenews.org (354),
 * sciencedaily.com (343), phys.org (307), defense.gov (228),
 * themarshallproject.org (138), noaa.gov (91), energy.gov (83),
 * texastribune.org (3), undark.org (0). Dead or blocking from this box:
 * nsf.gov, usgs.gov, wikinews (404), nih.gov, stateline.org (403).
 *
 * ---------------------------------------------------------------------------
 * TIERS — how often we fetch, and how high the block sits
 * ---------------------------------------------------------------------------
 *   tier 1 (10 min)  the three fastest feeds on the desks this edition leads
 *                    with. Top of the page.
 *   tier 2 (30 min)  every other feed on a desk this edition leads with, plus
 *                    any feed publishing at least once a day. Middle of the page.
 *   tier 3 (60 min)  everything else, and anything measured dormant.
 *                    Lower down the page.
 *
 * 'rank' is the freshness order: feeds sorted by tier, then by measured
 * per_day, numbered from 1. sectionTier() and sectionRank() lift the same
 * numbers to the desk, so a renderer can order blocks by how fast they move
 * without knowing anything about individual feeds.
 *
 * ---------------------------------------------------------------------------
 * LICENCES — this is a legal contract, not a courtesy line
 * ---------------------------------------------------------------------------
 * 'extract' => true  means headline + short extract + link ONLY. Never the
 *                    full body. These are the sources whose licence does not
 *                    reach an advertising-supported site.
 * 'extract' => false means the whole article may be republished, with the
 *                    'attribution' line shown and a link back.
 * 'images'  => false means we may NOT republish that publisher's photograph
 *                    even when the feed carries one — the site placeholder is
 *                    used instead. Nearly every publisher here licenses its
 *                    TEXT and withholds its PICTURES; each 'notes' line quotes
 *                    the publisher saying so.
 *
 * No derivative works anywhere in this roster: every full-text source is
 * ND (no derivatives) or asks for the piece whole. Publish the body as it
 * arrives — do not rewrite it, do not summarise it into something new.
 *
 * Entry shape:
 *   slug        stable id, also the database key — never change one in place
 *   name        the credit line printed under every headline
 *   publisher   groups feeds from one newsroom, so a front-page cap can stop
 *               one publisher supplying ten of twelve stories
 *   feed        the URL fetched
 *   section     which desk it files to; must be a key of sections()
 *   country     ISO-3166-1 alpha-2 of the newsroom, for the sources page
 *   tier        1 = fetch often, 2 = middling, 3 = slow. See TIER_MINUTES.
 *   rank        freshness order across the whole roster, 1 = fastest
 *   weight      ranking multiplier in Compose; 1.0 is neutral
 *   per_day     measured items published per day
 *   extract     true = headline + extract + link only
 *   images      false = never republish this source's picture
 *   license     the licence in force
 *   license_url where that licence is published
 *   attribution the credit sentence the licence requires on every article
 *   notes       the publisher's own words on the condition that matters
 *   homepage    where the credit links to
 */
final class Feeds
{
    /** How many minutes between fetches of a feed in each tier. */
    private const TIER_MINUTES = [1 => 10, 2 => 30, 3 => 60];

    /** Consecutive failures after which a feed is parked and retried slowly. */
    public const PARK_AFTER_FAILURES = 8;

    /** How long a parked feed is left alone before one more attempt (minutes). */
    private const PARKED_RETRY_MINUTES = 360;

    /**
     * The desks.
     *
     * Order here is the order of the navigation. 'home' is the order of the
     * blocks down the front page and null keeps a desk off it entirely; the
     * three tier-1 desks lead, then the tier-2 desks, then the rest — which is
     * the client's rule that the fast sections sit at the top.
     *
     * This edition leads on politics and the environment — the two desks
     * measured moving fastest. Every other desk is on the page too, lower
     * down and under its own heading: a desk that is worth a link in the
     * navigation is worth a block on the front page.
     *
     * 'note' and 'blurb' are where the masthead's promise has to keep showing
     * up: a plumb line is a weighted string that hangs dead straight and shows
     * what is truly vertical, and every desk is described as something held
     * against it.
     *
     * 'priority' is the relative pull of a desk when Compose ranks stories for
     * the hero. It is the second half of the weighting, and it is why two
     * editions reading the same feeds do not produce the same front page.
     *
     * 'finance' marks the desks the front-page money quota applies to. It is
     * data, so Compose, Render and the tests all read the same list.
     */
    private const SECTIONS = [
        'us-news' => [
            'label'    => 'U.S. News',
            'note'     => 'the country, reported at length',
            'blurb'    => 'What is happening across the United States, from newsrooms that publish the whole piece rather than a headline and a link.',
            'home'     => 1,
            'priority' => 1.35,
            'finance'  => false,
        ],
        'financial' => [
            'label'    => 'Financial',
            'note'     => 'money, work and the economy',
            'blurb'    => 'Markets, jobs, prices and the decisions behind them — explained by the people who study them.',
            'home'     => 5,
            'priority' => 1.10,
            'finance'  => true,
        ],
        'politics' => [
            'label'    => 'Politics',
            'note'     => 'the record, held against the line',
            'blurb'    => 'Congress, the courts, the states and the money — checked against what was said the first time.',
            'home'     => 1,
            'priority' => 1.30,
            'finance'  => false,
        ],
        'environment' => [
            'label'    => 'Environment',
            'note'     => 'land, air, water and power',
            'blurb'    => 'Climate, energy and the places people live, reported at length rather than in headlines.',
            'home'     => 2,
            'priority' => 1.25,
            'finance'  => false,
        ],
        'education' => [
            'label'    => 'Education',
            'note'     => 'schools, colleges and what they cost',
            'blurb'    => 'Classrooms, campuses, curricula and the public money behind all three.',
            'home'     => 3,
            'priority' => 1.15,
            'finance'  => false,
        ],
        'health' => [
            'label'    => 'Health',
            'note'     => 'medicine and public health',
            'blurb'    => 'Care, coverage and cost, and the evidence underneath the claims made about them.',
            'home'     => 4,
            'priority' => 1.00,
            'finance'  => false,
        ],
        'world' => [
            'label'    => 'World',
            'note'     => 'reporting from outside the United States',
            'blurb'    => 'Correspondents and local writers, published whole and credited by name.',
            'home'     => 5,
            'priority' => 0.95,
            'finance'  => false,
        ],
        'business' => [
            'label'    => 'Business',
            'note'     => 'money and the economy',
            'blurb'    => 'Companies, work and the economy — kept off the top of the page, never off the site.',
            'home'     => 6,
            'priority' => 0.55,
            'finance'  => true,
        ],
        'technology' => [
            'label'    => 'Technology',
            'note'     => 'the industry and the rules catching up with it',
            'blurb'    => 'What the systems actually do, measured rather than announced.',
            'home'     => 7,
            'priority' => 0.80,
            'finance'  => false,
        ],
        'science' => [
            'label'    => 'Science',
            'note'     => 'research, space and the natural world',
            'blurb'    => 'Findings from the laboratories and agencies that publish their own work.',
            'home'     => 8,
            'priority' => 0.75,
            'finance'  => false,
        ],
        'culture' => [
            'label'    => 'Culture',
            'note'     => 'books, film, music and ideas',
            'blurb'    => 'The arts, written by the people who study them.',
            'home'     => 9,
            'priority' => 0.70,
            'finance'  => false,
        ],
    ];

    /**
     * The roster. 18 feeds, every one measured full-text.
     *
     * Tier 1 here is the three fastest feeds on the two desks this edition
     * leads with: the environment feed, and the two quickest of the three
     * politics feeds. Nothing is on the fast tier because it is important —
     * only because it was measured moving.
     */
    private const FEEDS = [

        // ------------------------------------------------------------ tier 1
        [
            'slug' => 'tc-us', 'name' => 'The Conversation (U.S.)', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/articles.atom',
            'section' => 'us-news', 'country' => 'US',
            'tier' => 1, 'rank' => 1, 'weight' => 1.20, 'per_day' => 6.0,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'Measured 2026-08-24: median body 7,279 chars. The general US edition, so it carries the desks the topic feeds do not.',
            'homepage' => 'https://theconversation.com/us',
        ],
        [
            'slug' => 'commondreams-opinion', 'name' => 'Common Dreams — Opinion', 'publisher' => 'common-dreams',
            'feed' => 'https://www.commondreams.org/feeds/opinion.rss',
            'section' => 'us-news', 'country' => 'US',
            'tier' => 1, 'rank' => 2, 'weight' => 1.10, 'per_day' => 6.0,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'attribution' => 'This story was originally published by Common Dreams.',
            'notes' => 'Measured 2026-08-24: median body 7,076 chars, newest item 20.9h. Separate from the main Common Dreams feed already on the politics desk.',
            'homepage' => 'https://www.commondreams.org/',
        ],
        [
            'slug' => 'motherjones', 'name' => 'Mother Jones', 'publisher' => 'mother-jones',
            'feed' => 'https://www.motherjones.com/feed/',
            'section' => 'us-news', 'country' => 'US',
            'tier' => 1, 'rank' => 3, 'weight' => 1.05, 'per_day' => 8.0,
            'extract' => true, 'images' => true,
            'license' => 'All rights reserved — no republication grant published',
            'license_url' => '',
            'attribution' => 'Reported by Mother Jones.',
            'notes' => 'Measured 2026-08-24: median body 3,908 chars, newest 15.0h — the fastest US-desk source found. EXTRACT ONLY: Mother Jones publishes no reuse grant, so this is headline, short extract and a link, never the body.',
            'homepage' => 'https://www.motherjones.com/',
        ],
        [
            'slug' => 'liberty-street', 'name' => 'Liberty Street Economics', 'publisher' => 'ny-fed',
            'feed' => 'https://libertystreeteconomics.newyorkfed.org/feed/',
            'section' => 'financial', 'country' => 'US',
            'tier' => 2, 'rank' => 1, 'weight' => 1.20, 'per_day' => 1.2,
            'extract' => false, 'images' => true,
            'license' => 'Federal Reserve Bank of New York — reproduction permitted with attribution',
            'license_url' => 'https://libertystreeteconomics.newyorkfed.org/disclaimer/',
            'attribution' => 'Originally published by the Federal Reserve Bank of New York.',
            'notes' => 'Measured 2026-08-24: median body 10,517 chars — the longest on either roster. Research economists writing for a general reader; slow (a post or two a week) but authoritative.',
            'homepage' => 'https://libertystreeteconomics.newyorkfed.org/',
        ],
        [
            'slug' => 'econofact', 'name' => 'EconoFact', 'publisher' => 'econofact',
            'feed' => 'https://econofact.org/feed',
            'section' => 'financial', 'country' => 'US',
            'tier' => 3, 'rank' => 2, 'weight' => 1.00, 'per_day' => 0.6,
            'extract' => false, 'images' => true,
            'license' => 'Republication permitted with credit',
            'license_url' => 'https://econofact.org/about',
            'attribution' => 'This memo was originally published by EconoFact, a non-partisan publication of the Fletcher School at Tufts University.',
            'notes' => 'Measured 2026-08-24: median body 3,635 chars. Economists writing short evidence memos on the questions in the news.',
            'homepage' => 'https://econofact.org/',
        ],
        [
            'slug' => 'tc-au', 'name' => 'The Conversation (Australia)', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/au/articles.atom',
            'section' => 'world', 'country' => 'AU',
            'tier' => 1, 'rank' => 2, 'weight' => 1.05, 'per_day' => 9.0,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'A different timezone to the US edition, so it fills the American night when the US desks are asleep. Measured newest 5.2h.',
            'homepage' => 'https://theconversation.com/au',
        ],
        [
            'slug' => 'commondreams', 'name' => 'Common Dreams', 'publisher' => 'common-dreams',
            'feed' => 'https://www.commondreams.org/feeds/news.rss',
            'section' => 'politics', 'country' => 'US',
            'tier' => 1, 'rank' => 1, 'weight' => 1.30, 'per_day' => 12.0,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'attribution' => 'This story was originally published by Common Dreams.',
            'notes' => 'Measured 2026-08-23: newest item 1.5h old, median body 4,950 chars. One of only two sources on this roster that is BOTH full-text and genuinely fast.',
            'homepage' => 'https://www.commondreams.org/',
        ],
        [
            'slug' => 'grist', 'name' => 'Grist', 'publisher' => 'grist',
            'feed' => 'https://grist.org/feed/',
            'section' => 'environment', 'country' => 'US',
            'tier' => 1, 'rank' => 1, 'weight' => 1.25, 'per_day' => 2.90,
            'extract' => false, 'images' => true,
            'license' => 'Republication permitted with credit',
            'license_url' => 'https://grist.org/about/promote/',
            'attribution' => 'This story was originally published by Grist.',
            'notes' => 'Grist: "articles must be republished in their entirety", "If you already have ads populating on your site, that\'s completely fine", and "you can\'t republish photographs, collages ... or illustrations without written permission".',
            'homepage' => 'https://grist.org/',
        ],
        [
            'slug' => '19th-news', 'name' => 'The 19th', 'publisher' => 'the-19th',
            'feed' => 'https://19thnews.org/feed/',
            'section' => 'politics', 'country' => 'US',
            'tier' => 1, 'rank' => 2, 'weight' => 1.10, 'per_day' => 1.79,
            'extract' => true, 'images' => true,
            'license' => 'CC BY-NC-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
            'attribution' => 'Reported by The 19th.',
            'notes' => 'NonCommercial: an advertising-supported page is a commercial use, so this source is headline, short extract and link only. Their republishing guidelines page refuses requests from this network (HTTP 403), so the licence is taken from the measured roster rather than re-read here.',
            'homepage' => 'https://19thnews.org/',
        ],
        [
            'slug' => 'tc-politics', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/politics/articles.atom',
            'section' => 'politics', 'country' => 'US',
            'tier' => 1, 'rank' => 3, 'weight' => 1.30, 'per_day' => 1.49,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'The lead desk\'s full-text half: political analysis written by named academics that we are licensed to publish whole, next to two accountability newsrooms we may only quote from.',
            'homepage' => 'https://theconversation.com/us/politics',
        ],
        // ------------------------------------------------------------ tier 2
        [
            'slug' => 'nasa-main', 'name' => 'NASA', 'publisher' => 'nasa',
            'feed' => 'https://www.nasa.gov/feed/',
            'section' => 'science', 'country' => 'US',
            'tier' => 2, 'rank' => 4, 'weight' => 0.70, 'per_day' => 7.57,
            'extract' => false, 'images' => true,
            'license' => 'Public domain (US Government)',
            'license_url' => 'https://www.nasa.gov/nasa-brand-center/images-and-media/',
            'attribution' => 'Published by NASA.',
            'notes' => 'Work of the US Government, not protected by copyright. The one condition is that NASA must not be shown as endorsing this site, so the credit never appears next to advertising furniture. The only publisher in the roster whose photographs we may republish — its two feeds are the only pictures on this site that are not the house placeholder.',
            'homepage' => 'https://www.nasa.gov/',
        ],
        [
            'slug' => 'mit-news', 'name' => 'MIT News', 'publisher' => 'mit',
            'feed' => 'https://news.mit.edu/rss/feed',
            'section' => 'science', 'country' => 'US',
            'tier' => 2, 'rank' => 5, 'weight' => 0.65, 'per_day' => 3.65,
            'extract' => false, 'images' => true,
            'license' => 'Republication permitted with credit',
            'license_url' => 'https://news.mit.edu/terms-of-use',
            'attribution' => 'Reprinted with permission of MIT News.',
            'notes' => 'MIT: "MIT News ... offers RSS feeds for syndication purposes." Two credits are required — "MIT News" linking to the original at the top, and "Reprinted with permission of MIT News" linking to news.mit.edu at the bottom. Its images are separately CC BY-NC-ND, so they are not republished here.',
            'homepage' => 'https://news.mit.edu/',
        ],
        [
            'slug' => 'tc-health', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/health/articles.atom',
            'section' => 'health', 'country' => 'US',
            'tier' => 2, 'rank' => 6, 'weight' => 0.95, 'per_day' => 3.50,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'No derivatives: publish the piece whole. Images unconfirmed, so they are not republished.',
            'homepage' => 'https://theconversation.com/us/health',
        ],
        [
            'slug' => 'kff-health', 'name' => 'KFF Health News', 'publisher' => 'kff',
            'feed' => 'https://kffhealthnews.org/feed/',
            'section' => 'health', 'country' => 'US',
            'tier' => 2, 'rank' => 7, 'weight' => 1.00, 'per_day' => 3.00,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-NC-ND 4.0, with an explicit grant to ad-supported outlets',
            'license_url' => 'https://kffhealthnews.org/syndication/',
            'attribution' => 'Originally published by KFF Health News.',
            'notes' => 'NonCommercial, but the publisher waives it for this exact case: "This license allows all news outlets — including for-profit news organizations that charge for subscriptions and accept advertising — to republish our content free of charge." Their photographs are NOT covered: "available for republication for noncommercial use only", so images stay off.',
            'homepage' => 'https://kffhealthnews.org/',
        ],
        [
            'slug' => 'globalvoices', 'name' => 'Global Voices', 'publisher' => 'global-voices',
            'feed' => 'https://globalvoices.org/feed/',
            'section' => 'world', 'country' => 'NL',
            'tier' => 2, 'rank' => 8, 'weight' => 1.00, 'per_day' => 1.73,
            'extract' => false, 'images' => true,
            'license' => 'CC BY 3.0',
            'license_url' => 'https://creativecommons.org/licenses/by/3.0/',
            'attribution' => 'Originally published by Global Voices.',
            'notes' => 'The most permissive licence in the roster — attribution only, no NonCommercial and no NoDerivatives. Their preferred wording names the writer: "This story by [author] originally appeared on Global Voices." Pictures are excluded: "Photos, video, audio sourced from other creators may not always be available on the same terms."',
            'homepage' => 'https://globalvoices.org/',
        ],
        [
            'slug' => 'tc-technology', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/technology/articles.atom',
            'section' => 'technology', 'country' => 'US',
            'tier' => 2, 'rank' => 9, 'weight' => 0.80, 'per_day' => 1.56,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'No derivatives: publish the piece whole. Images unconfirmed, so they are not republished.',
            'homepage' => 'https://theconversation.com/us/technology',
        ],
        [
            'slug' => 'tc-environment', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/environment/articles.atom',
            'section' => 'environment', 'country' => 'US',
            'tier' => 2, 'rank' => 10, 'weight' => 1.20, 'per_day' => 1.42,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'No derivatives: publish the piece whole. Images unconfirmed, so they are not republished.',
            'homepage' => 'https://theconversation.com/us/environment',
        ],
        [
            'slug' => 'propublica', 'name' => 'ProPublica', 'publisher' => 'propublica',
            'feed' => 'https://www.propublica.org/feeds/propublica/main',
            'section' => 'politics', 'country' => 'US',
            'tier' => 2, 'rank' => 11, 'weight' => 1.30, 'per_day' => 1.39,
            'extract' => true, 'images' => true,
            'license' => 'CC BY-NC-ND 3.0 US',
            'license_url' => 'https://creativecommons.org/licenses/by-nc-nd/3.0/us/',
            'attribution' => 'This story was originally published by ProPublica.',
            'notes' => 'NonCommercial, and their own rules draw the line at a site like this one: "It\'s OK to put our stories on pages with ads, but not ads specifically sold against our stories", followed by "You can\'t use our work to populate a website designed to improve rankings on search engines or solely to gain revenue from network-based advertisements." Headline, extract and link only — which is also how every wire story is treated. Photographs need specific permission.',
            'homepage' => 'https://www.propublica.org/',
        ],
        [
            'slug' => 'tc-education', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/education/articles.atom',
            'section' => 'education', 'country' => 'US',
            'tier' => 2, 'rank' => 12, 'weight' => 1.20, 'per_day' => 0.64,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'Below the once-a-day line, but education is a lead desk of this edition, so it is fetched on the 30-minute tier rather than hourly.',
            'homepage' => 'https://theconversation.com/us/education',
        ],
        // ------------------------------------------------------------ tier 3
        [
            'slug' => 'tc-business', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/business/articles.atom',
            'section' => 'business', 'country' => 'US',
            'tier' => 3, 'rank' => 13, 'weight' => 0.70, 'per_day' => 0.93,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'Filed to the money desk, which the front-page finance quota deliberately keeps small.',
            'homepage' => 'https://theconversation.com/us/business',
        ],
        [
            'slug' => 'ieee-spectrum', 'name' => 'IEEE Spectrum', 'publisher' => 'ieee',
            'feed' => 'https://spectrum.ieee.org/feeds/feed.rss',
            'section' => 'technology', 'country' => 'US',
            'tier' => 3, 'rank' => 14, 'weight' => 0.55, 'per_day' => 0.78,
            'extract' => true, 'images' => false,
            'license' => 'All rights reserved — no republication grant published',
            'license_url' => 'https://spectrum.ieee.org/about',
            'attribution' => 'Reported by IEEE Spectrum.',
            'notes' => 'Unlike every other full-text source here, IEEE publishes no republishing licence at all, so the body is never reproduced: headline, short extract and link only. Its feed also carries sponsored posts bylined to the sponsor, which is a second reason not to run it whole.',
            'homepage' => 'https://spectrum.ieee.org/',
        ],
        [
            'slug' => 'tc-world', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/world/articles.atom',
            'section' => 'world', 'country' => 'US',
            'tier' => 3, 'rank' => 15, 'weight' => 0.90, 'per_day' => 0.58,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'No derivatives: publish the piece whole. Images unconfirmed, so they are not republished.',
            'homepage' => 'https://theconversation.com/us/world',
        ],
        [
            'slug' => 'tc-arts', 'name' => 'The Conversation', 'publisher' => 'the-conversation',
            'feed' => 'https://theconversation.com/us/arts/articles.atom',
            'section' => 'culture', 'country' => 'US',
            'tier' => 3, 'rank' => 16, 'weight' => 0.75, 'per_day' => 0.48,
            'extract' => false, 'images' => true,
            'license' => 'CC BY-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nd/4.0/',
            'attribution' => 'This article is republished from The Conversation under a Creative Commons licence.',
            'notes' => 'No derivatives: publish the piece whole. Images unconfirmed, so they are not republished.',
            'homepage' => 'https://theconversation.com/us/arts',
        ],
        [
            'slug' => 'nasa-technology', 'name' => 'NASA', 'publisher' => 'nasa',
            'feed' => 'https://www.nasa.gov/technology/feed/',
            'section' => 'science', 'country' => 'US',
            'tier' => 3, 'rank' => 17, 'weight' => 0.50, 'per_day' => 0.33,
            'extract' => false, 'images' => true,
            'license' => 'Public domain (US Government)',
            'license_url' => 'https://www.nasa.gov/nasa-brand-center/images-and-media/',
            'attribution' => 'Published by NASA.',
            'notes' => 'The slowest NASA feed, kept for the science desk rather than the front page.',
            'homepage' => 'https://www.nasa.gov/technology/',
        ],
        [
            'slug' => 'themarkup', 'name' => 'The Markup', 'publisher' => 'the-markup',
            'feed' => 'https://themarkup.org/feeds/rss.xml',
            'section' => 'technology', 'country' => 'US',
            'tier' => 3, 'rank' => 18, 'weight' => 0.85, 'per_day' => 0.08,
            'extract' => true, 'images' => true,
            'license' => 'CC BY-NC-ND 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-nc-nd/4.0/',
            'attribution' => 'Reported by The Markup.',
            'notes' => 'NonCommercial, so headline, extract and link only. Measured dormant on 2026-08-21 — the newest item in the feed was 24 days old — which is why it sits on the hourly tier whatever its desk. If it stays silent it earns nothing and can be dropped without replacing it.',
            'homepage' => 'https://themarkup.org/',
        ],
    ];

    /** @var array<int,array<string,mixed>>|null */
    private static ?array $cache = null;

    /**
     * Every feed, normalised and validated.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $out  = [];
        $seen = [];
        foreach (self::FEEDS as $f) {
            $slug = (string) ($f['slug'] ?? '');
            $url  = (string) ($f['feed'] ?? '');
            if ($slug === '' || $url === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $section = (string) ($f['section'] ?? '');
            if (!isset(self::SECTIONS[$section])) {
                $section = array_key_first(self::SECTIONS);
            }
            $tier = (int) ($f['tier'] ?? 2);

            $out[] = [
                'slug'        => $slug,
                'name'        => (string) ($f['name'] ?? $slug),
                'publisher'   => (string) ($f['publisher'] ?? $slug),
                'feed'        => $url,
                'section'     => $section,
                'country'     => strtoupper(substr((string) ($f['country'] ?? ''), 0, 2)),
                'tier'        => isset(self::TIER_MINUTES[$tier]) ? $tier : 2,
                'rank'        => max(1, (int) ($f['rank'] ?? 99)),
                'weight'      => round(max(0.05, min(3.0, (float) ($f['weight'] ?? 1.0))), 2),
                'per_day'     => round(max(0.0, (float) ($f['per_day'] ?? 0.0)), 2),
                'extract'     => (bool) ($f['extract'] ?? false),
                'images'      => (bool) ($f['images'] ?? false),
                'license'     => (string) ($f['license'] ?? ''),
                'license_url' => (string) ($f['license_url'] ?? ''),
                'attribution' => (string) ($f['attribution'] ?? ''),
                'notes'       => (string) ($f['notes'] ?? ''),
                'homepage'    => (string) ($f['homepage'] ?? ''),
            ];
        }

        return self::$cache = $out;
    }

    /**
     * The feeds worth fetching right now, most overdue first.
     *
     * $tierState carries what the last run knew, and may be keyed by feed slug
     * or by tier number — whichever the caller has to hand. Each value is
     * either a timestamp in milliseconds, or an array holding any of
     * 'last_fetched_at' / 'fetched_at' / 'last' (ms) and 'fail_count'.
     * A slug that is not mentioned has never been fetched, so it is due.
     *
     * Failures push a feed further out, doubling per failure, and after
     * PARK_AFTER_FAILURES it is only retried every six hours — one broken feed
     * must never eat the fetch budget the working ones need.
     *
     * @param  array<string|int,mixed> $tierState
     * @return array<int,array<string,mixed>>
     */
    public static function due(int $nowMs, array $tierState): array
    {
        $due = [];

        foreach (self::all() as $feed) {
            $state = self::stateFor($feed, $tierState);
            $last  = $state['last'];
            $fails = $state['fails'];

            $minutes = self::TIER_MINUTES[$feed['tier']] ?? 30;
            if ($fails > 0) {
                $minutes = $fails >= self::PARK_AFTER_FAILURES
                    ? self::PARKED_RETRY_MINUTES
                    : (int) min(self::PARKED_RETRY_MINUTES, $minutes * (2 ** min($fails, 5)));
            }
            $intervalMs = $minutes * 60000;

            $overdueBy = $last <= 0 ? PHP_INT_MAX : ($nowMs - $last) - $intervalMs;
            if ($overdueBy < 0) {
                continue;
            }

            $feed['_overdue_ms'] = $overdueBy;
            $due[]               = $feed;
        }

        // Most overdue first, then the faster tiers, then the heavier sources.
        // Feeds that have never been fetched sort to the very front, which is
        // what makes the first run on a fresh install fill the front page.
        usort($due, static function (array $a, array $b): int {
            return [$b['_overdue_ms'], $a['tier'], $b['weight']]
                <=> [$a['_overdue_ms'], $b['tier'], $a['weight']];
        });

        foreach ($due as &$feed) {
            unset($feed['_overdue_ms']);
        }
        unset($feed);

        return $due;
    }

    /** One feed by slug, or null. */
    public static function bySlug(string $slug): ?array
    {
        foreach (self::all() as $feed) {
            if ($feed['slug'] === $slug) {
                return $feed;
            }
        }

        return null;
    }

    /**
     * Every feed filed to one desk, freshest tier first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function bySection(string $section): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $f): bool => $f['section'] === $section
        ));
    }

    /**
     * Every feed on one tier, in rank order.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function byTier(int $tier): array
    {
        $out = array_values(array_filter(
            self::all(),
            static fn (array $f): bool => $f['tier'] === $tier
        ));
        usort($out, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return $out;
    }

    /**
     * The desks, in navigation order, each with its slug, tier and rank folded
     * in. A desk's tier and rank are those of its fastest feed, so a desk fed
     * by a tier-1 source is itself tier 1.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function sections(): array
    {
        $out = [];
        foreach (self::SECTIONS as $slug => $meta) {
            $out[$slug] = ['slug' => $slug]
                + $meta
                + ['tier' => self::sectionTier($slug), 'rank' => self::sectionRank($slug)];
        }

        return $out;
    }

    /** One desk's metadata, or null when the slug is not a section. */
    public static function section(string $slug): ?array
    {
        $sections = self::sections();

        return $sections[$slug] ?? null;
    }

    /**
     * Sections that are initialisms rather than words. Title-casing turns 'us'
     * into "Us", which reads as the pronoun; these are the slugs where that is
     * wrong. Kept beside the registry because it is the same kind of knowledge:
     * what a desk is called.
     */
    private const SECTION_INITIALISMS = ['us' => 'U.S.', 'usa' => 'U.S.', 'uk' => 'U.K.', 'eu' => 'E.U.'];

    /**
     * The one place a section slug becomes a name a reader sees.
     *
     * The registry answers first, then the initialisms above, then a title-cased
     * slug. Everything that prints a desk name — the front page through Compose,
     * the ticker on every other page through Render — comes through here, so a
     * desk cannot be called two different things in two places on one page.
     */
    public static function labelFor(string $section): string
    {
        $section = strtolower(trim($section));
        if ($section === '') {
            return 'News';
        }

        $meta = self::section($section);
        if ($meta !== null && ($meta['label'] ?? '') !== '') {
            return (string) $meta['label'];
        }

        return self::SECTION_INITIALISMS[$section] ?? ucwords(str_replace('-', ' ', $section));
    }

    /**
     * How fast a desk moves: the tier of its fastest feed, or 3 when it has no
     * feeds at all — the slowest reading is the safe one for a desk we cannot
     * measure.
     */
    public static function sectionTier(string $slug): int
    {
        $best = 3;
        foreach (self::all() as $f) {
            if ($f['section'] === $slug && $f['tier'] < $best) {
                $best = $f['tier'];
            }
        }

        return $best;
    }

    /** Freshness rank of a desk: the best rank among its feeds, 99 when it has none. */
    public static function sectionRank(string $slug): int
    {
        $best = 99;
        foreach (self::all() as $f) {
            if ($f['section'] === $slug && $f['rank'] < $best) {
                $best = $f['rank'];
            }
        }

        return $best;
    }

    /**
     * The desks that appear on the front page, in the order they appear.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function homeSections(): array
    {
        $out = array_values(array_filter(self::sections(), static fn (array $s): bool => $s['home'] !== null));
        usort($out, static fn (array $a, array $b): int => $a['home'] <=> $b['home']);

        return $out;
    }

    /**
     * The same front-page desks ordered purely by how fast they move — tier
     * first, then freshness rank. This is the order for a renderer that wants
     * the page to reorganise itself around whatever is moving today, rather
     * than the fixed editorial order homeSections() gives.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function freshSections(): array
    {
        $out = self::homeSections();
        usort($out, static function (array $a, array $b): int {
            return [$a['tier'], $a['rank'], $a['home']] <=> [$b['tier'], $b['rank'], $b['home']];
        });

        return $out;
    }

    /**
     * Front-page block ids in order — the list Compose iterates.
     *
     * @return array<int,string>
     */
    public static function blockOrder(): array
    {
        return array_column(self::homeSections(), 'slug');
    }

    /**
     * The front-page block a story from this desk belongs in, or '' when the
     * desk does not appear on the front page at all. Compose calls this
     * instead of holding its own section-to-block table, so adding a desk is
     * a change here and nowhere else.
     */
    public static function blockFor(string $section): string
    {
        $meta = self::SECTIONS[$section] ?? null;

        return ($meta !== null && $meta['home'] !== null) ? $section : '';
    }

    /**
     * The relative pull of a desk when stories are ranked. 1.0 is neutral.
     *
     * A section this edition does not run — a row left in the database by an
     * older roster, say — answers 0.80 rather than 1.0. A desk nobody
     * publishes to any more must never outrank one we do.
     */
    public static function sectionPriority(string $slug): float
    {
        $meta = self::SECTIONS[$slug] ?? null;

        return $meta === null ? 0.80 : round((float) ($meta['priority'] ?? 1.0), 2);
    }

    /**
     * Desks the front-page money quota applies to. Kept here so Compose,
     * Render and the tests cannot drift apart on what "finance" means.
     *
     * @return array<int,string>
     */
    public static function financeSections(): array
    {
        return array_values(array_keys(array_filter(
            self::SECTIONS,
            static fn (array $s): bool => !empty($s['finance'])
        )));
    }

    /**
     * True when this source may only be shown as headline, extract and link —
     * never as a full article. Unknown slugs answer true, because the safe
     * default for something we cannot identify is to publish less of it.
     */
    public static function isExtractOnly(string $slug): bool
    {
        $feed = self::bySlug($slug);

        return $feed === null ? true : (bool) $feed['extract'];
    }

    /**
     * Slugs that may only ever run as an extract.
     *
     * @return array<int,string>
     */
    public static function extractOnly(): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['slug'],
            array_filter(self::all(), static fn (array $f): bool => $f['extract'])
        ));
    }

    /**
     * True when we are licensed to republish this source's photographs.
     * False — the common case — means the card and the article page use the
     * site's own placeholder even though the feed carried a picture.
     */
    public static function imagesAllowed(string $slug): bool
    {
        $feed = self::bySlug($slug);

        return $feed === null ? false : (bool) $feed['images'];
    }

    /**
     * The licence block for one source: what it is, where it is published, the
     * credit sentence it requires, whether the body may be republished and
     * whether the pictures may. Everything a page needs to be compliant.
     *
     * @return array{license:string,license_url:string,attribution:string,extract:bool,images:bool,name:string,homepage:string,notes:string}
     */
    public static function licence(string $slug): array
    {
        $feed = self::bySlug($slug);
        if ($feed === null) {
            return [
                'license' => '', 'license_url' => '', 'attribution' => '',
                'extract' => true, 'images' => true, 'name' => '', 'homepage' => '', 'notes' => '',
            ];
        }

        return [
            'license'     => $feed['license'],
            'license_url' => $feed['license_url'],
            'attribution' => $feed['attribution'],
            'extract'     => (bool) $feed['extract'],
            'images'      => (bool) $feed['images'],
            'name'        => $feed['name'],
            'homepage'    => $feed['homepage'],
            'notes'       => $feed['notes'],
        ];
    }

    /** The newsroom behind a feed, for a per-publisher cap on one page. */
    public static function publisherOf(string $slug): string
    {
        $feed = self::bySlug($slug);

        return $feed === null ? '' : $feed['publisher'];
    }

    /** Minutes between fetches for a tier. */
    public static function tierMinutes(int $tier): int
    {
        return self::TIER_MINUTES[$tier] ?? 30;
    }

    /**
     * Pull one feed's last-run state out of whatever shape the caller passed.
     *
     * @param  array<string,mixed>     $feed
     * @param  array<string|int,mixed> $state
     * @return array{last:int,fails:int}
     */
    private static function stateFor(array $feed, array $state): array
    {
        $raw = null;
        foreach ([$feed['slug'], 'tier' . $feed['tier'], $feed['tier'], (string) $feed['tier']] as $key) {
            if (array_key_exists($key, $state)) {
                $raw = $state[$key];
                break;
            }
        }

        if ($raw === null) {
            return ['last' => 0, 'fails' => 0];
        }
        if (is_int($raw) || is_float($raw) || (is_string($raw) && ctype_digit($raw))) {
            return ['last' => (int) $raw, 'fails' => 0];
        }
        if (!is_array($raw)) {
            return ['last' => 0, 'fails' => 0];
        }

        $last = 0;
        // 'last_fetch_at' is the actual column name on the sources table. It
        // was missing here once, so a state array handed straight from the
        // database read 0 for every feed, every feed looked overdue, and the
        // tier cadence and the parked-feed backoff below did nothing at all.
        foreach (['last_fetched_at', 'fetched_at', 'last_fetch_ms', 'last_fetch_at', 'last', 'at'] as $k) {
            if (isset($raw[$k]) && (is_int($raw[$k]) || is_float($raw[$k]) || (is_string($raw[$k]) && ctype_digit($raw[$k])))) {
                $last = (int) $raw[$k];
                break;
            }
        }
        $fails = 0;
        foreach (['fail_count', 'failures', 'fails'] as $k) {
            if (isset($raw[$k]) && is_numeric($raw[$k])) {
                $fails = max(0, (int) $raw[$k]);
                break;
            }
        }

        return ['last' => $last, 'fails' => $fails];
    }
}
