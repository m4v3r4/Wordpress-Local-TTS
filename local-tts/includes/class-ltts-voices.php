<?php
/**
 * Voices added by the site administrator.
 *
 * Two kinds:
 *  - From the Piper voice library (rhasspy/piper-voices on Hugging Face): picked from its
 *    voices.json index; size and SHA-256 are read from the Hugging Face API at install time,
 *    like the built-in Piper voices.
 *  - From URLs: any Piper-compatible voice (.onnx + .onnx.json). A SHA-256 can be given for the
 *    model file; without it the digest is recorded on first download (trust on first use).
 *
 * Added voices are stored in the ltts_custom_voices option and merged into the catalog.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registry of administrator-added voices.
 */
class LTTS_Voices {

	const OPTION    = 'ltts_custom_voices';
	const INDEX_KEY = 'ltts_piper_index';
	const PREFIX    = 'custom-';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( 'local-tts/v1', '/voices/library', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_library' ), 'permission_callback' => $admin ) );
		register_rest_route( 'local-tts/v1', '/voices/add', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_add' ), 'permission_callback' => $admin ) );
		register_rest_route(
			'local-tts/v1',
			'/voices/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_delete' ),
				'permission_callback' => $admin,
				'args'                => array( 'id' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ) ),
			)
		);
	}

	/** @return array<string,array> id => stored definition. */
	public static function all() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Catalog entries for the added voices. */
	public static function catalog_items() {
		$items = array();
		foreach ( self::all() as $id => $def ) {
			$item = self::to_item( $id, $def );
			if ( $item ) {
				$items[ $id ] = $item;
			}
		}
		return $items;
	}

	/** Builds a catalog entry from a stored definition. */
	private static function to_item( $id, array $def ) {
		$base = array(
			'type'        => 'model',
			'engine'      => 'piper',
			'custom'      => true,
			'language'    => $def['language'],
			'name'        => $def['name'],
			'version'     => '1.0.0',
			'license'     => $def['license'],
			'credit'      => $def['credit'],
			'description' => $def['description'],
			'dir'         => 'models/' . $id . '/1.0.0',
		);

		if ( 'library' === $def['kind'] ) {
			$hf      = $def['hf']; // e.g. en/en_GB/alan/medium/en_GB-alan-medium
			$sources = static function ( $file ) {
				return array(
					'https://huggingface.co/' . LTTS_Catalog::PIPER_VOICES_REPO . '/resolve/' . LTTS_Catalog::PIPER_VOICES_REV . '/' . $file,
					'https://huggingface.co/diffusionstudio/piper-voices/resolve/main/' . $file,
				);
			};
			$base['homepage']    = 'https://huggingface.co/' . LTTS_Catalog::PIPER_VOICES_REPO . '/tree/' . LTTS_Catalog::PIPER_VOICES_REV . '/' . dirname( $hf );
			$base['source_code'] = array( $base['homepage'], 'https://github.com/rhasspy/piper' );
			$base['files']       = array(
				'model'  => array( 'path' => 'model.onnx', 'hf' => $hf . '.onnx', 'sources' => $sources( $hf . '.onnx' ), 'estimate' => (int) $def['bytes'] ),
				'config' => array( 'path' => 'model.onnx.json', 'type' => 'application/json', 'hf' => $hf . '.onnx.json', 'sources' => $sources( $hf . '.onnx.json' ), 'estimate' => 5000 ),
				'card'   => array( 'path' => 'MODEL_CARD.txt', 'doc' => true, 'optional' => true, 'hf' => dirname( $hf ) . '/MODEL_CARD', 'sources' => $sources( dirname( $hf ) . '/MODEL_CARD' ), 'estimate' => 600 ),
			);
			return $base;
		}

		if ( 'url' === $def['kind'] ) {
			$base['homepage']    = $def['homepage'];
			$base['source_code'] = $def['homepage'] ? array( $def['homepage'] ) : array();
			$base['files']       = array(
				'model'  => array( 'path' => 'model.onnx', 'sources' => array( $def['model_url'] ), 'sha256' => $def['sha256'], 'estimate' => 60 * 1000000 ),
				'config' => array( 'path' => 'model.onnx.json', 'type' => 'application/json', 'sources' => array( $def['config_url'] ), 'estimate' => 5000 ),
			);
			return $base;
		}
		return null;
	}

	// ------------------------------------------------------------------ Piper library index

	/**
	 * The Piper voice index (voices.json), cached for a day.
	 *
	 * @return array|WP_Error key => voice entry
	 */
	public static function library_index() {
		$cached = get_transient( self::INDEX_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$url = (string) apply_filters( 'ltts_piper_index_url', 'https://huggingface.co/' . LTTS_Catalog::PIPER_VOICES_REPO . '/resolve/' . LTTS_Catalog::PIPER_VOICES_REV . '/voices.json' );
		$res = wp_safe_remote_get( $url, array( 'timeout' => 30, 'user-agent' => 'Local-TTS/' . LTTS_VERSION . ' (WordPress)' ) );
		$data = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			/* translators: %s: error detail */
			return new WP_Error( 'ltts_index', sprintf( __( 'Could not load the Piper voice list: %s', 'local-tts' ), is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ), array( 'status' => 502 ) );
		}
		// Keep only what the admin needs; the full index is about 1 MB.
		$index = array();
		foreach ( $data as $key => $v ) {
			if ( ! is_array( $v ) || empty( $v['files'] ) || ! preg_match( '/^[a-z]{2,3}_[A-Za-z]{2,3}-[\w.]+-(x_low|low|medium|high)$/', (string) $key ) ) {
				continue;
			}
			$onnx = '';
			$size = 0;
			foreach ( $v['files'] as $path => $f ) {
				if ( '.onnx' === substr( $path, -5 ) ) {
					$onnx = substr( $path, 0, -5 );
					$size = isset( $f['size_bytes'] ) ? (int) $f['size_bytes'] : 0;
				}
			}
			if ( ! $onnx ) {
				continue;
			}
			$lang          = isset( $v['language'] ) ? $v['language'] : array();
			$index[ $key ] = array(
				'key'      => $key,
				'hf'       => $onnx,
				'name'     => isset( $v['name'] ) ? (string) $v['name'] : $key,
				'quality'  => isset( $v['quality'] ) ? (string) $v['quality'] : '',
				'family'   => isset( $lang['family'] ) ? strtolower( (string) $lang['family'] ) : substr( $key, 0, 2 ),
				'locale'   => isset( $lang['code'] ) ? (string) $lang['code'] : '',
				'language' => isset( $lang['name_english'] ) ? (string) $lang['name_english'] : '',
				'native'   => isset( $lang['name_native'] ) ? (string) $lang['name_native'] : '',
				'country'  => isset( $lang['country_english'] ) ? (string) $lang['country_english'] : '',
				'speakers' => isset( $v['num_speakers'] ) ? (int) $v['num_speakers'] : 1,
				'bytes'    => $size,
			);
		}
		ksort( $index );
		set_transient( self::INDEX_KEY, $index, DAY_IN_SECONDS );
		return $index;
	}

	// ------------------------------------------------------------------ REST

	public static function rest_library() {
		$index = self::library_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		$added = array();
		foreach ( self::all() as $id => $def ) {
			if ( 'library' === $def['kind'] ) {
				$added[ $def['key'] ] = $id;
			}
		}
		$builtin = array();
		foreach ( LTTS_Catalog::builtin() as $id => $item ) {
			if ( ! empty( $item['files']['model']['hf'] ) ) {
				$builtin[ basename( $item['files']['model']['hf'], '.onnx' ) ] = $id;
			}
		}
		$out = array();
		foreach ( $index as $key => $v ) {
			$v['added'] = isset( $added[ $key ] ) || isset( $builtin[ $key ] );
			$out[]      = $v;
		}
		return rest_ensure_response( array( 'voices' => $out ) );
	}

	public static function rest_add( WP_REST_Request $req ) {
		$kind = sanitize_key( (string) $req['kind'] );
		$res  = 'url' === $kind ? self::add_url( $req->get_params() ) : self::add_library( (string) $req['key'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'id' => $res ) );
	}

	public static function rest_delete( WP_REST_Request $req ) {
		$res = self::delete( $req['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	// ------------------------------------------------------------------ add / delete

	/**
	 * Adds a voice from the Piper library by its key (e.g. "en_GB-alan-medium").
	 *
	 * @return string|WP_Error New component ID.
	 */
	public static function add_library( $key ) {
		$index = self::library_index();
		if ( is_wp_error( $index ) ) {
			return $index;
		}
		if ( ! isset( $index[ $key ] ) ) {
			return new WP_Error( 'ltts_voice', __( 'This voice is not in the Piper library.', 'local-tts' ), array( 'status' => 400 ) );
		}
		$v  = $index[ $key ];
		$id = self::PREFIX . sanitize_key( str_replace( '_', '-', strtolower( $key ) ) );

		$label = $v['language'] . ( $v['country'] ? ', ' . $v['country'] : '' );
		$def   = array(
			'kind'        => 'library',
			'key'         => $key,
			'hf'          => $v['hf'],
			'language'    => LTTS_Plugin::sanitize_lang( $v['family'] ),
			'language_name' => $v['native'] ? $v['native'] : $v['language'],
			/* translators: 1: voice name, 2: language and country, 3: quality (low, medium, high) */
			'name'        => sprintf( __( 'Piper %1$s (%2$s, %3$s)', 'local-tts' ), ucfirst( $v['name'] ), $label, $v['quality'] ),
			'license'     => __( 'See the model card', 'local-tts' ),
			'credit'      => 'Piper ' . ucfirst( $v['name'] ) . ' (rhasspy/piper-voices, see MODEL_CARD)',
			'description' => ( $v['speakers'] > 1
				/* translators: %d: number of speakers */
				? sprintf( __( 'Added from the Piper library. %d speakers; the first one is used.', 'local-tts' ), $v['speakers'] )
				: __( 'Added from the Piper library.', 'local-tts' ) )
				. ' ' . __( 'Check the model card for the dataset licence before serving it.', 'local-tts' ),
			'bytes'       => $v['bytes'],
		);
		return self::save( $id, $def );
	}

	/**
	 * Adds a Piper-compatible voice from two HTTPS URLs.
	 *
	 * @param array $p name, language, model_url, config_url, sha256?, license?, credit?, homepage?
	 * @return string|WP_Error New component ID.
	 */
	public static function add_url( array $p ) {
		$name   = sanitize_text_field( isset( $p['name'] ) ? $p['name'] : '' );
		$lang   = LTTS_Plugin::sanitize_lang( isset( $p['language'] ) ? $p['language'] : '' );
		$model  = esc_url_raw( isset( $p['model_url'] ) ? trim( $p['model_url'] ) : '', array( 'https' ) );
		$config = esc_url_raw( isset( $p['config_url'] ) ? trim( $p['config_url'] ) : '', array( 'https' ) );
		$sha    = strtolower( preg_replace( '/[^a-f0-9]/i', '', isset( $p['sha256'] ) ? (string) $p['sha256'] : '' ) );

		if ( '' === $name || '' === $lang || ! $model || ! $config ) {
			return new WP_Error( 'ltts_voice', __( 'Enter a name, a language code and two HTTPS addresses.', 'local-tts' ), array( 'status' => 400 ) );
		}
		if ( '' !== $sha && 64 !== strlen( $sha ) ) {
			return new WP_Error( 'ltts_voice', __( 'The SHA-256 must be 64 hexadecimal characters.', 'local-tts' ), array( 'status' => 400 ) );
		}
		$license = sanitize_text_field( isset( $p['license'] ) ? $p['license'] : '' );
		$def     = array(
			'kind'          => 'url',
			'language'      => $lang,
			'language_name' => '',
			'name'          => $name,
			'model_url'     => $model,
			'config_url'    => $config,
			'sha256'        => $sha,
			'license'       => '' !== $license ? $license : __( 'Not specified', 'local-tts' ),
			'credit'        => sanitize_text_field( isset( $p['credit'] ) && '' !== $p['credit'] ? $p['credit'] : $name ),
			'homepage'      => esc_url_raw( isset( $p['homepage'] ) ? (string) $p['homepage'] : '', array( 'https', 'http' ) ),
			'description'   => '' !== $sha
				? __( 'Added from addresses. The model file is checked against the SHA-256 you entered.', 'local-tts' )
				: __( 'Added from addresses. No SHA-256 was given, so the file is trusted as first downloaded.', 'local-tts' ),
			'bytes'         => 0,
		);
		$id = self::PREFIX . sanitize_key( $lang . '-' . sanitize_title( $name ) );
		return self::save( $id, $def );
	}

	private static function save( $id, array $def ) {
		$all = self::all();
		if ( isset( $all[ $id ] ) || LTTS_Catalog::builtin_has( $id ) ) {
			return new WP_Error( 'ltts_voice', __( 'This voice is already in the list.', 'local-tts' ), array( 'status' => 409 ) );
		}
		$all[ $id ] = $def;
		update_option( self::OPTION, $all, false );
		LTTS_Catalog::flush();
		return $id;
	}

	/**
	 * Removes an added voice from the list, with its files.
	 *
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$all = self::all();
		if ( ! isset( $all[ $id ] ) ) {
			return new WP_Error( 'ltts_voice', __( 'Only voices you added can be removed from the list.', 'local-tts' ), array( 'status' => 400 ) );
		}
		LTTS_Storage::remove( $id ); // files and "served" setting
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		LTTS_Catalog::flush();
		return true;
	}

	/** Native or English name stored for a language code by an added voice, if any. */
	public static function language_name( $code ) {
		foreach ( self::all() as $def ) {
			if ( $def['language'] === $code && ! empty( $def['language_name'] ) ) {
				return $def['language_name'];
			}
		}
		return '';
	}
}
