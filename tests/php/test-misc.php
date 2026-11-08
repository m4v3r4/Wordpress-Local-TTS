<?php
/**
 * Settings, language codes, GitHub updates and migration from the previous plugin.
 *
 * @package Local_TTS
 */

require __DIR__ . '/bootstrap.php';

echo "Settings\n";

check( 'language from locale', 'pt' === LTTS_Plugin::sanitize_lang( 'pt_BR' ) && 'fil' === LTTS_Plugin::sanitize_lang( 'fil-PH' ) );
check( 'invalid language rejected', '' === LTTS_Plugin::sanitize_lang( 'english' ) && '' === LTTS_Plugin::sanitize_lang( '<x>' ) );

$s = LTTS_Settings::sanitize(
	array(
		'_tab'       => 'display',
		'post_types' => array( 'post', 'attachment', 'nope' ),
		'scope'      => 'weird',
		'placement'  => 'after',
		'min_words'  => '-50',
		'terms'      => array( '3', 'x', '0' ),
	)
);
check( 'only public post types kept', array( 'post' ) === $s['post_types'] );
check( 'unknown values fall back to defaults', 'all' === $s['scope'] && 'after' === $s['placement'] );
check( 'numbers coerced', 50 === $s['min_words'] && array( 3 ) === $s['terms'] );
update_option( LTTS_Settings::OPTION, $s );

$s = LTTS_Settings::sanitize( array( '_tab' => 'reading', 'speed' => '1,25', 'exclude' => "<b>.ads</b>\n.share" ) );
check( 'decimal comma accepted', 1.25 === $s['speed'] );
check( 'other tabs kept', array( 'post' ) === $s['post_types'] && 'after' === $s['placement'] );
check( 'unticked boxes saved as off', 0 === $s['highlight'] && 0 === $s['show_credit'] );
check( 'tags stripped from selectors', ".ads\n.share" === $s['exclude'] );
$s = LTTS_Settings::sanitize( array( '_tab' => 'reading', 'speed' => '9' ) );
check( 'out-of-range speed reset', 1.0 === $s['speed'] );
$s = LTTS_Settings::sanitize( array( '_tab' => 'models', 'models' => array( 'tr' => 'ema-lightning-tr' ) ) );
check( 'a voice that is not installed cannot be served', array() === $s['models'] );

echo "Updates from GitHub\n";

$release = array(
	'tag_name'     => 'v9.1.0',
	'html_url'     => 'https://github.com/m4v3r4/Wordpress-Local-TTS/releases/tag/v9.1.0',
	'published_at' => '2026-01-01T00:00:00Z',
	'body'         => "## Changes\n- Faster **downloads** <script>alert(1)</script>\n- See [docs](https://example.com/docs)\n\nRequires PHP: 8.0\nTested up to: 6.9",
	'assets'       => array(
		array( 'name' => 'source.tar.gz', 'browser_download_url' => 'https://github.com/x/y/archive.tar.gz' ),
		array( 'name' => 'local-tts-9.1.0.zip', 'browser_download_url' => 'https://github.com/m4v3r4/Wordpress-Local-TTS/releases/download/v9.1.0/local-tts-9.1.0.zip' ),
	),
);
$r = LTTS_Updater::parse( $release );
check( 'release parsed', $r && '9.1.0' === $r['version'] && false !== strpos( $r['package'], 'local-tts-9.1.0.zip' ) );
check( 'requirements read from release notes', '8.0' === $r['requires_php'] && '6.9' === $r['tested'] );
check( 'pre-releases ignored', null === LTTS_Updater::parse( array( 'prerelease' => true ) + $release ) );
check( 'release without the plugin ZIP ignored', null === LTTS_Updater::parse( array( 'assets' => array() ) + $release ) );
$html = LTTS_Updater::to_html( $release['body'] );
check( 'release notes escaped', false === strpos( $html, '<script>' ) && false !== strpos( $html, '<strong>downloads</strong>' ) && false !== strpos( $html, '<a href="https://example.com/docs">docs</a>' ) );

$api = 'https://api.github.com/repos/m4v3r4/Wordpress-Local-TTS/releases/latest';
$GLOBALS['fake_http'][ $api ] = ltts_test_json( $release );
$plugin_data = array( 'UpdateURI' => 'https://github.com/m4v3r4/Wordpress-Local-TTS', 'Version' => LTTS_VERSION );
$update      = LTTS_Updater::check( false, $plugin_data, 'local-tts/local-tts.php' );
check( 'newer release offered', is_array( $update ) && '9.1.0' === $update['version'] && 'local-tts' === $update['slug'] );
check( 'other plugins untouched', false === LTTS_Updater::check( false, $plugin_data, 'other/other.php' ) );
$count = count( $GLOBALS['requests'] );
LTTS_Updater::check( false, $plugin_data, 'local-tts/local-tts.php' );
check( 'release cached between checks', count( $GLOBALS['requests'] ) === $count );

LTTS_Updater::flush();
$GLOBALS['fake_http'][ $api ] = ltts_test_json( array( 'tag_name' => 'v' . LTTS_VERSION ) + $release );
check( 'same version not offered', false === LTTS_Updater::check( false, $plugin_data, 'local-tts/local-tts.php' ) );

LTTS_Updater::flush();
$GLOBALS['fake_http'][ $api ] = ltts_test_json( array( 'message' => 'rate limited' ), 403 );
check( 'API errors are quiet', false === LTTS_Updater::check( false, $plugin_data, 'local-tts/local-tts.php' ) );
check( 'failures cached too', array() === get_site_transient( LTTS_Updater::TRANSIENT ) );

LTTS_Updater::flush();
$GLOBALS['fake_http'][ $api ] = ltts_test_json( $release );
$info = LTTS_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'local-tts' ) );
check( 'details dialog filled', is_object( $info ) && '9.1.0' === $info->version && false !== strpos( $info->sections['changelog'], '<li>' ) && '' !== $info->sections['description'] );
check( 'other details untouched', false === LTTS_Updater::info( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );

echo "Migration from asosyoloji-dinle\n";

$GLOBALS['opts'] = array(
	'asd_settings'  => array( 'enabled' => 1, 'speed' => 1.2 ),
	'asd_installed' => array( 'onnxruntime-web' => array( 'version' => LTTS_ORT_VERSION, 'files' => array(), 'bytes' => 1 ) ),
);
$old = LTTS_TEST_TMP . '/uploads/asosyoloji-dinle/models/x/1.0.0';
wp_mkdir_p( $old );
file_put_contents( $old . '/manifest.json', '{}' );
$GLOBALS['legacy_active'] = true;
$GLOBALS['wpdb']          = new class() {
	public $postmeta = 'wp_postmeta';
	public $q        = array();
	public function prepare( $sql, ...$a ) {
		return vsprintf( str_replace( '%s', "'%s'", $sql ), $a );
	}
	public function query( $sql ) {
		$this->q[] = $sql;
		return 1;
	}
};
LTTS_Migration::run();
check( 'settings moved', 1.2 === LTTS_Settings::get( 'speed' ) && null === get_option( 'asd_settings', null ) );
check( 'install records moved', isset( LTTS_Storage::installed()['onnxruntime-web'] ) );
check( 'uploads folder renamed', is_file( LTTS_TEST_TMP . '/uploads/local-tts/models/x/1.0.0/manifest.json' ) && ! is_dir( LTTS_TEST_TMP . '/uploads/asosyoloji-dinle' ) );
check( 'post meta renamed', 2 === count( $GLOBALS['wpdb']->q ) && false !== strpos( $GLOBALS['wpdb']->q[0], "'_ltts_mode' WHERE meta_key = '_asd_mode'" ) );
check( 'old plugin deactivated', array( 'asosyoloji-dinle/asosyoloji-dinle.php' ) === $GLOBALS['deactivated'] );
check( 'runs once', 1 === get_option( LTTS_Migration::DONE ) );

done();
