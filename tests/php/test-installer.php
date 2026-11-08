<?php
/**
 * Installer and storage: chunked and resumable downloads, source fallback, digests, manifests,
 * credits, requirements and removal.
 *
 * @package Local_TTS
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

echo "Installer\n";

check( 'nothing served at first', array() === LTTS_Storage::served_languages() );
check( 'Piper voice needs runtime and phonemizer', array( 'onnxruntime-web', 'piper-phonemize' ) === LTTS_Catalog::requires( LTTS_Catalog::get( 'piper-en-us-ljspeech' ) ) );

// Runtime: first source is dead, the second serves ranges.
$job = install( 'onnxruntime-web' );
check( 'runtime installs from the second source', job_ok( $job ), job_error( $job ) );
check( 'runtime ready', LTTS_Storage::runtime_ready() );

// Model with a corrupted first source: the digest check moves on to the next source.
set_mode( 'bad' );
$job = install( 'ema-lightning-tr' );
check( 'model with only corrupted sources fails', is_array( $job ) && 'error' === $job['status'] );
LTTS_Installer::cancel();
set_mode( 'range' );
$GLOBALS['requests'] = array();
$job = install( 'ema-lightning-tr' );
check( 'model installs', job_ok( $job ), job_error( $job ) );
$ranged = count( array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r, '-> 206' ); } ) );
check( 'large file downloaded in 4 MB chunks', $ranged >= 3, "$ranged ranged requests" );

$item = LTTS_Catalog::get( 'ema-lightning-tr' );
$dir  = LTTS_Storage::dir( $item );
check( 'files match their digests', hash_file( 'sha256', $dir . 'sound.onnx' ) === $item['files']['sound']['sha256'] );
check( 'no partial files left', ! glob( $dir . '*.part*' ) );

$manifest = json_decode( file_get_contents( $dir . 'manifest.json' ), true );
check( 'manifest names the engine and runtime', 'ema-lightning' === $manifest['engine'] && LTTS_ORT_VERSION === $manifest['runtime']['version'] );
$doc_keys = array_keys( array_filter( $item['files'], function ( $f ) { return ! empty( $f['doc'] ); } ) );
check( 'licence files are not sent to browsers', $doc_keys && ! array_intersect( $doc_keys, array_keys( $manifest['files'] ) ) );
check( 'licence files are kept on the server', is_file( $dir . 'LICENSE.txt' ) );
$credits = (string) @file_get_contents( $dir . 'CREDITS.txt' );
check( 'CREDITS.txt names licence and credit', false !== strpos( $credits, $item['license'] ) && false !== strpos( $credits, $item['credit'] ) );
check( 'first voice of a language is served', array( 'tr' => 'ema-lightning-tr' ) === LTTS_Settings::get( 'models' ) );
check( 'uploads folder protected', is_file( LTTS_Storage::base_dir() . '.htaccess' ) );

// No Range support + resume of an existing .part file.
set_mode( 'norange' );
$lite  = LTTS_Catalog::get( 'ema-lightning-tr-lite' );
$ldir  = LTTS_Storage::dir( $lite );
$sound = LTTS_TEST_SRV . '/files/ema-lightning-tr-lite/sound.onnx';
wp_mkdir_p( $ldir );
$job = install( 'ema-lightning-tr-lite' );
check( 'server without Range support works', job_ok( $job ) && hash_file( 'sha256', $ldir . 'sound.onnx' ) === hash_file( 'sha256', $sound ), job_error( $job ) );
check( 'second voice of a language does not replace the first', 'ema-lightning-tr' === LTTS_Settings::get( 'models' )['tr'] );
LTTS_Storage::remove( 'ema-lightning-tr-lite' );

set_mode( 'range' );
wp_mkdir_p( $ldir );
file_put_contents( $ldir . 'sound.onnx.part', substr( file_get_contents( $sound ), 0, 5 * 1048576 ) );
$GLOBALS['requests'] = array();
$job = install( 'ema-lightning-tr-lite' );
$resumed = (bool) array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r, 'bytes=5242880-' ); } );
check( 'partial download resumes where it stopped', job_ok( $job ) && $resumed, job_error( $job ) );
LTTS_Storage::remove( 'ema-lightning-tr-lite' );

// A CDN that compresses partial responses: the installer falls back to one whole request.
set_mode( 'gzforce' );
$job = install( 'ema-lightning-tr-lite' );
check( 'compressed ranges fall back to a whole-file request', job_ok( $job ) && hash_file( 'sha256', $ldir . 'sound.onnx' ) === hash_file( 'sha256', $sound ), job_error( $job ) );
set_mode( 'range' );

// Piper: digests come from the (fake) Hugging Face API.
$job = install( 'piper-en-us-ljspeech' );
check( 'Piper voice installs with digests from Hugging Face', job_ok( $job ), job_error( $job ) );
check( 'voice is not served without its requirements', ! LTTS_Storage::is_ready( 'piper-en-us-ljspeech' ) && ! in_array( 'en', LTTS_Storage::served_languages(), true ) );
$job = install( 'piper-phonemize' );
check( 'phonemizer installs', job_ok( $job ), job_error( $job ) );
check( 'voice served once requirements are installed', in_array( 'en', LTTS_Storage::served_languages(), true ) );

$pitem = LTTS_Catalog::get( 'piper-en-us-ljspeech' );
$pdir  = LTTS_Storage::dir( $pitem );
$pman  = json_decode( file_get_contents( $pdir . 'manifest.json' ), true );
check( 'manifest links the shared phonemizer', isset( $pman['files']['phonemizer_wasm'] ) && is_file( $pdir . $pman['files']['phonemizer_wasm']['path'] ) );
check( 'model card kept but not in the manifest', is_file( $pdir . 'MODEL_CARD.txt' ) && ! isset( $pman['files']['card'] ) );

file_put_contents( $pdir . 'model.onnx.json', '{"edited":true}' );
check( 'verify catches an edited file (git blob SHA-1)', is_wp_error( LTTS_Installer::verify_on_disk( 'piper-en-us-ljspeech' ) ) );

LTTS_Storage::remove( 'piper-phonemize' );
check( 'removing the phonemizer stops serving Piper voices', ! in_array( 'en', LTTS_Storage::served_languages(), true ) );

add_filter( 'ltts_hf_tree_url', function () { return LTTS_TEST_SERVER . '/dead/api'; }, 20 );
LTTS_Storage::remove( 'piper-en-us-ljspeech' );
$job = LTTS_Installer::start( 'piper-en-us-ljspeech' );
check( 'Hugging Face API down gives a clear error', is_wp_error( $job ) && false !== strpos( $job->get_error_message(), 'Hugging Face' ) );

LTTS_Storage::purge();
check( 'purge removes all files', ! is_dir( LTTS_Storage::base_dir() . 'models' ) );

done();
