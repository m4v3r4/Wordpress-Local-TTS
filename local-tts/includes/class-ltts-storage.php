<?php
/**
 * Where installed components live and which are installed: wp-content/uploads/local-tts/
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

class LTTS_Storage {

	const OPTION = 'ltts_installed';
	const FOLDER = 'local-tts';

	public static function base_dir() {
		$u = wp_upload_dir( null, false );
		return trailingslashit( $u['basedir'] ) . self::FOLDER . '/';
	}

	public static function base_url() {
		$u = wp_upload_dir( null, false );
		return set_url_scheme( trailingslashit( $u['baseurl'] ) . self::FOLDER . '/' );
	}

	public static function dir( $item ) {
		return self::base_dir() . trailingslashit( $item['dir'] );
	}

	public static function url( $item ) {
		return self::base_url() . trailingslashit( $item['dir'] );
	}

	/** @return array<string,array> id => {version, files, bytes, installed_at} */
	public static function installed() {
		$v = get_option( self::OPTION, array() );
		return is_array( $v ) ? $v : array();
	}

	/** Installed in the catalog's version? */
	public static function is_installed( $id ) {
		$all  = self::installed();
		$item = LTTS_Catalog::get( $id );
		return $item && isset( $all[ $id ] ) && $all[ $id ]['version'] === $item['version'];
	}

	/** Installed and every component it needs is installed too. */
	public static function is_ready( $id ) {
		if ( ! self::is_installed( $id ) ) {
			return false;
		}
		foreach ( LTTS_Catalog::requires( LTTS_Catalog::get( $id ) ) as $req ) {
			if ( ! self::is_installed( $req ) ) {
				return false;
			}
		}
		return true;
	}

	/** Components this one needs that are missing. */
	public static function missing_requirements( $id ) {
		return array_values( array_filter( LTTS_Catalog::requires( LTTS_Catalog::get( $id ) ), function ( $req ) {
			return ! self::is_installed( $req );
		} ) );
	}

	/** Installed, but the catalog has a different version. */
	public static function has_update( $id ) {
		$all  = self::installed();
		$item = LTTS_Catalog::get( $id );
		return $item && isset( $all[ $id ] ) && $all[ $id ]['version'] !== $item['version'];
	}

	public static function runtime_ready() {
		return self::is_installed( LTTS_Catalog::RUNTIME_ID );
	}

	/** Creates the upload folder and its server configuration files. */
	public static function ensure_base() {
		$base = self::base_dir();
		if ( ! wp_mkdir_p( $base ) ) {
			return false;
		}
		if ( ! file_exists( $base . 'index.php' ) ) {
			file_put_contents( $base . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$htaccess = "# Local TTS: voice models and runtime files.\n"
			. "<IfModule mod_mime.c>\n  AddType application/wasm .wasm\n  AddType text/javascript .mjs\n</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "  <FilesMatch \"\\.(wasm|mjs|onnx|data)$\">\n    Header set Cache-Control \"public, max-age=31536000, immutable\"\n  </FilesMatch>\n"
			. "  <FilesMatch \"manifest\\.json$\">\n    Header set Cache-Control \"no-cache\"\n  </FilesMatch>\n"
			. "</IfModule>\n"
			. "<FilesMatch \"\\.(part|chunk|php)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n</FilesMatch>\n";
		file_put_contents( $base . '.htaccess', $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Records a component as installed and writes the manifests that depend on it.
	 *
	 * @param string $id    Component id.
	 * @param array  $files key => {path, bytes, sha256}.
	 */
	public static function mark_installed( $id, array $files ) {
		$item = LTTS_Catalog::get( $id );
		$sum  = 0;
		foreach ( $files as $f ) {
			$sum += (int) $f['bytes'];
		}

		$all        = self::installed();
		$all[ $id ] = array(
			'version'      => $item['version'],
			'files'        => $files,
			'bytes'        => $sum,
			'installed_at' => time(),
		);
		update_option( self::OPTION, $all, false );
		self::write_credits( $id, $files );

		if ( 'model' === $item['type'] ) {
			self::write_manifest( $id );
			// Serve it if the language has no working model yet.
			$models = LTTS_Settings::get( 'models' );
			$lang   = $item['language'];
			if ( empty( $models[ $lang ] ) || ! self::is_ready( $models[ $lang ] ) ) {
				$models[ $lang ] = $id;
				LTTS_Settings::update( array( 'models' => $models ) );
			}
		} else {
			// A runtime or library changed: rewrite the manifests of models that use it.
			foreach ( array_keys( $all ) as $other ) {
				$o = LTTS_Catalog::get( $other );
				if ( $o && 'model' === $o['type'] && in_array( $id, LTTS_Catalog::requires( $o ), true ) ) {
					self::write_manifest( $other );
				}
			}
		}
	}

	/**
	 * Writes manifest.json for an installed model. Library files (such as the Piper phonemizer)
	 * are listed with paths relative to the model folder, so the browser verifies and caches them
	 * like the model's own files.
	 */
	public static function write_manifest( $id ) {
		$item = LTTS_Catalog::get( $id );
		$all  = self::installed();
		if ( ! $item || empty( $all[ $id ] ) || 'model' !== $item['type'] ) {
			return false;
		}
		$manifest = array(
			'format'   => 1,
			'id'       => $id,
			'version'  => $item['version'],
			'engine'   => $item['engine'],
			'language' => $item['language'],
			'runtime'  => array( 'name' => 'onnxruntime-web', 'version' => LTTS_ORT_VERSION ),
			'source'   => isset( $item['source'] ) ? $item['source'] : ( isset( $item['homepage'] ) ? $item['homepage'] : null ),
			'files'    => array(),
		);
		if ( isset( $item['sample_rate'] ) ) {
			$manifest['sampleRate'] = (int) $item['sample_rate'];
		}
		foreach ( $all[ $id ]['files'] as $key => $f ) {
			if ( ! empty( $item['files'][ $key ]['doc'] ) ) {
				continue; // licence texts stay on the server; readers' browsers don't need them
			}
			$manifest['files'][ $key ] = self::manifest_entry( $f, isset( $item['files'][ $key ]['type'] ) ? $item['files'][ $key ]['type'] : '' );
		}
		$up = str_repeat( '../', substr_count( trim( $item['dir'], '/' ), '/' ) + 1 );
		foreach ( LTTS_Catalog::requires( $item ) as $req ) {
			$lib = LTTS_Catalog::get( $req );
			if ( empty( $lib['manifest_prefix'] ) || empty( $all[ $req ] ) ) {
				continue;
			}
			foreach ( $all[ $req ]['files'] as $key => $f ) {
				if ( ! empty( $lib['files'][ $key ]['doc'] ) ) {
					continue;
				}
				$entry         = self::manifest_entry( $f, isset( $lib['files'][ $key ]['type'] ) ? $lib['files'][ $key ]['type'] : '' );
				$entry['path'] = $up . trim( $lib['dir'], '/' ) . '/' . $f['path'];
				$manifest['files'][ $lib['manifest_prefix'] . '_' . $key ] = $entry;
			}
		}
		return false !== file_put_contents( self::dir( $item ) . 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Writes CREDITS.txt next to a component: what it is, its licence, where its source code is,
	 * and the digest of every file. Licence texts downloaded with the component sit beside it.
	 *
	 * @param string $id    Component ID.
	 * @param array  $files key => {path, bytes, sha256}.
	 */
	private static function write_credits( $id, array $files ) {
		$item  = LTTS_Catalog::get( $id );
		$lines = array(
			$item['name'] . ' ' . $item['version'],
			str_repeat( '=', 60 ),
			'Licence:      ' . ( isset( $item['license'] ) ? $item['license'] : '' ),
			'Credit:       ' . ( isset( $item['credit'] ) ? $item['credit'] : '' ),
			'Homepage:     ' . ( isset( $item['homepage'] ) ? $item['homepage'] : '' ),
			'Source code:  ' . implode( "\n              ", isset( $item['source_code'] ) ? (array) $item['source_code'] : array() ),
			'Installed by: Local TTS ' . LTTS_VERSION . ' on ' . gmdate( 'Y-m-d' ),
			'',
			'This component is third-party software or data. It is not part of Local TTS and is',
			'distributed under its own licence. Licence texts that came with it are in this folder.',
			'',
			'Files (SHA-256):',
		);
		foreach ( $files as $f ) {
			$lines[] = sprintf( '  %s  %s (%d bytes)', $f['sha256'], $f['path'], $f['bytes'] );
		}
		file_put_contents( self::dir( $item ) . 'CREDITS.txt', implode( "\n", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	private static function manifest_entry( $f, $type ) {
		return array(
			'path'   => $f['path'],
			'bytes'  => (int) $f['bytes'],
			'sha256' => $f['sha256'],
			'type'   => $type ? $type : 'application/octet-stream',
		);
	}

	/** Deletes a component's files and record. */
	public static function remove( $id ) {
		$item = LTTS_Catalog::get( $id );
		$all  = self::installed();
		if ( $item && ! empty( $item['dir'] ) ) {
			self::rmdir( self::base_dir() . $item['dir'] );
			$parent = dirname( self::base_dir() . $item['dir'] );
			if ( is_dir( $parent ) && 2 === count( (array) scandir( $parent ) ) ) {
				@rmdir( $parent ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );

		// A language that served this voice falls back to another installed voice of that language.
		$models = LTTS_Settings::get( 'models' );
		foreach ( $models as $lang => $active ) {
			if ( $active !== $id ) {
				continue;
			}
			unset( $models[ $lang ] );
			foreach ( array_keys( $all ) as $other ) {
				$o = LTTS_Catalog::get( $other );
				if ( $o && 'model' === $o['type'] && $o['language'] === $lang ) {
					$models[ $lang ] = $other;
					break;
				}
			}
		}
		LTTS_Settings::update( array( 'models' => $models ) );
	}

	/** Recursive delete, only inside the plugin's upload folder. */
	public static function rmdir( $dir ) {
		$base = realpath( self::base_dir() );
		$real = realpath( $dir );
		if ( ! $base || ! $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || ! is_dir( $real ) ) {
			return false;
		}
		self::delete_tree( $real );
		return true;
	}

	/** Deletes everything that was downloaded (on uninstall). */
	public static function purge() {
		$real = realpath( self::base_dir() );
		if ( $real && is_dir( $real ) ) {
			self::delete_tree( $real );
		}
		delete_option( self::OPTION );
	}

	private static function delete_tree( $real ) {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	// ------------------------------------------------------------------ for the player

	/** The model served for a language, if it is ready. */
	public static function active_model( $lang ) {
		$models = LTTS_Settings::get( 'models' );
		$id     = isset( $models[ $lang ] ) ? $models[ $lang ] : '';
		return $id && self::is_ready( $id ) ? $id : '';
	}

	/** Languages the player can read. */
	public static function served_languages() {
		$out = array();
		foreach ( (array) LTTS_Settings::get( 'models' ) as $lang => $id ) {
			if ( self::is_ready( $id ) ) {
				$out[] = $lang;
			}
		}
		return $out;
	}

	public static function manifest_url( $id ) {
		$item = LTTS_Catalog::get( $id );
		return $item ? self::url( $item ) . 'manifest.json' : '';
	}

	public static function runtime_url() {
		$url = self::url( LTTS_Catalog::get( LTTS_Catalog::RUNTIME_ID ) );
		return trailingslashit( (string) apply_filters( 'ltts_ort_base_url', $url ) );
	}

	/** What a first listen downloads: the model (with its libraries) and the runtime's WASM file. */
	public static function download_bytes( $id ) {
		$all   = self::installed();
		$bytes = isset( $all[ $id ]['bytes'] ) ? (int) $all[ $id ]['bytes'] : 0;
		foreach ( LTTS_Catalog::requires( LTTS_Catalog::get( $id ) ) as $req ) {
			if ( LTTS_Catalog::RUNTIME_ID === $req ) {
				$bytes += isset( $all[ $req ]['files']['wasm']['bytes'] ) ? (int) $all[ $req ]['files']['wasm']['bytes'] : 0;
			} elseif ( isset( $all[ $req ]['bytes'] ) ) {
				$bytes += (int) $all[ $req ]['bytes'];
			}
		}
		return $bytes;
	}
}
