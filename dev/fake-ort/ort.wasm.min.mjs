// Test only: imitates the onnxruntime-web API without real inference, to exercise the worker's
// manifest, cache, integrity and message flow in a browser.
export const env = { versions: { web: "1.30.0" }, wasm: {}, logLevel: "warning" };
export class Tensor { constructor(type, data, dims) { this.type = type; this.data = data; this.dims = dims; } async getData() { return this.data; } }
export const InferenceSession = { async create(bytes) {
  if (!(bytes instanceof Uint8Array) || bytes.length < 1000) throw new Error("bad model bytes");
  return { async release() {}, async run(feeds) {
    if (feeds.input && feeds.scales) { const n = feeds.input.dims[1]; self.__piperScales = Array.from(feeds.scales.data); const a = new Float32Array(n * 256); for (let i = 0; i < a.length; i++) a[i] = 0.05 * Math.sin(i / 9); return { output: new Tensor("float32", a, [1, 1, 1, a.length]) }; }
    if (feeds.ids) { const L = feeds.ids.dims[1]; return { h: new Tensor("float32", new Float32Array(L * 224), [1, L, 224]), dur: new Tensor("float32", new Float32Array(L).fill(2), [1, L]) }; }
    if (feeds.noise) { const T = feeds.fw.dims[1]; return { latents: new Tensor("float32", new Float32Array(T * 64), [1, T, 64]) }; }
    const n = feeds.z.dims[2], a = new Float32Array(n * 1920); for (let i = 0; i < a.length; i++) a[i] = 0.05 * Math.sin(i / 20);
    return { audio: new Tensor("float32", a, [1, a.length]) };
  } };
} };
