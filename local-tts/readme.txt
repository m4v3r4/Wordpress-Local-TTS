=== Local TTS ===
Contributors: localtts
Tags: text-to-speech, tts, accessibility, audio, listen
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds a "Listen" button to your posts. Speech is generated in the reader's browser with open voice models; post text is never sent to a speech service.

== Description ==

When a reader presses Listen, the browser downloads a voice model once, generates the audio on the
device and starts playing as soon as the first sentence is ready. Voices are cached in the browser,
so other posts start without downloading again.

Local TTS is a tool: it ships no voice models. You choose the voices on the plugin's Voices page and
the plugin downloads them from their publishers, verifies them and serves them to your readers.

Ready-made presets:

* Turkish: EMA Lightning (full or light)
* English: Piper LJSpeech
* German: Piper Thorsten
* French: Piper SIWIS
* Spanish: Piper davefx

You can also add any of the 100+ voices of the Piper voice library (40+ languages), or any
Piper-compatible voice from your own addresses, and remove them again.

Player:

* Play, pause, resume, stop; reading speed 0.8× to 1.4×, remembered per reader
* A map of the post: one line per paragraph, headings as section marks; tap to listen from there
* Time left, highlight of the paragraph being read
* A small floating bar while the player is scrolled out of view
* Speaks the language of each post (post setting, Polylang/WPML, or site language)

Admin (English, Turkish, German, French, Spanish):

* One-click setup of the recommended voice for your site language
* Resumable downloads in 4 MB steps, verified with SHA-256
* Choose content types, categories, minimum length, placement or the [local_tts] shortcode
* Show only to logged-in editors while you test
* Server check for .wasm/.mjs MIME types and outgoing connections
* Updates from GitHub releases (https://github.com/m4v3r4/Wordpress-Local-TTS)

== Installation ==

1. Upload the ZIP from Plugins → Add New → Upload Plugin and activate it.
2. On Local TTS → Voices, click "Install the recommended voice" (or pick others).
3. Choose where the button appears on Local TTS → Display.

If your server cannot download files, the Voices page lists the files and folders for a manual
FTP upload, followed by "Check uploaded files".

== Frequently Asked Questions ==

= Is any text sent to a server? =
No. The post is read from the page in the browser; speech is generated on the reader's device.

= Which browsers? =
Current Chrome, Edge, Firefox and Safari (desktop and mobile) with WebAssembly and module workers.

= What about licences? =
The plugin is GPL-3.0-or-later. Voice models and runtimes are not part of it: each keeps its own
licence (shown before installing, and saved in CREDITS.txt next to its files), and you are
responsible for respecting the licences of the voices you serve. The Piper phonemizer includes
eSpeak NG (GPL-3.0-or-later). Some Piper voices derive from a dataset whose licence may restrict
commercial use. See the NOTICE file.

= How are updates delivered? =
From the releases of https://github.com/m4v3r4/Wordpress-Local-TTS. WordPress shows them on the
Plugins and Updates screens like any other update.

== Changelog ==

= 0.5.0 =
* Licence changed to GPL-3.0-or-later. Third-party notices updated; each installed component gets a
  CREDITS.txt with its licence texts, and the player can show the voice's credit line.
* Add and remove voices: the Piper voice library (100+ voices) or any Piper-compatible voice from
  your own addresses.
* A licence notice is shown before a voice is installed.
* Updates from GitHub releases.
* An invalid CSS selector in "Never read" no longer stops a post from being read.
* Removing the voice served for a language falls back to another installed voice of that language.
* Code review: comments in English, no invisible control characters in the source.

= 0.4.0 =
* Renamed to Local TTS (folder local-tts). Settings, post options and downloaded voices move over
  from the previous plugin automatically; the old plugin is deactivated.
* New voices: English, German, French and Spanish (Piper). The shared phonemizer installs with the
  first Piper voice. Piper file digests are read from the Hugging Face API at install time.
* Admin interface in English, Turkish, German, French and Spanish, with its own language setting.
* The player speaks the language of the post.

= 0.3.0 =
* New player: post map, seek by paragraph, time left, reading speed, floating bar.

= 0.2.1 =
* Fixed "could not verify ort.wasm.min.mjs" on CDNs that compress partial responses.

= 0.2.0 =
* Runtime and voices install from WordPress.
