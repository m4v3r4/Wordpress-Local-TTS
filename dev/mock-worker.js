// Mock worker for tests: imitates the model download and synthesis and returns a sine tone.
// Query parameters: ?fail=network | ?slow=1 (synthesis slower than playback) | ?segfail=2 (error in that segment)
const params = new URL(import.meta.url).searchParams;
const cancelled = new Set();
let ready = null;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

self.onmessage = async ({ data: msg }) => {
  if (msg.type === "init") {
    ready = (async () => {
      const total = 36171095;
      for (let i = 1; i <= 5; i++) { await sleep(60); self.postMessage({ type: "progress", loaded: (total * i) / 5, total }); }
      if (params.get("fail") === "network") { self.postMessage({ type: "error", code: "network", message: "mock" }); throw new Error("x"); }
      self.postMessage({ type: "ready", modelId: "ema-lightning-tr", modelVersion: "1.0.0", fromCache: false, persisted: true });
    })();
    ready.catch(() => {});
  } else if (msg.type === "cancel") {
    cancelled.add(msg.sessionId);
    self.postMessage({ type: "cancelled", sessionId: msg.sessionId });
  } else if (msg.type === "synthesize") {
    try { await ready; } catch { return; }
    const seconds = Math.min(1.2, 0.25 + msg.text.length / 120);
    await sleep(params.get("slow") ? seconds * 1500 : 80);
    if (cancelled.has(msg.sessionId)) return;
    if (String(msg.segmentIndex) === params.get("segfail")) {
      self.postMessage({ type: "error", code: "synthesis", sessionId: msg.sessionId, segmentIndex: msg.segmentIndex });
      return;
    }
    const rate = 48000, n = Math.round(seconds * rate), pcm = new Float32Array(n);
    const f = 220 + (msg.segmentIndex % 5) * 55;
    for (let i = 0; i < n; i++) pcm[i] = 0.1 * Math.sin((2 * Math.PI * f * i) / rate);
    self.log = (self.log || []).concat(msg.segmentIndex);
    self.postMessage({ type: "audio", sessionId: msg.sessionId, segmentIndex: msg.segmentIndex, pcm: pcm.buffer, sampleRate: rate, ms: 80 }, [pcm.buffer]);
  }
};
