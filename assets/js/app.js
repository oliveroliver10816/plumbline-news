/* Enhancement only: deleting this breaks nothing, the server already
   rendered a correct page. Under 10 KB. */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
  var reduced = !!(mq && mq.matches);

  /* theme: TWO states, because site.css has two — no prefers-color-scheme
     block (client's rule: light for everyone), so a 'System' state would claim
     to follow the OS and do nothing. Light is default; only 'dark' is stored. */
  var KEY = 'theme';
  var MODES = ['light', 'dark'];
  var FACE = {
    light: ['☀', 'Light', 'Colour theme: light. Switch to dark'],
    dark:  ['☾', 'Dark',  'Colour theme: dark. Switch to light']
  };

  function savedTheme() {
    try {
      return localStorage.getItem(KEY) === 'dark' ? 'dark' : 'light';
    } catch (e) {
      return 'light';
    }
  }

  function paintMeta() {
    var meta = doc.querySelector('meta[name="theme-color"]');
    if (!meta || !doc.body || !window.getComputedStyle) { return; }
    var bg = window.getComputedStyle(doc.body).backgroundColor;
    if (bg && bg !== 'transparent' && bg.indexOf('rgba(0, 0, 0, 0)') !== 0) {
      meta.setAttribute('content', bg);
    }
  }

  function applyTheme(mode, persist) {
    var dark = mode === 'dark';
    if (dark) { root.setAttribute('data-theme', 'dark'); } else { root.removeAttribute('data-theme'); }
    if (persist) {
      try {
        if (dark) { localStorage.setItem(KEY, 'dark'); } else { localStorage.removeItem(KEY); }
      } catch (e) { /* private mode: dies with the tab */ }
    }
    var face = FACE[mode] || FACE.light;
    for (var i = 0; i < toggles.length; i++) {
      var b = toggles[i];
      var icon = b.querySelector('.tt-icon'), text = b.querySelector('.tt-text');
      if (icon) { icon.textContent = face[0]; }
      if (text) { text.textContent = face[1]; }
      b.setAttribute('aria-label', face[2]);
      b.setAttribute('title', face[2]);
    }
    paintMeta();
  }

  var toggles = doc.querySelectorAll('[data-theme-toggle]');
  if (toggles.length) {
    var current = savedTheme();
    applyTheme(current, false);
    for (var t = 0; t < toggles.length; t++) {
      var li = toggles[t].parentNode;
      if (li && li.classList) { li.classList.add('tt-ready'); }
      toggles[t].addEventListener('click', function () {
        current = MODES[(MODES.indexOf(current) + 1) % MODES.length];
        applyTheme(current, true);
      });
    }
  }

  /* clock, aligned to the second so it never drifts. */
  var clock = doc.getElementById('clock'), clockFmt = null, clockTimer = null;

  if (clock && window.Intl && Intl.DateTimeFormat) {
    var opts = { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true };
    var tz = clock.getAttribute('data-tz');
    var loc = clock.getAttribute('data-locale') || undefined;
    if (tz) { opts.timeZone = tz; }
    try {
      clockFmt = new Intl.DateTimeFormat(loc, opts);
    } catch (e) {
      delete opts.timeZone;        // an unknown tz must not stop the clock
      try { clockFmt = new Intl.DateTimeFormat(undefined, opts); } catch (e2) { clockFmt = null; }
    }
  }

  function tickClock() {
    if (clockFmt) { clock.textContent = clockFmt.format(new Date()); }
  }

  function startClock() {
    if (!clockFmt || clockTimer) { return; }
    tickClock();
    clockTimer = setTimeout(function loop() {
      tickClock();
      clockTimer = setTimeout(loop, 1000 - (Date.now() % 1000));
    }, 1000 - (Date.now() % 1000));
  }

  function stopClock() { if (clockTimer) { clearTimeout(clockTimer); clockTimer = null; } }

  /* times: data-abs holds the server's text; a day-old one comes back. */
  function ago(seconds) {
    if (seconds < 0) { return null; }
    if (seconds < 60) { return 'just now'; }
    var m = Math.floor(seconds / 60);
    if (m < 60) { return m + 'm ago'; }
    var h = Math.floor(m / 60);
    if (h < 24) { return h + 'h ago'; }
    return null;
  }

  function refreshTimes() {
    var nodes = doc.querySelectorAll('time[datetime]'), now = Date.now();
    for (var i = 0; i < nodes.length; i++) {
      var el = nodes[i];
      var abs = el.getAttribute('data-abs');
      if (abs === null) {
        abs = el.textContent;
        el.setAttribute('data-abs', abs);
      }
      var when = Date.parse(el.getAttribute('datetime'));
      if (isNaN(when)) { continue; }
      var label = ago(Math.round((now - when) / 1000));
      el.textContent = label === null ? abs : label;
      if (!el.getAttribute('title')) { el.setAttribute('title', abs); }
    }
  }

  var timesTimer = null;

  function startTimes() {
    if (!timesTimer) { refreshTimes(); timesTimer = setInterval(refreshTimes, 60000); }
  }

  function stopTimes() { if (timesTimer) { clearInterval(timesTimer); timesTimer = null; } }

  /* ticker: hover/focus/motion are CSS; only hidden needs us. */
  var track = doc.querySelector('.ticker-track');

  function pauseTicker(paused) {
    if (track && !reduced) { track.style.animationPlayState = paused ? 'paused' : ''; }
  }

  /* rotation: poll /api/top.json, cross-fade hero cards. See app/Rotate.php. */
  const hero = doc.querySelector('section[aria-label="Top stories"]');
  const leadCard = hero?.querySelector('.card--lead, .hero-lead');
  const slots = hero ? [...hero.querySelectorAll('.card')].sort((x, y) => (y === leadCard) - (x === leadCard)) : [];
  const me = doc.currentScript || doc.querySelector('script[src*="app.js"]');
  const api = (me ? me.src.split('?')[0].replace(/\/assets\/js\/[^/]*$/, '') : '')
    + (doc.querySelector('a[href*="index.php?r="]') ? '/index.php?r=/api/top.json' : '/api/top.json');
  const rotMs = 1000 * +(hero?.getAttribute('data-rotate-seconds') ?? 90);
  // data-pinned cards never rotate: pinning means "this stays here".
  const rotatable = slots.filter(c => c.getAttribute('data-pinned') !== '1');
  const spin = rotatable.slice(0, +hero?.getAttribute('data-rotate-count') || 9);
  let pool = [], cursor = slots.length, rotTimer = null;

  const put = (el, s) => { if (el) { el.textContent = s || ''; } };

  function fill(c, st) {
    const a = c.querySelector('.card-hed a'), img = c.querySelector('img'),
      m = c.querySelector('.card-media'), chip = c.querySelector('.chip'),
      line = c.querySelector('.card-src'), tm = line?.querySelector('time'),
      src = st.source || '';
    if (a) { a.href = st.href; put(a, st.headline); }
    put(c.querySelector('.kicker'), st.section_label);
    put(c.querySelector('.card-sum'), st.summary);
    put(c.querySelector('.credit'), 'PHOTO · ' + src.toUpperCase());
    if (chip) { chip.style.display = st.fresh ? '' : 'none'; }
    if (m) { m.href = st.href; m.style.display = ''; }
    if (img) {
      // The inline onerror hides the img, nulls itself and marks the card
      // text-only. Undo all three, re-arm it.
      const oe = img.getAttribute('onerror');
      if (oe) { img.setAttribute('onerror', oe); }
      img.style.display = '';
      c.classList.remove('card--text');
      img.width = st.image.width;
      img.height = st.image.height;
      img.alt = st.image.alt || st.headline;
      img.src = st.image.url;
    }
    if (tm && st.published_label) {
      line.textContent = src + ' · ';
      tm.dateTime = st.published_iso;
      tm.textContent = st.published_label;
      tm.removeAttribute('data-abs');   // so refreshTimes re-stashes it
      line.appendChild(tm);
    } else {
      put(line, src);
    }
  }

  function paint(keep) {
    slots.forEach(el => { el.style.opacity = '1'; });   // first: never blank
    if (keep) { return; }              // keep: fade back, change nothing
    spin.forEach((el, i) => fill(el, pool[(cursor + i) % pool.length]));
    cursor = (cursor + spin.length) % pool.length;
    refreshTimes();
  }

  // Yanking a link from under a click is the unforgivable failure here.
  const busy = () => {
    const a = doc.activeElement;
    try { if (hero.matches(':hover')) { return true; } } catch (e) { /* old engine */ }
    return !!(a && a !== doc.body && hero.contains(a));
  };

  function swap() {
    if (pool.length <= slots.length || busy()) { return; }
    if (reduced) { paint(); return; }
    slots.forEach(el => { el.style.transition = 'opacity .28s ease'; el.style.opacity = '0'; });
    setTimeout(() => paint(busy()), 280);   // they may arrive mid-fade
  }

  function pullTop() {
    fetch(api, { credentials: 'same-origin' })
      .then(r => r.ok ? r.json() : Promise.reject(r.status))
      .then(d => {
        const next = (d?.stories || []).filter(s => s?.href && s.headline && s.image?.url);
        if (next.length <= slots.length) { return; }  // too thin to rotate
        pool = next;
        if (cursor >= pool.length) { cursor = 0; }
        swap();
      })
      .catch(() => { /* keep the screen; ask again next tick */ });
  }

  // +/-11%, jittered so a crowd never polls on the same second.
  const rotDelay = () => rotMs * 0.89 + Math.random() * rotMs * 0.22;

  function startRotation() {
    if (!slots.length || !rotMs || !window.fetch || rotTimer) { return; }
    rotTimer = setTimeout(function loop() {
      rotTimer = setTimeout(loop, rotDelay());
      pullTop();
    }, rotDelay());
  }

  function stopRotation() { if (rotTimer) { clearTimeout(rotTimer); rotTimer = null; } }

  function onVisible() {        // a hidden tab does nothing at all
    if (doc.hidden) {
      stopClock();
      stopTimes();
      pauseTicker(true);
      stopRotation();
    } else {
      startClock();
      startTimes();
      pauseTicker(false);
      startRotation();
    }
  }

  doc.addEventListener('visibilitychange', onVisible);
  if (mq) {
    var onMotion = function (e) {
      reduced = e.matches;
      if (reduced && track) { track.style.animationPlayState = ''; }
    };
    if (mq.addEventListener) { mq.addEventListener('change', onMotion); }
    else if (mq.addListener) { mq.addListener(onMotion); }
  }

  onVisible();
}());
