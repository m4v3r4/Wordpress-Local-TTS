<?php
/**
 * Voices added by the site owner: the Piper library index, addresses, install and removal.
 *
 * @package Local_TTS
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

echo "Voices\n";

$index_url = 'https://voices.example/voices.json';
add_filter( 'ltts_piper_index_url', function () use ( $index_url ) { return $index_url; } );

$voice = function ( $family, $code, $name, $quality, $speakers = 1 ) {
	$dir = "$family/$code/$name/$quality";
	return array(
		'key'          => "$code-$name-$quality",
		'name'         => $name,
		'quality'      => $quality,
		'num_speakers' => $speakers,
		'language'     => array( 'code' => $code, 'family' => $family, 'name_english' => 'Lang ' . $family, 'name_native' => 'Native ' . $family, 'country_english' => 'Country' ),
		'files'        => array(
			"$dir/$code-$name-$quality.onnx"      => array( 'size_bytes' => 5243003 ),
			"$dir/$code-$name-$quality.onnx.json" => array( 'size_bytes' => 300 ),
			"$dir/MODEL_CARD"                     => array( 'size_bytes' => 300 ),
		),
	);
};
$GLOBALS['fake_http'][ $index_url ] = ltts_test_json(
	array(
		'en_GB-alan-medium'  => $voice( 'en', 'en_GB', 'alan', 'medium' ),
		'nl_BE-nathalie-x_low' => $voice( 'nl', 'nl_BE', 'nathalie', 'x_low', 2 ),
		'en_US-ljspeech-medium' => $voice( 'en', 'en_US', 'ljspeech', 'medium' ),
		'not a voice'        => array( 'files' => array() ),
	)
);

$index = LTTS_Voices::library_index();
check( 'library index parsed and filtered', is_array( $index ) && array( 'en_GB-alan-medium', 'en_US-ljspeech-medium', 'nl_BE-nathalie-x_low' ) === array_keys( $index ) );
check( 'library index cached', false !== get_transient( LTTS_Voices::INDEX_KEY ) );

$lib = LTTS_Voices::rest_library();
$added = array();
foreach ( $lib['voices'] as $v ) {
	$added[ $v['key'] ] = $v['added'];
}
check( 'built-in presets show as already added', true === $added['en_US-ljspeech-medium'] && false === $added['en_GB-alan-medium'] );

$id = LTTS_Voices::add_library( 'en_GB-alan-medium' );
check( 'library voice added', 'custom-en-gb-alan-medium' === $id, is_wp_error( $id ) ? $id->get_error_message() : (string) $id );
$item = LTTS_Catalog::get( $id );
check( 'added voice appears in the catalog', $item && 'piper' === $item['engine'] && 'en' === $item['language'] && ! empty( $item['custom'] ) );
check( 'added voice has a credit and a model card file', ! empty( $item['credit'] ) && ! empty( $item['files']['card']['doc'] ) );
check( 'adding twice is refused', is_wp_error( LTTS_Voices::add_library( 'en_GB-alan-medium' ) ) );
check( 'unknown library key is refused', is_wp_error( LTTS_Voices::add_library( 'xx_XX-nobody-low' ) ) );

foreach ( array( 'onnxruntime-web', 'piper-phonemize', $id ) as $c ) {
	$job = install( $c );
	check( "$c installs", job_ok( $job ), job_error( $job ) );
}
check( 'added voice is served', 'custom-en-gb-alan-medium' === LTTS_Storage::active_model( 'en' ) );

// Voices from addresses.
$bad = LTTS_Voices::add_url( array( 'name' => 'X', 'language' => 'en', 'model_url' => 'http://insecure.example/a.onnx', 'config_url' => 'https://voices.example/a.onnx.json' ) );
check( 'plain HTTP addresses are refused', is_wp_error( $bad ) );
$bad = LTTS_Voices::add_url( array( 'name' => 'X', 'language' => 'en', 'model_url' => 'https://voices.example/a.onnx', 'config_url' => 'https://voices.example/a.onnx.json', 'sha256' => 'abc' ) );
check( 'short SHA-256 is refused', is_wp_error( $bad ) );
$bad = LTTS_Voices::add_url( array( 'name' => '', 'language' => 'en', 'model_url' => 'https://voices.example/a.onnx', 'config_url' => 'https://voices.example/a.onnx.json' ) );
check( 'name is required', is_wp_error( $bad ) );

$model = fixture( 'url/fil.onnx', 1048576 + 5 );
fixture( 'url/fil.onnx.json', 200 );
add_filter(
	'ltts_catalog',
	function ( $items ) {
		foreach ( $items as $cid => &$it ) {
			if ( 0 === strpos( $cid, 'custom-' ) ) {
				foreach ( $it['files'] as &$f ) {
					$f['sources'] = str_replace( 'https://voices.example/', LTTS_TEST_SERVER . '/range/url/', $f['sources'] );
				}
			}
		}
		return $items;
	}
);
$uid = LTTS_Voices::add_url(
	array(
		'name'       => 'Tagalog <b>Test</b>',
		'language'   => 'fil',
		'model_url'  => 'https://voices.example/fil.onnx',
		'config_url' => 'https://voices.example/fil.onnx.json',
		'sha256'     => strtoupper( hash_file( 'sha256', $model ) ),
		'license'    => 'CC BY 4.0',
		'credit'     => 'Test voice by Someone',
		'homepage'   => 'https://voices.example/',
	)
);
check( 'voice from addresses added', is_string( $uid ) && 0 === strpos( $uid, 'custom-fil-' ), is_wp_error( $uid ) ? $uid->get_error_message() : '' );
$uitem = LTTS_Catalog::get( $uid );
check( 'three-letter language code kept', 'fil' === $uitem['language'] );
check( 'name is sanitised', false === strpos( $uitem['name'], '<' ) );
check( 'SHA-256 normalised to lower case', hash_file( 'sha256', $model ) === $uitem['files']['model']['sha256'] );
$job = install( $uid );
check( 'voice from addresses installs and is verified', job_ok( $job ), job_error( $job ) );
check( 'voice from addresses is served', $uid === LTTS_Storage::active_model( 'fil' ) );

$nosha = LTTS_Voices::add_url( array( 'name' => 'No digest', 'language' => 'fil', 'model_url' => 'https://voices.example/fil.onnx', 'config_url' => 'https://voices.example/fil.onnx.json' ) );
$job   = install( $nosha );
check( 'voice without SHA-256 installs (size from the server)', job_ok( $job ), job_error( $job ) );
check( 'its digest is recorded at install', hash_file( 'sha256', $model ) === LTTS_Storage::installed()[ $nosha ]['files']['model']['sha256'] );

// Removal.
$dir = LTTS_Storage::dir( $uitem );
check( 'built-in voices cannot be deleted', is_wp_error( LTTS_Voices::delete( 'piper-en-us-ljspeech' ) ) );
check( 'added voice deleted', true === LTTS_Voices::delete( $uid ) );
check( 'its files are gone', ! is_dir( $dir ) );
check( 'it is no longer in the catalog', null === LTTS_Catalog::get( $uid ) );
check( 'the language falls back to the other voice', $nosha === LTTS_Storage::active_model( 'fil' ) );

LTTS_Storage::purge();
done();
