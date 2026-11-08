<?php
/**
 * Plugin settings, stored in one option (ltts_settings) and merged with defaults on read.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read, update and sanitise settings.
 */
class LTTS_Settings {

	const OPTION = 'ltts_settings';

	public static function defaults() {
		return array(
			// Display.
			'enabled'      => 1,
			'post_types'   => array( 'post' ),
			'scope'        => 'all',      // all | opt_in
			'audience'     => 'everyone', // everyone | editors
			'placement'    => 'before',   // before | after | manual
			'terms_mode'   => 'all',      // all | include | exclude
			'terms'        => array(),    // category term IDs
			'min_words'    => 0,
			'admin_locale' => '',         // '' = the user's WordPress language
			// Reading.
			'speed'        => 1.0,
			'highlight'    => 1,
			'show_hint'    => 1,
			'show_credit'  => 1,
			'exclude'      => '',         // extra CSS selectors, one per line
			// Voices: language code => served component ID.
			'models'       => array(),
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Merges and saves values set by the plugin itself (not form input). */
	public static function update( array $values ) {
		$all = self::normalize( array_merge( self::all(), $values ) );
		update_option( self::OPTION, $all );
		return $all;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * Each admin page posts only its own fields plus a hidden "_tab" field; the other pages'
	 * values are kept. Input without "_tab" (a programmatic update_option(), or WordPress
	 * sanitising a value twice when the option is first created) is normalised as a whole.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$current = self::all();
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$tab = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		if ( '' === $tab ) {
			return self::normalize( array_merge( $current, array_intersect_key( $input, self::defaults() ) ) );
		}
		$out = $current;

		if ( 'display' === $tab ) {
			$out['enabled']      = empty( $input['enabled'] ) ? 0 : 1;
			$out['post_types']   = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
			$out['scope']        = isset( $input['scope'] ) ? $input['scope'] : '';
			$out['audience']     = isset( $input['audience'] ) ? $input['audience'] : '';
			$out['placement']    = isset( $input['placement'] ) ? $input['placement'] : '';
			$out['terms_mode']   = isset( $input['terms_mode'] ) ? $input['terms_mode'] : '';
			$out['terms']        = isset( $input['terms'] ) ? (array) $input['terms'] : array();
			$out['min_words']    = isset( $input['min_words'] ) ? $input['min_words'] : 0;
			$out['admin_locale'] = isset( $input['admin_locale'] ) ? $input['admin_locale'] : '';
		}

		if ( 'reading' === $tab ) {
			$out['speed']     = isset( $input['speed'] ) ? str_replace( ',', '.', (string) $input['speed'] ) : 1.0;
			$out['highlight'] = empty( $input['highlight'] ) ? 0 : 1;
			$out['show_hint']   = empty( $input['show_hint'] ) ? 0 : 1;
			$out['show_credit'] = empty( $input['show_credit'] ) ? 0 : 1;
			$out['exclude']   = isset( $input['exclude'] ) ? (string) $input['exclude'] : '';
		}

		if ( 'models' === $tab ) {
			$out['models'] = isset( $input['models'] ) ? (array) $input['models'] : array();
		}

		return self::normalize( $out );
	}

	/**
	 * Coerces every known key to its type and allowed values.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function normalize( array $s ) {
		$d   = self::defaults();
		$out = array_intersect_key( array_merge( $d, $s ), $d );

		$out['enabled']    = empty( $out['enabled'] ) ? 0 : 1;
		$out['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', (array) $out['post_types'] ), array_keys( self::public_post_types() ) ) );
		$out['scope']      = 'opt_in' === $out['scope'] ? 'opt_in' : 'all';
		$out['audience']   = 'editors' === $out['audience'] ? 'editors' : 'everyone';
		$out['placement']  = in_array( $out['placement'], array( 'before', 'after', 'manual' ), true ) ? $out['placement'] : 'before';
		$out['terms_mode'] = in_array( $out['terms_mode'], array( 'all', 'include', 'exclude' ), true ) ? $out['terms_mode'] : 'all';
		$out['terms']      = array_values( array_filter( array_map( 'absint', (array) $out['terms'] ) ) );
		$out['min_words']  = min( 100000, absint( $out['min_words'] ) );

		$locale              = (string) $out['admin_locale'];
		$out['admin_locale'] = isset( LTTS_I18n::NATIVE[ $locale ] ) ? $locale : '';

		$speed        = (float) $out['speed'];
		$out['speed'] = $speed >= 0.7 && $speed <= 1.5 ? round( $speed, 2 ) : 1.0;

		$out['highlight'] = empty( $out['highlight'] ) ? 0 : 1;
		$out['show_hint']   = empty( $out['show_hint'] ) ? 0 : 1;
		$out['show_credit'] = empty( $out['show_credit'] ) ? 0 : 1;
		$out['exclude']   = trim( wp_strip_all_tags( (string) $out['exclude'] ) );

		// A language may only serve an installed voice of that language.
		$models = array();
		foreach ( (array) $out['models'] as $lang => $id ) {
			$lang = LTTS_Plugin::sanitize_lang( $lang );
			$id   = sanitize_key( (string) $id );
			$item = $id ? LTTS_Catalog::get( $id ) : null;
			if ( $lang && $item && 'model' === $item['type'] && $item['language'] === $lang && LTTS_Storage::is_installed( $id ) ) {
				$models[ $lang ] = $id;
			}
		}
		$out['models'] = $models;

		return $out;
	}

	/** Public post types, without attachments: name => singular label. */
	public static function public_post_types() {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		$out = array();
		foreach ( $types as $name => $obj ) {
			$out[ $name ] = $obj->labels->singular_name;
		}
		return $out;
	}
}
