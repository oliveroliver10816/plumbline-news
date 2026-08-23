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
