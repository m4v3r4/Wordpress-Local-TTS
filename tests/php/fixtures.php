<?php
/**
 * Points every catalog download at tests/server.py and creates random fixture files for it.
 *
 * Plain files get pinned sizes and SHA-256 digests, like the real catalog. Files hosted on
 * Hugging Face (Piper voices) keep no digest, so the installer asks the fake Hugging Face API.
 * Each file has two sources: a dead one (503) and one served in $GLOBALS['mode'] ("range" by
 * default), so every install also exercises the fallback to the next source.
 *
 * @package Local_TTS
 */

define( 'LTTS_TEST_SRV', LTTS_TEST_TMP . '/srv' );
$GLOBALS['mode'] = 'range';

/** Creates a fixture file once; big files test chunked downloads (4 MB chunks). */
function fixture( $rel, $bytes ) {
	$path = LTTS_TEST_SRV . '/' . $rel;
	if ( ! is_file( $path ) ) {
		wp_mkdir_p( dirname( $path ) );
		if ( '.json' === substr( $rel, -5 ) ) {
			$data = json_encode( array( 'audio' => array( 'sample_rate' => 22050 ), 'espeak' => array( 'voice' => 'en-us' ), 'pad' => str_repeat( 'x', $bytes ) ) );
		} elseif ( preg_match( '/(LICENSE|COPYING|NOTICE|MODEL_CARD|NOTICES)/', $rel ) ) {
			$data = "Licence text for $rel\n" . str_repeat( "lorem ipsum\n", 20 );
		} else {
			$data = random_bytes( $bytes );
		}
		file_put_contents( $path, $data );
	}
	return $path;
}

/** Changes the server mode of the second source (range, norange, bad, gzforce). */
function set_mode( $mode ) {
	$GLOBALS['mode'] = $mode;
	LTTS_Catalog::flush();
}

add_filter(
	'ltts_catalog',
	function ( $items ) {
		foreach ( $items as $id => &$item ) {
			foreach ( $item['files'] as $key => &$f ) {
				if ( ! empty( $f['hf'] ) ) {
					$size = '.onnx' === substr( $f['hf'], -5 ) ? 5 * 1048576 + 123 : 300;
					fixture( 'hf/' . $f['hf'], $size );
					$f['sources'] = array( LTTS_TEST_SERVER . '/dead/hf/' . $f['hf'], LTTS_TEST_SERVER . '/range/hf/' . $f['hf'] );
					unset( $f['sha256'], $f['bytes'] );
					continue;
				}
				if ( 0 === strpos( $id, 'custom-' ) ) {
					continue; // voices added from addresses point at the test server themselves
				}
				$rel  = 'files/' . $id . '/' . $f['path'];
				$size = ( 'sound' === $key ) ? 9 * 1048576 + 777 : 40000 + strlen( $rel );
				$path = fixture( $rel, $size );
				$f['bytes']   = filesize( $path );
				$f['sha256']  = hash_file( 'sha256', $path );
				$f['sources'] = array( LTTS_TEST_SERVER . '/dead/' . $rel, LTTS_TEST_SERVER . '/' . $GLOBALS['mode'] . '/' . $rel );
			}
			unset( $f );
		}
		return $items;
	}
);

add_filter(
	'ltts_hf_tree_url',
	function ( $url, $dir ) {
		return LTTS_TEST_SERVER . '/hfapi/' . $dir;
	},
	10,
	2
);

/** Runs an install job to the end (or 300 steps). */
function install( $id ) {
	$job = LTTS_Installer::start( $id );
	$n   = 0;
	while ( is_array( $job ) && 'running' === $job['status'] && $n++ < 300 ) {
		$job = LTTS_Installer::step( $job );
	}
	return $job;
}

function job_ok( $job ) {
	return is_array( $job ) && 'done' === $job['status'];
}

function job_error( $job ) {
	return is_wp_error( $job ) ? $job->get_error_message() : ( is_array( $job ) ? $job['error'] : '' );
}

LTTS_Catalog::flush();
