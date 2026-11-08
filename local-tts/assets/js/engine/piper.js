// Piper (VITS) engine: text -> phonemes (eSpeak NG via piper-phonemize WebAssembly) -> PCM.
//
// Follows the inference in diffusionstudio/vits-web (MIT): the phonemizer prints
// {"phoneme_ids": [...]} for the input, and the voice model takes
// input [1, n] int64, input_lengths [1] int64, scales [noise_scale, length_scale, noise_w] float32
// and, for multi-speaker voices, sid [1] int64. All files arrive as verified bytes from the worker,
// so the phonemizer never fetches anything itself.

class Cancelled extends Error {
  constructor() { super("cancelled"); this.name = "Cancelled"; }
}

const UNSAFE = /[\u0000-\u0008\u000b-\u001f\u007f-\u009f\u200b-\u200f\u202a-\u202e\u2066-\u2069]/g;

/** Light clean-up; eSpeak NG reads numbers, dates and abbreviations in each language itself. */
export function clean(text) {
  return text
    .replace(UNSAFE, " ")
    .replace(/\bhttps?:\/\/\S+|\bwww\.\S+/gi, " ")
    .replace(/[‘’ʼ]/g, "'")
    .replace(/[“”„«»]/g, '"')
    .replace(/\s+/g, " ")
    .trim();
}

/** Imports the phonemizer's ES-module glue from verified bytes (blob URL), else from its URL. */
async function importGlue(bytes, url) {
  try {
    const blobUrl = URL.createObjectURL(new Blob([bytes], { type: "text/javascript" }));
    try {
      return await import(blobUrl);
    } finally {
      URL.revokeObjectURL(blobUrl);
    }
  } catch (err) {
    if (!url) throw err;
    return import(url); // CSP may forbid blob: scripts; the same file from the site is the fallback
  }
}

export class Piper {
  static async create(ort, buffers, manifest) {
    const config = JSON.parse(new TextDecoder().decode(buffers.config));
    const base = manifest.baseUrl || self.location.href;
    const glueUrl = manifest.files.phonemizer_js ? new URL(manifest.files.phonemizer_js.path, base).href : "";
    const glue = await importGlue(buffers.phonemizer_js, glueUrl);
    if (typeof glue.createPiperPhonemize !== "function") throw new Error("phonemizer glue has no createPiperPhonemize");
    const wasmModule = await WebAssembly.compile(buffers.phonemizer_wasm);
    const session = await ort.InferenceSession.create(new Uint8Array(buffers.model), {
      executionProviders: ["wasm"], graphOptimizationLevel: "all",
    });
    return new Piper(ort, config, session, glue.createPiperPhonemize, wasmModule, buffers.phonemizer_data);
  }

  constructor(ort, config, session, createPhonemizer, wasmModule, data) {
    this.ort = ort;
    this.config = config;
    this.session = session;
    this.createPhonemizer = createPhonemizer;
    this.wasmModule = wasmModule;
    this.data = data;
    this.sampleRate = (config.audio && config.audio.sample_rate) || 22050;
    this.voice = (config.espeak && config.espeak.voice) || "en-us";
    const inf = config.inference || {};
    this.noiseScale = inf.noise_scale ?? 0.667;
    this.lengthScale = inf.length_scale ?? 1;
    this.noiseW = inf.noise_w ?? 0.8;
    this.multiSpeaker = (config.num_speakers || 0) > 1;
  }

  async release() {
    await this.session?.release?.().catch(() => {});
  }

  /** A fresh phonemizer instance per call, as in vits-web; the compiled WASM module is reused. */
  async phonemize(text) {
    let result = null;
    const errors = [];
    const wasmModule = this.wasmModule;
    const data = this.data;
    const module = await this.createPhonemizer({
      print: (line) => {
        try {
          const parsed = JSON.parse(line);
          if (parsed && Array.isArray(parsed.phoneme_ids)) result = parsed.phoneme_ids;
        } catch { /* not JSON */ }
      },
      printErr: (line) => errors.push(line),
      noInitialRun: true,
      locateFile: (path) => path,
      getPreloadedPackage: () => data.slice(0),
      instantiateWasm: (imports, done) => {
        WebAssembly.instantiate(wasmModule, imports).then((instance) => done(instance, wasmModule));
        return {};
      },
    });
    module.callMain(["-l", this.voice, "--input", JSON.stringify([{ text }]), "--espeak_data", "/espeak-ng-data"]);
    if (!result) throw new Error("phonemizer returned nothing" + (errors.length ? ": " + errors.join(" ") : ""));
    return result;
  }

  /**
   * @param {string} text
   * @param {{speed?: number, isCancelled?: () => boolean}} opts
   * @returns {Promise<Float32Array>}
   */
  async synthesize(text, { speed = 1, isCancelled = () => false } = {}) {
    const input = clean(text);
    if (!input || !/\p{L}/u.test(input)) return new Float32Array(0);
    if (isCancelled()) throw new Cancelled();
    const ids = await this.phonemize(input);
    if (isCancelled()) throw new Cancelled();
    if (!ids.length) return new Float32Array(0);

    const { ort } = this;
    const feeds = {
      input: new ort.Tensor("int64", BigInt64Array.from(ids, (x) => BigInt(x)), [1, ids.length]),
      input_lengths: new ort.Tensor("int64", BigInt64Array.from([BigInt(ids.length)]), [1]),
      scales: new ort.Tensor("float32", Float32Array.from([this.noiseScale, this.lengthScale / speed, this.noiseW]), [3]),
    };
    if (this.multiSpeaker) feeds.sid = new ort.Tensor("int64", BigInt64Array.from([0n]), [1]);
    const out = await this.session.run(feeds);
    const tensor = out.output || out[this.session.outputNames[0]];
    const data = tensor.getData ? await tensor.getData() : tensor.data;
    return new Float32Array(data); // own copy: the worker transfers its buffer
  }
}

/** Worker entry point. */
export async function createEngine(ort, buffers, manifest) {
  for (const key of ["model", "config", "phonemizer_js", "phonemizer_wasm", "phonemizer_data"]) {
    if (!buffers[key]) throw new Error(`missing ${key}`);
  }
  return Piper.create(ort, buffers, manifest);
}
