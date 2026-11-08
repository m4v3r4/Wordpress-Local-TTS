<?php
/**
 * Languages: names, locales, the admin interface language setting, and player strings in the
 * language of the post being read.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

class LTTS_I18n {

	/** Language code => locale used for translations. */
	const LOCALES = array(
		'en' => 'en_US',
		'tr' => 'tr_TR',
		'de' => 'de_DE',
		'fr' => 'fr_FR',
		'es' => 'es_ES',
	);

	/** Native names, shown in the admin language selector (not translated on purpose). */
	const NATIVE = array(
		'en_US' => 'English',
		'tr_TR' => 'Türkçe',
		'de_DE' => 'Deutsch',
		'fr_FR' => 'Français',
		'es_ES' => 'Español',
	);

	public static function init() {
		add_filter( 'plugin_locale', array( __CLASS__, 'plugin_locale' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 1 );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'local-tts', false, dirname( plugin_basename( LTTS_FILE ) ) . '/languages' );
	}

	/** Admin screens use the language chosen in the plugin settings, if any. */
	public static function plugin_locale( $locale, $domain ) {
		if ( 'local-tts' !== $domain || ! is_admin() || ! class_exists( 'LTTS_Settings' ) ) {
			return $locale;
		}
		$chosen = (string) LTTS_Settings::get( 'admin_locale' );
		return isset( self::NATIVE[ $chosen ] ) ? $chosen : $locale;
	}

	/** Translated language name for a language code. */
	public static function language_name( $code ) {
		$names = array(
			'en' => __( 'English', 'local-tts' ),
			'tr' => __( 'Turkish', 'local-tts' ),
			'de' => __( 'German', 'local-tts' ),
			'fr' => __( 'French', 'local-tts' ),
			'es' => __( 'Spanish', 'local-tts' ),
		);
		if ( isset( $names[ $code ] ) ) {
			return $names[ $code ];
		}
		$custom = class_exists( 'LTTS_Voices' ) ? LTTS_Voices::language_name( $code ) : '';
		return '' !== $custom ? $custom : strtoupper( $code );
	}

	/** Language codes that have at least one voice in the catalog. */
	public static function catalog_languages() {
		$langs = array();
		foreach ( LTTS_Catalog::all() as $item ) {
			if ( 'model' === $item['type'] && LTTS_Catalog::installable( $item ) ) {
				$langs[ $item['language'] ] = true;
			}
		}
		return array_keys( $langs );
	}

	/**
	 * Runs $callback with the plugin's translations in the given language (for the player, which
	 * speaks the language of the post rather than the site's).
	 */
	public static function in_language( $lang, $callback ) {
		$locale = isset( self::LOCALES[ $lang ] ) ? self::LOCALES[ $lang ] : '';
		if ( ! $locale || ! function_exists( 'switch_to_locale' ) || determine_locale() === $locale ) {
			return call_user_func( $callback );
		}
		$switched = switch_to_locale( $locale );
		if ( $switched ) {
			// Make sure our domain is reloaded for the new locale.
			unload_textdomain( 'local-tts' );
			self::load_textdomain();
		}
		$out = call_user_func( $callback );
		if ( $switched ) {
			restore_previous_locale();
			unload_textdomain( 'local-tts' );
			self::load_textdomain();
		}
		return $out;
	}
}
