<?php
/**
 * Renders every admin page with WordPress stand-ins: catches PHP errors and unescaped output.
 *
 * @package Local_TTS
 */

require __DIR__ . '/bootstrap.php';

function wp_unslash( $v ) { return $v; }
function esc_textarea( $s ) { return esc_html( $s ); }
function esc_html_e( $s, $d = null ) { echo esc_html( $s ); }
function esc_attr_e( $s, $d = null ) { echo esc_attr( $s ); }
function checked( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' checked="checked"' : ''; if ( $echo ) { echo $r; } return $r; }
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected="selected"' : ''; if ( $echo ) { echo $r; } return $r; }
function disabled( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' disabled="disabled"' : ''; if ( $echo ) { echo $r; } return $r; }
function settings_fields() {}
function settings_errors() {}
function submit_button() { echo '<input type="submit">'; }
function get_categories() { return array( (object) array( 'term_id' => 3, 'name' => 'News <b>', 'count' => 4 ) ); }
function number_format_i18n( $n, $d = 0 ) { return number_format( $n, $d ); }
function size_format( $n ) { return round( $n / 1048576, 1 ) . ' MB'; }
function admin_url( $p = '' ) { return '/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function rest_url( $p = '' ) { return 'https://example.com/wp-json/' . $p; }
function wp_create_nonce() { return 'nonce'; }
function is_ssl() { return true; }
function get_admin_page_title() { return 'Local TTS'; }
function wp_get_current_user() { return (object) array( 'locale' => '' ); }
function get_user_locale() { return 'en_US'; }
function is_multisite() { return false; }
function is_network_admin() { return false; }
function wp_enqueue_script() {}
function wp_enqueue_style() {}
function wp_localize_script() {}
function wp_add_inline_script() {}
function get_bloginfo() { return '6.8'; }

require_once LTTS_TEST_ROOT . '/local-tts/includes/class-ltts-admin.php';

echo "Admin pages\n";

$GLOBALS['opts']['ltts_custom_voices'] = array(
	'custom-en-test' => array( 'kind' => 'url', 'language' => 'en', 'language_name' => '', 'name' => 'Test <script>x</script>', 'model_url' => 'https://voices.example/a.onnx', 'config_url' => 'https://voices.example/a.onnx.json', 'sha256' => '', 'license' => 'CC BY 4.0', 'credit' => 'Someone', 'homepage' => '', 'description' => 'd', 'bytes' => 0 ),
);
LTTS_Catalog::flush();

foreach ( LTTS_Admin::pages() as $tab => $slug ) {
	$_GET['page'] = $slug;
	$error        = '';
	ob_start();
	try {
		LTTS_Admin::render_page();
	} catch ( Throwable $e ) {
		$error = $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
	}
	$html = ob_get_clean();
	check( "$tab page renders", '' === $error && strlen( $html ) > 500, $error );
	check( "$tab page has no raw markup from data", false === strpos( $html, '<script>x' ) && false === strpos( $html, 'News <b>' ) );
	if ( 'models' === $tab ) {
		check( 'voices page offers adding voices', false !== strpos( $html, 'data-ltts-library-load' ) && false !== strpos( $html, 'data-ltts-url-add' ) );
		check( 'added voice can be removed from the list', false !== strpos( $html, 'data-ltts-delete-voice="custom-en-test"' ) );
		check( 'install buttons carry a licence notice', false !== strpos( $html, 'data-ltts-license=' ) );
	}
}

done();
