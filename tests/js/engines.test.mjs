// Unit tests for the engines' text handling (no model needed): node --test tests/js/
import { test } from "node:test";
import assert from "node:assert/strict";
import { preTurkish, chunk, gaussian } from "../../local-tts/assets/js/engine/ema-lightning.js";
import { clean } from "../../local-tts/assets/js/engine/piper.js";

// Stand-in for normalizer-tr: spells out the few numbers used below.
const NUMBERS = { 1: "bir", 2: "iki", 3: "üç", 19: "on dokuz", 20: "yirmi", 2023: "iki bin yirmi üç" };
const normalize = (text) => text.replace(/\d+/g, (d) => NUMBERS[d] ?? d);

test("Turkish ordinals before a lower-case word", () => {
  assert.equal(preTurkish("19. yüzyıl", normalize), "on dokuzuncu yüzyıl");
  assert.equal(preTurkish("3. bölüm", normalize), "üçüncü bölüm");
});

test("Turkish suffixed numbers and Roman numerals", () => {
  assert.equal(preTurkish("2023'teki", normalize), "iki bin yirmi üçteki");
  assert.equal(preTurkish("2023’teki", normalize), "iki bin yirmi üçteki");
  assert.equal(preTurkish("II. Mahmut", normalize), "ikinci Mahmut");
  assert.equal(preTurkish("XX. yüzyıl", normalize), "yirminci yüzyıl");
});

test("initials keep their letter but lose the dot", () => {
  assert.equal(preTurkish("C. Wright Mills", normalize), "C Wright Mills");
});

test("a lone V or X before a capital is left alone (may be an initial)", () => {
  assert.equal(preTurkish("V. Lenin", normalize), "V Lenin");
});

test("chunks keep the text", () => {
  const parts = chunk("Merhaba dünya. Bu bir deneme.");
  assert.ok(parts.length >= 1);
  assert.equal(parts.map((p) => p[0]).join(" "), "Merhaba dünya. Bu bir deneme.");
});

test("seeded noise is reproducible", () => {
  assert.deepEqual(Array.from(gaussian(7)(8)), Array.from(gaussian(7)(8)));
  assert.notDeepEqual(Array.from(gaussian(7)(8)), Array.from(gaussian(8)(8)));
});

test("Piper clean-up removes control characters and links", () => {
  assert.equal(clean("a\u202eb\u200bc https://example.com/x  “quoted”"), "a b c \"quoted\"");
  assert.equal(clean("it’s"), "it's");
});
