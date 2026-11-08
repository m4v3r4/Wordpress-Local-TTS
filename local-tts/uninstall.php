<?php
/**
 * Removes settings, post options and downloaded files (wp-content/uploads/local-tts/) when the
 * plugin is deleted. Voices cached in readers' browsers belong to those browsers.
 *
 * @package Local_TTS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/local-tts.php';

LTTS_Storage::purge();
delete_option( 'ltts_settings' );
delete_option( 'ltts_job' );
delete_option( 'ltts_installed' );
delete_option( 'ltts_migrated_from_asd' );
delete_post_meta_by_key( '_ltts_mode' );
delete_post_meta_by_key( '_ltts_lang' );
delete_option( 'ltts_custom_voices' );
delete_transient( 'ltts_piper_index' );
delete_site_transient( 'ltts_update_release' );
