<?php
/**
 * Front end: display rules, player placement, scripts and the player configuration.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides where the player appears and hands the browser everything it needs.
 */
class LTTS_Plugin {

	const SHORTCODE = 'local_tts';

	/** Shortcode name used before the rename (0.1 – 0.3); still works. */
	const LEGACY_SHORTCODE = 'yaziyi_dinle';

	/** @var array<int,bool> Posts whose content already got the markers (prevents duplicates). */
	private static $rendered = array();

	/** @var array<int,bool> Per-request cache of is_eligible() results. */
	private static $eligible = array();

	/** @var bool Whether the shortcode already printed a player on this request. */
	private static $shortcode_used = false;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_shortcode( self::LEGACY_SHORTCODE, array( __CLASS__, 'shortcode' ) );
	}

	/** Per-post options, editable in the classic box and through the REST API. */
	public static function register_meta() {
		$auth = static function ( $allowed, $meta_key, $post_id ) {
			return current_user_can( 'edit_post', $post_id );
		};
		foreach ( (array) LTTS_Settings::get( 'post_types' ) as $type ) {
			register_post_meta( $type, '_ltts_mode', array( 'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true, 'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ), 'auth_callback' => $auth ) );
			register_post_meta( $type, '_ltts_lang', array( 'type' => 'string', 'single' => true, 'default' => '', 'show_in_rest' => true, 'sanitize_callback' => array( __CLASS__, 'sanitize_lang' ), 'auth_callback' => $auth ) );
		}
	}

	/** '' (follow site settings), 'on' or 'off'. */
	public static function sanitize_mode( $value ) {
		return in_array( $value, array( 'on', 'off' ), true ) ? $value : '';
	}

	/** Two- or three-letter language code; region subtags are dropped ("pt-BR" -> "pt"). */
	public static function sanitize_lang( $value ) {
		$parts = preg_split( '/[-_]/', strtolower( trim( (string) $value ) ) );
		$code  = isset( $parts[0] ) ? $parts[0] : '';
		return preg_match( '/^[a-z]{2,3}$/', $code ) ? $code : '';
	}

	/** Languages the player can read: those with a served, ready voice. */
	public static function supported_langs() {
		return array_values( (array) apply_filters( 'ltts_supported_languages', LTTS_Storage::served_languages() ) );
	}

	/**
	 * Language of a post: the post's own setting, then Polylang or WPML, then the site language.
	 *
	 * @param int $post_id Post ID.
	 * @return string Two-letter language code.
	 */
	public static function post_language( $post_id ) {
		$lang = (string) get_post_meta( $post_id, '_ltts_lang', true );

		if ( '' === $lang && function_exists( 'pll_get_post_language' ) ) {
			$lang = (string) pll_get_post_language( $post_id, 'slug' );
		}
		if ( '' === $lang && has_filter( 'wpml_post_language_details' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				$lang = (string) $details['language_code'];
			}
		}
		if ( '' === $lang ) {
			$lang = (string) get_locale();
		}

		$lang = substr( self::sanitize_lang( $lang ), 0, 2 );

		/**
		 * Filters the language the player uses for a post.
		 *
		 * @param string $lang    Two-letter language code.
		 * @param int    $post_id Post ID.
		 */
		return (string) apply_filters( 'ltts_post_language', $lang, $post_id );
	}

	/**
	 * Should this post get a player? Applies the rules from Local TTS → Display.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function is_eligible( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$eligible[ $post_id ] ) ) {
			return self::$eligible[ $post_id ];
		}

		$s    = LTTS_Settings::all();
		$post = get_post( $post_id );
		$ok   = $s['enabled'] && $post && 'publish' === $post->post_status
			&& in_array( $post->post_type, (array) $s['post_types'], true )
			&& ! post_password_required( $post );

		if ( $ok && 'editors' === $s['audience'] ) {
			$ok = current_user_can( 'edit_posts' );
		}

		if ( $ok ) {
			$mode   = (string) get_post_meta( $post_id, '_ltts_mode', true );
			$forced = 'on' === $mode;
			$ok     = 'off' !== $mode && ( 'all' === $s['scope'] || $forced );

			// "Show" in the post box overrides the category and length rules.
			if ( $ok && ! $forced && 'all' !== $s['terms_mode'] && $s['terms'] && is_object_in_taxonomy( $post->post_type, 'category' ) ) {
				$in = has_term( array_map( 'intval', $s['terms'] ), 'category', $post );
				$ok = 'include' === $s['terms_mode'] ? $in : ! $in;
			}
			if ( $ok && ! $forced && $s['min_words'] > 0 ) {
				$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ), -1, PREG_SPLIT_NO_EMPTY );
				$ok    = count( $words ) >= (int) $s['min_words'];
			}
		}

		// Nothing to play without the runtime and at least one served voice.
		if ( $ok ) {
			$ok = LTTS_Storage::runtime_ready() && (bool) LTTS_Storage::served_languages();
		}

		/**
		 * Filters whether a post gets a player (return false for paywalled content, for example).
		 *
		 * @param bool $ok      Result of the plugin's own rules.
		 * @param int  $post_id Post ID.
		 */
		self::$eligible[ $post_id ] = (bool) apply_filters( 'ltts_should_render', $ok, $post_id );
		return self::$eligible[ $post_id ];
	}

	/** Are we rendering the main query's single post of an enabled type? */
	private static function is_main_single() {
		return ! is_admin() && ! is_feed() && ! wp_doing_ajax()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& is_singular( (array) LTTS_Settings::get( 'post_types' ) );
	}

	/**
	 * Adds the player and two invisible markers around the post body.
	 *
	 * The text to read lies between the markers. The content is not wrapped in an element, so
	 * block-theme layout rules (".is-layout-constrained > *") keep working.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( ! self::is_main_single() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		// Automatic excerpts also run the_content; keep the player's text out of them.
		if ( doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id || (int) get_queried_object_id() !== (int) $post_id ) {
			return $content;
		}
		if ( isset( self::$rendered[ $post_id ] ) || false !== strpos( $content, 'data-ltts-content-start' ) ) {
			return $content;
		}
		if ( ! self::is_eligible( $post_id ) ) {
			return $content;
		}
		self::$rendered[ $post_id ] = true;

		$start  = '<span data-ltts-content-start hidden></span>';
		$end    = '<span data-ltts-content-end hidden></span>';
		$has_sc = false !== strpos( $content, 'data-ltts-player' ); // the shortcode already ran (priority 11)
		$place  = LTTS_Settings::get( 'placement' );
		$player = ( $has_sc || 'manual' === $place ) ? '' : self::player_markup();

		if ( 'after' === $place ) {
			return $start . $content . $end . $player;
		}
		return $player . $start . $content . $end;
	}

	/** [local_tts] (or the legacy [yaziyi_dinle]): puts the player where the shortcode is. */
	public static function shortcode() {
		if ( self::$shortcode_used || ! self::is_main_single() || ! in_the_loop() ) {
			return '';
		}
		$post_id = get_the_ID();
		if ( ! $post_id || (int) get_queried_object_id() !== (int) $post_id || ! self::is_eligible( $post_id ) ) {
			return '';
		}
		self::$shortcode_used = true;
		return self::player_markup();
	}

	/** Empty container; player.js builds the interface. It stays hidden without JavaScript. */
	public static function player_markup() {
		$html = '<div class="ltts-player" data-ltts-player data-ltts-skip></div>';

		/**
		 * Filters the player container's HTML. Keep the data-ltts-player attribute.
		 *
		 * @param string $html Markup.
		 */
		return (string) apply_filters( 'ltts_player_markup', $html );
	}

	public static function enqueue() {
		if ( ! self::is_main_single() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( ! $post_id || ! self::is_eligible( $post_id ) ) {
			return;
		}

		wp_enqueue_style( 'ltts-player', LTTS_URL . 'assets/css/player.css', array(), LTTS_VERSION );
		wp_enqueue_script( 'ltts-player', LTTS_URL . 'assets/js/player.js', array(), LTTS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		// JSON_HEX_* keep "</script>" and quotes in post titles from breaking out of the script tag.
		$json = wp_json_encode(
			self::config( $post_id ),
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		wp_add_inline_script( 'ltts-player', 'window.lttsPlayerConfig = ' . $json . ';', 'before' );
	}

	/**
	 * Configuration passed to player.js as window.lttsPlayerConfig.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function config( $post_id ) {
		$s     = LTTS_Settings::all();
		$lang  = self::post_language( $post_id );
		$model = LTTS_Storage::active_model( $lang );
		$item  = $model ? LTTS_Catalog::get( $model ) : null;
		$ort   = LTTS_Storage::runtime_url();

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Never read: code, embeds, navigation, footnotes, share boxes and the player itself.
		$exclude = array(
			'pre', '.wp-block-code', '.wp-block-preformatted', 'script', 'style', 'noscript', 'template',
			'svg', 'math', 'iframe', 'button', 'form', 'nav', 'figure.wp-block-embed', 'sup.fn',
			'.wp-block-footnotes', '.screen-reader-text', '.sharedaddy', '.jp-relatedposts',
			'[data-ltts-skip]', '.ltts-skip', '.ltts-player',
		);
		if ( '' !== trim( $s['exclude'] ) ) {
			$exclude = array_merge( $exclude, array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $s['exclude'] ) ) ) );
		}

		/**
		 * Filters the CSS selectors that are never read aloud.
		 *
		 * @param string[] $exclude Selectors.
		 * @param int      $post_id Post ID.
		 */
		$exclude = (array) apply_filters( 'ltts_exclude_selectors', $exclude, $post_id );

		$config = array(
			'postId'           => $post_id,
			'lang'             => $lang,
			'supportedLangs'   => self::supported_langs(),
			'title'            => $title,
			'workerUrl'        => add_query_arg( 'ver', LTTS_VERSION, LTTS_URL . 'assets/js/tts-worker.js' ),
			'manifestUrl'      => $model ? LTTS_Storage::manifest_url( $model ) : '',
			'ortModuleUrl'     => $ort . 'ort.wasm.min.mjs',
			'ortWasmBase'      => $ort,
			'modelId'          => $model,
			'modelVersion'     => $item ? $item['version'] : '',
			'downloadBytes'    => $model && $s['show_hint'] ? LTTS_Storage::download_bytes( $model ) : 0,
			'excludeSelectors' => array_values( array_unique( array_map( 'strval', $exclude ) ) ),

			/**
			 * Filters a CSS selector for the element to read, instead of the plugin's markers.
			 *
			 * @param string $selector Empty by default.
			 * @param int    $post_id  Post ID.
			 */
			'contentSelector'  => (string) apply_filters( 'ltts_content_selector', '', $post_id ),
			'speed'            => (float) $s['speed'],
			'prefetch'         => 1,
			'maxSegmentChars'  => 280,
			'minSegmentChars'  => 24,
			'highlight'        => (bool) $s['highlight'],
			'credit'           => $item && $s['show_credit'] && ! empty( $item['credit'] ) ? (string) $item['credit'] : '',
			'creditUrl'        => $item && $s['show_credit'] && ! empty( $item['homepage'] ) ? (string) $item['homepage'] : '',
			'i18n'             => LTTS_I18n::in_language( $lang, array( __CLASS__, 'strings' ) ),
		);

		/**
		 * Filters the whole player configuration.
		 *
		 * @param array $config  Configuration.
		 * @param int   $post_id Post ID.
		 */
		return (array) apply_filters( 'ltts_player_config', $config, $post_id );
	}

	/** Player strings, translated by the caller into the post's language. */
	public static function strings() {
		return array(
			'listen'       => __( 'Listen', 'local-tts' ),
			'preparing'    => __( 'Preparing', 'local-tts' ),
			'reading'      => __( 'Reading with a synthetic voice', 'local-tts' ),
			'paused'       => __( 'Paused', 'local-tts' ),
			'waiting'      => __( 'Preparing audio', 'local-tts' ),
			'failed'       => __( 'Couldn’t play', 'local-tts' ),
			'finished'     => __( 'End of the post', 'local-tts' ),
			'loadingModel' => __( 'Preparing the voice…', 'local-tts' ),
			/* translators: {loaded} and {total} are megabytes. */
			'downloading'  => __( 'Downloading the voice: {loaded} / {total} MB', 'local-tts' ),
			/* translators: {size} is megabytes. */
			'downloadHint' => __( 'The first listen downloads about {size} MB. Audio is generated on your device.', 'local-tts' ),
			'mapHint'      => __( 'Tap anywhere on the strip to listen from there.', 'local-tts' ),
			/* translators: {n} is a number. */
			'minutes'      => __( '{n} min', 'local-tts' ),
			/* translators: {n} is a number. */
			'seconds'      => __( '{n} s', 'local-tts' ),
			/* translators: {t} is a duration, e.g. "8 min". */
			'left'         => __( '{t} left', 'local-tts' ),
			'play'         => __( 'Play', 'local-tts' ),
			'pause'        => __( 'Pause', 'local-tts' ),
			'resume'       => __( 'Resume', 'local-tts' ),
			'stop'         => __( 'Stop', 'local-tts' ),
			'retry'        => __( 'Try again', 'local-tts' ),
			/* translators: {v} is a speed, e.g. "1.2×". */
			'speed'        => __( 'Reading speed: {v}', 'local-tts' ),
			'jump'         => __( 'Go to the current paragraph', 'local-tts' ),
			'position'     => __( 'Position in the post', 'local-tts' ),
			/* translators: {n} and {total} are part numbers. */
			'part'         => __( 'Part {n} of {total}', 'local-tts' ),
			/* translators: {credit} is the voice's attribution, e.g. "Piper SIWIS, … (CC BY 4.0)". */
			'credit'       => __( 'Voice: {credit}', 'local-tts' ),
			'errors'       => array(
				'network'      => __( 'The voice could not be downloaded. Check your connection and try again.', 'local-tts' ),
				'integrity'    => __( 'A downloaded voice file could not be verified. Try again.', 'local-tts' ),
				'incompatible' => __( 'The voice could not be opened in this browser. Reload the page and try again.', 'local-tts' ),
				'unsupported'  => __( 'Your browser does not support on-device speech.', 'local-tts' ),
				'synthesis'    => __( 'Something went wrong while generating audio. Try again.', 'local-tts' ),
				'lang'         => __( 'No voice is available for this post’s language yet.', 'local-tts' ),
				'empty'        => __( 'This post has no text to read.', 'local-tts' ),
				'quota'        => __( 'Not enough storage to keep the voice; it may download again next time.', 'local-tts' ),
			),
		);
	}
}
