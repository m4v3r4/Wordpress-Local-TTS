# Changelog

All notable changes. The format follows [Keep a Changelog](https://keepachangelog.com/); versions
follow [Semantic Versioning](https://semver.org/).

## [0.5.0] - 2026-11-08

### Changed
- Licence: GPL-3.0-or-later (was Apache-2.0). Apache-2.0 notices for the adapted EMA Lightning
  code are kept in `local-tts/licenses/`.
- The plugin is presented as a tool that ships no voice models; the site owner is responsible for
  the licences of the voices it serves.
- Code review: comments in English, invisible/bidirectional control characters in regular
  expressions replaced with escapes.

### Added
- Add and remove voices: 100+ voices from the Piper voice library, or any Piper-compatible voice
  from your own HTTPS addresses (with an optional SHA-256).
- A licence notice before a voice is installed; `CREDITS.txt` with the publisher's licence texts
  next to every component; optional voice credit line under the player.
- Updates from GitHub releases (`Update URI` header, release ZIP asset).
- Test suite (`tests/`), source checks, CI and release workflows, `tools/build.py`.

### Fixed
- Removing the voice served for a language now falls back to another installed voice of that
  language.
- Three-letter language codes (for example `fil`) are kept for added voices.
- An invalid CSS selector in "Never read" no longer stops the post from being read.

## [0.4.0]

- Renamed to Local TTS (`local-tts`); automatic migration from the previous plugin.
- Piper voices for English, German, French and Spanish; shared phonemizer.
- Admin in English, Turkish, German, French and Spanish.

## [0.3.0]

- New player: post map, seek by paragraph, time left, reading speed, floating bar.

## [0.2.1]

- Fixed "could not verify ort.wasm.min.mjs" on CDNs that compress partial responses.

## [0.2.0]

- Runtime and voices install from WordPress.
