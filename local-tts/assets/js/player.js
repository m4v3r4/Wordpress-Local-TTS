/*!
 * Local TTS front end: the player, the text extractor and the Web Audio scheduler.
 * Configuration is read from window.lttsPlayerConfig (printed by PHP).
 */
(function () {
  "use strict";

  var config = window.lttsPlayerConfig;
  if (!config) return;

  var NUM_LOCALE = config.lang || document.documentElement.lang || undefined;
  var BLOCK_SELECTOR = "h1,h2,h3,h4,h5,h6,p,blockquote,li";
  var HEADING = /^H[1-6]$/;
  var PAUSE = { sentence: 0, block: 0.35, heading: 0.6 };
  var CANCEL_TIMEOUT_MS = 1500;
  var MAX_CONSECUTIVE_ERRORS = 3;

  // ---------------------------------------------------------------- text extraction

  function isHidden(el) {
    if (el.closest("[hidden],[aria-hidden='true']")) return true;
    if (!el.getClientRects().length) return true;
    var style = window.getComputedStyle(el);
    return style.visibility === "hidden" || style.display === "none";
  }

  function cleanText(text) {
    return text.replace(/[\u00ad\u200b]/g, "").replace(/\s+/g, " ").trim();
  }

  /** An element's own text, skipping excluded descendants and nested block candidates. */
  function ownText(el, candidates, exclude) {
    var out = "";
    (function walk(node) {
      for (var child = node.firstChild; child; child = child.nextSibling) {
        if (child.nodeType === 3) {
          out += child.nodeValue;
        } else if (child.nodeType === 1) {
          if (candidates.has(child)) continue;
          if (exclude && child.matches(exclude)) continue;
          if (child.tagName === "BR") { out += " "; continue; }
          var display = child.ownerDocument.defaultView.getComputedStyle(child).display;
          var block = display && display !== "inline" && display !== "contents";
          if (block) out += " ";
          walk(child);
          if (block) out += " ";
        }
      }
    })(el);
    return cleanText(out);
  }

  /**
   * The post body as [{el, text, heading}] in document order.
   * The body is everything between the start and end markers printed by the plugin, so no wrapper
   * element is needed that could upset the theme's layout. Without start/end the whole root is read.
   */
  function extractBlocks(root, exclude, start, end) {
    var all = Array.prototype.slice.call(root.querySelectorAll(BLOCK_SELECTOR));
    var candidates = all.filter(function (el) {
      if (start && !(start.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING)) return false;
      if (start && start.contains(el)) return false;
      if (end && !(end.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_PRECEDING)) return false;
      if (exclude && el.closest(exclude)) return false;
      return !isHidden(el);
    });
    var set = new Set(candidates);
    var blocks = [];
    candidates.forEach(function (el) {
      var text = ownText(el, set, exclude);
      if (/\p{L}/u.test(text)) blocks.push({ el: el, text: text, heading: HEADING.test(el.tagName) });
    });
    return blocks;
  }

  // ---------------------------------------------------------------- splitting

  var segmenter = null;
  try {
    if (window.Intl && Intl.Segmenter) segmenter = new Intl.Segmenter(config.lang || "tr", { granularity: "sentence" });
  } catch (e) { segmenter = null; }

  function sentences(text) {
    if (segmenter) {
      var out = [];
      for (var it = segmenter.segment(text)[Symbol.iterator](), s = it.next(); !s.done; s = it.next()) {
        var t = s.value.segment.trim();
        if (t) out.push(t);
      }
      return out;
    }
    // Without Intl.Segmenter: split at whitespace after punctuation (match(), as old Safari lacks lookbehind)
    return (text.match(/[^.!?…]+(?:[.!?…]+["'”’)\]]*|$)|[.!?…]+/g) || [])
      .map(function (t) { return t.trim(); }).filter(Boolean);
  }

  // Abbreviations and initials that look like sentence ends ("Dr. Smith", "C. Wright Mills", "z. B.",
  // "bkz. s. 45"): a segment ending with one is joined to the next sentence.
  var ABBREVIATION = new RegExp("(?:^|[\\s(“\"'])(?:" + [
    "Dr", "Prof", "Mr", "Mrs", "Ms", "Mx", "Jr", "Sr", "St", "Mt", "No", "no", "Nr", "nr", "vs", "etc", "ed", "Ed", "eds", "vol", "Vol", "p", "pp", "ch", "fig", "Fig", "cf", "approx", "e\\.g", "i\\.e",
    "Doç", "Yrd", "Öğr", "Gör", "Arş", "Uzm", "Av", "Op", "Sn", "Bkz", "bkz", "Krş", "krş", "vb", "vd", "s", "ss", "yy", "çev", "haz", "der", "ör", "Ör",
    "z\\.\\s?B", "bzw", "usw", "ca", "evtl", "ggf", "Hr", "Fr", "Hrsg", "S",
    "M", "Mme", "Mlle", "Mgr", "env", "cf", "av", "apr", "J\\.-C",
    "Sra", "Srta", "Dña", "Ud", "Uds", "pág", "págs", "aprox", "etc",
    "\\p{Lu}", "[IVX]+", "\\d{1,2}",
  ].join("|") + ")\\.$", "u");

  /** Joins short sentences and splits very long ones at word boundaries. */
  function splitBlock(text, maxChars, minChars) {
    var merged = [];
    sentences(text).forEach(function (s) {
      var last = merged[merged.length - 1];
      var glue = last !== undefined && ABBREVIATION.test(last);
      if (last !== undefined && (glue || last.length < minChars || s.length < minChars) && last.length + s.length + 1 <= maxChars) {
        merged[merged.length - 1] = last + " " + s;
      } else {
        merged.push(s);
      }
    });
    var out = [];
    merged.forEach(function (s) {
      while (s.length > maxChars) {
        var head = s.slice(0, maxChars + 1);
        var cut = Math.max(head.lastIndexOf(", "), head.lastIndexOf("; "), head.lastIndexOf(": "));
        if (cut < maxChars * 0.5) cut = head.lastIndexOf(" ");
        if (cut <= 0) cut = maxChars;
        out.push(s.slice(0, cut + 1).trim());
        s = s.slice(cut + 1).trim();
      }
      if (s) out.push(s);
    });
    return out;
  }

  /**
   * Joins the selectors that the browser accepts. One invalid selector (a typo in the settings)
   * would otherwise make matches() throw and stop the whole post from being read.
   */
  function safeSelector(list) {
    if (!list) return "";
    if (!Array.isArray(list)) list = String(list).split(/\n/);
    return list.filter(function (sel) {
      sel = String(sel).trim();
      if (!sel) return false;
      try { document.createDocumentFragment().querySelector(sel); return true; } catch (e) { return false; }
    }).join(",");
  }

  function buildSegments(area) {
    var maxChars = config.maxSegmentChars || 280;
    var minChars = config.minSegmentChars || 24;
    var segments = [];
    var title = cleanText(config.title || "");
    var blocks = extractBlocks(area.root, safeSelector(config.excludeSelectors), area.start, area.end);
    if (title && !(blocks.length && blocks[0].heading && blocks[0].text === title)) {
      segments.push({ text: title, el: null, pause: PAUSE.heading });
    }
    blocks.forEach(function (b) {
      var parts = splitBlock(b.text, maxChars, minChars);
      parts.forEach(function (p, i) {
        var last = i === parts.length - 1;
        segments.push({ text: p, el: b.el, pause: last ? (b.heading ? PAUSE.heading : PAUSE.block) : PAUSE.sentence });
      });
    });
    return segments;
  }

  // ---------------------------------------------------------------- helpers

  function mb(bytes) {
    return (bytes / 1048576).toLocaleString(NUM_LOCALE, { maximumFractionDigits: bytes < 10485760 ? 1 : 0 });
  }

  function fmt(str, values) {
    return String(str || "").replace(/\{(\w+)\}/g, function (_, k) { return values[k] != null ? values[k] : ""; });
  }

  function supported() {
    return typeof window.Worker === "function" && typeof WebAssembly === "object" &&
      !!(window.AudioContext || window.webkitAudioContext) && typeof Promise === "function";
  }

  function modelCacheName() {
    return "ltts-model-" + config.modelId + "-" + config.modelVersion;
  }

  /**
   * What to read: the element of a custom theme selector if one is set; otherwise everything between
   * the plugin's start and end markers; without markers, the siblings that follow the player.
   */
  function findContent(player) {
    if (config.contentSelector) {
      var el = document.querySelector(config.contentSelector);
      return el ? { root: el, start: null, end: null } : null;
    }
    var startMark = document.querySelector("[data-ltts-content-start]");
    if (startMark) {
      var endMark = document.querySelector("[data-ltts-content-end]");
      var root = startMark.parentElement;
      while (root && endMark && !root.contains(endMark)) root = root.parentElement;
      return root ? { root: root, start: startMark, end: endMark } : null;
    }
    var parent = player.parentElement;
    if (!parent) return null;
    var end = null;
    var ends = parent.querySelectorAll("[data-ltts-content-end]");
    for (var i = 0; i < ends.length; i++) {
      if (player.compareDocumentPosition(ends[i]) & Node.DOCUMENT_POSITION_FOLLOWING) { end = ends[i]; break; }
    }
    return { root: parent, start: player, end: end };
  }

  /** A worker served from another origin (e.g. a CDN) is loaded through a same-origin blob. */
  function createWorker(url) {
    var abs = new URL(url, location.href);
    if (abs.origin === location.origin) return new Worker(abs.href, { type: "module" });
    var blob = new Blob(["import " + JSON.stringify(abs.href) + ";"], { type: "text/javascript" });
    return new Worker(URL.createObjectURL(blob), { type: "module" });
  }

  // ---------------------------------------------------------------- strings and icons

  var DEFAULT_T = {
    listen: "Listen", preparing: "Preparing", reading: "Reading with a synthetic voice",
    paused: "Paused", waiting: "Preparing audio", failed: "Couldn’t play", finished: "End of the post",
    loadingModel: "Preparing the voice…", downloading: "Downloading the voice: {loaded} / {total} MB",
    downloadHint: "The first listen downloads about {size} MB. Audio is generated on your device.",
    mapHint: "Tap anywhere on the strip to listen from there.",
    minutes: "{n} min", seconds: "{n} s", left: "{t} left",
    play: "Play", pause: "Pause", resume: "Resume", stop: "Stop", retry: "Try again",
    speed: "Reading speed: {v}", jump: "Go to the current paragraph", position: "Position in the post",
    part: "Part {n} of {total}", credit: "Voice: {credit}", errors: {},
  };
  var T = {};
  Object.keys(DEFAULT_T).forEach(function (k) { T[k] = config.i18n && config.i18n[k] != null ? config.i18n[k] : DEFAULT_T[k]; });
  T.errors = (config.i18n && config.i18n.errors) || {};

  var SPEEDS = [0.8, 1, 1.2, 1.4];
  var CHARS_PER_SECOND = 14; // first guess; measured and corrected after the first segment

  var ICON = {
    play: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg>',
    pause: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z"/></svg>',
    stop: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h10v10H7z"/></svg>',
    spin: '<svg viewBox="0 0 24 24" aria-hidden="true" class="ltts-spin"><path d="M12 3a9 9 0 1 1-9 9" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>',
    retry: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 0 7 7h-2.2A4.8 4.8 0 1 1 12 7.2V10l4-4-4-4z"/></svg>',
    locate: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 6h14v2.2H5zM5 10.9h9v2.2H5zM5 15.8h14V18H5z"/></svg>',
  };

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }

  function duration(seconds) {
    seconds = Math.max(0, Math.round(seconds));
    if (seconds < 60) return fmt(T.seconds, { n: Math.max(1, seconds) });
    return fmt(T.minutes, { n: Math.round(seconds / 60) });
  }

  function speedLabel(v) {
    return v.toLocaleString(NUM_LOCALE, { maximumFractionDigits: 1 }) + "×";
  }

  function storedSpeed() {
    try {
      var v = parseFloat(localStorage.getItem("ltts-speed"));
      if (SPEEDS.indexOf(v) !== -1) return v;
    } catch (e) { /* storage disabled */ }
    var d = config.speed || 1;
    return SPEEDS.reduce(function (a, b) { return Math.abs(b - d) < Math.abs(a - d) ? b : a; }, 1);
  }

  /** The first non-transparent background behind the player (for the floating bar). */
  function surfaceOf(node) {
    for (var n = node; n && n.nodeType === 1; n = n.parentElement) {
      var bg = getComputedStyle(n).backgroundColor;
      if (bg && bg !== "transparent" && !/rgba\(.*,\s*0\)$/.test(bg)) return bg;
    }
    return getComputedStyle(document.documentElement).backgroundColor || "Canvas";
  }

  // ---------------------------------------------------------------- player

  function Player(root) {
    this.root = root;
    this.content = findContent(root);
    this.ctx = null;
    this.worker = null;
    this.workerReady = false;
    this.sessionId = 0;
    this.state = "idle";
    this.speed = storedSpeed();
    this.secPerChar = 1 / CHARS_PER_SECOND;
    this.segments = [];
    this.blocks = [];
    this.current = null;
    this.raf = 0;
    this.resetSession();
    this.render();
    root.classList.add("ltts-ready");
    this.init();
  }

  Player.prototype.render = function () {
    var self = this, root = this.root;
    root.innerHTML = "";
    root.setAttribute("role", "region");
    root.setAttribute("aria-label", T.listen);

    var head = el("div", "ltts-head");
    this.ui = {};
    this.ui.play = el("button", "ltts-play");
    this.ui.play.type = "button";
    var info = el("div", "ltts-info");
    var line = el("div", "ltts-line");
    this.ui.label = el("span", "ltts-label");
    this.ui.time = el("span", "ltts-time");
    line.appendChild(this.ui.label);
    line.appendChild(this.ui.time);
    this.ui.status = el("div", "ltts-status");
    this.ui.status.setAttribute("role", "status");
    this.ui.status.setAttribute("aria-live", "polite");
    info.appendChild(line);
    info.appendChild(this.ui.status);

    var tools = el("div", "ltts-tools");
    this.ui.speed = el("button", "ltts-tool ltts-speed");
    this.ui.speed.type = "button";
    this.ui.stop = el("button", "ltts-tool ltts-stop", ICON.stop);
    this.ui.stop.type = "button";
    this.ui.stop.setAttribute("aria-label", T.stop);
    this.ui.stop.title = T.stop;
    this.ui.retry = el("button", "ltts-tool ltts-retry", ICON.retry + "<span>" + T.retry + "</span>");
    this.ui.retry.type = "button";
    tools.appendChild(this.ui.speed);
    tools.appendChild(this.ui.stop);
    tools.appendChild(this.ui.retry);

    head.appendChild(this.ui.play);
    head.appendChild(info);
    head.appendChild(tools);

    this.ui.map = el("div", "ltts-map");
    this.ui.map.setAttribute("role", "slider");
    this.ui.map.setAttribute("tabindex", "0");
    this.ui.map.setAttribute("aria-label", T.position);
    this.ui.load = el("div", "ltts-load", '<div class="ltts-load-bar"></div>');

    root.appendChild(head);
    root.appendChild(this.ui.map);
    root.appendChild(this.ui.load);

    // Attribution of the voice model, as its licence asks (setting "Show voice credit").
    if (config.credit) {
      var credit = el("div", "ltts-credit");
      var parts = T.credit.split("{credit}");
      credit.appendChild(document.createTextNode(parts[0] || ""));
      var name = config.creditUrl && /^https?:\/\//.test(config.creditUrl) ? el("a") : el("span");
      if (name.tagName === "A") { name.href = config.creditUrl; name.rel = "noopener"; name.target = "_blank"; }
      name.textContent = config.credit;
      credit.appendChild(name);
      credit.appendChild(document.createTextNode(parts.slice(1).join("{credit}")));
      root.appendChild(credit);
    }

    this.ui.play.addEventListener("click", function () { self.onToggle(); });
    this.ui.stop.addEventListener("click", function () { self.stop(); });
    this.ui.retry.addEventListener("click", function () { self.play(self.resumeFrom || 0); });
    this.ui.speed.addEventListener("click", function () { self.cycleSpeed(); });
    this.ui.map.addEventListener("click", function (e) {
      var tick = e.target.closest(".ltts-tick");
      if (tick) self.jumpToBlock(+tick.getAttribute("data-block"));
    });
    this.ui.map.addEventListener("keydown", function (e) { self.onMapKey(e); });
    this.updateSpeed();
  };

  Player.prototype.init = function () {
    if (!supported()) return this.fail("unsupported", true);
    if ((config.supportedLangs || []).indexOf(config.lang) === -1) return this.fail("lang", true);
    if (!this.content) return this.fail("empty", true);
    this.segments = buildSegments(this.content);
    if (!this.segments.length) return this.fail("empty", true);
    this.buildBlocks();
    this.buildMap();
    this.setState("idle");
    this.showDownloadHint();
  };

  /** Groups segments into paragraphs (the ticks on the map strip). */
  Player.prototype.buildBlocks = function () {
    var blocks = [], last = null;
    this.segments.forEach(function (s, i) {
      if (!last || s.el !== last.el || !s.el) {
        last = { el: s.el, heading: !s.el || HEADING.test(s.el.tagName), first: i, last: i, chars: 0, text: s.text };
        blocks.push(last);
      }
      last.last = i;
      last.chars += s.text.length;
      s.block = blocks.length - 1;
      s.offset = last.chars - s.text.length;
    });
    this.blocks = blocks;
    this.totalChars = blocks.reduce(function (a, b) { return a + b.chars; }, 0);
  };

  Player.prototype.buildMap = function () {
    var map = this.ui.map, frag = document.createDocumentFragment();
    this.blocks.forEach(function (b, i) {
      var t = el("span", "ltts-tick" + (b.heading ? " ltts-tick-heading" : ""), '<span class="ltts-fill"></span>');
      t.setAttribute("data-block", i);
      t.style.flexGrow = b.heading ? 0 : Math.max(1, Math.round(Math.sqrt(b.chars)));
      t.title = b.text.length > 90 ? b.text.slice(0, 88) + "…" : b.text;
      frag.appendChild(t);
    });
    map.innerHTML = "";
    map.appendChild(frag);
    map.setAttribute("aria-valuemin", "1");
    map.setAttribute("aria-valuemax", String(this.blocks.length));
    this.ticks = map.querySelectorAll(".ltts-tick");
    this.paintMap(0, 0);
  };

  /** Paints the strip: earlier ticks full, the current one partly. */
  Player.prototype.paintMap = function (block, fraction) {
    var ticks = this.ticks;
    if (!ticks) return;
    for (var i = 0; i < ticks.length; i++) {
      var f = i < block ? 1 : i === block ? fraction : 0;
      ticks[i].firstChild.style.transform = "scaleX(" + f + ")";
      ticks[i].classList.toggle("ltts-tick-current", i === block && this.state !== "idle");
    }
    var b = this.blocks[block];
    this.ui.map.setAttribute("aria-valuenow", String(block + 1));
    if (b) this.ui.map.setAttribute("aria-valuetext", fmt(T.part, { n: block + 1, total: this.blocks.length }) + ": " + b.text.slice(0, 80));
  };

  Player.prototype.showDownloadHint = function () {
    var self = this, bytes = config.downloadBytes;
    this.hint = T.mapHint;
    if (!bytes) return this.refresh();
    var text = fmt(T.downloadHint, { size: mb(bytes) });
    var show = function () { self.hint = text; self.refresh(); };
    if (!window.caches || !window.isSecureContext) return show();
    caches.has(modelCacheName()).then(function (has) { if (!has) show(); else self.refresh(); }).catch(show);
  };

  Player.prototype.resetSession = function () {
    this.ready = new Map();
    this.scheduled = [];
    this.startIndex = 0;
    this.nextRequest = 0;
    this.nextSchedule = 0;
    this.playIndex = -1;
    this.inflight = -1;
    this.errors = 0;
    this.lastEnd = 0;
  };

  /** Estimated time left in seconds, from the given segment on. */
  Player.prototype.remaining = function (from, fraction) {
    var chars = 0;
    for (var i = Math.max(0, from); i < this.segments.length; i++) chars += this.segments[i].text.length;
    if (from >= 0 && this.segments[from]) chars -= this.segments[from].text.length * (fraction || 0);
    return chars * this.secPerChar / this.speed;
  };

  Player.prototype.setState = function (state, status) {
    this.state = state;
    this.root.setAttribute("data-state", state);
    if (status !== undefined) this.statusText = status;
    else if (state === "idle" || state === "playing") this.statusText = "";
    this.refresh();
    this.updateMini();
    if (state === "playing" || state === "waiting") this.loop();
  };

  /** Updates labels, buttons and time for the current state. */
  Player.prototype.refresh = function () {
    var ui = this.ui, s = this.state;
    var busy = s === "loading" || s === "buffering";
    var active = s === "playing" || s === "waiting";
    var labels = {
      idle: T.listen, loading: T.preparing, buffering: T.preparing, playing: T.reading,
      waiting: T.waiting, paused: T.paused, error: T.failed, unavailable: T.listen,
    };
    ui.label.textContent = this.finished && s === "idle" ? T.finished : labels[s] || T.listen;

    ui.play.innerHTML = busy ? ICON.spin : active ? ICON.pause : ICON.play;
    ui.play.setAttribute("aria-label", active ? T.pause : s === "paused" ? T.resume : T.play);
    ui.play.disabled = busy || s === "unavailable";

    var time = "";
    if (s === "idle" && this.segments.length) time = "~" + duration(this.remaining(0));
    else if (active || s === "paused") time = fmt(T.left, { t: duration(this.remaining(this.playIndex, this.segFraction || 0)) });
    ui.time.textContent = time;

    var status = this.statusText;
    if (!status && s === "idle") status = this.hint || "";
    if (!status && (active || s === "paused") && this.blocks.length) {
      var b = this.segments[Math.max(0, this.playIndex)];
      if (b) status = fmt(T.part, { n: b.block + 1, total: this.blocks.length });
    }
    ui.status.textContent = status || "";
    ui.stop.hidden = !(busy || active || s === "paused");
    ui.speed.hidden = s === "error" || s === "unavailable";
    ui.retry.hidden = s !== "error";
    ui.map.hidden = s === "unavailable" || !this.blocks.length;
    ui.load.hidden = s !== "loading";
  };

  Player.prototype.setLoad = function (fraction) {
    this.ui.load.firstChild.style.transform = "scaleX(" + Math.max(0, Math.min(1, fraction)) + ")";
  };

  Player.prototype.fail = function (code, permanent) {
    this.resumeFrom = Math.max(this.playIndex, this.startIndex, 0);
    this.teardown();
    var msg = T.errors[code] || T.errors.synthesis || code;
    this.setState(permanent ? "unavailable" : "error", msg);
    if (window.console) console.warn("[local-tts]", code);
  };

  // ---------------------------------------------------------------- user commands

  Player.prototype.onToggle = function () {
    switch (this.state) {
      case "idle":
      case "error":
        return this.play(0);
      case "playing":
      case "waiting":
        return this.pause();
      case "paused":
        return this.resume();
    }
  };

  Player.prototype.cycleSpeed = function () {
    var i = SPEEDS.indexOf(this.speed);
    this.speed = SPEEDS[(i + 1) % SPEEDS.length];
    try { localStorage.setItem("ltts-speed", String(this.speed)); } catch (e) { /* not important */ }
    this.updateSpeed();
    this.refresh();
    // The segment already being prepared keeps the old speed; later ones are requested at the new speed.
  };

  Player.prototype.updateSpeed = function () {
    this.ui.speed.textContent = speedLabel(this.speed);
    this.ui.speed.setAttribute("aria-label", fmt(T.speed, { v: speedLabel(this.speed) }));
    this.ui.speed.title = this.ui.speed.getAttribute("aria-label");
  };

  Player.prototype.onMapKey = function (e) {
    var cur = Math.max(0, this.playIndex >= 0 ? this.segments[this.playIndex].block : 0);
    var n = this.blocks.length, to = null;
    if (e.key === "ArrowRight" || e.key === "ArrowDown") to = Math.min(n - 1, cur + 1);
    else if (e.key === "ArrowLeft" || e.key === "ArrowUp") to = Math.max(0, cur - 1);
    else if (e.key === "Home") to = 0;
    else if (e.key === "End") to = n - 1;
    else if (e.key === "Enter" || e.key === " ") to = cur;
    if (to === null) return;
    e.preventDefault();
    this.jumpToBlock(to);
  };

  Player.prototype.jumpToBlock = function (i) {
    var b = this.blocks[i];
    if (!b || this.state === "unavailable" || this.state === "loading") return;
    this.play(b.first);
  };

  Player.prototype.ensureAudio = function () {
    // Inside the user's click: create or resume the AudioContext.
    if (!this.ctx) {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      this.ctx = new Ctx();
      var self = this;
      this.ctx.onstatechange = function () {
        if (self.ctx && self.ctx.state !== "running" && (self.state === "playing" || self.state === "waiting")) {
          self.setState("paused");
        }
      };
      // iOS: play one silent sample to unlock audio output
      var b = this.ctx.createBuffer(1, 1, 22050), s = this.ctx.createBufferSource();
      s.buffer = b; s.connect(this.ctx.destination); s.start(0);
    }
    if (this.ctx.state !== "running") this.ctx.resume();
  };

  /** Starts playing from the given segment (stopping current playback first). */
  Player.prototype.play = function (from) {
    from = from || 0;
    this.ensureAudio();
    if (!this.segments.length) return this.fail("empty", true);
    this.cancelSession();
    this.sessionId += 1;
    this.resetSession();
    this.startIndex = this.nextRequest = this.nextSchedule = from;
    this.finished = false;
    this.segFraction = 0;
    this.paintMap(this.segments[from].block, 0);
    this.ensureWorker();
    if (this.workerReady) this.setState("buffering", T.waiting + "…");
    else this.setState("loading", T.loadingModel);
    this.pump();
  };

  Player.prototype.pause = function () {
    if (this.ctx) this.ctx.suspend();
    this.setState("paused");
  };

  Player.prototype.resume = function () {
    this.ensureAudio();
    this.setState(this.scheduled.length ? "playing" : "waiting");
  };

  Player.prototype.stop = function () {
    this.cancelSession();
    this.highlight(null);
    this.paintMap(0, 0);
    this.segFraction = 0;
    if (this.ctx && this.ctx.state !== "running") this.ctx.resume();
    this.setState("idle");
  };

  /** Clears the queue and scheduled audio and cancels running inference. */
  Player.prototype.cancelSession = function () {
    var old = this.sessionId;
    this.sessionId += 1;
    this.scheduled.forEach(function (item) {
      if (!item.source) return;
      item.source.onended = null;
      try { item.source.stop(); } catch (e) { /* may not have started */ }
      item.source.disconnect();
    });
    var busy = this.inflight !== -1;
    this.resetSession();
    if (this.worker && busy) {
      var self = this, worker = this.worker;
      worker.postMessage({ type: "cancel", sessionId: old });
      clearTimeout(this.cancelTimer);
      this.cancelAck = false;
      this.cancelTimer = setTimeout(function () {
        if (!self.cancelAck && self.worker === worker) self.killWorker();
      }, CANCEL_TIMEOUT_MS);
    }
  };

  Player.prototype.teardown = function () {
    this.cancelSession();
    this.highlight(null);
  };

  // ---------------------------------------------------------------- Worker

  Player.prototype.ensureWorker = function () {
    if (this.worker) return;
    var self = this;
    var worker;
    try {
      worker = createWorker(config.workerUrl);
    } catch (e) {
      return this.fail("unsupported");
    }
    this.worker = worker;
    this.workerReady = false;
    worker.onmessage = function (event) { if (self.worker === worker) self.onWorkerMessage(event.data || {}); };
    worker.onerror = function (event) {
      if (self.worker !== worker) return;
      if (event && event.preventDefault) event.preventDefault();
      self.killWorker();
      self.fail("unsupported");
    };
    worker.postMessage({
      type: "init",
      manifestUrl: config.manifestUrl,
      ortModuleUrl: config.ortModuleUrl,
      ortWasmBase: config.ortWasmBase,
    });
  };

  Player.prototype.killWorker = function () {
    if (this.worker) this.worker.terminate();
    this.worker = null;
    this.workerReady = false;
    this.inflight = -1;
  };

  Player.prototype.onWorkerMessage = function (msg) {
    switch (msg.type) {
      case "progress":
        if (this.state === "loading" && msg.total) {
          this.setLoad(msg.loaded / msg.total);
          this.statusText = fmt(T.downloading, { loaded: mb(msg.loaded), total: mb(msg.total) });
          this.refresh();
        }
        break;
      case "ready":
        this.workerReady = true;
        this.hint = T.mapHint;
        if (this.state === "loading") this.setState("buffering", T.waiting + "…");
        break;
      case "warning":
        if (msg.code === "quota" && T.errors.quota) this.notice = T.errors.quota;
        break;
      case "cancelled":
        this.cancelAck = true;
        break;
      case "audio":
        if (msg.sessionId !== this.sessionId) return; // eski oturum
        this.inflight = -1;
        this.errors = 0;
        this.onAudio(msg.segmentIndex, new Float32Array(msg.pcm), msg.sampleRate);
        break;
      case "error":
        if (msg.sessionId !== undefined && msg.sessionId !== this.sessionId) return;
        this.inflight = -1;
        if (msg.segmentIndex !== undefined && msg.code === "synthesis" && ++this.errors < MAX_CONSECUTIVE_ERRORS) {
          this.onAudio(msg.segmentIndex, new Float32Array(0), 48000); // error in one segment: skip it
          return;
        }
        if (msg.segmentIndex === undefined) this.killWorker(); // load error: start clean next time
        if (this.state !== "idle") this.fail(msg.code || "synthesis");
        break;
    }
  };

  /** Requests the next segment; only one segment ahead of the playing one is prepared. */
  Player.prototype.pump = function () {
    if (!this.worker || this.inflight !== -1) return;
    var prefetch = config.prefetch || 1;
    var i = this.nextRequest;
    if (i >= this.segments.length) return;
    if (i > Math.max(this.playIndex, this.startIndex) + prefetch) return;
    this.inflight = i;
    this.nextRequest = i + 1;
    this.worker.postMessage({
      type: "synthesize", sessionId: this.sessionId, segmentIndex: i,
      text: this.segments[i].text, speed: this.speed,
    });
  };

  // ---------------------------------------------------------------- Web Audio

  Player.prototype.onAudio = function (index, pcm, sampleRate) {
    var buffer = null;
    if (pcm.length) {
      buffer = this.ctx.createBuffer(1, pcm.length, sampleRate);
      if (buffer.copyToChannel) buffer.copyToChannel(pcm, 0);
      else buffer.getChannelData(0).set(pcm);
      // Measure the time estimate (seconds per character, normalised to 1× speed).
      var len = this.segments[index].text.length;
      if (len > 20) this.secPerChar = this.secPerChar * 0.6 + (buffer.duration * this.speed / len) * 0.4;
    }
    this.ready.set(index, buffer);
    this.schedule();
    this.pump();
  };

  /** Schedules ready segments in order with a short gap between them. */
  Player.prototype.schedule = function () {
    var ctx = this.ctx;
    while (this.ready.has(this.nextSchedule)) {
      var index = this.nextSchedule;
      var buffer = this.ready.get(index);
      this.ready.delete(index);
      this.nextSchedule += 1;
      var prev = this.scheduled[this.scheduled.length - 1];
      var gap = index > this.startIndex ? this.segments[index - 1].pause : 0;
      var startAt = prev ? prev.endAt + gap : Math.max(ctx.currentTime + 0.05, this.lastEnd + gap);
      if (startAt < ctx.currentTime + 0.02) startAt = ctx.currentTime + 0.05;
      var item = { index: index, source: null, startAt: startAt, endAt: startAt };
      if (buffer) {
        var source = ctx.createBufferSource();
        source.buffer = buffer;
        source.connect(ctx.destination);
        source.onended = this.onEnded.bind(this, item);
        source.start(startAt);
        item.source = source;
        item.endAt = startAt + buffer.duration;
      }
      this.scheduled.push(item);
      if (this.playIndex === -1) this.startedSegment(index);
      if (!buffer) this.onEnded(item); // empty segment
    }
    if (this.scheduled.length && (this.state === "buffering" || this.state === "waiting")) {
      this.setState("playing");
    }
  };

  Player.prototype.onEnded = function (item) {
    var pos = this.scheduled.indexOf(item);
    if (pos === -1) return;
    this.scheduled.splice(pos, 1);
    this.lastEnd = Math.max(this.lastEnd, item.endAt);
    var next = item.index + 1;
    if (next >= this.segments.length) return this.finish();
    this.startedSegment(next);
    if (!this.scheduled.length && this.state !== "paused") {
      this.setState("waiting"); // the next segment is not ready yet
    }
    this.pump();
  };

  Player.prototype.startedSegment = function (index) {
    this.playIndex = index;
    this.segFraction = 0;
    var seg = this.segments[index];
    this.highlight(seg.el);
    this.paintMap(seg.block, this.blocks[seg.block].chars ? seg.offset / this.blocks[seg.block].chars : 0);
    this.refresh();
    this.updateMini();
  };

  /** Smoothly updates the strip and time left while playing. */
  Player.prototype.loop = function () {
    if (this.raf) return;
    var self = this, lastText = 0;
    var tick = function () {
      self.raf = 0;
      if (self.state !== "playing" && self.state !== "waiting") return;
      var item = self.scheduled[0], ctx = self.ctx;
      var seg = self.segments[self.playIndex];
      if (item && seg && item.index === self.playIndex && item.endAt > item.startAt) {
        var f = Math.max(0, Math.min(1, (ctx.currentTime - item.startAt) / (item.endAt - item.startAt)));
        self.segFraction = f;
        var b = self.blocks[seg.block];
        self.paintMap(seg.block, b.chars ? (seg.offset + seg.text.length * f) / b.chars : 1);
        if (self.mini) self.mini.bar.style.transform = "scaleX(" + self.overall() + ")";
        var now = Date.now();
        if (now - lastText > 1000) { lastText = now; self.ui.time.textContent = fmt(T.left, { t: duration(self.remaining(self.playIndex, f)) }); }
      }
      self.raf = requestAnimationFrame(tick);
    };
    this.raf = requestAnimationFrame(tick);
  };

  /** How much of the post has been read (share of characters). */
  Player.prototype.overall = function () {
    var seg = this.segments[this.playIndex];
    if (!seg || !this.totalChars) return 0;
    var done = 0;
    for (var i = 0; i < this.playIndex; i++) done += this.segments[i].text.length;
    return Math.min(1, (done + seg.text.length * (this.segFraction || 0)) / this.totalChars);
  };

  Player.prototype.highlight = function (node) {
    if (this.current === node) return;
    if (this.current) this.current.classList.remove("ltts-reading");
    this.current = node;
    if (node && config.highlight !== false) node.classList.add("ltts-reading");
  };

  Player.prototype.finish = function () {
    this.resetSession();
    this.highlight(null);
    this.paintMap(this.blocks.length, 0);
    this.finished = true;
    this.segFraction = 0;
    this.setState("idle", "");
  };

  // ---------------------------------------------------------------- floating bar

  /** Shows a small control bar at the bottom while the player is off screen. */
  Player.prototype.initMini = function () {
    if (this.mini || !("IntersectionObserver" in window)) return;
    var self = this;
    var bar = el("div", "ltts-mini");
    bar.setAttribute("data-ltts-skip", "");
    bar.setAttribute("role", "region");
    bar.setAttribute("aria-label", T.listen);
    bar.innerHTML = '<div class="ltts-mini-progress"><span></span></div>';
    var play = el("button", "ltts-mini-play");
    play.type = "button";
    var text = el("button", "ltts-mini-text");
    text.type = "button";
    text.title = T.jump;
    var stop = el("button", "ltts-mini-stop", ICON.stop);
    stop.type = "button";
    stop.setAttribute("aria-label", T.stop);
    bar.appendChild(play);
    bar.appendChild(text);
    bar.appendChild(stop);
    document.body.appendChild(bar);

    var cs = getComputedStyle(this.root);
    bar.style.setProperty("--ltts-mini-bg", surfaceOf(this.root));
    bar.style.color = cs.color;
    bar.style.fontFamily = cs.fontFamily;

    play.addEventListener("click", function () { self.onToggle(); });
    stop.addEventListener("click", function () { self.stop(); });
    text.addEventListener("click", function () {
      var seg = self.segments[self.playIndex];
      var target = seg && seg.el ? seg.el : self.root;
      target.scrollIntoView({ behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "center" });
    });

    this.mini = { el: bar, play: play, text: text, bar: bar.querySelector(".ltts-mini-progress span") };
    this.offscreen = false;
    new IntersectionObserver(function (entries) {
      self.offscreen = !entries[0].isIntersecting;
      self.updateMini();
    }).observe(this.root);
  };

  Player.prototype.updateMini = function () {
    var s = this.state;
    var live = s === "playing" || s === "waiting" || s === "paused" || s === "buffering";
    if (live && !this.mini) this.initMini();
    var m = this.mini;
    if (!m) return;
    var show = live && this.offscreen;
    m.el.classList.toggle("ltts-mini-shown", show);
    m.el.setAttribute("aria-hidden", show ? "false" : "true");
    m.el.inert = !show;
    var active = s === "playing" || s === "waiting";
    m.play.innerHTML = s === "buffering" ? ICON.spin : active ? ICON.pause : ICON.play;
    m.play.setAttribute("aria-label", active ? T.pause : T.resume);
    var seg = this.segments[this.playIndex];
    var b = seg ? this.blocks[seg.block] : null;
    m.text.innerHTML = "";
    var strong = el("span", "ltts-mini-label");
    strong.textContent = s === "paused" ? T.paused : T.reading;
    var snip = el("span", "ltts-mini-snippet");
    snip.textContent = b ? b.text : "";
    m.text.appendChild(strong);
    m.text.appendChild(snip);
    m.text.setAttribute("aria-label", T.jump + (b ? ": " + b.text.slice(0, 60) : ""));
    m.bar.style.transform = "scaleX(" + this.overall() + ")";
  };

  // ---------------------------------------------------------------- start-up

  function boot() {
    var nodes = document.querySelectorAll("[data-ltts-player]");
    for (var i = 0; i < nodes.length && i < 1; i++) {
      if (!nodes[i].__ltts) nodes[i].__ltts = new Player(nodes[i]);
    }
  }

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot);
  else boot();

  // For tests and debugging
  window.localTTS = { buildSegments: buildSegments, splitBlock: splitBlock, extractBlocks: extractBlocks };
})();
