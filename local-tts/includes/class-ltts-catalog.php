<?php
/**
 * Installable components: runtime, libraries and voice models.
 *
 * Every file lists its download sources and, where known, its size and SHA-256. Sources are tried in
 * order and every download is checked against the same digest. Piper voices on Hugging Face are not
 * pinned here; their size and SHA-256 are read from the Hugging Face API at install time ('hf' key).
 * The catalog can be extended with the 'ltts_catalog' filter.
 *
 * Component keys:
 *   type            runtime | library | model
 *   engine          model only: ema-lightning | piper
 *   language        model only: two-letter code
 *   dir             folder under wp-content/uploads/local-tts/
 *   license         short licence label shown in the admin
 *   credit          attribution shown to readers (player credit line) and written to CREDITS.txt
 *   source_code     where the source of the distributed files is (written to CREDITS.txt)
 *   files           key => { path, sources[], bytes?, sha256?, hf?, type?, doc?, optional? }
 *                   doc: licence or notice text, kept with the component but not sent to browsers
 *                   optional: skipped if every source answers 404
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

class LTTS_Catalog {

	const RUNTIME_ID = 'onnxruntime-web';
	const PIPER_LIB  = 'piper-phonemize';

	/** Engines this plugin version can run. */
	const ENGINES = array( 'ema-lightning', 'piper' );

	const EMA_REPO   = 'ozcancelik/ema-lightning-web';
	const EMA_COMMIT = 'a9a688b19badc36486cdc2444ba957bc63ac860e';

	const PIPER_VOICES_REPO = 'rhasspy/piper-voices';
	const PIPER_VOICES_REV  = 'v1.0.0';
	const PIPER_WASM_COMMIT = '022edba0455ef024574747e1134ef893f1d71b76'; // diffusionstudio/piper-wasm
	const VITS_WEB_COMMIT   = 'f9ad810fa202420337a91b2f683bf1ccb223b5ae'; // diffusionstudio/vits-web

	/** @var array<string,array>|null Per-request cache of all(). */
	private static $cache = null;

	/**
	 * Every component: the built-in presets, voices added by the administrator, and anything added
	 * through the 'ltts_catalog' filter.
	 *
	 * @return array<string,array> Component id => definition.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$items = array_merge( self::builtin(), LTTS_Voices::catalog_items() );

			/**
			 * Filters the component catalog (add voices, change sources, mirror downloads).
			 *
			 * @param array $items Component id => definition; see the class docblock for the keys.
			 */
			self::$cache = (array) apply_filters( 'ltts_catalog', $items );
		}
		return self::$cache;
	}

	/** Forget the cached catalog (after voices are added or removed). */
	public static function flush() {
		self::$cache = null;
	}

	/** Is this one of the plugin's own presets? */
	public static function builtin_has( $id ) {
		return array_key_exists( $id, self::builtin() );
	}

	/**
	 * Presets shipped with the plugin. They are only links to third-party files: nothing is
	 * downloaded until an administrator installs a component.
	 *
	 * @return array<string,array>
	 */
	public static function builtin() {
		$ort = LTTS_ORT_VERSION;
		$npm = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@' . $ort . '/dist/';
		$unp = 'https://unpkg.com/onnxruntime-web@' . $ort . '/dist/';

		$items = array();

		$items[ self::RUNTIME_ID ] = array(
			'type'        => 'runtime',
			'name'        => 'ONNX Runtime Web',
			'version'     => $ort,
			'license'     => 'MIT',
			'homepage'    => 'https://github.com/microsoft/onnxruntime',
			'description' => __( 'Runs the voice models in the browser. Required by every voice.', 'local-tts' ),
			'dir'         => 'runtime/onnxruntime-web/' . $ort,
			// Size and SHA-256 from the jsDelivr package data (onnxruntime-web@1.30.0).
			'files'       => array(
				'module' => array( 'path' => 'ort.wasm.min.mjs', 'type' => 'text/javascript', 'bytes' => 50126, 'sha256' => '219e6a1fc8a9938268d18efca3c91d310bd2f4a59bbd13744df5b2b7fc6cee3b', 'sources' => array( $npm . 'ort.wasm.min.mjs', $unp . 'ort.wasm.min.mjs' ) ),
				'glue'   => array( 'path' => 'ort-wasm-simd-threaded.mjs', 'type' => 'text/javascript', 'bytes' => 24381, 'sha256' => 'e13f7f94fc51b4ca72b12faeb1ee95f4ace6dfbc8939bc718aabdc0a27c4299b', 'sources' => array( $npm . 'ort-wasm-simd-threaded.mjs', $unp . 'ort-wasm-simd-threaded.mjs' ) ),
				'wasm'   => array( 'path' => 'ort-wasm-simd-threaded.wasm', 'type' => 'application/wasm', 'bytes' => 14239897, 'sha256' => '3398c10d07d229bd91b364548e130e0e51a8e5704b88c7c083ebbeb78842dee2', 'sources' => array( $npm . 'ort-wasm-simd-threaded.wasm', $unp . 'ort-wasm-simd-threaded.wasm' ) ),
				'license' => array( 'path' => 'LICENSE.txt', 'doc' => true, 'optional' => true, 'sources' => array( 'https://raw.githubusercontent.com/microsoft/onnxruntime/v' . $ort . '/LICENSE', 'https://cdn.jsdelivr.net/gh/microsoft/onnxruntime@v' . $ort . '/LICENSE' ) ),
			),
			'credit'      => 'ONNX Runtime Web © Microsoft (MIT)',
			'source_code' => array( 'https://github.com/microsoft/onnxruntime/tree/v' . $ort ),
		);

		$pw = 'diffusionstudio/piper-wasm';
		$vw = 'diffusionstudio/vits-web';
		$items[ self::PIPER_LIB ] = array(
			'type'            => 'library',
			'name'            => 'Piper phonemizer (eSpeak NG)',
			'version'         => '1.0.0',
			'license'         => 'GPL-3.0 (eSpeak NG), MIT',
			'homepage'        => 'https://github.com/' . $pw,
			'description'     => __( 'Converts text to phonemes for Piper voices, in the browser. Installed automatically with the first Piper voice.', 'local-tts' ),
			'dir'             => 'runtime/piper-phonemize/1.0.0',
			'manifest_prefix' => 'phonemizer',
			'files'           => array(
				'js'   => array( 'path' => 'piper_phonemize.mjs', 'type' => 'text/javascript', 'bytes' => 153224, 'sha256' => '338f50063fb25f80b33d5671c019c67ba98309c2752ea42463365a31627d06e0', 'sources' => self::gh( $vw, self::VITS_WEB_COMMIT, 'src/piper.js' ) ),
				'wasm' => array( 'path' => 'piper_phonemize.wasm', 'type' => 'application/wasm', 'bytes' => 635212, 'sha256' => 'b777cd107a91d2bcc6a1ea46f2c26a662a7407394fe84589198aeaa83dd7a9d6', 'sources' => self::gh( $pw, self::PIPER_WASM_COMMIT, 'build/piper_phonemize.wasm' ) ),
				'data' => array( 'path' => 'piper_phonemize.data', 'type' => 'application/octet-stream', 'bytes' => 18077249, 'sha256' => '29f1025eb23a5b5c192cd14a6efbce4509402ff265405072ee6f7d1a09b78f8c', 'sources' => self::gh( $pw, self::PIPER_WASM_COMMIT, 'build/piper_phonemize.data' ) ),
				// The WebAssembly build contains eSpeak NG (GPL-3.0-or-later); its licence travels with it.
				'copying' => array( 'path' => 'COPYING-espeak-ng.txt', 'doc' => true, 'optional' => true, 'sources' => self::gh( 'espeak-ng/espeak-ng', 'master', 'COPYING' ) ),
			),
			'credit'      => 'eSpeak NG (GPL-3.0-or-later) via piper-phonemize',
			'source_code' => array(
				'https://github.com/' . $pw . '/tree/' . self::PIPER_WASM_COMMIT . ' (build steps in README)',
				'https://github.com/rhasspy/espeak-ng',
				'https://github.com/wide-video/piper-phonemize',
				'https://github.com/' . $vw . '/blob/' . self::VITS_WEB_COMMIT . '/src/piper.js',
			),
		);

		$items['ema-lightning-tr'] = self::ema_pack(
			'ema-lightning-tr',
			__( 'EMA Lightning (Turkish)', 'local-tts' ),
			__( 'Recommended for Turkish. Full precision (fp32).', 'local-tts' ),
			array( 'path' => 'sound.onnx', 'remote' => 'models/sound.onnx', 'bytes' => 18120655, 'sha256' => '958364217360cecaa92dc5ff2170d19f3c9f6995fd5e61241ea4068971a46a59' )
		);

		$items['ema-lightning-tr-lite'] = self::ema_pack(
			'ema-lightning-tr-lite',
			__( 'EMA Lightning (Turkish, light)', 'local-tts' ),
			__( 'Experimental. The sound graph uses half precision (fp16): about 9 MB smaller. Compare the sound quality on real devices before serving it.', 'local-tts' ),
			array( 'path' => 'sound.onnx', 'remote' => 'models/fp16/sound.onnx', 'bytes' => 9635325, 'sha256' => 'db50477b8693ca87155b0316d3ac2e5ae644c1a9346d77079287d455b615ff28' )
		);

		$lessac = __( 'Fine-tuned from the Piper “lessac” voice, whose Blizzard 2013 dataset licence may restrict commercial use. Check before serving on a commercial site.', 'local-tts' );

		$items['piper-en-us-ljspeech'] = self::piper_voice(
			'piper-en-us-ljspeech', 'en', 'en/en_US/ljspeech/medium/en_US-ljspeech-medium',
			__( 'Piper LJSpeech (English, US)', 'local-tts' ),
			__( 'Female voice. Trained from scratch on the public-domain LJ Speech dataset.', 'local-tts' ),
			__( 'Public domain dataset', 'local-tts' ), 63531379,
			'Piper LJSpeech, rhasspy/piper-voices (LJ Speech dataset, public domain)'
		);
		$items['piper-de-thorsten'] = self::piper_voice(
			'piper-de-thorsten', 'de', 'de/de_DE/thorsten/medium/de_DE-thorsten-medium',
			__( 'Piper Thorsten (German)', 'local-tts' ),
			__( 'Male voice. Thorsten-Voice dataset (CC0).', 'local-tts' ) . ' ' . $lessac,
			__( 'CC0 dataset', 'local-tts' ), 63201294,
			'Piper Thorsten, rhasspy/piper-voices (Thorsten-Voice dataset, CC0)'
		);
		$items['piper-fr-siwis'] = self::piper_voice(
			'piper-fr-siwis', 'fr', 'fr/fr_FR/siwis/medium/fr_FR-siwis-medium',
			__( 'Piper SIWIS (French)', 'local-tts' ),
			__( 'Female voice. SIWIS dataset (CC BY 4.0).', 'local-tts' ) . ' ' . $lessac,
			__( 'CC BY 4.0 dataset', 'local-tts' ), 63 * 1000000,
			'Piper SIWIS, rhasspy/piper-voices (SIWIS French Speech Synthesis Database, CC BY 4.0)'
		);
		$items['piper-es-davefx'] = self::piper_voice(
			'piper-es-davefx', 'es', 'es/es_ES/davefx/medium/es_ES-davefx-medium',
			__( 'Piper davefx (Spanish, Spain)', 'local-tts' ),
			__( 'Male voice. NabuCasa voice dataset (CC0).', 'local-tts' ) . ' ' . $lessac,
			__( 'CC0 dataset', 'local-tts' ), 63 * 1000000,
			'Piper davefx, rhasspy/piper-voices (CC0 dataset)'
		);

		return $items;
	}

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/** Can this component be installed by this plugin version? */
	public static function installable( $item ) {
		if ( ! $item || empty( $item['files'] ) ) {
			return false;
		}
		return 'model' !== $item['type'] || in_array( $item['engine'], self::ENGINES, true );
	}

	/**
	 * Components a model needs, in install order (runtime first).
	 *
	 * @return string[]
	 */
	public static function requires( $item ) {
		if ( ! $item || 'model' !== $item['type'] ) {
			return array();
		}
		$req = array( self::RUNTIME_ID );
		if ( 'piper' === $item['engine'] ) {
			$req[] = self::PIPER_LIB;
		}
		return $req;
	}

	/** Estimated total size in bytes. */
	public static function bytes( $item ) {
		$total = 0;
		foreach ( (array) $item['files'] as $f ) {
			$total += ! empty( $f['bytes'] ) ? (int) $f['bytes'] : ( isset( $f['estimate'] ) ? (int) $f['estimate'] : 0 );
		}
		return $total;
	}

	private static function gh( $repo, $commit, $path ) {
		return array(
			'https://raw.githubusercontent.com/' . $repo . '/' . $commit . '/' . $path,
			'https://cdn.jsdelivr.net/gh/' . $repo . '@' . $commit . '/' . $path,
		);
	}

	/** EMA Lightning pack: text, normaliser and decoder are shared; the sound graph varies. */
	private static function ema_pack( $id, $name, $description, $sound ) {
		$src = static function ( $remote ) {
			return self::gh( self::EMA_REPO, self::EMA_COMMIT, $remote );
		};
		$version = '1.0.0';
		return array(
			'type'        => 'model',
			'engine'      => 'ema-lightning',
			'language'    => 'tr',
			'name'        => $name,
			'version'     => $version,
			'license'     => 'Apache-2.0',
			'homepage'    => 'https://huggingface.co/canberkkkkkk/ema-lightning',
			'description' => $description,
			'dir'         => 'models/' . $id . '/' . $version,
			'sample_rate' => 48000,
			'files'       => array(
				'meta'       => array( 'path' => 'meta.json', 'type' => 'application/json', 'sources' => $src( 'models/meta.json' ), 'bytes' => 357, 'sha256' => '76627201405723633f5319a208ed7dea63c42acbf3ca997671bccdca9d9186b2' ),
				'normalizer' => array( 'path' => 'normalizer.wasm', 'type' => 'application/wasm', 'sources' => $src( 'models/normalizer.wasm' ), 'bytes' => 1211738, 'sha256' => '2614bcf15b4546249d49474c029420a4a611daf9e9a0c596cc852bd16cdecc89' ),
				'text'       => array( 'path' => 'text.onnx', 'sources' => $src( 'models/text.onnx' ), 'bytes' => 4806400, 'sha256' => 'ad5d4885b5d218f63d976a6e562334ea0194aec54847a6c6482d328e784089f0' ),
				'sound'      => array( 'path' => $sound['path'], 'sources' => $src( $sound['remote'] ), 'bytes' => $sound['bytes'], 'sha256' => $sound['sha256'] ),
				'decoder'    => array( 'path' => 'decoder.onnx', 'sources' => $src( 'models/decoder.onnx' ), 'bytes' => 12031945, 'sha256' => '396c06a8e5711fd40bfd2c3f4f33851feb2ad07a86485833844e0ae8ff637b17' ),
				// Apache-2.0 asks redistributors to pass on the licence and NOTICE.
				'license'    => array( 'path' => 'LICENSE.txt', 'doc' => true, 'sources' => $src( 'LICENSE' ), 'bytes' => 11356, 'sha256' => '5c9817c129b98e7bb966bca028c43c19107102ef8e03fe799bffb4354f4ef015' ),
				'notice'     => array( 'path' => 'NOTICE.txt', 'doc' => true, 'sources' => $src( 'NOTICE' ), 'bytes' => 1139, 'sha256' => 'edea30454a87856db23d44f12fd4d4d11f89ef61e0570f15a4013e0373039c37' ),
				'notices'    => array( 'path' => 'normalizer-tr-THIRD_PARTY_NOTICES.md', 'doc' => true, 'sources' => $src( 'licenses/normalizer-tr-THIRD_PARTY_NOTICES.md' ), 'bytes' => 7251, 'sha256' => '978e0b5c33ecb5d89970e035727cd70f1d76a18e21de8ea7b37596d6424c0d81' ),
			),
			'credit'      => 'EMA Lightning (Apache-2.0)',
			'source_code' => array( 'https://github.com/' . self::EMA_REPO . '/tree/' . self::EMA_COMMIT, 'https://github.com/canberk7/ema-lightning', 'https://github.com/erdemtuna/normalizer-tr' ),
			'source'      => array(
				'package' => 'https://github.com/' . self::EMA_REPO,
				'commit'  => self::EMA_COMMIT,
				'weights' => 'https://huggingface.co/canberkkkkkk/ema-lightning',
			),
		);
	}

	/**
	 * Piper voice from rhasspy/piper-voices. Size and SHA-256 come from the Hugging Face API at
	 * install time; $estimate is only shown before installing. $credit is the attribution line the
	 * player can show under it.
	 */
	private static function piper_voice( $id, $lang, $hf, $name, $description, $license, $estimate, $credit ) {
		$sources = static function ( $file ) {
			return array(
				'https://huggingface.co/' . self::PIPER_VOICES_REPO . '/resolve/' . self::PIPER_VOICES_REV . '/' . $file,
				'https://huggingface.co/diffusionstudio/piper-voices/resolve/main/' . $file,
			);
		};
		return array(
			'type'        => 'model',
			'engine'      => 'piper',
			'language'    => $lang,
			'name'        => $name,
			'version'     => '1.0.0',
			'license'     => $license,
			'homepage'    => 'https://huggingface.co/' . self::PIPER_VOICES_REPO . '/tree/' . self::PIPER_VOICES_REV . '/' . dirname( $hf ),
			'description' => $description,
			'dir'         => 'models/' . $id . '/1.0.0',
			'files'       => array(
				'model'  => array( 'path' => 'model.onnx', 'hf' => $hf . '.onnx', 'sources' => $sources( $hf . '.onnx' ), 'estimate' => $estimate ),
				'config' => array( 'path' => 'model.onnx.json', 'type' => 'application/json', 'hf' => $hf . '.onnx.json', 'sources' => $sources( $hf . '.onnx.json' ), 'estimate' => 5000 ),
				// The model card states the dataset and its licence.
				'card'   => array( 'path' => 'MODEL_CARD.txt', 'doc' => true, 'optional' => true, 'hf' => dirname( $hf ) . '/MODEL_CARD', 'sources' => $sources( dirname( $hf ) . '/MODEL_CARD' ), 'estimate' => 600 ),
			),
			'credit'      => $credit,
			'source_code' => array( 'https://huggingface.co/' . self::PIPER_VOICES_REPO . '/tree/' . self::PIPER_VOICES_REV . '/' . dirname( $hf ), 'https://github.com/rhasspy/piper' ),
		);
	}
}
