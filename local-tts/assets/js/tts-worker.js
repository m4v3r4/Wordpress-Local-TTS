// Local TTS: speech synthesis worker (ES module).
//
// The engine is chosen by the manifest ("ema-lightning" or "piper"). Each engine module exports
// createEngine(ort, buffers, manifest) returning { sampleRate, synthesize(text, opts), release() }
// and throws an Error named "Cancelled" when opts.isCancelled() turns true.
//
// Messages:
//   gelen  {type:"init", manifestUrl, ortModuleUrl, ortWasmBase}
//          {type:"synthesize", sessionId, segmentIndex, text, speed}
//          {type:"cancel", sessionId}
//   giden  {type:"progress", loaded, total}
//          {type:"ready", modelId, modelVersion, fromCache, persisted}
//          {type:"warning", code}
//          {type:"audio", sessionId, segmentIndex, pcm: ArrayBuffer (transferred), sampleRate, ms}
//          {type:"cancelled", sessionId}
//          {type:"error", code, message, sessionId?, segmentIndex?}
//
// Error codes: network, integrity, incompatible, unsupported, synthesis.

// Engines load with the worker's own ?ver= query, so a plugin update never mixes old and new code.
const ENGINES = { "ema-lightning": "./engine/ema-lightning.js", piper: "./engine/piper.js" };
const engineUrl = (name) => new URL(ENGINES[name] + new URL(import.meta.url).search, import.meta.url).href;

const CACHE_PREFIX = "ltts-model-";

let initPromise = null;
let engine = null;
let queue = Promise.resolve();
const cancelled = new Set();

class WorkerError extends Error {
  constructor(code, message) { super(message || code); this.code = code; }
}

self.onmessage = (event) => {
  const msg = event.data || {};
  switch (msg.type) {
    case "init":
      init(msg).catch(() => {}); // init() reports its own errors
      break;
    case "synthesize":
      queue = queue.then(() => synthesize(msg));
      break;
    case "cancel":
      cancelled.add(msg.sessionId);
      self.postMessage({ type: "cancelled", sessionId: msg.sessionId });
      break;
  }
};

function init(msg) {
  if (!initPromise) {
    initPromise = load(msg).catch((err) => {
      initPromise = null; // yeniden denemeye izin ver
      report(err);
      throw err;
    });
  }
  return initPromise;
}

function report(err, extra = {}) {
  const code = err instanceof WorkerError ? err.code : "synthesis";
  self.postMessage({ type: "error", code, message: String(err?.message || err), ...extra });
}

// ---------- loading ----------

async function load({ manifestUrl, ortModuleUrl, ortWasmBase }) {
  if (typeof WebAssembly !== "object") throw new WorkerError("unsupported", "no WebAssembly");

  let manifest;
  try {
    const res = await fetch(manifestUrl, { cache: "no-cache" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    manifest = await res.json();
  } catch (err) {
    throw new WorkerError("network", `manifest: ${err.message}`);
  }
  if (manifest.format !== 1 || !ENGINES[manifest.engine] || !manifest.files) {
    throw new WorkerError("incompatible", "unknown manifest or engine");
  }

  let ort, engineModule;
  try {
    engineModule = await import(engineUrl(manifest.engine));
  } catch (err) {
    throw new WorkerError("unsupported", `engine: ${err.message}`);
  }
  try {
    ort = await import(ortModuleUrl);
    ort = ort.default && !ort.InferenceSession ? ort.default : ort;
  } catch (err) {
    throw new WorkerError("unsupported", `onnxruntime-web: ${err.message}`);
  }
  const want = manifest.runtime?.version;
  const have = ort.env?.versions?.web;
  if (want && have && want !== have) {
    throw new WorkerError("incompatible", `runtime ${have}, model needs ${want}`);
  }
  ort.env.wasm.numThreads = 1; // cross-origin isolation gerektirmez
  ort.env.wasm.proxy = false;
  ort.env.wasm.wasmPaths = ortWasmBase;
  ort.env.logLevel = "error";

  const { buffers, fromCache, persisted } = await fetchModelFiles(manifest, manifestUrl);

  try {
    manifest.baseUrl = manifestUrl;
    engine = await engineModule.createEngine(ort, buffers, manifest);
  } catch (err) {
    throw new WorkerError("incompatible", `model session: ${err.message}`);
  }

  self.postMessage({ type: "ready", modelId: manifest.id, modelVersion: manifest.version, fromCache, persisted });
  return engine;
}

/**
 * Reads the manifest's files from Cache Storage or downloads them. A cached entry that fails the size
 * or SHA-256 check is deleted and downloaded again. When the quota is full, files are kept in memory only.
 */
async function fetchModelFiles(manifest, manifestUrl) {
  const cacheName = `${CACHE_PREFIX}${manifest.id}-${manifest.version}`;
  let cache = null;
  try {
    for (const key of await caches.keys()) {
      if (key.startsWith(`${CACHE_PREFIX}${manifest.id}-`) && key !== cacheName) await caches.delete(key);
    }
    cache = await caches.open(cacheName);
  } catch {
    cache = null; // no Cache Storage (e.g. insecure context): memory only
  }

  const entries = Object.entries(manifest.files);
  const buffers = {};
  const missing = [];

  for (const [key, entry] of entries) {
    const url = new URL(entry.path, manifestUrl).href;
    let buf = null;
    try {
      const hit = cache && (await cache.match(url));
      if (hit) buf = await hit.arrayBuffer();
    } catch { buf = null; }
    if (buf && (await verify(buf, entry))) {
      buffers[key] = buf;
    } else {
      if (buf) await cache?.delete(url).catch(() => {});
      missing.push([key, entry, url]);
    }
  }

  const total = missing.reduce((a, [, e]) => a + e.bytes, 0);
  let loaded = 0;
  let lastPost = 0;
  const progress = (force) => {
    const now = Date.now();
    if (force || now - lastPost > 120) {
      lastPost = now;
      self.postMessage({ type: "progress", loaded, total });
    }
  };
  if (missing.length) progress(true);

  let persisted = !!cache;
  for (const [key, entry, url] of missing) {
    const start = loaded;
    const buf = await download(url, entry, (n) => { loaded = start + n; progress(false); });
    if (!(await verify(buf, entry))) {
      throw new WorkerError("integrity", `${entry.path} failed the integrity check`);
    }
    buffers[key] = buf;
    loaded = start + entry.bytes;
    progress(true);
    if (cache) {
      try {
        await cache.put(url, new Response(buf, { headers: {
          "Content-Type": entry.type || "application/octet-stream",
          "Content-Length": String(entry.bytes),
        } }));
      } catch (err) {
        if (persisted) self.postMessage({ type: "warning", code: "quota", message: String(err?.message || err) });
        persisted = false;
      }
    }
  }

  return { buffers, fromCache: missing.length === 0, persisted };
}

async function download(url, entry, onBytes) {
  let res;
  try {
    res = await fetch(url, { cache: "no-cache" });
  } catch (err) {
    throw new WorkerError("network", `${entry.path}: ${err.message}`);
  }
  if (!res.ok) throw new WorkerError("network", `${entry.path}: HTTP ${res.status}`);
  if (!res.body) {
    const buf = await res.arrayBuffer();
    onBytes(buf.byteLength);
    return buf;
  }
  const out = new Uint8Array(entry.bytes);
  const reader = res.body.getReader();
  let n = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      if (n + value.length > out.length) throw new WorkerError("integrity", `${entry.path}: larger than expected`);
      out.set(value, n);
      n += value.length;
      onBytes(n);
    }
  } catch (err) {
    if (err instanceof WorkerError) throw err;
    throw new WorkerError("network", `${entry.path}: download incomplete`);
  }
  if (n !== entry.bytes) throw new WorkerError("network", `${entry.path}: download incomplete (${n}/${entry.bytes})`);
  return out.buffer;
}

async function verify(buf, entry) {
  if (buf.byteLength !== entry.bytes) return false;
  if (!entry.sha256) return true;
  if (!self.crypto?.subtle) return true; // insecure context: size check only
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", buf));
  const hex = Array.from(digest, (b) => b.toString(16).padStart(2, "0")).join("");
  return hex === entry.sha256.toLowerCase();
}

// ---------- sentez ----------

async function synthesize({ sessionId, segmentIndex, text, speed = 1 }) {
  if (cancelled.has(sessionId)) return;
  try {
    if (!initPromise) throw new WorkerError("incompatible", "worker not initialised");
    await initPromise;
  } catch {
    return; // the init error was already reported
  }
  if (cancelled.has(sessionId)) return;

  const t0 = performance.now();
  try {
    const pcm = await engine.synthesize(text, {
      speed,
      seed: segmentIndex,
      isCancelled: () => cancelled.has(sessionId),
    });
    if (cancelled.has(sessionId)) return;
    self.postMessage({
      type: "audio", sessionId, segmentIndex, pcm: pcm.buffer,
      sampleRate: engine.sampleRate, ms: Math.round(performance.now() - t0),
    }, [pcm.buffer]);
  } catch (err) {
    if (err && err.name === "Cancelled") return;
    report(err, { sessionId, segmentIndex });
  }
}
