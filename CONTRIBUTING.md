# Contributing

Thanks for helping. Issues and pull requests are welcome in English or Turkish.

## Setup

You need PHP 7.4+ (CLI with the curl extension), Node.js 18+ and Python 3. No Composer or npm
packages are required.

```bash
bash tests/run.sh          # everything CI runs
python3 -m http.server 8080  # then open http://localhost:8080/dev/ (player with a mock voice)
```

To try it in WordPress, build the ZIP with `python3 tools/build.py` (or `tools/build.ps1` on
Windows) and upload it, or symlink `local-tts/` into `wp-content/plugins/`.

## Guidelines

- **Code style:** WordPress coding standards for PHP (tabs, `snake_case`, Yoda conditions, escaping
  on output). Plain ES2020 JavaScript, no build step: `assets/js/player.js` is a classic script,
  the worker and engines are ES modules.
- **Comments in English.** Explain *why* when it is not obvious; no commented-out code.
- **No invisible or bidirectional control characters** in source files. Write them as escapes
  (`​`); `tools/check_source.py` enforces this.
- **Strings:** wrap admin and player text in `__()` with the `local-tts` text domain, then run
  `python3 tools/i18n.py build`. Add translations to `tools/translations/<locale>.json` if you can;
  missing ones fall back to English.
- **Never commit voice models, runtimes or downloaded binaries.** The plugin is a tool; voices are
  third-party works that each site downloads itself.
- **New catalog entries** (`includes/class-ltts-catalog.php`) need pinned sizes and SHA-256 digests
  (or Hugging Face paths), the publisher's licence, a credit line and a link to the source.
- **Tests:** add a check to `tests/php/` or `tests/js/` for behaviour you change. The PHP tests run
  the real classes against WordPress stand-ins (`tests/php/bootstrap.php`) and a fixture HTTP server
  (`tests/server.py`).

## Licence

By contributing you agree that your contribution is licensed under GPL-3.0-or-later, like the rest
of the plugin.
