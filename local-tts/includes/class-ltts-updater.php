<?php
/**
 * Plugin updates from GitHub releases.
 *
 * The plugin header carries "Update URI: https://github.com/m4v3r4/Wordpress-Local-TTS", so
 * WordPress (5.8+) never offers a WordPress.org update for it and instead asks the
 * update_plugins_github.com filter. This class answers that filter with the latest GitHub release
 * whose assets include the plugin ZIP (local-tts-<version>.zip, built by .github/workflows/release.yml).
 *
 * Releasing: push a tag such as v0.5.1 whose number matches the Version header; the workflow builds
 * the ZIP and attaches it to the release. Drafts and pre-releases are ignored by the GitHub
 * "latest release" endpoint.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks GitHub for a newer release and feeds it to the WordPress updater.
 */
class LTTS_Updater {

	const REPO      = 'm4v3r4/Wordpress-Local-TTS';
	const TRANSIENT = 'ltts_update_release';
	const SLUG      = 'local-tts';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_flush' ) );
	}

	/** "owner/repo"; filterable for forks. */
	public static function repo() {
		return (string) apply_filters( 'ltts_update_repo', self::REPO );
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	/** Dashboard → Updates → "Check again" also refreshes the release cache. */
	public static function maybe_flush() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache flush.
			self::flush();
		}
	}

	/**
	 * The latest release as {version, package, url, body, published, requires, requires_php, tested},
	 * or null. Cached for 12 hours (1 hour after a failure) to stay well inside GitHub's
	 * unauthenticated API limit of 60 requests per hour per IP.
	 *
	 * @return array|null
	 */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$url = 'https://api.github.com/repos/' . self::repo() . '/releases/latest';
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'Local-TTS/' . LTTS_VERSION . ' (WordPress)',
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$release = self::parse( json_decode( wp_remote_retrieve_body( $res ), true ) );
		}

		set_site_transient( self::TRANSIENT, $release ? $release : array(), $release ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Picks the plugin ZIP from a GitHub release object.
	 *
	 * Optional lines in the release notes override the requirements shown by WordPress:
	 * "Requires at least: 6.5", "Requires PHP: 7.4", "Tested up to: 6.8".
	 *
	 * @param mixed $data Decoded API response.
	 * @return array|null
	 */
	public static function parse( $data ) {
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+(\.\d+){1,3}([.-][0-9A-Za-z.-]+)?$/', $version ) ) {
			return null;
		}
		$package = '';
		foreach ( isset( $data['assets'] ) ? (array) $data['assets'] : array() as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( preg_match( '/^local-tts(-[0-9][0-9A-Za-z.-]*)?\.zip$/', $name ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package || 0 !== strpos( $package, 'https://github.com/' ) ) {
			return null; // Only the built ZIP is installable; GitHub's source archives lack the right folder name.
		}

		$body = isset( $data['body'] ) ? (string) $data['body'] : '';
		$meta = array();
		foreach ( array( 'requires' => 'Requires at least', 'requires_php' => 'Requires PHP', 'tested' => 'Tested up to' ) as $key => $label ) {
			if ( preg_match( '/^\s*' . preg_quote( $label, '/' ) . ':\s*([0-9.]+)\s*$/mi', $body, $m ) ) {
				$meta[ $key ] = $m[1];
			}
		}

		return array_merge(
			array(
				'version'      => $version,
				'package'      => $package,
				'url'          => isset( $data['html_url'] ) ? (string) $data['html_url'] : 'https://github.com/' . self::repo(),
				'body'         => $body,
				'published'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
				'requires'     => '',
				'requires_php' => '',
				'tested'       => '',
			),
			$meta
		);
	}

	/**
	 * Filter update_plugins_{hostname}: report a newer release for this plugin.
	 *
	 * @param array|false $update      Update data from an earlier filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( LTTS_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release || version_compare( $release['version'], LTTS_VERSION, '<=' ) ) {
			return $update;
		}
		return array(
			'id'           => $plugin_data['UpdateURI'],
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
			'tested'       => $release['tested'],
		);
	}

	/**
	 * Filter plugins_api: the "View version x.y details" dialog.
	 *
	 * @param false|object|array $result Result so far.
	 * @param string             $action API action.
	 * @param object             $args   Arguments; $args->slug is the plugin slug.
	 * @return false|object|array
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		$readme = LTTS_DIR . 'readme.txt';
		$desc   = is_readable( $readme ) ? self::readme_section( (string) file_get_contents( $readme ), 'Description' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

		return (object) array(
			'name'          => 'Local TTS',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( self::repo() ) . '">Local TTS contributors</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'download_link' => $release['package'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $release['tested'],
			'last_updated'  => $release['published'],
			'sections'      => array(
				'description' => self::to_html( $desc ),
				'changelog'   => self::to_html( $release['body'] ) . '<p><a href="' . esc_url( $release['url'] ) . '">GitHub</a></p>',
			),
		);
	}

	/**
	 * Filter upgrader_source_selection: make sure the unpacked folder is "local-tts", so an update
	 * replaces the plugin instead of installing a second copy.
	 *
	 * @param string|WP_Error $source        Unpacked folder.
	 * @param string          $remote_source Parent folder.
	 * @param WP_Upgrader     $upgrader      Upgrader.
	 * @param array           $extra         Context; $extra['plugin'] is the basename being updated.
	 * @return string|WP_Error
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || empty( $extra['plugin'] ) || plugin_basename( LTTS_FILE ) !== $extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted || ! $wp_filesystem ) {
			return $source;
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ) ) ) {
			return new WP_Error( 'ltts_update_folder', __( 'Could not rename the downloaded plugin folder.', 'local-tts' ) );
		}
		return $wanted;
	}

	/** One "== Name ==" section of readme.txt. */
	private static function readme_section( $readme, $name ) {
		if ( preg_match( '/^==\s*' . preg_quote( $name, '/' ) . '\s*==\s*$(.*?)(?=^==\s|\z)/msi', $readme, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Small Markdown subset to HTML for the details dialog: headings, bullets, `code`, **bold**, links.
	 * Everything is escaped first, so release notes cannot inject markup.
	 *
	 * @param string $text Markdown or readme text.
	 * @return string
	 */
	public static function to_html( $text ) {
		$html  = '';
		$list  = false;
		$lines = preg_split( '/\r\n|\r|\n/', (string) $text );
		foreach ( $lines as $line ) {
			$line = rtrim( $line );
			$esc  = esc_html( $line );
			$esc  = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $esc );
			$esc  = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $esc );
			$esc  = preg_replace_callback(
				'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
				function ( $m ) {
					return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
				},
				$esc
			);
			if ( preg_match( '/^\s*[-*]\s+(.*)$/', $esc, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . $m[1] . '</li>';
				$list  = true;
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( preg_match( '/^(#{1,4})\s+(.*)$|^=+\s*(.*?)\s*=+$/', $esc, $m ) ) {
				$html .= '<h4>' . ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : $m[2] ) . '</h4>';
			} elseif ( '' !== trim( $esc ) ) {
				$html .= '<p>' . $esc . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}
}
