# Feed recon — measured 2026-08-21

Every URL below was fetched from this box on 2026-08-21, returned **HTTP 200**, parsed
with `app/Xml.php`, and had its first fifteen items measured. Nothing is in the registry
that was not measured, and nothing was measured and left out.

## Why this roster exists

The client's rule is absolute: *"articles must be pulled from sites where we can grab MAX
article length and not 3-4-5 lines. This is a STRICT NO GO."*

Wire feeds cannot satisfy it. Publishers withhold the body from the feed on purpose — ABC
gives **0** characters of article text, CBS 121, UPI 147, NPR 200. No parser fixes that,
because the text is not in the file. The fix is a different kind of source: newsrooms that
publish the whole article inside the feed *and* licence it for republication.

## The roster — 18 feeds, all full text

`per day` is the measured publishing rate across the window each feed carries. It is what
sets `tier` and `rank` in `app/Feeds.php`; none of it is estimated.

| Feed | per day | median body | images | licence | body |
|---|---|---|---|---|---|
| `https://www.nasa.gov/feed/` | 7.57 | 5,170 | yes | public domain | full |
| `https://news.mit.edu/rss/feed` | 3.65 | 6,000+ | yes | credit required | full |
| `https://theconversation.com/us/health/articles.atom` | 3.50 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://kffhealthnews.org/feed/` | 3.00 | 6,000+ | yes | CC BY-NC-ND 4.0 † | full |
| `https://grist.org/feed/` | 2.90 | 5,603 | some | credit required | full |
| `https://19thnews.org/feed/` | 1.79 | 6,000+ | some | CC BY-NC-ND | extract |
| `https://globalvoices.org/feed/` | 1.73 | 6,000+ | yes | CC BY 3.0 | full |
| `https://theconversation.com/us/technology/articles.atom` | 1.56 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://theconversation.com/us/politics/articles.atom` | 1.49 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://theconversation.com/us/environment/articles.atom` | 1.42 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://www.propublica.org/feeds/propublica/main` | 1.39 | 6,000+ | yes | CC BY-NC-ND 3.0 US | extract |
| `https://theconversation.com/us/business/articles.atom` | 0.93 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://spectrum.ieee.org/feeds/feed.rss` | 0.78 | 6,000+ | yes | all rights reserved | extract |
| `https://theconversation.com/us/education/articles.atom` | 0.64 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://theconversation.com/us/world/articles.atom` | 0.58 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://theconversation.com/us/arts/articles.atom` | 0.48 | 6,000+ | yes | CC BY-ND 4.0 | full |
| `https://www.nasa.gov/technology/feed/` | 0.33 | 5,744 | yes | public domain | full |
| `https://themarkup.org/feeds/rss.xml` | 0.08 | 6,000+ | yes | CC BY-NC-ND 4.0 | extract |

† KFF is NonCommercial **and** waives it for this exact case — see the licence section.

### Measured, and deliberately left out

`theconversation.com/us/articles.atom` — The Conversation's all-sections feed — measures
**11.45 items a day**, faster than anything registered above, and it is still not in the
roster. Reason: **47 of the 50 items it carries are the same articles as the eight The
Conversation section feeds we already take.** It contributes three pieces in fifty. Worse,
to earn its speed it would have to be fetched more often than the section feeds, so it
would claim their articles first and file all of them to a single catch-all desk — hollowing
out the very desks this edition leads with. A general feed sitting on top of eight specific
ones is a duplicate with a scoreboard, not a source. Measured 2026-08-21; re-measure before
anyone re-adds it.

"6,000+" in the table above was our own ingest body cap at the time of the measurement,
not the end of the article: the real medians run 6,898 to 11,713 characters.

⚠ **That cap was wrong and has been raised.** It was 6,000, inherited from the wire-fed
build where the body was 0-200 characters and the cap never bit. Re-measured across all
eighteen feeds on 2026-08-22, the median body is **7,640** characters and the 90th
percentile is **10,500** — so the old cap was silently truncating **77.2%** of everything
the site carried, mid-word, with nothing on the page to say so. On a NoDerivatives roster
that is a licence problem, not only a quality one.

`BODY_MAX` in `app/Xml.php` is now **24,000**, which clears the 90th percentile twice over
and costs about 1.5x the body bytes — roughly 4 MB for the whole three-day corpus. The cap
still exists to bound a pathological feed: one Grist item measured 84,387 characters
because the feed carried the whole page. Where it does bite, the cut lands on a paragraph,
sentence or word boundary, is marked with an ellipsis, and `app/Render.php` prints a line
telling the reader the rest is at the publisher.
| `https://www.commondreams.org/feeds/news.rss` | **4,950** | 15/15 | CC BY-SA 4.0 | **newest 1.5h** — fast AND full |
| `https://theconversation.com/au/articles.atom` | 5,765 | 0/15 | CC BY-ND 4.0 | newest 5.2h — fills the US night |

## Measured and rejected — do not re-add

These were fetched and measured. Each one is a stub: the feed carries a teaser, not the
article, which is precisely the failure this roster exists to avoid.

`arstechnica.com` 1,021 characters · `quantamagazine.org` 408 · `insideclimatenews.org` 354 ·
`sciencedaily.com` 343 · `phys.org` 307 · `defense.gov` 228 · `themarshallproject.org` 138 ·
`noaa.gov` 91 · `energy.gov` 83 · `texastribune.org` 3 · `undark.org` 0.

Dead or blocking from this box: `nsf.gov` 404 · `usgs.gov` 404 · `wikinews` 404 ·
`nih.gov` 403 · `stateline.org` 403 · `hbr.org` 404 · `cdc.gov/media/rss.xml` 404.

## Licences — read this before changing anything

Attribution is a licence condition, not a courtesy. Every full-text source here is
**ND (no derivatives)** or asks for the piece whole: publish the body as it arrives, never
rewritten and never cut down into something new. Each source's exact terms, in the
publisher's own words, are quoted in the `notes` field of its entry in `app/Feeds.php`.

**Four sources run as headline + extract + link, never as a full article:**

- **ProPublica** — [steal-our-stories](https://www.propublica.org/steal-our-stories).
  NonCommercial, and their own wording draws the line at a site like this one: *"You can't
  use our work to populate a website designed to improve rankings on search engines or
  solely to gain revenue from network-based advertisements."*
- **The 19th** — CC BY-NC-ND. NonCommercial; an ad-supported page is a commercial use.
- **The Markup** — CC BY-NC-ND 4.0, same reason. Also measured dormant (newest item 24
  days old).
- **IEEE Spectrum** — publishes no republication licence at all
  ([about](https://spectrum.ieee.org/about)), so the body is never reproduced. Its feed
  also carries sponsored posts bylined to the sponsor.

**One NonCommercial source that may still run in full:** KFF Health News waives it
explicitly on [their syndication page](https://kffhealthnews.org/syndication/) — *"This
license allows all news outlets — including for-profit news organizations that charge for
subscriptions and accept advertising — to republish our content free of charge."*

**Pictures are licensed separately from words, and almost none of them are licensed to
us.** Every entry carries an `images` flag; only NASA's is true.

- The Conversation: *"You have to confirm you're licensed to republish images in our
  articles"* — impossible to confirm from a feed.
- Grist: *"you can't republish photographs, collages ... or illustrations without written
  permission"* ([republishing terms](https://grist.org/about/promote/)).
- ProPublica: *"You cannot republish our photographs or illustrations without specific
  permission."*
- KFF Health News: photographs are *"available for republication for noncommercial use
  only"*.
- Global Voices: *"Photos, video, audio sourced from other creators may not always be
  available on the same terms."*
- MIT News: images are separately CC BY-NC-ND
  ([terms of use](https://news.mit.edu/terms-of-use)).
- NASA: [public domain](https://www.nasa.gov/nasa-brand-center/images-and-media/) — the
  one source whose photographs we may run, subject only to not implying NASA endorses
  this site.

Every card therefore uses the site's own placeholder unless the story came from NASA. That
is a one-word change per source in `app/Feeds.php` if a publisher ever grants more.

## Two things worth knowing about the sources

1. **The Conversation supplies eight of the eighteen feeds.** No block should be allowed to
   fill up with one newsroom — each entry carries a `publisher` key so a front-page cap can
   be applied per newsroom rather than per feed.
2. **These sources are slow by wire standards** — a few times a day, not every ten minutes.
   That is exactly why the front page is tiered: three feeds on a ten-minute cycle at the
   top, the rest on thirty minutes and an hour, lower down.

## Freshness audit — 2026-08-23, and what it changed

The first roster was chosen purely on article LENGTH and it produced a news site showing
week-old news. Measured on the live pool before the fix:

| | |
|---|---|
| Front-page lead | **43 hours old** |
| Freshest story anywhere in the candidate pool | 24.5 hours |
| Median age of the pool | **145 hours (6 days)** |
| Oldest story reaching the front page | **1,393 hours (58 days)** |
| Stories under 6 hours old | **0 of 240** |

Per-feed newest-item age, measured the same day:

| Feed | Newest item | Median item age |
|---|---|---|
| grist.org | **0.4h** | 111h |
| commondreams.org | **1.5h** | 41h |
| theconversation.com/au | 5.2h | 61h |
| nasa.gov | 5.8h | 44h |
| theconversation.com/africa | 7.3h | 96h |
| globalvoices.org | 13.4h | 105h |
| kffhealthnews.org | 28.4h | 76h |
| theconversation.com/us | 43.4h | 69h |
| spectrum.ieee.org | 43.4h | **285h** |
| news.mit.edu | 45.4h | 111h |
| propublica.org | 51.4h | **220h** |
| 19thnews.org | 51.4h | 142h |

⚠ **The lesson: full text and fast are in tension.** Publishers who hand you the whole
article are long-form outlets that publish a few times a day; the ones that publish hourly
give you three lines. Only two sources on this roster are both.

Two fixes, both applied:
1. **The per-desk top-up had no time window at all.** The flat read was bounded to 96 hours,
   but the section top-up took the newest N in a desk however old they were — which is how a
   58-day-old story reached the front page. Bounded to 14 days (`HOME_TOPUP_WINDOW_HOURS`).
2. **Added the fast full-text sources** above, and put them on tier 1 so they refresh every
   ten minutes.

Result: the lead went from **43 hours old to 0.5 hours**, and the oldest item able to reach
the front page from 1,393 hours to 313.

⚠ Blocked from this box, do not keep retrying: every States Newsroom site
(`stateline.org`, `michiganadvance.com`, `floridaphoenix.com`, `ohiocapitaljournal.com`,
`virginiamercury.com`, `missouriindependent.com`) returns **403** to both our UA and a
browser UA — Cloudflare refusing the datacenter IP. They would otherwise be ideal: daily,
full text, CC-licensed.
Also measured and rejected: `democracynow.org` (1,048 chars), `truthout.org` (405),
`justice.gov` press releases (248), `insideclimatenews.org` (371), `eff.org` (full text but
newest 45h, median 377h).

## Two verticals added 2026-08-24 — U.S. News and Financial

Measured the same day, same method. Client asked for both on Plumbline only.

| Feed | Median body | Newest item | Licence | Desk |
|---|---|---|---|---|
| `https://theconversation.com/us/articles.atom` | 7,279 | 63.5h | CC BY-ND 4.0 | U.S. News |
| `https://www.commondreams.org/feeds/opinion.rss` | 7,076 | **20.9h** | CC BY-SA 4.0 | U.S. News |
| `https://www.motherjones.com/feed/` | 3,908 | **15.0h** | none published — **extract only** | U.S. News |
| `https://libertystreeteconomics.newyorkfed.org/feed/` | **10,517** | 118.5h | NY Fed, reproduction with attribution | Financial |
| `https://econofact.org/feed` | 3,635 | 784h | Republication with credit | Financial |

⚠ **Free full-text financial journalism barely exists.** Everything else measured for that desk
was a stub or dead: `nakedcapitalism` 0 chars · `wolfstreet` 0 · `federalreserve.gov` 0 ·
`taxfoundation.org` 0 · `bls.gov` 403 · `imf.org` 403 · `stlouisfed.org` timeout ·
`chicagofed.org` 404 · `finance.yahoo.com` fast but 0 chars of body ·
`calculatedriskblog.com` newest item 5,377h old · `cepr.net` 18,379h ·
`marketplace.org` 308 redirect loop · `promarket.org` 326 chars.
So Financial launches with two sources, one of them slow. It will look thinner than the other
desks until a better source appears — that is a sourcing limit, not a bug.

⚠ Also rejected for U.S. News: `thenation.com` 212 chars · `truthout.org` 405 ·
`rawstory.com` 404 · `theintercept.com` full text but newest 23h / median 177h and no reuse
grant · every States Newsroom site still Cloudflare-403 from this box.
⚠ The Conversation's **topic** feeds are abandoned — `us-economy` newest item 54,578h old
(six years), `inflation` 5,486h, `us-politics` 859h. Only the section feeds are maintained.
