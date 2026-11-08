// EMA Lightning (Turkish) engine: text -> 48 kHz PCM with onnxruntime-web.
//
// Portions copyright the ema-lightning-web and EMA Lightning authors, Apache License 2.0
// (see licenses/ema-lightning-web-NOTICE.txt and NOTICE). Modifications are part of Local TTS (GPL-3.0-or-later).
// Adapted from ozcancelik/ema-lightning-web tts.js (Apache-2.0), which follows frontend.py,
// chunker.py and engine.py of the ema_lightning Python package. Modified for Local TTS:
//   - ORT and the model bytes are passed in (the worker reads them from the manifest and cache).
//   - All audio of a segment is returned as one Float32Array.
//   - Cancellation is checked before every ORT call.
//   - Turkish pre-normalisation of ordinals, Roman numerals and suffixed numbers (preTurkish).
// See the NOTICE file for details.

export const RATE = 48000;
const FIRST_WINDOW = 25; // the first decoded window is one second
const WINDOW = 100; // later windows are four seconds each
const CONTEXT = 8; // extra frames decoded on each side of a window
const MAX_WORD_FRAMES = 250;
const MAX_FRAMES = 3000;

// ---------- text front end (frontend.py) ----------

const TURKISH = new Set("çğıöşüÇĞİÖŞÜ");
const TYPOGRAPHY = { "’": "'", "‘": "'", "ʼ": "'", "´": "'", "`": "'", "“": '"', "”": '"', "„": '"',
  "«": '"', "»": '"', "–": "-", "—": "-", "−": "-", "…": "..." };
// eslint-disable-next-line no-control-regex
const UNSAFE = /[\x00-\x08\x0b-\x1f\x7f-\x9f\u061c\u200e\u200f\u202a-\u202e\u2066-\u2069]/g;
const BLOCK_BYTES = 8 * 1024;
const encoder = new TextEncoder();

/** Blocks split at whitespace, each small enough for one normaliser call. */
function blocks(text) {
  const out = [];
  let block = [], size = 0;
  for (const word of text.split(/\s+/).filter(Boolean)) {
    const n = encoder.encode(word).length + 1;
    if (block.length && size + n > BLOCK_BYTES) { out.push(block.join(" ")); block = []; size = 0; }
    block.push(word); size += n;
  }
  if (block.length) out.push(block.join(" "));
  return out;
}

// ---------- Turkish fixes before normalizer-tr (Local TTS addition) ----------
// normalizer-tr reads patterns such as "2023'teki" and "19. yüzyıl" literally ("apostrophe teki",
// "nineteen dot"). They are common in academic text, so they are spelled out here first.

const ORDINAL = { "sıfır": "sıfırıncı", "bir": "birinci", "iki": "ikinci", "üç": "üçüncü", "dört": "dördüncü",
  "beş": "beşinci", "altı": "altıncı", "yedi": "yedinci", "sekiz": "sekizinci", "dokuz": "dokuzuncu", "on": "onuncu",
  "yirmi": "yirminci", "otuz": "otuzuncu", "kırk": "kırkıncı", "elli": "ellinci", "altmış": "altmışıncı",
  "yetmiş": "yetmişinci", "seksen": "sekseninci", "doksan": "doksanıncı", "yüz": "yüzüncü", "bin": "bininci",
  "milyon": "milyonuncu", "milyar": "milyarıncı" };
const ROMAN = { I: 1, V: 5, X: 10, L: 50, C: 100, D: 500, M: 1000 };
const LOWER = "a-zçğıöşüâîû";

function romanValue(s) {
  let total = 0;
  for (let i = 0; i < s.length; i++) {
    const v = ROMAN[s[i]], next = ROMAN[s[i + 1]] || 0;
    total += v < next ? -v : v;
  }
  return total;
}

function cardinal(digits, normalize) {
  const spoken = normalize(digits).trim();
  return /\d/.test(spoken) ? null : spoken;
}

function ordinal(n, normalize) {
  const words = cardinal(String(n), normalize);
  if (!words) return null;
  const parts = words.split(" ");
  const last = ORDINAL[parts.at(-1).toLocaleLowerCase("tr")];
  if (!last) return null;
  parts[parts.length - 1] = last;
  return parts.join(" ");
}

export function preTurkish(text, normalize) {
  // links are not read
  text = text.replace(/\bhttps?:\/\/\S+|\bwww\.\S+/gi, " ");
  // "19. yüzyıl", "3. bölüm": number + dot + lower-case word => ordinal
  text = text.replace(new RegExp(`(^|[^\\d.,%$€₺])(\\d{1,4})\\.(?=\\s+[${LOWER}])`, "g"), (m, pre, num) => {
    const w = ordinal(Number(num), normalize);
    return w ? pre + w : m;
  });
  // "1. Dünya Savaşı", "2. Abdülhamid": a one- or two-digit number + dot + capital is an ordinal too
  // (longer numbers, such as a year ending a sentence, are left alone)
  text = text.replace(/(^|[^\d.,%$€₺])(\d{1,2})\.(?=\s+\p{Lu}\p{Ll})/gu, (m, pre, num) => {
    const w = ordinal(Number(num), normalize);
    return w ? pre + w : m;
  });
  // "XIX. yüzyıl", "XX. yy"
  text = text.replace(/\b([IVXLC]{1,7})\.?(?=\s+(?:yüzyıl|yy\b|asır))/g, (m, r) => {
    const n = romanValue(r);
    const w = n > 0 && n < 200 ? ordinal(n, normalize) : null;
    return w || m;
  });
  // "II. Mahmut", "I. Dünya Savaşı": a Roman numeral of I/V/X + dot + capital.
  // A lone V or X is skipped, as it may be an initial (V. Lenin).
  text = text.replace(/(^|[^\p{L}\d.])(X{0,3}(?:IX|IV|V?I{0,3}))\.(?=\s+\p{Lu}\p{Ll})/gu, (m, pre, r) => {
    if (!r || r === "V" || r === "X") return m;
    const w = ordinal(romanValue(r), normalize);
    return w ? pre + w : m;
  });
  // Initials: "C. Wright Mills" => "C Wright Mills" (so the normaliser does not read the dot aloud)
  text = text.replace(/(^|[\s(“"'])(\p{Lu})\.(?=\s+\p{Lu})/gu, "$1$2");
  // "yirminci yy." => "yirminci yüzyıl" (century abbreviation)
  text = text.replace(/((?:ıncı|inci|uncu|üncü)\s+)yy\.?(?=\s|$|[,;:)])/g, "$1yüzyıl");
  // "2023'teki", "1980'lerde": number + apostrophe + suffix => spelled number + suffix
  text = text.replace(new RegExp(`(^|[^\\d.,%$€₺\\p{L}])(\\d+)['’]([${LOWER}]+)`, "giu"), (m, pre, num, suffix) => {
    const w = cardinal(num, normalize);
    return w ? pre + w + suffix : m;
  });
  return text;
}

export function frontend(text, alpha, normalize) {
  text = text.replace(UNSAFE, " ");
  if (!text.trim()) return "";
  try { text = preTurkish(text, normalize); } catch { /* a pre-processing error must not break the text */ }
  const spoken = blocks(text).map((b) => {
    try { return normalize(b); } catch { return b; } // keep the words if the normaliser fails
  });
  return alphabet(spoken.join(" "), alpha);
}

export function alphabet(text, alpha) {
  text = text.replace(UNSAFE, " ");
  text = [...text].map((ch) => TYPOGRAPHY[ch] ?? ch).join("");
  text = text.replace(/İ/g, "i").replace(/I/g, "ı").toLocaleLowerCase("tr");
  let out = "";
  for (let ch of text) {
    if (!TURKISH.has(ch)) ch = ch.normalize("NFKD").replace(/\p{M}/gu, "");
    out += ch && [...ch].every((c) => alpha.has(c)) ? ch : " ";
  }
  return out.replace(/\s+/g, " ").trim();
}

// ---------- chunking (chunker.py) ----------

const LETTERS_PER_SECOND = 18, MAX_SECONDS = 10, MAX_LETTERS = 250;
const SENTENCE_PAUSE = 0.25, CLAUSE_PAUSE = 0.12;
const CUTS = [[/[.!?]+["')]*(?= )/g, SENTENCE_PAUSE], [/[,;:](?= )/g, CLAUSE_PAUSE], [/\S(?= )/g, CLAUSE_PAUSE]];

function finish(piece) {
  if (/[.!?]$/.test(piece.replace(/["')]+$/, ""))) return piece;
  return piece.replace(/[,;:\- ]+$/, "") + ".";
}

/** [[chunk, silence after it (s)], ...]; each chunk fits one pass of the model. */
export function chunk(text, speed = 1) {
  const limit = Math.floor(Math.min(MAX_LETTERS, LETTERS_PER_SECOND * MAX_SECONDS * speed));
  const pieces = [];
  let rest = text.trim();
  while (rest) {
    let cut = rest.length, pause = 0;
    if (rest.length > limit) {
      cut = limit;
      const head = rest.slice(0, limit + 1);
      for (const [pattern, gap] of CUTS) {
        const ends = [...head.matchAll(pattern)].map((m) => m.index + m[0].length);
        if (ends.length) { cut = ends.at(-1); pause = gap; break; }
      }
    }
    const piece = rest.slice(0, cut).trim();
    rest = rest.slice(cut).trim();
    if (/\p{L}/u.test(piece)) pieces.push([finish(piece), pause]);
  }
  if (pieces.length) pieces.at(-1)[1] = 0;
  return pieces;
}

// ---------- seeded Gaussian noise (mulberry32 + Box-Muller) ----------

export function gaussian(seed) {
  let a = seed >>> 0;
  const uniform = () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
  return (n) => {
    const out = new Float32Array(n);
    for (let i = 0; i < n; i += 2) {
      const r = Math.sqrt(-2 * Math.log(1 - uniform())), th = 2 * Math.PI * uniform();
      out[i] = r * Math.cos(th);
      if (i + 1 < n) out[i + 1] = r * Math.sin(th);
    }
    return out;
  };
}

// ---------- frame plan (engine.py) ----------

/** Each character's word index and that word's first character (a space belongs to the next word). */
export function words(text) {
  const starts = [];
  for (let i = 0; i < text.length; i++) if (text[i] !== " " && (i === 0 || text[i - 1] === " ")) starts.push(i);
  if (!starts.length) starts.push(0);
  const bounds = [0, ...starts.slice(1), text.length];
  const cw = [], wstart = [];
  for (let w = 0; w + 1 < bounds.length; w++)
    for (let i = bounds[w]; i < bounds[w + 1]; i++) { cw.push(w); wstart.push(bounds[w]); }
  return { cw, wstart };
}

export function plan(cw, wstart, dur, speed) {
  const L = cw.length, nWords = cw[L - 1] + 1;
  const d = Float32Array.from(dur, (x) => x / speed);
  const sums = new Float64Array(nWords);
  for (let i = 0; i < L; i++) sums[cw[i]] += d[i];
  const counts = Array.from(sums, (s) => Math.min(MAX_WORD_FRAMES, Math.max(1, roundHalfEven(s))));
  const T = Math.min(counts.reduce((a, b) => a + b, 0), MAX_FRAMES);
  const fw = new Int32Array(T), fp = new Float32Array(T);
  for (let w = 0, f = 0; w < nWords; w++)
    for (let j = 0; j < counts[w] && f < T; j++, f++) { fw[f] = w; fp[f] = j / counts[w]; }
  // sound_stage: the character's position within its word, by duration
  const c = Float32Array.from(d, (x) => Math.max(x, 1e-4));
  const done = new Float32Array(L), total = new Float32Array(nWords);
  for (let i = 0, acc = 0; i < L; i++) { acc += c[i]; done[i] = acc; total[cw[i]] += c[i]; }
  const before = (i) => done[i] - c[i];
  // aligner letter_pos: harf cinsinden genel konum
  const wlen = new Float32Array(nWords);
  for (const w of cw) wlen[w] += 1;
  const woff = new Float32Array(nWords);
  for (let w = 1; w < nWords; w++) woff[w] = woff[w - 1] + Math.max(wlen[w - 1], 1);
  const cg = new Float32Array(L), fg = new Float32Array(T);
  for (let i = 0; i < L; i++) {
    const cp = Math.min(1, Math.max(0, (done[i] - before(wstart[i]) - 0.5 * c[i]) / Math.max(total[cw[i]], 1e-8)));
    cg[i] = woff[cw[i]] + cp * Math.max(wlen[cw[i]], 1);
  }
  for (let f = 0; f < T; f++) fg[f] = woff[fw[f]] + fp[f] * Math.max(wlen[fw[f]], 1);
  return { T, fw, cg, fg };
}

/** Like torch.round: halves round to even. */
function roundHalfEven(x) {
  const r = Math.round(x);
  return Math.abs(x % 1) === 0.5 && r % 2 !== 0 ? r - 1 : r;
}

export function windows(frames, first) {
  const spans = [];
  for (let s = 0; s < frames;) {
    const e = Math.min(frames, s + (s === 0 ? first : WINDOW));
    spans.push([s, e]);
    s = e;
  }
  return spans;
}

// ---------- normaliser (normalizer-tr, WebAssembly via its C ABI) ----------

export async function loadNormalizer(wasmBytes) {
  const { instance } = await WebAssembly.instantiate(wasmBytes, {});
  const w = instance.exports;
  const dec = new TextDecoder();
  return (text) => {
    const input = encoder.encode(text);
    const ptr = w.alloc(input.length);
    new Uint8Array(w.memory.buffer, ptr, input.length).set(input);
    w.normalize(ptr, input.length);
    w.dealloc(ptr, input.length);
    return dec.decode(new Uint8Array(w.memory.buffer, w.result_ptr(), w.result_len()));
  };
}

// ---------- motor ----------

export class Cancelled extends Error {
  constructor() { super("cancelled"); this.name = "Cancelled"; }
}

export class EmaLightning {
  /**
   * @param {object} ort       onnxruntime-web module
   * @param {object} files     { text, sound, decoder, normalizer: ArrayBuffer, meta: object }
   */
  static async create(ort, files) {
    const opts = { executionProviders: ["wasm"], graphOptimizationLevel: "all" };
    const normalize = await loadNormalizer(files.normalizer);
    const sessions = {};
    for (const name of ["text", "sound", "decoder"]) {
      sessions[name] = await ort.InferenceSession.create(new Uint8Array(files[name]), opts);
    }
    return new EmaLightning(ort, files.meta, sessions, normalize);
  }

  constructor(ort, meta, sessions, normalize) {
    this.ort = ort;
    this.meta = meta;
    this.sessions = sessions;
    this.normalize = normalize;
    this.sampleRate = meta.sample_rate || RATE;
    this.stoi = new Map(meta.vocab.map((ch, i) => [ch, i]));
    this.alpha = new Set(meta.vocab.filter((v) => v.length === 1));
  }

  async release() {
    for (const s of Object.values(this.sessions)) await s?.release?.().catch(() => {});
  }

  pieces(text, speed = 1) {
    return chunk(frontend(text, this.alpha, this.normalize), speed);
  }

  /**
   * Synthesises all audio of one segment.
   * @param {string} text
   * @param {{speed?: number, seed?: number, isCancelled?: () => boolean}} opts
   * @returns {Promise<Float32Array>}
   */
  async synthesize(text, { speed = 1, seed = 0, isCancelled = () => false } = {}) {
    const { ort } = this;
    const i64 = (arr) => new ort.Tensor("int64", BigInt64Array.from(arr, (x) => BigInt(x)), [1, arr.length]);
    const f32 = (arr, dims) => new ort.Tensor("float32", arr, dims);
    const check = () => { if (isCancelled()) throw new Cancelled(); };

    const pieces = this.pieces(text, speed);
    const nSteps = this.meta.times.length, D = this.meta.latent_dim, hop = this.meta.hop;
    const out = [];
    let total = 0;
    const push = (a) => { out.push(a); total += a.length; };

    for (let k = 0; k < pieces.length; k++) {
      const [piece, pause] = pieces[k];
      check();
      const ids = [...piece].map((ch) => this.stoi.get(ch) ?? 1);
      const { cw, wstart } = words(piece);
      const { h, dur } = await this.sessions.text.run({ ids: i64(ids) });
      const { T, fw, cg, fg } = plan(cw, wstart, await dur.getData(), speed);

      check();
      const noise = gaussian(seed * 1000003 + k)(nSteps * T * D);
      const { latents } = await this.sessions.sound.run({
        h, cg: f32(cg, [1, cg.length]), fg: f32(fg, [1, T]), cw: i64(cw), fw: i64(fw),
        noise: f32(noise, [1, nSteps, T, D]),
      });
      const lat = await latents.getData(); // [T, D]

      for (const [s, e] of windows(T, k === 0 ? FIRST_WINDOW : WINDOW)) {
        check();
        const a = Math.max(0, s - CONTEXT), b = Math.min(T, e + CONTEXT), n = b - a;
        const z = new Float32Array(D * n); // [1, D, n]
        for (let f = 0; f < n; f++) for (let d = 0; d < D; d++) z[d * n + f] = lat[(a + f) * D + d];
        const { audio } = await this.sessions.decoder.run({ z: f32(z, [1, D, n]) });
        push((await audio.getData()).slice((s - a) * hop, (e - a) * hop));
      }
      if (pause) push(new Float32Array(Math.round(pause * this.sampleRate)));
    }

    const pcm = new Float32Array(total);
    let o = 0;
    for (const a of out) { pcm.set(a, o); o += a.length; }
    return pcm;
  }
}

/** Worker entry point: builds the engine from the manifest's verified files. */
export async function createEngine(ort, buffers, manifest) {
  const meta = JSON.parse(new TextDecoder().decode(buffers.meta));
  return EmaLightning.create(ort, {
    text: buffers.text, sound: buffers.sound, decoder: buffers.decoder,
    normalizer: buffers.normalizer, meta,
  });
}
