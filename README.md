# Local TTS for WordPress

[![CI](https://github.com/m4v3r4/Wordpress-Local-TTS/actions/workflows/ci.yml/badge.svg)](https://github.com/m4v3r4/Wordpress-Local-TTS/actions/workflows/ci.yml)
[![License: GPL v3 or later](https://img.shields.io/badge/license-GPL--3.0--or--later-blue.svg)](LICENSE)

**Türkçe:** [README.tr.md](README.tr.md)

Local TTS adds a **Listen** button to WordPress posts. The speech is generated **in the reader's
browser** with open text-to-speech models (ONNX Runtime Web + WebAssembly). Post text is never sent
to a speech service, and the site needs no GPU or API key.

Local TTS is a tool for running local voice models in WordPress. **It does not include any voice
model.** The site owner chooses voices on the plugin's Voices page; the plugin downloads them from
their publishers, verifies them and serves them to readers. Each voice keeps its own licence.

## Features

- Player with a map of the post (one line per paragraph, headings as marks), seek by paragraph,
  time left, speed 0.8×–1.4×, paragraph highlight and a floating mini bar.
- Starts as soon as the first sentence is ready; only one sentence ahead is synthesised.
- Voices are cached in the browser (Cache Storage) and checked with SHA-256.
- Speaks the language of each post (post setting, Polylang/WPML or site language).
- Engines: **EMA Lightning** (Turkish) and **Piper** (VITS + eSpeak NG phonemizer, 40+ languages).
- Voices: ready-made presets, 100+ voices from the [Piper voice library](https://huggingface.co/rhasspy/piper-voices),
  or any Piper-compatible voice from your own addresses. Add and remove them in the admin.
- Resumable installs in 4 MB steps with source fallback; manual FTP upload if the server cannot
  download.
- Admin in English, Turkish, German, French and Spanish.
- Updates from this repository's GitHub releases.

## Install

1. Download `local-tts-<version>.zip` from the [latest release](https://github.com/m4v3r4/Wordpress-Local-TTS/releases/latest).
2. **Plugins → Add New → Upload Plugin**, then activate.
3. **Local TTS → Voices → Install the recommended voice** (or add the voices you want).
4. **Local TTS → Display**: choose where the button appears.

Requirements: WordPress 6.5+, PHP 7.4+, HTTPS (for Cache Storage and `crypto.subtle`), and a
current browser with WebAssembly and module workers. The server should send `.wasm` as
`application/wasm` and `.mjs` as `text/javascript`; **Local TTS → Status** checks this.

## Voices and licences

| Preset | Language | Engine | Download | Licence (as stated by the publisher) |
|---|---|---|---|---|
| EMA Lightning | Turkish | EMA Lightning | 34.5 MB | Apache-2.0 |
| EMA Lightning light (fp16) | Turkish | EMA Lightning | 26.4 MB | Apache-2.0, experimental |
| Piper LJSpeech | English (US) | Piper | ~61 MB | public-domain dataset |
| Piper Thorsten | German | Piper | ~60 MB | CC0 dataset ¹ |
| Piper SIWIS | French | Piper | ~60 MB | CC BY 4.0 dataset ¹ |
| Piper davefx | Spanish | Piper | ~60 MB | CC0 dataset ¹ |

Shared components: ONNX Runtime Web 1.30.0 (MIT, 13.7 MB) and the Piper phonemizer
(eSpeak NG, GPL-3.0-or-later, 18 MB, installed with the first Piper voice).

¹ Fine-tuned from Piper's “lessac” voice, whose Blizzard 2013 dataset licence may restrict
commercial use.

**You are responsible for the licences of the voices you serve.** The admin shows each licence
before installing, saves a `CREDITS.txt` with the publisher's licence texts next to each component,
and can show a credit line under the player. Generated audio is labelled as synthetic while it
plays. See [NOTICE](local-tts/NOTICE).

## Licence

Local TTS is free software under the **GNU General Public License v3.0 or later** ([LICENSE](LICENSE)).
`assets/js/engine/ema-lightning.js` is adapted from Apache-2.0 code
([ozcancelik/ema-lightning-web](https://github.com/ozcancelik/ema-lightning-web)); Apache-2.0 is
compatible with GPLv3 and its notice is kept in [local-tts/licenses](local-tts/licenses).
Voice models, ONNX Runtime and the Piper phonemizer are not part of the plugin.

## Repository layout

```
local-tts/                 the WordPress plugin (what the ZIP contains)
  local-tts.php            entry point, version, constants
  includes/                PHP: catalog, installer, storage, voices, settings, admin, updater, i18n
  assets/js/player.js      player, text extraction, Web Audio scheduling
  assets/js/tts-worker.js  worker: manifest, Cache Storage, integrity checks, engine dispatch
  assets/js/engine/        EMA Lightning and Piper engines
  languages/               translations (.pot, .po, .mo, .l10n.php)
  licenses/                third-party licence texts for included code
tools/                     build.py / build.ps1 (ZIP), i18n.py (translations), check_source.py
tests/                     PHP tests with WordPress stand-ins + fixture server, JS unit tests
dev/                       a page to try the player without WordPress (mock voice)
docs/                      original design notes (Turkish)
```

## Development

```bash
bash tests/run.sh                 # lint, source checks, PHP + JS tests (needs php-cli with curl, node 18+, python 3)
python3 tools/i18n.py build       # after changing strings: .pot, .po, .mo, .l10n.php
python3 tools/build.py            # dist/local-tts-<version>.zip
python3 -m http.server 8080       # then open http://localhost:8080/dev/ for the player with a mock voice
```

Translations live in `tools/translations/<locale>.json` (`"English": "translation"`). To add a
language, add a JSON file and its locale to `LTTS_I18n::LOCALES`.

### Releasing

1. Update the version in `local-tts/local-tts.php` (header and `LTTS_VERSION`) and the
   `Stable tag` in `local-tts/readme.txt`; add a changelog entry.
2. Tag and push: `git tag v0.5.0 && git push origin v0.5.0`.
3. The **Release** workflow checks the versions, runs the tests, builds `local-tts-<version>.zip`
   and attaches it to a GitHub release. Sites running Local TTS see the update on their Plugins
   page (WordPress checks `releases/latest`; drafts and pre-releases are ignored).

Optional lines in the release notes override what WordPress shows:
`Requires at least: 6.5`, `Requires PHP: 7.4`, `Tested up to: 6.8`.

## Contributing

Issues and pull requests are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Security reports:
[SECURITY.md](SECURITY.md).
