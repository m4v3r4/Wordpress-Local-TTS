<?php
/**
 * Minimal WordPress stand-ins for running the plugin's PHP classes without WordPress.
 *
 * Options, transients, filters and actions live in $GLOBALS. HTTP requests go through cURL
 * (wp_safe_remote_get is not "safe" here, so tests can use 127.0.0.1), or are answered from
 * $GLOBALS['fake_http'][ $url ] when a test sets one.
 *
 * Environment:
 *   LTTS_TEST_TMP     scratch folder (uploads/ and fixtures/ go there)
 *   LTTS_TEST_SERVER  base URL of tests/server.py, e.g. http://127.0.0.1:8899
 *
 * @package Local_TTS
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );
set_error_handler(
	function ( $no, $msg, $file, $line ) {
		if ( ! ( error_reporting() & $no ) ) {
			return false; // silenced with @
		}
		throw new ErrorException( $msg, 0, $no, $file, $line );
	}
);

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'LTTS_TEST_ROOT', dirname( __DIR__, 2 ) );
define( 'LTTS_TEST_TMP', rtrim( getenv( 'LTTS_TEST_TMP' ) ?: sys_get_temp_dir() . '/ltts-test', '/' ) );
define( 'LTTS_TEST_SERVER', rtrim( getenv( 'LTTS_TEST_SERVER' ) ?: 'http://127.0.0.1:8899', '/' ) );

$GLOBALS['opts']       = array();
$GLOBALS['site_opts']  = array();
$GLOBALS['transients'] = array();
$GLOBALS['filters']    = array();
$GLOBALS['actions']    = array();
$GLOBALS['meta']       = array();
$GLOBALS['posts']      = array();
$GLOBALS['can']        = true;
$GLOBALS['requests']   = array();
$GLOBALS['fake_http']  = array();

class WP_Error {
	public $code;
	public $msg;
	public $data;
	public function __construct( $code = '', $msg = '', $data = null ) {
		$this->code = $code;
		$this->msg  = $msg;
		$this->data = $data;
	}
	public function get_error_message() {
		return $this->msg;
	}
	public function get_error_code() {
		return $this->code;
	}
}

class WP_REST_Request implements ArrayAccess {
	private $p;
	public function __construct( $p = array() ) {
		$this->p = $p;
	}
	public function get_params() {
		return $this->p;
	}
	#[\ReturnTypeWillChange]
	public function offsetGet( $k ) {
		return isset( $this->p[ $k ] ) ? $this->p[ $k ] : null;
	}
	#[\ReturnTypeWillChange]
	public function offsetExists( $k ) {
		return isset( $this->p[ $k ] );
	}
	#[\ReturnTypeWillChange]
	public function offsetSet( $k, $v ) {}
	#[\ReturnTypeWillChange]
	public function offsetUnset( $k ) {}
}

function is_wp_error( $x ) { return $x instanceof WP_Error; }

// ------------------------------------------------------------------ hooks
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['actions'][ $h ][] = $cb; return true; }
function remove_action( $h, $cb ) {
	foreach ( $GLOBALS['actions'][ $h ] ?? array() as $i => $c ) {
		if ( $c === $cb ) { unset( $GLOBALS['actions'][ $h ][ $i ] ); }
	}
}
function do_action( $h, ...$a ) { foreach ( $GLOBALS['actions'][ $h ] ?? array() as $cb ) { $cb( ...$a ); } }
function did_action( $h ) { return 0; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['filters'][ $h ][] = $cb; return true; }
function remove_all_filters( $h ) { unset( $GLOBALS['filters'][ $h ] ); }
function apply_filters( $h, $v, ...$a ) { foreach ( $GLOBALS['filters'][ $h ] ?? array() as $cb ) { $v = $cb( $v, ...$a ); } return $v; }
function has_filter( $h ) { return ! empty( $GLOBALS['filters'][ $h ] ); }
function add_shortcode() {}
function register_activation_hook() {}
function register_rest_route() {}
function register_setting() {}
function register_post_meta() {}
function register_meta() {}

// ------------------------------------------------------------------ options and transients
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function get_site_transient( $k ) { return $GLOBALS['transients'][ 'site:' . $k ] ?? false; }
function set_site_transient( $k, $v, $e = 0 ) { $GLOBALS['transients'][ 'site:' . $k ] = $v; return true; }
function delete_site_transient( $k ) { unset( $GLOBALS['transients'][ 'site:' . $k ] ); return true; }

// ------------------------------------------------------------------ i18n, escaping, sanitising
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_attr__( $s, $d = null ) { return $s; }
function _e( $s, $d = null ) { echo $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? htmlspecialchars( $u, ENT_QUOTES ) : ''; }
function esc_url_raw( $u, $protocols = null ) {
	$u = trim( (string) $u );
	$s = strtolower( (string) parse_url( $u, PHP_URL_SCHEME ) );
	if ( '' === $u || ! filter_var( $u, FILTER_VALIDATE_URL ) ) { return ''; }
	return ( $protocols && ! in_array( $s, $protocols, true ) ) ? '' : $u;
}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return preg_replace( '/\[[^\]]+\]/', '', $s ); }
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function add_query_arg( ...$a ) {
	if ( is_array( $a[0] ) ) {
		list( $q, $u ) = array( $a[0], $a[1] ?? '' );
	} else {
		list( $q, $u ) = array( array( $a[0] => $a[1] ), $a[2] ?? '' );
	}
	return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $q );
}
function set_url_scheme( $u ) { return $u; }

// ------------------------------------------------------------------ environment
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function current_user_can() { return $GLOBALS['can']; }
function get_locale() { return $GLOBALS['locale'] ?? 'en_US'; }
function determine_locale() { return get_locale(); }
function switch_to_locale( $l ) { return false; }
function restore_previous_locale() { return false; }
function load_textdomain() { return true; }
function load_plugin_textdomain() { return true; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/local-tts/'; }
function plugin_basename( $f ) { return 'local-tts/local-tts.php'; }
function is_plugin_active( $p ) { return ! empty( $GLOBALS['legacy_active'] ); }
function deactivate_plugins( $p ) { $GLOBALS['legacy_active'] = false; $GLOBALS['deactivated'][] = $p; }
function wp_upload_dir() {
	return array( 'error' => false, 'basedir' => LTTS_TEST_TMP . '/uploads', 'baseurl' => 'https://example.com/wp-content/uploads' );
}
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function wp_is_writable( $d ) { return is_writable( $d ); }
function rest_ensure_response( $x ) { return $x; }
function get_post_types() {
	return array(
		'post' => (object) array( 'labels' => (object) array( 'singular_name' => 'Post' ) ),
		'page' => (object) array( 'labels' => (object) array( 'singular_name' => 'Page' ) ),
	);
}
function get_post( $id ) { return isset( $GLOBALS['posts'][ $id ] ) ? (object) $GLOBALS['posts'][ $id ] : null; }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function post_password_required( $p ) { return ! empty( $p->post_password ); }
function is_object_in_taxonomy( $t, $tax ) { return 'post' === $t; }
function has_term( $ids, $tax, $p ) { return (bool) array_intersect( $ids, $GLOBALS['terms'][ $p->ID ] ?? array() ); }
function get_the_title( $id ) { return $GLOBALS['posts'][ $id ]['post_title'] ?? ''; }

// ------------------------------------------------------------------ HTTP
function wp_remote_get( $url, $args = array() ) {
	if ( isset( $GLOBALS['fake_http'][ $url ] ) ) {
		$GLOBALS['requests'][] = $url . ' (fake)';
		return $GLOBALS['fake_http'][ $url ];
	}
	$stream = ! empty( $args['filename'] );
	$file   = $stream ? $args['filename'] : tempnam( sys_get_temp_dir(), 'ltts' );
	$hdrs   = array();
	foreach ( $args['headers'] ?? array() as $k => $v ) {
		$hdrs[] = "$k: $v";
	}
	$ch  = curl_init( $url );
	$fh  = fopen( $file, 'wb' );
	$res = array();
	curl_setopt( $ch, CURLOPT_ENCODING, '' ); // like WordPress: accept compressed responses
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_FILE           => $fh,
			CURLOPT_HTTPHEADER     => $hdrs,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_TIMEOUT        => $args['timeout'] ?? 10,
			CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$res ) {
				if ( strpos( $line, ':' ) ) {
					list( $k, $v )               = explode( ':', $line, 2 );
					$res[ strtolower( trim( $k ) ) ] = trim( $v );
				}
				return strlen( $line );
			},
		)
	);
	do_action( 'http_api_curl', $ch, $args, $url );
	$ok   = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	fclose( $fh );
	$GLOBALS['requests'][] = $url . ' ' . ( $args['headers']['Range'] ?? '' ) . ' -> ' . $code;
	if ( ! $ok ) {
		return new WP_Error( 'http_request_failed', curl_error( $ch ) );
	}
	$r = array( 'response' => array( 'code' => $code ), 'headers' => $res );
	if ( ! $stream ) {
		$r['body'] = file_get_contents( $file );
		unlink( $file );
	}
	return $r;
}
function wp_safe_remote_get( $url, $args = array() ) { return wp_remote_get( $url, $args ); }
function wp_remote_head( $url, $args = array() ) { return wp_remote_get( $url, $args ); }
function wp_safe_remote_head( $url, $args = array() ) { return wp_remote_get( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_header( $r, $k ) { return $r['headers'][ strtolower( $k ) ] ?? ''; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

/** A fake JSON response for $GLOBALS['fake_http']. */
function ltts_test_json( $data, $code = 200 ) {
	return array( 'response' => array( 'code' => $code ), 'headers' => array(), 'body' => json_encode( $data ) );
}

// ------------------------------------------------------------------ test helpers
$GLOBALS['ltts_failed'] = 0;
$GLOBALS['ltts_passed'] = 0;

function check( $label, $cond, $detail = '' ) {
	if ( $cond ) {
		$GLOBALS['ltts_passed']++;
		echo "  ok   $label\n";
	} else {
		$GLOBALS['ltts_failed']++;
		echo "  FAIL $label" . ( '' !== $detail ? " ($detail)" : '' ) . "\n";
	}
}

function done() {
	printf( "%d passed, %d failed\n", $GLOBALS['ltts_passed'], $GLOBALS['ltts_failed'] );
	exit( $GLOBALS['ltts_failed'] ? 1 : 0 );
}

function rrmdir( $d ) {
	if ( ! is_dir( $d ) ) { return; }
	foreach ( scandir( $d ) as $f ) {
		if ( '.' !== $f && '..' !== $f ) {
			is_dir( "$d/$f" ) ? rrmdir( "$d/$f" ) : unlink( "$d/$f" );
		}
	}
	rmdir( $d );
}

/** Fresh uploads folder and options for each test file. */
function reset_site() {
	rrmdir( LTTS_TEST_TMP . '/uploads' );
	mkdir( LTTS_TEST_TMP . '/uploads', 0777, true );
	$GLOBALS['opts']       = array();
	$GLOBALS['transients'] = array();
	if ( class_exists( 'LTTS_Catalog' ) ) {
		LTTS_Catalog::flush();
	}
}

@mkdir( LTTS_TEST_TMP, 0777, true );
reset_site();
require LTTS_TEST_ROOT . '/local-tts/local-tts.php';
