<?php

declare(strict_types=1);

namespace TEB;

/**
 * The standing pages: About, Editorial Standards, Contact, Privacy and Terms.
 *
 * A reader — or a reviewer from an advertising network — comes to these pages to
 * find out who is behind the site and by what right it carries somebody else's
 * reporting. So they are written as a statement of fact. There is no invented
 * postal address, no telephone number nobody answers, no list of awards, no
 * masthead of editors who do not exist, and no sentence that would embarrass us
 * if a publisher read it.
 *
 * RULES OF THIS FILE
 * ------------------
 * 1. The brand name is NEVER typed here. It arrives from $cfg['site']['name']
 *    through the {NAME} token — config.php is the only place the brand exists.
 * 2. Contact addresses are built from $cfg['site']['domain'], so the mailto
 *    links belong to this site on day one and follow it if the domain changes.
 * 3. The prose lives in nowdoc blocks with {TOKEN} placeholders, substituted by
 *    fill(), which escapes each value exactly once. Nothing is interpolated
 *    directly into markup in this file.
 * 4. Every method returns ['title','description','body','jsonld'] and performs
 *    no I/O whatsoever. app/Router.php wraps the result in Render::layout().
 *
 * ⚠ THE LICENCE LIST in standards() is the roster measured on 2026-08-21 and
 *   written down in FEEDS.md. If app/Feeds.php gains or drops a publisher, this
 *   list must be corrected in the same commit: an attribution page naming the
 *   wrong licence is worse than having no page. The rule stated above the list
 *   is deliberately written to remain true whatever the roster becomes.
 */
final class Pages
{
    /** The day these pages were last read end to end and checked against the code. */
    private const REVIEWED = '2026-08-21';

    /**
     * What each licence permits, and therefore how much of an article is
     * carried. The bias is conservative on purpose: if the terms do not plainly
     * allow a whole article to be republished on a page that might one day
     * carry advertising, only a headline and a short extract are carried.
     *
     * @var array<int,array{0:string,1:string,2:string,3:string}>
     *      [licence, publishers, what is carried, the reasoning]
     */
    private const LICENCES = [
        [
            'CC BY — Attribution',
            'Global Voices',
            'The article in full',
            'The most permissive licence in the set: commercial use is allowed and so are edits. We do not edit anyway, for the reason given below.',
        ],
        [
            'CC BY-ND — Attribution, NoDerivatives',
            'The Conversation (US)',
            'The article in full, word for word',
            'ND forbids derivative works. Trimming a piece to fit, re-titling it or replacing it with a summary would all breach the licence. Whole, or not at all.',
        ],
        [
            'CC BY-NC-ND, with NonCommercial waived in writing for news outlets',
            'KFF Health News',
            'The article in full',
            'NonCommercial would rule this site out, so it matters that KFF lifts it themselves, in terms that name this exact case: their syndication page licences republication by news outlets "including for-profit news organizations that charge for subscriptions and accept advertising". The waiver stops at the text — their photographs stay non-commercial, so none are carried.',
        ],
        [
            'CC BY-NC-ND — Attribution, NonCommercial, NoDerivatives',
            'ProPublica, The Markup, The 19th',
            'Headline, a short extract, and a link to the original',
            'NC rules out commercial use, and a page carrying advertising is a commercial use. So these newsrooms are never reproduced in full here — they are handled exactly as a wire story is.',
        ],
        [
            'The publisher’s own syndication terms, credit required',
            'MIT News, Grist',
            'The article as supplied, carrying the credit the publisher asks for',
            'Not Creative Commons at all. These are house terms. MIT offers the feed for syndication and asks for two credits, one above the piece and one beneath it. Grist asks that a piece be republished in its entirety and says in writing that existing advertising on the page is fine. Neither of them licences its pictures, so neither one’s pictures appear here.',
        ],
        [
            'No republication grant published',
            'IEEE Spectrum',
            'Headline, a short extract, and a link to the original',
            'IEEE publishes no republishing licence at all, and silence is not permission. It is therefore handled the way the NonCommercial newsrooms are — never reproduced in full. Its feed also carries sponsored posts bylined to the sponsor, which is a second reason not to run one whole.',
        ],
        [
            'Public domain — a work of the United States government',
            'NASA',
            'The article in full',
            'No licence is needed for the text. It is credited all the same, and NASA does not endorse this site: the NASA name, logo and insignia are protected separately and appear nowhere here.',
        ],
    ];

    /**
     * Copy that belongs to THIS edition but is printed by pages the shared
     * renderer owns — /sources and the 404. It lived in app/Router.php, which
     * is byte-identical across both editions, so both sites served the same
     * paragraph word for word. Reader-facing sentences belong in the per-site
     * file; app/Router.php now asks for them here.
     */
    public const SOURCES_INTRO =
        'Every story on this site was reported by one of the newsrooms below, and each is carried on '
        . 'the terms that newsroom sets — the whole article where the licence allows it, a headline, '
        . 'a short extract and a link where it does not. Nothing is rewritten to fit. The author, the '
        . 'publication and the licence are named on every article page, and the way back to the '
        . 'original is never more than one click.';

    /** The one-line summary of /sources, for the page description. */
    public const SOURCES_DESCRIPTION =
        'The newsrooms whose public feeds this site reads, and the licence each one is carried under.';

    /** What a reader is told when a URL does not resolve. */
    public const NOT_FOUND_MESSAGE =
        'Nothing hangs at this address. The page may have moved, or the link may be older than the '
        . 'story it pointed at. The front page and the search box below both still work.';

    // ================================================================= about

    /** @return array{title:string,description:string,body:string,jsonld:array} */
    public static function about(array $cfg): array
    {
        $body = <<<'HTML'
<section class="block wrap page">
  <header class="page-head">
    <p class="page-kicker"><span class="block-label">About</span></p>
    <h1 class="page-title">About {NAME}</h1>
    <p class="page-standfirst">A plumb line is a weight on a string. Hang it from anything and it
      finds true vertical, every time, without being asked and without an opinion about the wall.
      That is the standard this site is trying to keep.</p>
    <p class="page-meta">Last reviewed {REVIEWED_LONG}</p>
  </header>

  <div class="page-prose">
    <h2 id="the-name">The name is an instrument</h2>
    <p>Builders have used the plumb line for four thousand years, and it has never been improved on,
      because there is nothing in it to improve. A cord, a weight, and gravity. It cannot be flattered
      into agreeing with you. Hold it against a wall you are proud of and it will tell you the wall
      leans; hold it against a wall you dislike and it will tell you the wall is true. The answer does
      not depend on who is holding the string.</p>
    <p>That is what we mean by it here, and it is a claim about method rather than about wisdom.
      {NAME} does not tell you what the day added up to. It takes reporting that other newsrooms have
      published under open licences, sets it down straight — the whole article, the author’s name,
      the licence, the link home — and gets out of the way. The line hangs; you read what it shows.</p>
    <p>It is also a promise about what we will not do. A plumb line that has been nudged is worse than
      no plumb line, because now you trust a wrong answer. So nothing here is rewritten to read
      better, no headline is sharpened, and no article is quietly shortened to fit a slot in a grid.
      What arrives is what the newsroom filed.</p>

    <h2 id="not-the-publisher">Plainly: we are not the original publisher</h2>
    <p><strong>Nothing on this site was reported by us.</strong> {NAME} has no reporters, no
      photographers, no bureau and no editor commissioning coverage. Every story here was researched,
      written, checked and paid for by another newsroom, and that newsroom is named on the piece and
      linked from it. We are the second place you read it, never the first.</p>
    <p>This is an aggregator, and what it contributes is arrangement: gathering, filing, ordering and
      presenting. That has value — it is why front pages exist — but it is not journalism, and calling
      it journalism would be the first crooked thing on the page.</p>

    <h2 id="the-test">The test a source has to pass</h2>
    <p>Most feeds are shop windows. The publisher puts a headline and a sentence in them and keeps the
      article back, which is entirely their right, and it makes them useless to a site like this one:
      a page of three-line stubs wastes the reader’s time and takes the publisher’s traffic without
      giving anything for it.</p>
    <p>So a source has to clear two bars before it goes on the roster. <strong>It has to publish the
      full text of its articles in its own public feed</strong>, and <strong>it has to licence that
      text for republication</strong> — or state terms that plainly allow it. That is a short list of
      newsrooms: non-profit investigative desks, academic-authored explanatory journalism, university
      newsrooms, science and climate publishers, and public-domain agency reporting. They are set out
      with their licences on the <a href="{U_STANDARDS}">editorial standards</a> page, and the roster
      the site is actually fetching today is on the <a href="{U_SOURCES}">sources</a> page.</p>

    <h2 id="how-built">How the page is built</h2>
    <p>Automatically, and by a short set of rules that anybody can check against what they see:</p>
    <ol>
      <li>A scheduled job reads each publisher’s feed on a timer — every ten minutes for the
        fast-moving desks, every half hour for the middle tier and hourly for the slowest, which is
        why those sit further down the page.</li>
      <li>Anything already stored is thrown away, so one story cannot appear twice because a newsroom
        publishes it on two feeds.</li>
      <li>Each story files to the desk its feed belongs to.</li>
      <li>The front page is ordered by publication time, with a limit on how many pieces one publisher
        may hold in a single block, so that whoever files fastest does not own the page.</li>
    </ol>
    <p>Nobody rewrites a headline. No language model summarises, re-angles or tidies an article.
      Position on this page is not for sale, to anyone, at any price — there is no arrangement under
      which a newsroom, an agency or an advertiser could buy one.</p>

    <h2 id="wrong">Where we can go wrong, and what we do about it</h2>
    <p>Being an honest aggregator is mostly a matter of admitting which mistakes are available to us.
      A link can break or point at the wrong story. A byline can be attached to the wrong piece. A
      licence can be named incorrectly. A story can land on the wrong desk. An article can sit here
      after the publisher has corrected or withdrawn it.</p>
    <p>All of those are ours, and all of them are fixed on report — write to
      <a href="mailto:{CORRECTIONS}">{CORRECTIONS}</a>. What we cannot do is correct a fact inside
      somebody else’s reporting; that belongs to the newsroom that reported it, and the
      <a href="{U_STANDARDS}">editorial standards</a> page explains how the two are kept apart.</p>

    <h2 id="publishers">If you are one of the publishers</h2>
    <p>You can have anything of yours removed from this site, and you do not have to give a reason.
      One email to <a href="mailto:{PERMISSIONS}">{PERMISSIONS}</a> does it, and a feed-level opt-out
      is applied straight away so nothing further arrives while the rest is dealt with.</p>

    <h2 id="reach-us">Reaching us</h2>
    <p>By email, and only by email. There are no accounts on this site, no comment section and no
      newsletter, which is why the <a href="{U_PRIVACY}">privacy policy</a> is as short as it is. The
      addresses are on the <a href="{U_CONTACT}">contact</a> page and each one is read by a person.</p>
  </div>
</section>
HTML;

        return [
            'title'       => 'About',
            'description' => 'What this site is, what a plumb line has to do with it, how stories are '
                . 'chosen, and why we are a republisher of open-licensed journalism and not the original publisher.',
            'body'        => self::withContents(self::fill($body, $cfg)),
            'jsonld'      => self::jsonLd($cfg, 'AboutPage', 'About', '/about'),
        ];
    }

    // ============================================================= standards

    /** @return array{title:string,description:string,body:string,jsonld:array} */
    public static function standards(array $cfg): array
    {
        $items = '';
        foreach (self::LICENCES as $l) {
            $items .= '<div class="lic">'
                . '<h3 class="lic-name">' . Render::esc($l[0]) . '</h3>'
                . '<p class="lic-who"><span class="lic-tag">Sources</span> ' . Render::esc($l[1]) . '</p>'
                . '<p class="lic-what"><span class="lic-tag">We carry</span> ' . Render::esc($l[2]) . '</p>'
                . '<p class="lic-why">' . Render::esc($l[3]) . '</p>'
                . '</div>';
        }

        $body = <<<'HTML'
<section class="block wrap page">
  <header class="page-head">
    <p class="page-kicker"><span class="block-label">Editorial Standards</span></p>
    <h1 class="page-title">Editorial standards</h1>
    <p class="page-standfirst">The working rules of {NAME}: where the material comes from, the
      licences that make carrying it lawful, how credit is given, who fixes what when something is
      wrong, and how a publisher gets a piece taken down.</p>
    <p class="page-meta">Last reviewed {REVIEWED_LONG}</p>
  </header>

  <div class="page-prose">
    <h2 id="material">The material, and how it reaches us</h2>
    <p>Everything on this site arrives through a feed its publisher chose to make public. Nothing is
      lifted off a web page, nothing is taken from behind a paywall or a login, and no attempt is
      made — ever — to reach material a publisher has held back. Our fetcher gives its name in the
      user-agent string and points back at this site, so any newsroom curious about who is reading its
      feed can find out from a single line of its own server log.</p>
    <p>Feeds are read on a timer and treated gently. Nothing here is fetched more than once every ten
      minutes, and most feeds are read a great deal less often than that, because these newsrooms
      publish a few times a day rather than around the clock. A feed that keeps failing is set aside
      and retried slowly rather than hammered. Ask to come off the roster and you come off it; no case
      has to be made to us.</p>

    <h2 id="licences">The licences we work under</h2>
    <p>Carrying another newsroom’s article is lawful only if its licence allows it, and the licences
      are not interchangeable — the differences between them decide how much of a piece may appear.
      What follows is the roster as it stood on {REVIEWED_LONG}. The rule beneath it is the one that
      governs whatever the roster becomes: <strong>where the terms do not plainly permit a full
      republication on a page that may carry advertising, the piece runs as a headline, a short
      extract and a link.</strong> On a close call we take less, never more.</p>

    <div class="lic-list">{LICENCE_ITEMS}</div>

    <h3 id="nd">“NoDerivatives”, in practice</h3>
    <p>Three of the licences above carry ND, and it is the condition aggregators break most often —
      usually by accident, by running a template that trims every article to a fixed length. It is
      also the easiest to keep. Publish the piece <em>whole</em>, in the words the author wrote, or
      leave it alone. So there is no rewriting here, no cutting to fit, no machine-written summary
      standing in for the article, and no headline replaced with a livelier one. A shortened article
      is a derivative work, and a derivative work is precisely what the licence refuses.</p>

    <h3 id="nc">“NonCommercial”, in practice</h3>
    <p>Four of the newsrooms above publish under a NonCommercial licence, and a site carrying
      advertising is making commercial use of what it publishes. Three of them — ProPublica, The
      Markup and The 19th — are therefore <strong>never</strong> reproduced in full here, not today
      while the ad slots are empty and not later. They appear as a headline, a short extract and a
      prominent link to their own page, which is how a wire story is handled.</p>
    <p>The fourth, KFF Health News, is carried whole, and only because KFF has waived the term itself:
      their syndication terms licence republication by news outlets that accept advertising. That is
      the publisher’s decision, in writing, on their own page — not a reading of the licence made
      here. Any of the four that would rather not appear at all, in any form, can say so and be gone
      the same day.</p>

    <h2 id="credit">Credit</h2>
    <p>Attribution is a term of the licence, not good manners, and it is not discharged by a link in
      small type at the bottom. Every republished piece here carries, <strong>above</strong> the
      text, in the dateline the reader meets before the first paragraph:</p>
    <ul>
      <li>the author’s name, or the authors’ names, exactly as the publisher filed them;</li>
      <li>the name of the newsroom that published it.</li>
    </ul>
    <p>And again <strong>beneath</strong> the text, in a credit block that is part of the article and
      not part of the furniture:</p>
    <ul>
      <li>the publisher’s own credit line, in the publisher’s own words where they prescribe one;</li>
      <li>the author’s name and the newsroom’s name, the newsroom linked to its home page;</li>
      <li>the licence it is published under, named in full and linked to its deed;</li>
      <li>a link to the original piece.</li>
    </ul>
    <p>The link to the original is also on the last line of the article itself, so a reader who stops
      at the end of the text still has it.</p>
    <p>One thing that is worth stating rather than leaving to be discovered: <strong>the canonical
      tag on an article page here points at this page, not at the publisher’s.</strong> A canonical
      tag is a statement about which of two copies is the one to index, and pointing ours at theirs
      would ask search engines to drop this site’s article pages entirely. The link out is what
      carries the reader home, and it is on the page twice. If a publisher would rather the tag
      pointed at their copy, they only have to ask and it will.</p>

    <h2 id="corrections">Accuracy, and who fixes what</h2>
    <p>There is a line here that has to be drawn clearly, because it decides where a complaint should
      go to be dealt with properly.</p>
    <p><strong>The reporting is not ours to correct.</strong> If a fact inside a republished article
      is disputed, the newsroom that reported it is the only place that can settle it — they hold the
      sources, the notes and the standards process, and a correction made there propagates to
      everyone carrying the piece. What we undertake is to act on the result: tell us that a piece has
      been corrected, updated or withdrawn and the copy here is re-synchronised or removed the same
      day. A stale version is not left standing after the original has moved.</p>
    <p><strong>Our own mistakes are ours</strong>, and they are the ones this site is actually capable
      of making: a broken or misdirected link, a byline on the wrong piece, a licence named wrongly, a
      story on the wrong desk, a missing image credit, a headline mangled in transit. Send any of them
      to <a href="mailto:{CORRECTIONS}">{CORRECTIONS}</a>. The address is read every working day and
      acted on within two working days, and where a correction is substantive it is noted on the page
      rather than made quietly.</p>

    <h2 id="takedown">Taking something down</h2>
    <p>Any publisher, author or rights holder can have material removed from this site. No reason is
      required, there is nothing to argue with, and there is no form.</p>
    <p>Write to <a href="mailto:{PERMISSIONS}">{PERMISSIONS}</a>, from an address at the publication’s
      own domain if you can, and say:</p>
    <ul>
      <li>which URL — ours, or the original if that is easier to hand;</li>
      <li>whether you want it gone entirely, or cut back to a headline, an extract and a link;</li>
      <li>whether this covers the one article or your whole feed from now on.</li>
    </ul>
    <p><strong>Requests are acted on within one working day of being read, and a feed-level opt-out
      takes effect immediately</strong> so that nothing further arrives meanwhile. You will get a
      reply saying what was done. A formal legal notice may be sent to the same address; it will be
      handled the same way, just with more paperwork.</p>

    <h2 id="independence">Advertising does not touch the page</h2>
    <p>Nothing about which stories appear, or where, is for sale. Order is decided by publication time
      and by desk, and by nothing else. No newsroom pays to be here and none is paid to be here. If
      display advertising is running, it sits in fixed slots that are labelled as advertising and kept
      out of the story flow, and it has no bearing at all on what is carried.</p>

    <h2 id="machines">No machine-written text</h2>
    <p>None. Not an article, not a headline, not a summary, not a standfirst, not a caption. The words
      in a republished piece are the publisher’s words, and the words on the standing pages — this one
      included — were written by a person.</p>

    <h2 id="not-journalism">Material that is not journalism</h2>
    <p>Not everything carried here was written by a reporter, and where that is so the page says so.
      NASA publishes its own work into the public domain and MIT News writes about its own university;
      both are institutional communications rather than independent journalism, and both are credited
      by name at the top and the bottom of every piece taken from them. Academic writing — the bulk of
      what arrives from The Conversation — carries the author’s name and the university they write
      from, because who wrote a thing is part of reading it. None of these organisations is connected
      with us and none of them has any say in what appears here.</p>

    <h2 id="who">Who stands behind this</h2>
    <p>{NAME} is a small independent operation, funded by its owner rather than by a media group, a
      foundation or an advertiser. Anything at all can go to <a href="mailto:{EDITOR}">{EDITOR}</a>,
      and every address on the <a href="{U_CONTACT}">contact</a> page reaches somebody who can act on
      what you send.</p>
  </div>
</section>
HTML;

        $body = str_replace('{LICENCE_ITEMS}', $items, $body);

        return [
            'title'       => 'Editorial Standards',
            'description' => 'Where our material comes from, the Creative Commons and publisher terms '
                . 'we republish under, how credit and corrections work, and how a publisher has an '
                . 'article taken down.',
            'body'        => self::withContents(self::fill($body, $cfg)),
            'jsonld'      => self::jsonLd($cfg, 'WebPage', 'Editorial Standards', '/editorial-standards'),
        ];
    }

    // =============================================================== contact

    /** @return array{title:string,description:string,body:string,jsonld:array} */
    public static function contact(array $cfg): array
    {
        /*
         * ─────────────────────────────────────────────────────────────────
         *  DEVELOPER — READ THIS BEFORE TOUCHING THE FORM BELOW
         * ─────────────────────────────────────────────────────────────────
         *  THIS FORM HAS NO SERVER-SIDE HANDLER. Nothing in this application
         *  accepts a POST: app/Router.php dispatches on the route alone, and
         *  the project configures no mail transport, no database table for
         *  messages and no third-party form endpoint.
         *
         *  Rather than pretend, the form is wired to the reader's own mail
         *  client:
         *      action="mailto:…" method="post" enctype="text/plain"
         *  That is a genuine delivery path — it opens a pre-addressed message
         *  the reader sends themselves — and it is why there is no
         *  confirmation screen anywhere in this file. Do NOT add one before a
         *  handler exists. "Thank you, your message has been received" on a
         *  build that transmits nothing to us is simply a lie to the reader.
         *
         *  TO WIRE IT UP FOR REAL, three steps, none of them optional:
         *    1. Point action= at your endpoint, keeping method="post". The
         *       fields are name, email, subject, message — plus 'website',
         *       which is a honeypot: it is hidden from people, so a
         *       submission that arrives with it filled in is a bot and should
         *       be dropped without comment.
         *    2. Build a real success page AND a real failure page. Both must
         *       say what actually happened.
         *    3. Update /privacy in the SAME commit. That page states today
         *       that this site collects no personal data — which stops being
         *       true the instant a form posts a name and an email address to
         *       a server. See app/Pages.php::privacy().
         * ─────────────────────────────────────────────────────────────────
         */

        $body = <<<'HTML'
<section class="block wrap page">
  <header class="page-head">
    <p class="page-kicker"><span class="block-label">Contact</span></p>
    <h1 class="page-title">Contact us</h1>
    <p class="page-standfirst">Email, and nothing else. Every address below is on this site’s own
      domain and every one is read by a person — there is no ticket system, no automated reply and no
      chatbot standing in front of us.</p>
    <p class="page-meta">Last reviewed {REVIEWED_LONG}</p>
  </header>

  <div class="page-prose">
    <h2 id="addresses">The addresses</h2>
    <dl class="page-contacts">
      <dt>General enquiries, and anything not covered below</dt>
      <dd><a href="mailto:{EDITOR}">{EDITOR}</a></dd>

      <dt>Corrections — a broken link, the wrong byline, a licence named wrongly, a story on the wrong desk</dt>
      <dd><a href="mailto:{CORRECTIONS}">{CORRECTIONS}</a><span class="cd-note">Read every working day, acted on within two.</span></dd>

      <dt>Publishers and rights holders — removals, opt-outs, or a change to how you are credited</dt>
      <dd><a href="mailto:{PERMISSIONS}">{PERMISSIONS}</a><span class="cd-note">Acted on within one working day of being read; a feed-level opt-out takes effect immediately.</span></dd>

      <dt>Privacy and data protection</dt>
      <dd><a href="mailto:{PRIVACY}">{PRIVACY}</a></dd>
    </dl>

    <h2 id="takedown">Asking for something to be taken down</h2>
    <p>Write to <a href="mailto:{PERMISSIONS}">{PERMISSIONS}</a>, from an address at your
      publication’s own domain where you can, and give the URL — ours or your original — whether you
      want the piece removed altogether or reduced to a headline and a link, and whether this is about
      one article or your entire feed. No reason is needed. The whole procedure, with timescales, is
      on the <a href="{U_STANDARDS}">editorial standards</a> page.</p>

    <h2 id="form">Or write from here</h2>
    <p>This opens a message, already addressed, in whichever mail application you use — so you can see
      exactly what is going out and to whom before it goes. Nothing is submitted to this website and
      nothing you type here is stored by it. If your device has no mail application set up, write to
      <a href="mailto:{EDITOR}">{EDITOR}</a> instead.</p>

    <form class="cform" action="mailto:{EDITOR}" method="post" enctype="text/plain">
      <div class="cform-row">
        <label for="cf-name">Your name</label>
        <input type="text" id="cf-name" name="name" autocomplete="name" required>
      </div>
      <div class="cform-row">
        <label for="cf-email">Your email address</label>
        <input type="email" id="cf-email" name="email" autocomplete="email" required>
      </div>
      <div class="cform-row">
        <label for="cf-subject">Subject</label>
        <input type="text" id="cf-subject" name="subject" required>
      </div>
      <div class="cform-row">
        <label for="cf-message">Message</label>
        <textarea id="cf-message" name="message" rows="8" required></textarea>
      </div>
      <div class="cform-hp" aria-hidden="true">
        <label for="cf-website">Leave this field empty</label>
        <input type="text" id="cf-website" name="website" tabindex="-1" autocomplete="off">
      </div>
      <div class="cform-actions">
        <button type="submit" class="cform-send">Open this in your email app</button>
      </div>
    </form>

    <h2 id="cannot">Two things we cannot do</h2>
    <p>We cannot correct a fact inside an article we did not write. That has to go to the newsroom
      named on the piece, who will also put it right everywhere else the article has been syndicated —
      and if you tell us afterwards, we will re-sync or remove our copy the same day.</p>
    <p>And we cannot add a publication to the roster unless its feed carries the full text of its
      articles under terms that permit republication. If yours does, we would genuinely like to hear
      from you at <a href="mailto:{EDITOR}">{EDITOR}</a>.</p>
  </div>
</section>
HTML;

        return [
            'title'       => 'Contact',
            'description' => 'Email addresses for general enquiries, corrections, publisher removal '
                . 'requests and privacy questions, with a contact form.',
            'body'        => self::withContents(self::fill($body, $cfg)),
            'jsonld'      => self::jsonLd($cfg, 'ContactPage', 'Contact', '/contact'),
        ];
    }

    // =============================================================== privacy

    /** @return array{title:string,description:string,body:string,jsonld:array} */
    public static function privacy(array $cfg): array
    {
        $body = <<<'HTML'
<section class="block wrap page">
  <header class="page-head">
    <p class="page-kicker"><span class="block-label">Privacy</span></p>
    <h1 class="page-title">Privacy policy</h1>
    <p class="page-standfirst">This page describes what {NAME} does today — not what a downloaded
      template says a website might do. It is short for the plain reason that the site does very
      little: no accounts, no analytics, no tracking cookies, no personal information taken from
      readers.</p>
    <p class="page-meta">Last reviewed {REVIEWED_LONG}</p>
  </header>

  <div class="page-prose">
    <h2 id="summary">In one sentence</h2>
    <p>You can read every page on this site without telling it anything about yourself, and it has
      nothing about you to lose, sell or hand over.</p>

    <h2 id="nothing">What we take from you: nothing</h2>
    <p>There are no accounts and no way to make one. No comment section, no newsletter, no sign-in, no
      saved articles, no personalisation of any kind. This site sets no analytics cookie and no
      advertising cookie. Nothing is recorded about which stories you open, how long you read or where
      you go afterwards.</p>

    <h2 id="theme">The single thing kept on your device</h2>
    <p>This site is light, for everybody, whatever your computer is set to — that was a deliberate
      decision and there is no setting here that undoes it automatically. The control in the
      navigation is the one exception, and it has two positions: light, which is how the site ships,
      and dark, if you would rather read it that way. Choosing dark writes one key to your browser’s
      <code>localStorage</code>, <code>theme</code>, holding the single word <code>dark</code>. Its
      whole purpose is to stop the page appearing in the wrong colour for an instant on your next
      visit. Switch back to light and the key is deleted again. It is not a cookie, it is never sent
      to this site’s server or to anybody else, and it says nothing about who you are. Clear your
      browser’s site data and it is gone; the site works perfectly well without it.</p>

    <h2 id="logs">What the server records</h2>
    <p>As every web server does, the machine serving these pages writes a line for each request: the
      IP address it came from, the time, the page requested, the referring page if the browser sent
      one, and the browser’s user-agent string. That is unavoidable — it is how a server is kept
      running and how abuse is stopped — and the logs are held by the hosting provider under their own
      retention period. They are not turned into audience statistics, not joined to anything else, not
      used to build a picture of any reader, and not passed to anyone, except where a provider is
      legally compelled.</p>

    <h2 id="third-parties">Requests your browser makes elsewhere</h2>
    <p>There are two, and they deserve to be named exactly rather than waved at.</p>
    <ul>
      <li><strong>Photographs.</strong> Most of the newsrooms here licence their words but not their
        pictures, so most cards on this site carry a plain house graphic that is drawn by this
        server and comes from this domain — no other server is involved. Where a publisher does
        licence its photographs, the picture is loaded from that publisher’s own server rather than
        copied onto ours, and your browser contacts them directly for it. Every image tag on this
        site, ours and theirs alike, carries <code>referrerpolicy="no-referrer"</code>, which stops
        your browser telling them which page you were reading when it asked for the picture.</li>
      <li><strong>Typefaces.</strong> The fonts come from Google Fonts
        (<code>fonts.googleapis.com</code> and <code>fonts.gstatic.com</code>), so your browser makes a
        request to Google, which sees your IP address as it would for any file it serves. Google states
        that Google Fonts sets no cookies and that the requests are not used for advertising. Block
        those two hostnames if you would rather it did not happen — the site stays entirely readable in
        its fallback typefaces.</li>
    </ul>
    <p>The feeds themselves are fetched on a schedule by <em>our</em> server, not by your browser, so
      reading a story here does not connect you to the newsroom that reported it. A link out of this
      site is an ordinary link: follow it and you are on somebody else’s site, under their policy
      rather than this one.</p>

    <h2 id="form">The contact form</h2>
    <p>The form on the <a href="{U_CONTACT}">contact</a> page submits nothing to this website. It
      passes what you have written to your own email application so that you send it yourself, which
      means it reaches us as an ordinary email and nothing you typed is stored here. If you do email
      us, we keep the message for as long as it takes to answer it and act on it, and for nothing
      else — it is never added to a mailing list, because there is no mailing list.</p>

    <h2 id="advertising">Advertising</h2>
    <p>This section is written to be true whether advertising is on or off, because that is a single
      setting in this site’s configuration file rather than a rebuild.</p>
    <p><strong>While advertising is off</strong> — the state the site ships in — no ad-network code
      runs on these pages, and no third-party advertising cookie or identifier is set, by us or by
      anyone else.</p>
    <p><strong>If advertising is switched on</strong>, it will be delivered by a third-party network
      such as Google AdSense, and then the following applies: Google and its partners may use cookies
      or similar identifiers to serve ads based on your previous visits to this site or to other
      sites; Google’s use of advertising cookies enables it and its partners to serve ads to you based
      on your visit to this and other websites; you can switch off personalised advertising from
      Google at
      <a href="https://www.google.com/settings/ads" rel="noopener nofollow" target="_blank">google.com/settings/ads</a>,
      and opt out of a third-party vendor’s use of cookies for personalised advertising at
      <a href="https://www.aboutads.info/choices/" rel="noopener nofollow" target="_blank">aboutads.info/choices</a>.
      Any network used will handle data under its own privacy policy, which will be named and linked
      here beside it.</p>
    <p class="page-note"><strong>To whoever switches advertising on:</strong> rewrite this page the
      same day. From the moment an ad tag loads, the statements above about no third-party cookies and
      no tracking are no longer true, and readers in the United Kingdom and the European Economic Area
      will need a consent mechanism. Do not leave a policy standing that describes a site which no
      longer exists.</p>

    <h2 id="children">Children</h2>
    <p>This is a general news site and is not directed at children. It knowingly collects nothing from
      anyone of any age, for the simple reason that it provides no mechanism by which a person could
      submit personal information to us.</p>

    <h2 id="rights">Your rights over your data</h2>
    <p>The UK and EU GDPR, and the privacy laws of California and other US states, give you rights to
      see, correct, export and delete personal data an organisation holds about you. Here they resolve
      unusually quickly: <strong>we hold no personal data about readers</strong>, so there is nothing
      to produce, nothing to amend and nothing to erase. The one exception is an email you have sent
      us, which you may ask us to delete at any time at <a href="mailto:{PRIVACY}">{PRIVACY}</a>. We do
      not sell or share personal information — there is none to sell or share.</p>

    <h2 id="changes">Changes</h2>
    <p>When the site changes, this page changes with it and the review date at the top is updated.
      There is no mailing list to announce it on, so that date is how you tell. Anything you want to
      ask goes to <a href="mailto:{PRIVACY}">{PRIVACY}</a>.</p>
  </div>
</section>
HTML;

        return [
            'title'       => 'Privacy Policy',
            'description' => 'What this site collects — no accounts, no analytics, no tracking cookies '
                . '— plus server logs, third-party image and font requests, and a conditional advertising clause.',
            'body'        => self::withContents(self::fill($body, $cfg)),
            'jsonld'      => self::jsonLd($cfg, 'WebPage', 'Privacy Policy', '/privacy'),
        ];
    }

    // ================================================================= terms

    /** @return array{title:string,description:string,body:string,jsonld:array} */
    public static function terms(array $cfg): array
    {
        $body = <<<'HTML'
<section class="block wrap page">
  <header class="page-head">
    <p class="page-kicker"><span class="block-label">Terms</span></p>
    <h1 class="page-title">Terms of use</h1>
    <p class="page-standfirst">Short, and in plain English. This site republishes other people’s
      journalism under open licences, so most of what follows is about who owns what — and what you
      are free to do with it.</p>
    <p class="page-meta">Last reviewed {REVIEWED_LONG}</p>
  </header>

  <div class="page-prose">
    <h2 id="accept">Using the site means accepting these terms</h2>
    <p>Reading {NAME} at {DOMAIN} means accepting what is on this page. If you would rather not, the
      remedy costs nothing: stop reading. Nothing here removes a legal right you hold as a consumer
      that cannot lawfully be signed away.</p>

    <h2 id="service">What this service is</h2>
    <p>{NAME} is an aggregator. It gathers journalism that other newsrooms have published under open
      licences, republishes it where those licences allow, and links to the original every time. It is
      not a newsroom, it employs no reporters, and it originates none of the stories it carries. Where
      a licence does not permit full republication, only a headline, a short extract and a link
      appear.</p>

    <h2 id="ownership">Third-party content and who owns it</h2>
    <p>The articles, photographs and other material made by the publishers named on this site belong
      to those publishers and their authors. Their copyright is theirs and remains theirs; nothing on
      this site moves it and nothing on this site claims it. The licence each piece is carried under
      is named on its page.</p>
    <p>Views expressed in a republished article are the author’s, not ours. We do not adopt, endorse,
      verify or fact-check anyone else’s reporting, and we do not take responsibility for its
      contents — how corrections and removals are handled is set out under
      <a href="{U_STANDARDS}">editorial standards</a>.</p>

    <h2 id="reuse">What you may do with an article you find here</h2>
    <p>Your rights over a republished article come from that article’s licence, not from us. We cannot
      grant more than the publisher granted, and we do not try to grant less. To reuse a piece:</p>
    <ul>
      <li>look at the licence printed on the article page — it will be a Creative Commons licence,
        house syndication terms, or a public-domain dedication;</li>
      <li>comply with it, which will at the very least mean crediting the author and the original
        publisher and linking to the original;</li>
      <li>remember that NoDerivatives means you may not edit, abridge or rewrite the piece, and
        NonCommercial means you may not put it to commercial use;</li>
      <li>take the text from the original publisher rather than from us, so that you are working from
        the canonical version.</li>
    </ul>

    <h2 id="ours">What belongs to this site</h2>
    <p>The design, the layout, the code, the name, the wordmark and the wording of these standing
      pages are ours. Link to any page here as much as you like, quote a reasonable extract with a
      credit, or use the RSS feed. Copying the design wholesale, republishing these standing pages or
      presenting the site as your own is not allowed.</p>
    <p>Nor is systematic scraping, mirroring the site, or any automated use heavy enough to spoil it
      for other readers. The feed exists precisely so that none of that is necessary.</p>

    <h2 id="links">Links to other websites</h2>
    <p>This site links outward constantly — that is the point of it. Those sites are not ours and are
      not under our control. We make no promise about their content, their advertising, their accuracy
      or their handling of your data, and following a link takes you out of these terms and into
      theirs.</p>

    <h2 id="asis">Availability, and no warranty</h2>
    <p>The site is offered as it is. We try to keep it up, correct and current, and we promise none of
      the three at any given moment. Feeds break, publishers move URLs, hosts fall over and stories are
      withdrawn upstream. There is no guarantee of uninterrupted service and no guarantee that a
      particular article will still be here tomorrow — a publisher may have anything removed at any
      time, and we act on it when they do.</p>

    <h2 id="liability">Liability</h2>
    <p>Treat this site as a way of finding and reading journalism, not as a source of professional
      advice. Nothing here is medical, legal, financial or safety advice, and a piece republished here
      is never a substitute for an official notice from the body that issued it. So far as the law
      permits, we are not liable for loss arising from your use of this
      site or from third-party material republished on it. None of this removes a liability that the
      law does not permit to be removed.</p>

    <h2 id="publishers">Publishers and rights holders</h2>
    <p>If you publish something carried here, you can have it removed on request without giving a
      reason, and you can take your whole feed out. Write to
      <a href="mailto:{PERMISSIONS}">{PERMISSIONS}</a>; the steps, and how long each one takes, are set out on the
      <a href="{U_STANDARDS}">editorial standards</a> page. The route is deliberately faster and
      less formal than a legal notice — though a legal notice to the same address is handled
      identically.</p>

    <h2 id="ads">Advertising</h2>
    <p>Display advertising may appear on these pages. Where it does, the units sit in fixed slots, are
      labelled as advertising, come from a third-party network, are not endorsements, and never affect
      which stories are carried or the order they are carried in. What advertising means for your data is covered by the
      <a href="{U_PRIVACY}">privacy policy</a>.</p>

    <h2 id="changes">Changes to these terms</h2>
    <p>These terms may be revised as the site changes. The version on this page is always the current
      one, and the review date at the top says when it was last touched. Carrying on using the site
      after a change means accepting the revised version.</p>

    <h2 id="contact">Contact</h2>
    <p>Questions about these terms go to <a href="mailto:{EDITOR}">{EDITOR}</a>. Every other address
      is on the <a href="{U_CONTACT}">contact</a> page.</p>
  </div>
</section>
HTML;

        return [
            'title'       => 'Terms of Use',
            'description' => 'Plain-English terms: what this aggregator is, who owns the republished '
                . 'articles, what you may do with them, and how a publisher requests removal.',
            'body'        => self::withContents(self::fill($body, $cfg)),
            'jsonld'      => self::jsonLd($cfg, 'WebPage', 'Terms of Use', '/terms'),
        ];
    }

    // =============================================================== helpers

    /**
     * Wrap the prose in the two-column standing-page body and build the
     * contents rail from the page's own <h2 id="..."> headings.
     *
     * Generated, never hand-written: a contents list typed out by hand drifts
     * away from the headings it points at the first time a section is renamed,
     * and a table of contents with a dead anchor in it is worse than none.
     * DOM order is rail-then-prose so that on a phone, where the grid collapses
     * to one column, the contents still arrive before the text they index.
     */
    private static function withContents(string $html): string
    {
        $tail = "  </div>\n</section>";
        if (substr(rtrim($html), -strlen($tail)) !== $tail) {
            // The template changed shape. Ship the prose rather than mangle it.
            return $html;
        }
        $open = '<div class="page-prose">';
        if (strpos($html, $open) === false) {
            return $html;
        }
        if (preg_match_all('#<h2 id="([a-z0-9-]+)">(.*?)</h2>#s', $html, $m, PREG_SET_ORDER) < 2) {
            return $html;
        }

        $li = '';
        foreach ($m as $h) {
            $label = trim((string) preg_replace('/\s+/', ' ', strip_tags($h[2])));
            if ($label === '') {
                continue;
            }
            $li .= '<li><a href="#' . $h[1] . '">' . $label . '</a></li>';
        }
        if ($li === '') {
            return $html;
        }

        $nav = '<nav class="page-toc" aria-labelledby="page-toc-h">'
            . '<p class="page-toc-h" id="page-toc-h">On this page</p>'
            . '<ul>' . $li . '</ul></nav>';

        $html = str_replace($open, '<div class="page-body">' . $nav . $open, $html);

        // The new </div> closes .page-body and must land INSIDE the section, not
        // after it. Appending it to the end of the string put it after
        // </section> and left the document unbalanced — caught by the tag-stack
        // test below, not by the eye and not by a browser, which silently
        // repairs it.
        $html = rtrim($html);

        return substr($html, 0, -strlen($tail)) . "  </div>\n  </div>\n</section>";
    }

    /** The brand, out of config and nowhere else. */
    private static function name(array $cfg): string
    {
        $site = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $name = trim((string) ($site['name'] ?? ''));

        return $name !== '' ? $name : 'This site';
    }

    /**
     * The domain the mailboxes hang off. config.php is authoritative; the live
     * request host is the fallback, so the addresses are never blank on a
     * staging box whose config has not been filled in yet.
     */
    private static function domain(array $cfg): string
    {
        $site   = is_array($cfg['site'] ?? null) ? $cfg['site'] : [];
        $domain = strtolower(trim((string) ($site['domain'] ?? '')));
        $domain = (string) preg_replace('#^https?://#', '', $domain);
        $domain = trim($domain, "/ \t\n\r\0\x0B");
        if ($domain === '') {
            $domain = strtolower(Paths::host());
        }
        // A mailbox carries neither a port nor a www.
        $domain = (string) preg_replace('/:\d+$/', '', $domain);
        $domain = (string) preg_replace('/^www\./', '', $domain);

        return $domain !== '' ? $domain : 'example.com';
    }

    private static function mailbox(array $cfg, string $local): string
    {
        return $local . '@' . self::domain($cfg);
    }

    /** Exactly one escape pass over every value that reaches the markup. */
    private static function fill(string $html, array $cfg): string
    {
        $reviewed = \DateTimeImmutable::createFromFormat('Y-m-d', self::REVIEWED);

        return strtr($html, [
            '{NAME}'          => Render::esc(self::name($cfg)),
            '{DOMAIN}'        => Render::esc(self::domain($cfg)),
            '{EDITOR}'        => Render::esc(self::mailbox($cfg, 'editor')),
            '{CORRECTIONS}'   => Render::esc(self::mailbox($cfg, 'corrections')),
            '{PERMISSIONS}'   => Render::esc(self::mailbox($cfg, 'permissions')),
            '{PRIVACY}'       => Render::esc(self::mailbox($cfg, 'privacy')),
            '{REVIEWED}'      => Render::esc(self::REVIEWED),
            '{REVIEWED_LONG}' => Render::esc($reviewed === false ? self::REVIEWED : $reviewed->format('j F Y')),
            '{U_ABOUT}'       => Render::esc(Paths::url('/about')),
            '{U_STANDARDS}'   => Render::esc(Paths::url('/editorial-standards')),
            '{U_CONTACT}'     => Render::esc(Paths::url('/contact')),
            '{U_PRIVACY}'     => Render::esc(Paths::url('/privacy')),
            '{U_TERMS}'       => Render::esc(Paths::url('/terms')),
            '{U_SOURCES}'     => Render::esc(Paths::url('/sources')),
        ]);
    }

    /**
     * Small, truthful structured data. No postal address, no telephone number
     * and no logo are asserted, because there are none to assert.
     *
     * @return array<string,mixed>
     */
    private static function jsonLd(array $cfg, string $type, string $name, string $route): array
    {
        return [
            '@context'   => 'https://schema.org',
            '@type'      => $type,
            'name'       => $name,
            'url'        => Paths::absolute($route),
            'inLanguage' => 'en',
            'isPartOf'   => [
                '@type' => 'WebSite',
                'name'  => self::name($cfg),
                'url'   => Paths::absolute('/'),
            ],
            'publisher'  => [
                '@type' => 'Organization',
                'name'  => self::name($cfg),
                'url'   => Paths::absolute('/'),
                'email' => self::mailbox($cfg, 'editor'),
            ],
        ];
    }
}
