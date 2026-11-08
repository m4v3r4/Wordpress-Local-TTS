<?php
/**
 * Downloads components from inside WordPress.
 *
 * To stay within the request time limits of shared hosting, downloads run in steps: the admin
 * page calls /local-tts/v1/step repeatedly, and each call fetches 4 MB HTTP Range chunks for a few
 * seconds and saves its position. An interrupted download resumes where it stopped. Every file is
 * checked against its size and SHA-256 (or git blob SHA-1 for small Hugging Face files).
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST endpoints and the resumable download job.
 */
class LTTS_Installer {

	const JOB_OPTION = 'ltts_job';
	const CHUNK      = 4194304; // 4 MB
	const STALE      = 600;     // a job without a step for 10 minutes counts as abandoned

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$admin = static function () {
			return current_user_can( 'manage_options' );
		};
		$component = array(
			'component' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
		);
		register_rest_route( 'local-tts/v1', '/status', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_status' ), 'permission_callback' => $admin ) );
		register_rest_route( 'local-tts/v1', '/install', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_install' ), 'permission_callback' => $admin, 'args' => $component ) );
		register_rest_route( 'local-tts/v1', '/step', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_step' ), 'permission_callback' => $admin ) );
		register_rest_route( 'local-tts/v1', '/cancel', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_cancel' ), 'permission_callback' => $admin ) );
		register_rest_route( 'local-tts/v1', '/remove', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => $admin, 'args' => $component ) );
		register_rest_route( 'local-tts/v1', '/verify', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_verify' ), 'permission_callback' => $admin, 'args' => $component ) );
		register_rest_route( 'local-tts/v1', '/check-server', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_check_server' ), 'permission_callback' => $admin ) );
	}

	// ------------------------------------------------------------------ REST

	public static function rest_status() {
		return rest_ensure_response( array( 'job' => self::summary( self::job() ) ) );
	}

	public static function rest_install( WP_REST_Request $req ) {
		$res = self::start( $req['component'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'job' => self::summary( $res ) ) );
	}

	public static function rest_step() {
		$job = self::job();
		if ( ! $job ) {
			return new WP_Error( 'ltts_no_job', __( 'No installation is running.', 'local-tts' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'job' => self::summary( self::step( $job ) ) ) );
	}

	public static function rest_cancel() {
		self::cancel();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function rest_remove( WP_REST_Request $req ) {
		$id   = $req['component'];
		$item = LTTS_Catalog::get( $id );
		if ( ! $item ) {
			return new WP_Error( 'ltts_unknown', __( 'Unknown component.', 'local-tts' ), array( 'status' => 404 ) );
		}
		$job = self::job();
		if ( $job && $job['component'] === $id ) {
			self::cancel();
		}
		LTTS_Storage::remove( $id );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Checks files uploaded by FTP and records the component as installed if they pass. */
	public static function rest_verify( WP_REST_Request $req ) {
		$res = self::verify_on_disk( $req['component'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	/** Checks outgoing connections and the MIME types the server sends for .wasm and .mjs. */
	public static function rest_check_server() {
		$out  = array();
		$rt   = LTTS_Catalog::get( LTTS_Catalog::RUNTIME_ID );
		$urls = array();
		if ( LTTS_Storage::runtime_ready() ) {
			$urls['wasm'] = LTTS_Storage::url( $rt ) . $rt['files']['wasm']['path'];
			$urls['mjs']  = LTTS_Storage::url( $rt ) . $rt['files']['module']['path'];
		}
		foreach ( $urls as $key => $url ) {
			// The site's own URL: wp_safe_remote_* would refuse it on staging hosts with private IPs.
			$r           = wp_remote_head( $url, array( 'timeout' => 10, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
			$out[ $key ] = is_wp_error( $r )
				? array( 'ok' => null, 'detail' => $r->get_error_message() )
				: array(
					'ok'     => 'wasm' === $key
						? false !== stripos( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'application/wasm' )
						: (bool) preg_match( '#(java|ecma)script#i', (string) wp_remote_retrieve_header( $r, 'content-type' ) ),
					'detail' => wp_remote_retrieve_response_code( $r ) . ' ' . wp_remote_retrieve_header( $r, 'content-type' ),
				);
		}
		$probe          = wp_safe_remote_head( 'https://cdn.jsdelivr.net/npm/onnxruntime-web@' . LTTS_ORT_VERSION . '/package.json', array( 'timeout' => 10 ) );
		$out['outbound'] = array(
			'ok'     => ! is_wp_error( $probe ) && wp_remote_retrieve_response_code( $probe ) < 400,
			'detail' => is_wp_error( $probe ) ? $probe->get_error_message() : (string) wp_remote_retrieve_response_code( $probe ),
		);
		return rest_ensure_response( $out );
	}

	// ------------------------------------------------------------------ job

	public static function job() {
		$job = get_option( self::JOB_OPTION );
		return is_array( $job ) ? $job : null;
	}

	private static function save( $job ) {
		$job['touched'] = time();
		update_option( self::JOB_OPTION, $job, false );
		return $job;
	}

	/**
	 * Starts (or restarts) the install job. Files already on disk with the right digest are skipped,
	 * and partial ".part" files are resumed.
	 *
	 * @return array|WP_Error
	 */
	public static function start( $id ) {
		$item = LTTS_Catalog::get( $id );
		if ( ! LTTS_Catalog::installable( $item ) ) {
			return new WP_Error( 'ltts_not_installable', __( 'This component cannot be installed by this version.', 'local-tts' ), array( 'status' => 400 ) );
		}
		$job = self::job();
		if ( $job && 'running' === $job['status'] && $job['component'] !== $id && time() - $job['touched'] < self::STALE ) {
			return new WP_Error( 'ltts_busy', __( 'Another installation is running.', 'local-tts' ), array( 'status' => 409 ) );
		}
		if ( ! LTTS_Storage::ensure_base() || ! wp_mkdir_p( LTTS_Storage::dir( $item ) ) ) {
			return new WP_Error( 'ltts_fs', __( 'Cannot write to the download folder (wp-content/uploads).', 'local-tts' ), array( 'status' => 500 ) );
		}

		$dir   = LTTS_Storage::dir( $item );
		$files = array();
		foreach ( $item['files'] as $key => $f ) {
			$entry = array(
				'key'      => $key,
				'path'     => $f['path'],
				'sources'  => array_values( $f['sources'] ),
				'bytes'    => isset( $f['bytes'] ) ? (int) $f['bytes'] : 0,
				'sha256'   => isset( $f['sha256'] ) ? $f['sha256'] : '',
				'git_sha1' => '',
				'hf'       => isset( $f['hf'] ) ? $f['hf'] : '',
				'optional' => ! empty( $f['optional'] ),
				'source'   => 0,
				'done'     => false,
				'skipped'  => false,
			);
			$files[] = $entry;
		}

		$files = self::resolve_hf( $files );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		foreach ( $files as &$entry ) {
			$dest = $dir . $entry['path'];
			if ( $entry['bytes'] && is_file( $dest ) && filesize( $dest ) === $entry['bytes'] && self::digest_ok( $entry, $dest ) ) {
				$entry['done'] = true;
			}
		}
		unset( $entry );

		return self::save(
			array(
				'component' => $id,
				'status'    => 'running',
				'error'     => '',
				'started'   => time(),
				'files'     => $files,
			)
		);
	}

	/**
	 * Fills in size and digest for files hosted on Hugging Face (Piper voices) from the Hugging Face
	 * API: LFS files carry a SHA-256, small files a git blob SHA-1.
	 *
	 * @return array|WP_Error
	 */
	private static function resolve_hf( array $files ) {
		$dirs = array();
		foreach ( $files as $f ) {
			if ( $f['hf'] && ! $f['sha256'] ) {
				$dirs[ dirname( $f['hf'] ) ] = true;
			}
		}
		$info = array();
		foreach ( array_keys( $dirs ) as $dir ) {
			$url = (string) apply_filters( 'ltts_hf_tree_url', 'https://huggingface.co/api/models/' . LTTS_Catalog::PIPER_VOICES_REPO . '/tree/' . LTTS_Catalog::PIPER_VOICES_REV . '/' . $dir, $dir );
			$res = wp_safe_remote_get( $url, array( 'timeout' => 20, 'user-agent' => 'Local-TTS/' . LTTS_VERSION . ' (WordPress)' ) );
			$list = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $list ) ) {
				/* translators: %s: error detail */
				return new WP_Error( 'ltts_hf', sprintf( __( 'Could not read file details from Hugging Face: %s', 'local-tts' ), is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) ), array( 'status' => 502 ) );
			}
			foreach ( $list as $e ) {
				if ( isset( $e['path'] ) ) {
					$info[ $e['path'] ] = $e;
				}
			}
		}
		foreach ( $files as &$f ) {
			if ( ! $f['hf'] || $f['sha256'] ) {
				continue;
			}
			$e = isset( $info[ $f['hf'] ] ) ? $info[ $f['hf'] ] : null;
			if ( ! $e ) {
				/* translators: %s: file path */
				return new WP_Error( 'ltts_hf', sprintf( __( 'File not found on Hugging Face: %s', 'local-tts' ), $f['hf'] ), array( 'status' => 502 ) );
			}
			if ( ! empty( $e['lfs']['oid'] ) ) {
				$f['sha256'] = strtolower( $e['lfs']['oid'] );
				$f['bytes']  = (int) $e['lfs']['size'];
			} else {
				$f['git_sha1'] = strtolower( (string) $e['oid'] );
				$f['bytes']    = (int) $e['size'];
			}
		}
		unset( $f );
		return $files;
	}

	/** Checks a file against its SHA-256 or git blob SHA-1. Files with neither pass. */
	private static function digest_ok( array $f, $path ) {
		if ( $f['sha256'] ) {
			return hash_equals( $f['sha256'], hash_file( 'sha256', $path ) );
		}
		if ( ! empty( $f['git_sha1'] ) ) {
			$ctx = hash_init( 'sha1' );
			hash_update( $ctx, 'blob ' . filesize( $path ) . "\0" );
			hash_update_file( $ctx, $path );
			return hash_equals( $f['git_sha1'], hash_final( $ctx ) );
		}
		return true;
	}

	public static function cancel() {
		$job = self::job();
		if ( $job ) {
			$item = LTTS_Catalog::get( $job['component'] );
			if ( $item ) {
				foreach ( $job['files'] as $f ) {
					$part = LTTS_Storage::dir( $item ) . $f['path'] . '.part';
					if ( is_file( $part ) ) {
						unlink( $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
				}
			}
		}
		delete_option( self::JOB_OPTION );
	}

	/** Downloads chunks until the time budget for this request is used up. */
	public static function step( array $job ) {
		if ( 'running' !== $job['status'] ) {
			return $job;
		}
		$item = LTTS_Catalog::get( $job['component'] );
		if ( ! $item ) {
			$job['status'] = 'error';
			$job['error']  = __( 'The component is not in the catalog.', 'local-tts' );
			return self::save( $job );
		}

		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = $limit > 0 ? max( 5, min( 20, $limit - 8 ) ) : 20;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$t0  = microtime( true );
		$dir = LTTS_Storage::dir( $item );
		wp_mkdir_p( $dir );

		foreach ( $job['files'] as $i => $f ) {
			while ( ! $f['done'] ) {
				if ( microtime( true ) - $t0 > $budget ) {
					$job['files'][ $i ] = $f;
					return self::save( $job );
				}
				$f = self::download_chunk( $f, $dir );
				if ( ! empty( $f['fatal'] ) ) {
					$job['files'][ $i ] = $f;
					$job['status']      = 'error';
					$job['error']       = $f['fatal'];
					return self::save( $job );
				}
			}
			$job['files'][ $i ] = $f;
		}

		// Every file is in place.
		$files = array();
		foreach ( $job['files'] as $f ) {
			if ( ! $f['skipped'] ) {
				$files[ $f['key'] ] = array( 'path' => $f['path'], 'bytes' => (int) $f['bytes'], 'sha256' => $f['sha256'] );
			}
		}
		LTTS_Storage::mark_installed( $job['component'], $files );
		$job['status'] = 'done';
		return self::save( $job );
	}

	/**
	 * Downloads the next chunk of one file.
	 *
	 * @param array  $f   File state.
	 * @param string $dir Target folder.
	 * @return array Updated file state; a 'fatal' key carries an error that stops the job.
	 */
	private static function download_chunk( array $f, $dir ) {
		$dest   = $dir . $f['path'];
		$part   = $dest . '.part';
		$offset = is_file( $part ) ? filesize( $part ) : 0;
		clearstatcache();

		if ( $f['bytes'] && $offset >= $f['bytes'] ) {
			return self::finish_file( $f, $dest, $part );
		}
		if ( $f['source'] >= count( $f['sources'] ) ) {
			/* translators: %s: file name */
			$f['fatal'] = sprintf( __( '%s could not be downloaded from any source.', 'local-tts' ), $f['path'] );
			return $f;
		}

		$url   = $f['sources'][ $f['source'] ];
		$tmp   = $part . '.chunk';
		$whole = ! empty( $f['whole'] );
		if ( $whole && $offset > 0 ) {
			self::cleanup( $part );
			$offset = 0;
		}
		$headers = $whole ? array() : array(
			'Range'           => 'bytes=' . $offset . '-' . ( $offset + self::CHUNK - 1 ),
			'Accept-Encoding' => 'identity',
		);
		// WordPress' curl transport asks for compression and decodes the body. A CDN may then send a
		// compressed range whose decoded length no longer matches Content-Range, so ranged requests
		// ask for identity encoding.
		$identity = static function ( $handle ) {
			if ( defined( 'CURLOPT_ENCODING' ) ) {
				curl_setopt( $handle, CURLOPT_ENCODING, 'identity' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		};
		if ( ! $whole ) {
			add_action( 'http_api_curl', $identity );
		}
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => $whole ? 120 : 45,
				'redirection' => 5,
				'stream'      => true,
				'filename'    => $tmp,
				'decompress'  => $whole,
				'user-agent'  => 'Local-TTS/' . LTTS_VERSION . ' (WordPress)',
				'headers'     => $headers,
			)
		);
		remove_action( 'http_api_curl', $identity );
		$code     = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$encoding = is_wp_error( $res ) ? '' : strtolower( trim( (string) wp_remote_retrieve_header( $res, 'content-encoding' ) ) );
		$f['last_http'] = is_wp_error( $res ) ? $res->get_error_message() : trim( 'HTTP ' . $code . ' ' . (string) wp_remote_retrieve_header( $res, 'content-range' ) . ' ' . $encoding );

		if ( 206 === $code ) {
			$range = (string) wp_remote_retrieve_header( $res, 'content-range' );
			$got   = is_file( $tmp ) ? filesize( $tmp ) : 0;
			if ( preg_match( '#bytes (\d+)-(\d+)/(\d+|\*)#', $range, $m ) && (int) $m[1] === $offset
				&& ( '' === $encoding || 'identity' === $encoding ) && $got === (int) $m[2] - (int) $m[1] + 1 ) {
				if ( ! $f['bytes'] && '*' !== $m[3] ) {
					$f['bytes'] = (int) $m[3];
				}
				self::append( $part, $tmp );
				if ( $f['bytes'] && filesize( $part ) >= $f['bytes'] ) {
					return self::finish_file( $f, $dest, $part );
				}
				return $f;
			}
			// Compressed or inconsistent chunk: fetch the whole file from this source in one request.
			self::cleanup( $tmp, $part );
			$f['failures'][] = wp_parse_url( $url, PHP_URL_HOST ) . ': ' . $f['last_http'] . ', ' . $got . ' bytes';
			if ( $whole ) {
				$f['whole'] = false;
				$f['source']++;
				if ( $f['source'] >= count( $f['sources'] ) ) {
					/* translators: 1: file name, 2: details */
					$f['fatal'] = sprintf( __( 'Could not download %1$s. Details: %2$s', 'local-tts' ), $f['path'], implode( ' | ', $f['failures'] ) );
				}
				return $f;
			}
			$f['whole'] = true;
			return $f;
		}

		if ( 200 === $code ) {
			// The source ignores Range: the whole file arrived.
			self::cleanup( $part );
			rename( $tmp, $part ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! $f['bytes'] ) {
				$f['bytes'] = filesize( $part );
			}
			return self::finish_file( $f, $dest, $part );
		}

		if ( 416 === $code && $offset > 0 ) {
			self::cleanup( $tmp );
			if ( ! $f['bytes'] ) {
				$f['bytes'] = $offset;
			}
			return self::finish_file( $f, $dest, $part );
		}

		self::cleanup( $tmp );
		if ( 404 === $code && $f['optional'] ) {
			$f['done']    = true;
			$f['skipped'] = true;
			self::cleanup( $part );
			return $f;
		}
		// Network error or another status: try the next source. With a known digest the partial
		// file is kept, since every source must serve the same bytes.
		if ( ! $f['sha256'] ) {
			self::cleanup( $part );
		}
		$f['source']++;
		$f['last_error'] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . $code;
		if ( $f['source'] >= count( $f['sources'] ) ) {
			/* translators: 1: file name, 2: error */
			$f['fatal'] = sprintf( __( 'Could not download %1$s: %2$s', 'local-tts' ), $f['path'], $f['last_error'] );
		}
		return $f;
	}

	private static function finish_file( array $f, $dest, $part ) {
		clearstatcache();
		$size = is_file( $part ) ? filesize( $part ) : 0;
		$hash = $size ? hash_file( 'sha256', $part ) : '';
		$ok   = $size > 0 && ( ! $f['bytes'] || $size === $f['bytes'] ) && self::digest_ok( $f, $part );
		if ( ! $ok ) {
			self::cleanup( $part );
			$f['failures'][] = sprintf( '%s: %d/%d bytes, %s', wp_parse_url( $f['sources'][ $f['source'] ], PHP_URL_HOST ), $size, (int) $f['bytes'], isset( $f['last_http'] ) ? $f['last_http'] : '' );
			if ( empty( $f['whole'] ) ) {
				// Retry once from the same source without Range, then move on to the next source.
				$f['whole'] = true;
				return $f;
			}
			$f['whole'] = false;
			$f['source']++;
			if ( $f['source'] >= count( $f['sources'] ) ) {
				/* translators: 1: file name, 2: details */
				$f['fatal'] = sprintf( __( 'Could not verify %1$s (size or SHA-256 does not match). Details: %2$s', 'local-tts' ), $f['path'], implode( ' | ', $f['failures'] ) );
			}
			return $f;
		}
		self::cleanup( $dest );
		rename( $part, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$f['bytes']  = $size;
		$f['sha256'] = $hash; // recorded on first download when it was not pinned
		$f['done']   = true;
		return $f;
	}

	private static function append( $part, $tmp ) {
		$out = fopen( $part, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$in  = fopen( $tmp, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		clearstatcache();
	}

	private static function cleanup( ...$paths ) {
		foreach ( $paths as $p ) {
			if ( is_file( $p ) ) {
				unlink( $p ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/** @return true|WP_Error */
	public static function verify_on_disk( $id ) {
		$item = LTTS_Catalog::get( $id );
		if ( ! LTTS_Catalog::installable( $item ) ) {
			return new WP_Error( 'ltts_not_installable', __( 'This component cannot be installed by this version.', 'local-tts' ), array( 'status' => 400 ) );
		}
		LTTS_Storage::ensure_base();
		$dir     = LTTS_Storage::dir( $item );
		$entries = array();
		foreach ( $item['files'] as $key => $f ) {
			$entries[] = array(
				'key'      => $key,
				'path'     => $f['path'],
				'bytes'    => isset( $f['bytes'] ) ? (int) $f['bytes'] : 0,
				'sha256'   => isset( $f['sha256'] ) ? $f['sha256'] : '',
				'git_sha1' => '',
				'hf'       => isset( $f['hf'] ) ? $f['hf'] : '',
				'optional' => ! empty( $f['optional'] ),
			);
		}
		// If Hugging Face is unreachable (the reason for a manual upload), accept the files as they are.
		$resolved = self::resolve_hf( $entries );
		if ( ! is_wp_error( $resolved ) ) {
			$entries = $resolved;
		}
		$files = array();
		foreach ( $entries as $f ) {
			$path = $dir . $f['path'];
			if ( ! is_file( $path ) ) {
				if ( $f['optional'] ) {
					continue;
				}
				/* translators: %s: file path */
				return new WP_Error( 'ltts_missing', sprintf( __( 'File missing: %s', 'local-tts' ), $item['dir'] . '/' . $f['path'] ), array( 'status' => 400 ) );
			}
			$size = filesize( $path );
			if ( ( $f['bytes'] && $size !== $f['bytes'] ) || ! self::digest_ok( $f, $path ) ) {
				/* translators: %s: file path */
				return new WP_Error( 'ltts_integrity', sprintf( __( 'Could not verify: %s', 'local-tts' ), $item['dir'] . '/' . $f['path'] ), array( 'status' => 400 ) );
			}
			$files[ $f['key'] ] = array( 'path' => $f['path'], 'bytes' => $size, 'sha256' => hash_file( 'sha256', $path ) );
		}
		LTTS_Storage::mark_installed( $id, $files );
		return true;
	}


	/** Job summary for the admin page. */
	public static function summary( $job ) {
		if ( ! $job ) {
			return null;
		}
		$loaded = 0;
		$total  = 0;
		$item   = LTTS_Catalog::get( $job['component'] );
		$dir    = $item ? LTTS_Storage::dir( $item ) : '';
		foreach ( $job['files'] as $f ) {
			$size   = $f['bytes'] ? (int) $f['bytes'] : 0;
			$total += $size;
			if ( $f['done'] ) {
				$loaded += $size;
			} elseif ( $dir && is_file( $dir . $f['path'] . '.part' ) ) {
				$loaded += filesize( $dir . $f['path'] . '.part' );
			}
		}
		if ( $item ) {
			$total = max( $total, LTTS_Catalog::bytes( $item ) );
		}
		return array(
			'component' => $job['component'],
			'status'    => $job['status'],
			'error'     => $job['error'],
			'loaded'    => $loaded,
			'total'     => $total,
		);
	}
}
