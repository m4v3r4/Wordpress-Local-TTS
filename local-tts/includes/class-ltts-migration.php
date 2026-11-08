<?php
/**
 * Migration from the earlier "asosyoloji-dinle" / "Kulak Ver" plugin (0.1 – 0.3).
 *
 * Settings, post meta and downloaded models are moved to the new names, so that deleting the old
 * plugin (whose uninstall routine removes its own data) cannot remove anything this plugin uses.
 * The old plugin is deactivated to avoid two players on the same page.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

class LTTS_Migration {

	const DONE   = 'ltts_migrated_from_asd';
	const LEGACY = 'asosyoloji-dinle/asosyoloji-dinle.php';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 1 );
	}

	public static function maybe_migrate() {
		if ( get_option( self::DONE ) ) {
			return;
		}
		self::run();
	}

	public static function run() {
		global $wpdb;
		$moved = false;

		foreach ( array( 'asd_settings' => 'ltts_settings', 'asd_installed' => 'ltts_installed' ) as $old => $new ) {
			$value = get_option( $old, null );
			if ( null !== $value && false === get_option( $new, false ) ) {
				update_option( $new, $value, 'ltts_installed' === $new ? false : null );
				delete_option( $old );
				$moved = true;
			}
		}
		delete_option( 'asd_job' );
		delete_transient( 'asd_manifest_info' );

		// Post meta: rename the keys (the old uninstall routine deletes _asd_* keys).
		foreach ( array( '_asd_mode' => '_ltts_mode', '_asd_lang' => '_ltts_lang' ) as $old => $new ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s", $new, $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$moved = $moved || $n > 0;
		}

		// Downloaded files: uploads/asosyoloji-dinle -> uploads/local-tts
		$u = wp_upload_dir( null, false );
		if ( empty( $u['error'] ) ) {
			$old_dir = trailingslashit( $u['basedir'] ) . 'asosyoloji-dinle';
			$new_dir = trailingslashit( $u['basedir'] ) . LTTS_Storage::FOLDER;
			if ( is_dir( $old_dir ) && ! is_dir( $new_dir ) ) {
				if ( @rename( $old_dir, $new_dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					LTTS_Storage::ensure_base(); // .htaccess with the new name
					// Manifests contain only relative paths; nothing else to rewrite.
					$moved = true;
				}
			}
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( self::LEGACY ) ) {
			deactivate_plugins( self::LEGACY, true );
			$moved = true;
		}

		update_option( self::DONE, 1 );
		if ( $moved ) {
			set_transient( 'ltts_migrated_notice', 1, DAY_IN_SECONDS );
		}
	}
}
