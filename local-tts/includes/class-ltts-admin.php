<?php
/**
 * Admin: the "Local TTS" menu (Display, Voices, Reading, Status) and the per-post box.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

class LTTS_Admin {

	const PAGE = 'local-tts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'activation_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LTTS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function activate() {
		LTTS_Migration::run();
		if ( false === get_option( LTTS_Settings::OPTION, false ) ) {
			add_option( LTTS_Settings::OPTION, LTTS_Settings::defaults() );
		}
		LTTS_Storage::ensure_base();
		set_transient( 'ltts_activation_redirect', 1, 60 );
	}

	/** On first activation, open the Voices page if nothing is served yet. */
	public static function activation_redirect() {
		if ( ! get_transient( 'ltts_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'ltts_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! LTTS_Storage::served_languages() ) {
			wp_safe_redirect( self::url( 'models' ) );
			exit;
		}
	}

	/** Tab => page slug. Display is the top-level menu item itself. */
	public static function pages() {
		return array(
			'display' => self::PAGE,
			'models'  => self::PAGE . '-models',
			'reading' => self::PAGE . '-reading',
			'status'  => self::PAGE . '-status',
		);
	}

	public static function url( $tab = 'display' ) {
		$pages = self::pages();
		$slug  = isset( $pages[ $tab ] ) ? $pages[ $tab ] : self::PAGE;
		return add_query_arg( array( 'page' => $slug ), admin_url( 'admin.php' ) );
	}

	/** The plugin tab being viewed, or '' outside the plugin's pages. */
	private static function current_tab() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = array_search( $page, self::pages(), true );
		return false === $tab ? '' : $tab;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'local-tts' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		$render = array( __CLASS__, 'render_page' );
		add_menu_page( 'Local TTS', 'Local TTS', 'manage_options', self::PAGE, $render, 'dashicons-format-audio', 26 );
		$pages = self::pages();
		foreach ( self::tabs() as $tab => $label ) {
			add_submenu_page( self::PAGE, $label . ' ‹ Local TTS', $label, 'manage_options', $pages[ $tab ], $render );
		}
	}

	public static function tabs() {
		return array(
			'display' => __( 'Display', 'local-tts' ),
			'models'  => __( 'Voices', 'local-tts' ),
			'reading' => __( 'Reading', 'local-tts' ),
			'status'  => __( 'Status', 'local-tts' ),
		);
	}

	public static function register_settings() {
		register_setting( self::PAGE, LTTS_Settings::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'LTTS_Settings', 'sanitize' ) ) );
	}

	public static function assets( $hook ) {
		if ( '' === self::current_tab() ) {
			return;
		}
		wp_enqueue_style( 'ltts-admin', LTTS_URL . 'assets/admin/admin.css', array(), LTTS_VERSION );
		wp_enqueue_script( 'ltts-admin', LTTS_URL . 'assets/admin/admin.js', array(), LTTS_VERSION, true );
		wp_localize_script(
			'ltts-admin',
			'lttsAdmin',
			array(
				'root'  => esc_url_raw( rest_url( 'local-tts/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'installing'    => __( 'Downloading…', 'local-tts' ),
					/* translators: {loaded} and {total} are megabytes. */
					'progress'      => __( '{loaded} / {total} MB', 'local-tts' ),
					'done'          => __( 'Installed. Reloading the page…', 'local-tts' ),
					'failed'        => __( 'Installation failed:', 'local-tts' ),
					'confirmRemove' => __( 'Delete this component’s files from the server?', 'local-tts' ),
					'leave'         => __( 'A download is running. If you leave this page it pauses; you can resume it later.', 'local-tts' ),
					'checking'      => __( 'Checking…', 'local-tts' ),
					'ok'            => __( 'OK', 'local-tts' ),
					'bad'           => __( 'Problem', 'local-tts' ),
					'unknown'       => __( 'Could not check', 'local-tts' ),
					'loading'       => __( 'Loading…', 'local-tts' ),
					'added'         => __( 'Added. Reloading the page…', 'local-tts' ),
					'inList'        => __( 'already in the list', 'local-tts' ),
					/* translators: %d: number of voices */
					'voiceCount'    => __( '%d voices', 'local-tts' ),
					'confirmDelete' => __( 'Remove this voice from the list and delete its files?', 'local-tts' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------ page

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = self::current_tab();
		if ( '' === $tab ) {
			$tab = 'display';
		}
		?>
		<div class="wrap ltts-admin">
			<h1 class="ltts-title"><span class="dashicons dashicons-format-audio" aria-hidden="true"></span> Local TTS <span class="ltts-subtitle"><?php esc_html_e( 'reads your posts aloud on the reader’s device', 'local-tts' ); ?></span></h1>
			<?php self::setup_banner(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<div class="ltts-tab">
				<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
			</div>
		</div>
		<?php
	}

	/** Recommended voice for the site language (falls back to the first installable voice). */
	private static function recommended_model() {
		$lang     = strtolower( substr( get_locale(), 0, 2 ) );
		$fallback = '';
		foreach ( LTTS_Catalog::all() as $id => $item ) {
			if ( 'model' !== $item['type'] || ! LTTS_Catalog::installable( $item ) ) {
				continue;
			}
			if ( $item['language'] === $lang ) {
				return $id;
			}
			if ( ! $fallback && 'en' === $item['language'] ) {
				$fallback = $id;
			}
		}
		return $fallback;
	}

	/** Components still needed for a model, in install order, then the model itself. */
	private static function queue_for( $id ) {
		$queue   = LTTS_Storage::missing_requirements( $id );
		$queue[] = $id;
		return $queue;
	}

	/** One-button setup while no language is served. */
	private static function setup_banner() {
		if ( LTTS_Storage::served_languages() ) {
			return;
		}
		$id = self::recommended_model();
		if ( ! $id ) {
			return;
		}
		$model = LTTS_Catalog::get( $id );
		$queue = array_values( array_filter( self::queue_for( $id ), function ( $c ) use ( $id ) {
			return $c !== $id || ! LTTS_Storage::is_installed( $id );
		} ) );
		$bytes = 0;
		foreach ( $queue as $c ) {
			$bytes += LTTS_Catalog::bytes( LTTS_Catalog::get( $c ) );
		}
		?>
		<div class="ltts-setup notice notice-info inline">
			<h2><?php esc_html_e( 'Finish the setup', 'local-tts' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: voice name, 2: size in MB */
					esc_html__( 'The player needs the runtime and a voice on your server. Recommended for your site language: %1$s, about %2$s MB in total. Files are saved in wp-content/uploads/local-tts/ and verified with SHA-256.', 'local-tts' ),
					esc_html( $model['name'] ),
					esc_html( number_format_i18n( $bytes / 1048576 ) )
				);
				?>
			</p>
			<p>
				<button type="button" class="button button-primary button-hero" data-ltts-install="<?php echo esc_attr( implode( ',', $queue ) ); ?>"><?php esc_html_e( 'Install the recommended voice', 'local-tts' ); ?></button>
				<a class="button button-hero" href="<?php echo esc_url( self::url( 'models' ) ); ?>"><?php esc_html_e( 'Choose other voices', 'local-tts' ); ?></a>
			</p>
			<div class="ltts-job" hidden><progress max="1" value="0"></progress> <span class="ltts-job-text"></span></div>
		</div>
		<?php
	}

	private static function form_start( $tab ) {
		echo '<form action="options.php" method="post">';
		settings_fields( self::PAGE );
		echo '<input type="hidden" name="' . esc_attr( LTTS_Settings::OPTION ) . '[_tab]" value="' . esc_attr( $tab ) . '">';
		echo '<input type="hidden" name="_wp_http_referer" value="' . esc_attr( self::url( $tab ) ) . '">';
	}

	private static function name( $key ) {
		return esc_attr( LTTS_Settings::OPTION . '[' . $key . ']' );
	}

	// ------------------------------------------------------------------ Display

	private static function tab_display() {
		$s     = LTTS_Settings::all();
		$types = LTTS_Settings::public_post_types();
		$cats  = get_categories( array( 'hide_empty' => false ) );
		self::form_start( 'display' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Player', 'local-tts' ); ?></th>
				<td><label><input type="checkbox" name="<?php echo self::name( 'enabled' ); ?>" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Show the “Listen” button on the site', 'local-tts' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Content types', 'local-tts' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( $types as $name => $label ) : ?>
							<label><input type="checkbox" name="<?php echo self::name( 'post_types' ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $s['post_types'], true ) ); ?>> <?php echo esc_html( $label ); ?> <code><?php echo esc_html( $name ); ?></code></label><br>
						<?php endforeach; ?>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Scope', 'local-tts' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo self::name( 'scope' ); ?>" value="all" <?php checked( $s['scope'], 'all' ); ?>> <?php esc_html_e( 'All content that matches the rules', 'local-tts' ); ?></label><br>
						<label><input type="radio" name="<?php echo self::name( 'scope' ); ?>" value="opt_in" <?php checked( $s['scope'], 'opt_in' ); ?>> <?php esc_html_e( 'Only posts set to “Show” in the post box (pilot)', 'local-tts' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'Each post can also be switched on or off in the “Listen” box on its edit screen.', 'local-tts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Who sees it', 'local-tts' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo self::name( 'audience' ); ?>" value="everyone" <?php checked( $s['audience'], 'everyone' ); ?>> <?php esc_html_e( 'All visitors', 'local-tts' ); ?></label><br>
						<label><input type="radio" name="<?php echo self::name( 'audience' ); ?>" value="editors" <?php checked( $s['audience'], 'editors' ); ?>> <?php esc_html_e( 'Only logged-in editors (to try it before going live)', 'local-tts' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Categories', 'local-tts' ); ?></th>
				<td>
					<select name="<?php echo self::name( 'terms_mode' ); ?>">
						<option value="all" <?php selected( $s['terms_mode'], 'all' ); ?>><?php esc_html_e( 'All categories', 'local-tts' ); ?></option>
						<option value="include" <?php selected( $s['terms_mode'], 'include' ); ?>><?php esc_html_e( 'Only the selected categories', 'local-tts' ); ?></option>
						<option value="exclude" <?php selected( $s['terms_mode'], 'exclude' ); ?>><?php esc_html_e( 'All except the selected categories', 'local-tts' ); ?></option>
					</select>
					<div class="ltts-terms">
						<?php foreach ( $cats as $cat ) : ?>
							<label><input type="checkbox" name="<?php echo self::name( 'terms' ); ?>[]" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( in_array( (int) $cat->term_id, $s['terms'], true ) ); ?>> <?php echo esc_html( $cat->name ); ?> <span class="count">(<?php echo esc_html( number_format_i18n( $cat->count ) ); ?>)</span></label>
						<?php endforeach; ?>
					</div>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ltts-min-words"><?php esc_html_e( 'Minimum words', 'local-tts' ); ?></label></th>
				<td>
					<input id="ltts-min-words" type="number" min="0" step="50" class="small-text" name="<?php echo self::name( 'min_words' ); ?>" value="<?php echo esc_attr( $s['min_words'] ); ?>">
					<p class="description"><?php esc_html_e( 'Shorter posts get no button. 0 means no limit.', 'local-tts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Placement', 'local-tts' ); ?></th>
				<td>
					<fieldset>
						<label><input type="radio" name="<?php echo self::name( 'placement' ); ?>" value="before" <?php checked( $s['placement'], 'before' ); ?>> <?php esc_html_e( 'Before the post', 'local-tts' ); ?></label><br>
						<label><input type="radio" name="<?php echo self::name( 'placement' ); ?>" value="after" <?php checked( $s['placement'], 'after' ); ?>> <?php esc_html_e( 'After the post', 'local-tts' ); ?></label><br>
						<label><input type="radio" name="<?php echo self::name( 'placement' ); ?>" value="manual" <?php checked( $s['placement'], 'manual' ); ?>> <?php esc_html_e( 'Only where the shortcode is', 'local-tts' ); ?> <code>[<?php echo esc_html( LTTS_Plugin::SHORTCODE ); ?>]</code></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'The shortcode works with every placement; a post that contains it gets no automatic button.', 'local-tts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ltts-admin-locale"><?php esc_html_e( 'Plugin admin language', 'local-tts' ); ?></label></th>
				<td>
					<select id="ltts-admin-locale" name="<?php echo self::name( 'admin_locale' ); ?>">
						<option value="" <?php selected( $s['admin_locale'], '' ); ?>><?php esc_html_e( 'Same as my WordPress profile', 'local-tts' ); ?></option>
						<?php foreach ( LTTS_I18n::NATIVE as $locale => $native ) : ?>
							<option value="<?php echo esc_attr( $locale ); ?>" lang="<?php echo esc_attr( substr( $locale, 0, 2 ) ); ?>" <?php selected( $s['admin_locale'], $locale ); ?>><?php echo esc_html( $native ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only these settings pages. The player always speaks the language of the post it reads.', 'local-tts' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
	}

	// ------------------------------------------------------------------ Voices

	private static function tab_models() {
		$catalog   = LTTS_Catalog::all();
		$installed = LTTS_Storage::installed();
		$models    = LTTS_Settings::get( 'models' );
		$job       = LTTS_Installer::summary( LTTS_Installer::job() );

		// Runtime and libraries first, then voices grouped by language.
		$order = array();
		foreach ( $catalog as $id => $item ) {
			$order[ $id ] = ( 'model' === $item['type'] ? '2' . $item['language'] : ( 'runtime' === $item['type'] ? '0' : '1' ) ) . $id;
		}
		asort( $order );
		?>
		<p><?php esc_html_e( 'Installed files stay on your server and are sent from there to readers’ browsers. One voice is served per language; you can install several voices for a language and switch between them.', 'local-tts' ); ?></p>
		<?php if ( $job && 'running' === $job['status'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				/* translators: %s: component id */
				printf( esc_html__( 'An installation was interrupted: %s.', 'local-tts' ), '<code>' . esc_html( $job['component'] ) . '</code>' );
				?>
				<button type="button" class="button" data-ltts-install="<?php echo esc_attr( $job['component'] ); ?>"><?php esc_html_e( 'Resume', 'local-tts' ); ?></button>
				<button type="button" class="button-link" data-ltts-cancel><?php esc_html_e( 'Cancel', 'local-tts' ); ?></button>
			</p></div>
		<?php endif; ?>

		<table class="widefat ltts-components">
			<thead><tr>
				<th><?php esc_html_e( 'Component', 'local-tts' ); ?></th>
				<th><?php esc_html_e( 'Language', 'local-tts' ); ?></th>
				<th><?php esc_html_e( 'Size', 'local-tts' ); ?></th>
				<th><?php esc_html_e( 'Status', 'local-tts' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( array_keys( $order ) as $id ) :
				$item     = $catalog[ $id ];
				$is_in    = LTTS_Storage::is_installed( $id );
				$upd      = LTTS_Storage::has_update( $id );
				$can      = LTTS_Catalog::installable( $item );
				$is_model = 'model' === $item['type'];
				$lang     = $is_model ? $item['language'] : '';
				$bytes    = $is_in ? $installed[ $id ]['bytes'] : LTTS_Catalog::bytes( $item );
				$missing  = $is_in ? LTTS_Storage::missing_requirements( $id ) : array();
				?>
				<tr data-component="<?php echo esc_attr( $id ); ?>" class="<?php echo $can ? '' : 'ltts-unavailable'; ?>">
					<td>
						<strong><?php echo esc_html( $item['name'] ); ?></strong> <span class="ltts-version"><?php echo esc_html( $item['version'] ); ?></span>
						<?php if ( 'runtime' === $item['type'] ) : ?><span class="ltts-badge"><?php esc_html_e( 'required', 'local-tts' ); ?></span><?php endif; ?>
						<?php if ( 'library' === $item['type'] ) : ?><span class="ltts-badge"><?php esc_html_e( 'shared', 'local-tts' ); ?></span><?php endif; ?>
						<?php if ( ! empty( $item['custom'] ) ) : ?><span class="ltts-badge"><?php esc_html_e( 'added by you', 'local-tts' ); ?></span><?php endif; ?>
						<p class="description"><?php echo esc_html( $item['description'] ); ?>
							<?php if ( ! empty( $item['license'] ) ) : ?>
								<?php /* translators: %s: licence name */ printf( esc_html__( 'Licence: %s.', 'local-tts' ), esc_html( $item['license'] ) ); ?>
							<?php endif; ?>
						</p>
						<div class="ltts-job" hidden><progress max="1" value="0"></progress> <span class="ltts-job-text"></span></div>
					</td>
					<td><?php echo $lang ? esc_html( LTTS_I18n::language_name( $lang ) ) : '—'; ?></td>
					<td><?php echo $bytes ? esc_html( ( $is_in ? '' : '~' ) . number_format_i18n( $bytes / 1048576, 1 ) . ' MB' ) : '—'; ?></td>
					<td>
						<?php if ( $is_in && $missing ) : ?>
							<span class="ltts-warn"><?php esc_html_e( 'Needs a missing component', 'local-tts' ); ?></span>
						<?php elseif ( $is_in ) : ?>
							<span class="ltts-ok"><?php esc_html_e( 'Installed', 'local-tts' ); ?></span>
							<?php if ( $is_model && isset( $models[ $lang ] ) && $models[ $lang ] === $id ) : ?>
								<br><span class="ltts-badge ltts-badge-live"><?php esc_html_e( 'served', 'local-tts' ); ?></span>
							<?php endif; ?>
						<?php elseif ( $upd ) : ?>
							<span class="ltts-warn"><?php esc_html_e( 'Update available', 'local-tts' ); ?></span>
						<?php elseif ( $can ) : ?>
							<?php esc_html_e( 'Not installed', 'local-tts' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Coming later', 'local-tts' ); ?>
						<?php endif; ?>
					</td>
					<td class="ltts-actions">
						<?php if ( $can && ( ! $is_in || $missing ) ) : ?>
							<button type="button" class="button button-primary" data-ltts-install="<?php echo esc_attr( implode( ',', $is_in ? $missing : self::queue_for( $id ) ) ); ?>"
								<?php if ( $is_model && ! $is_in ) : ?>data-ltts-license="<?php echo esc_attr( sprintf( /* translators: 1: voice name, 2: licence */ __( '%1$s is third-party software distributed under its own licence: %2$s. Your site will send this voice to readers’ browsers, so make sure you may use and redistribute it. Continue?', 'local-tts' ), $item['name'], $item['license'] ) ); ?>"<?php endif; ?>
							><?php echo $upd ? esc_html__( 'Update', 'local-tts' ) : esc_html__( 'Install', 'local-tts' ); ?></button>
							<?php if ( ! $is_in ) : ?>
								<button type="button" class="button-link" data-ltts-verify="<?php echo esc_attr( $id ); ?>" title="<?php esc_attr_e( 'If you uploaded the files by FTP', 'local-tts' ); ?>"><?php esc_html_e( 'Check uploaded files', 'local-tts' ); ?></button>
							<?php endif; ?>
						<?php elseif ( $is_in ) : ?>
							<button type="button" class="button-link button-link-delete" data-ltts-remove="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Remove', 'local-tts' ); ?></button>
						<?php endif; ?>
						<?php if ( $is_in ) : ?>
							<a class="ltts-credits-link" href="<?php echo esc_url( LTTS_Storage::url( $item ) . 'CREDITS.txt' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Credits & licence', 'local-tts' ); ?></a>
						<?php endif; ?>
						<?php if ( ! empty( $item['custom'] ) ) : ?>
							<button type="button" class="button-link button-link-delete" data-ltts-delete-voice="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Remove from list', 'local-tts' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$by_lang = array();
		foreach ( $catalog as $id => $item ) {
			if ( 'model' === $item['type'] && LTTS_Storage::is_installed( $id ) ) {
				$by_lang[ $item['language'] ][ $id ] = $item['name'];
			}
		}
		if ( $by_lang ) :
			self::form_start( 'models' );
			?>
			<h2><?php esc_html_e( 'Served voices', 'local-tts' ); ?></h2>
			<p><?php esc_html_e( 'Choose which voice readers get for each language. Installed voices that are not served stay on the server.', 'local-tts' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $by_lang as $lang => $choices ) : ?>
					<tr>
						<th scope="row"><label for="ltts-model-<?php echo esc_attr( $lang ); ?>"><?php echo esc_html( LTTS_I18n::language_name( $lang ) ); ?></label></th>
						<td>
							<select id="ltts-model-<?php echo esc_attr( $lang ); ?>" name="<?php echo self::name( 'models' ); ?>[<?php echo esc_attr( $lang ); ?>]">
								<?php foreach ( $choices as $id => $label ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( isset( $models[ $lang ] ) ? $models[ $lang ] : '', $id ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
								<option value="" <?php selected( empty( $models[ $lang ] ) ); ?>><?php esc_html_e( 'Don’t serve (no audio in this language)', 'local-tts' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Save', 'local-tts' ) ); ?>
			</form>
		<?php endif; ?>

		<?php self::add_voice_section(); ?>

		<details class="ltts-manual">
			<summary><?php esc_html_e( 'If your server cannot download files: manual upload', 'local-tts' ); ?></summary>
			<p><?php esc_html_e( 'Download the files to your computer, upload them by FTP into the folders below, then click “Check uploaded files”. Files are checked by size and digest when possible.', 'local-tts' ); ?></p>
			<?php foreach ( array_keys( $order ) as $id ) : ?>
				<?php
				$item = $catalog[ $id ];
				if ( ! LTTS_Catalog::installable( $item ) ) {
					continue;
				}
				?>
				<h4><?php echo esc_html( $item['name'] ); ?> → <code>wp-content/uploads/<?php echo esc_html( LTTS_Storage::FOLDER . '/' . $item['dir'] ); ?>/</code></h4>
				<ul class="ltts-file-list">
					<?php foreach ( $item['files'] as $f ) : ?>
						<li><a href="<?php echo esc_url( $f['sources'][0] ); ?>" rel="noopener noreferrer"><?php echo esc_html( basename( wp_parse_url( $f['sources'][0], PHP_URL_PATH ) ) ); ?></a>
							<?php if ( basename( wp_parse_url( $f['sources'][0], PHP_URL_PATH ) ) !== $f['path'] ) : ?>
								<?php /* translators: %s: file name */ printf( esc_html__( '(save as %s)', 'local-tts' ), '<code>' . esc_html( $f['path'] ) . '</code>' ); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</details>
		<?php
	}

	/** "Add a voice": from the Piper library or from two addresses. */
	private static function add_voice_section() {
		?>
		<div class="ltts-add-voice">
			<h2><?php esc_html_e( 'Add a voice', 'local-tts' ); ?></h2>
			<p><?php esc_html_e( 'Local TTS runs voice models; it does not include any. Add the voices you want to serve. Each voice is third-party data under its own licence, which you are responsible for respecting.', 'local-tts' ); ?></p>

			<h3><?php esc_html_e( 'From the Piper voice library', 'local-tts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'More than 100 voices in over 40 languages, from rhasspy/piper-voices on Hugging Face. Files are verified against the digests published there.', 'local-tts' ); ?></p>
			<p>
				<button type="button" class="button" data-ltts-library-load><?php esc_html_e( 'Load the voice list', 'local-tts' ); ?></button>
			</p>
			<div class="ltts-library" hidden>
				<label class="screen-reader-text" for="ltts-library-search"><?php esc_html_e( 'Search voices', 'local-tts' ); ?></label>
				<input type="search" id="ltts-library-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search by language, country or name', 'local-tts' ); ?>">
				<select id="ltts-library-select" size="10" class="ltts-library-select"></select>
				<p><button type="button" class="button button-primary" data-ltts-library-add disabled><?php esc_html_e( 'Add to the list', 'local-tts' ); ?></button> <span class="ltts-library-msg"></span></p>
			</div>

			<details class="ltts-add-url">
				<summary><?php esc_html_e( 'From addresses (any Piper-compatible voice)', 'local-tts' ); ?></summary>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="ltts-url-name"><?php esc_html_e( 'Name', 'local-tts' ); ?></label></th><td><input type="text" id="ltts-url-name" class="regular-text"></td></tr>
					<tr><th scope="row"><label for="ltts-url-lang"><?php esc_html_e( 'Language code', 'local-tts' ); ?></label></th><td><input type="text" id="ltts-url-lang" class="small-text" placeholder="en" maxlength="3"></td></tr>
					<tr><th scope="row"><label for="ltts-url-model"><?php esc_html_e( 'Model (.onnx) address', 'local-tts' ); ?></label></th><td><input type="url" id="ltts-url-model" class="large-text code" placeholder="https://…/voice.onnx"></td></tr>
					<tr><th scope="row"><label for="ltts-url-config"><?php esc_html_e( 'Configuration (.onnx.json) address', 'local-tts' ); ?></label></th><td><input type="url" id="ltts-url-config" class="large-text code" placeholder="https://…/voice.onnx.json"></td></tr>
					<tr><th scope="row"><label for="ltts-url-sha"><?php esc_html_e( 'SHA-256 of the model (recommended)', 'local-tts' ); ?></label></th><td><input type="text" id="ltts-url-sha" class="large-text code" maxlength="64"></td></tr>
					<tr><th scope="row"><label for="ltts-url-license"><?php esc_html_e( 'Licence', 'local-tts' ); ?></label></th><td><input type="text" id="ltts-url-license" class="regular-text"></td></tr>
					<tr><th scope="row"><label for="ltts-url-credit"><?php esc_html_e( 'Credit shown to readers', 'local-tts' ); ?></label></th><td><input type="text" id="ltts-url-credit" class="regular-text"></td></tr>
					<tr><th scope="row"><label for="ltts-url-homepage"><?php esc_html_e( 'Homepage or source', 'local-tts' ); ?></label></th><td><input type="url" id="ltts-url-homepage" class="large-text code"></td></tr>
				</table>
				<p><button type="button" class="button button-primary" data-ltts-url-add><?php esc_html_e( 'Add to the list', 'local-tts' ); ?></button> <span class="ltts-url-msg"></span></p>
			</details>
		</div>
		<?php
	}

	// ------------------------------------------------------------------ Reading

	private static function tab_reading() {
		$s = LTTS_Settings::all();
		self::form_start( 'reading' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ltts-speed"><?php esc_html_e( 'Default speed', 'local-tts' ); ?></label></th>
				<td>
					<input id="ltts-speed" type="number" name="<?php echo self::name( 'speed' ); ?>" value="<?php echo esc_attr( $s['speed'] ); ?>" min="0.7" max="1.5" step="0.05" class="small-text">
					<p class="description"><?php esc_html_e( '1 is normal speed; between 0.7 and 1.5. Readers can change it in the player.', 'local-tts' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Player', 'local-tts' ); ?></th>
				<td>
					<label><input type="checkbox" name="<?php echo self::name( 'highlight' ); ?>" value="1" <?php checked( $s['highlight'] ); ?>> <?php esc_html_e( 'Highlight the paragraph being read', 'local-tts' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo self::name( 'show_hint' ); ?>" value="1" <?php checked( $s['show_hint'] ); ?>> <?php esc_html_e( 'Show the download size before the first listen', 'local-tts' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo self::name( 'show_credit' ); ?>" value="1" <?php checked( $s['show_credit'] ); ?>> <?php esc_html_e( 'Show the voice credit under the player (some voice licences require attribution)', 'local-tts' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ltts-exclude"><?php esc_html_e( 'Parts not to read', 'local-tts' ); ?></label></th>
				<td>
					<textarea id="ltts-exclude" name="<?php echo self::name( 'exclude' ); ?>" rows="5" class="large-text code" placeholder=".author-box&#10;.newsletter-signup"><?php echo esc_textarea( $s['exclude'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One CSS selector per line. Code blocks, hidden elements, footnotes and share boxes are already skipped. To skip a single block, give it the ltts-skip class.', 'local-tts' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		submit_button();
		echo '</form>';
	}

	// ------------------------------------------------------------------ Status

	private static function tab_status() {
		$base     = LTTS_Storage::base_dir();
		$writable = wp_mkdir_p( $base ) && wp_is_writable( $base );
		$langs    = LTTS_Storage::served_languages();
		$limit    = (int) ini_get( 'max_execution_time' );
		$https    = is_ssl() || 0 === strpos( home_url(), 'https://' );
		$rows     = array(
			array( __( 'Plugin version', 'local-tts' ), LTTS_VERSION, null ),
			array( __( 'Download folder', 'local-tts' ), $base, $writable ),
			array( 'ONNX Runtime Web ' . LTTS_ORT_VERSION, LTTS_Storage::runtime_ready() ? __( 'installed', 'local-tts' ) : __( 'not installed', 'local-tts' ), LTTS_Storage::runtime_ready() ),
			array( __( 'Served languages', 'local-tts' ), $langs ? implode( ', ', array_map( array( 'LTTS_I18n', 'language_name' ), $langs ) ) : __( 'none', 'local-tts' ), (bool) $langs ),
			array( 'HTTPS', $https ? __( 'yes', 'local-tts' ) : __( 'no (the browser cache and integrity checks need HTTPS)', 'local-tts' ), $https ),
			/* translators: %d: seconds */
			array( 'max_execution_time', $limit ? sprintf( __( '%d s', 'local-tts' ), $limit ) : __( 'unlimited', 'local-tts' ), null ),
			array( __( 'Site language', 'local-tts' ), get_locale(), null ),
		);
		?>
		<table class="widefat striped ltts-status-table"><tbody>
		<?php foreach ( $rows as $r ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $r[0] ); ?></th>
				<td><code><?php echo esc_html( $r[1] ); ?></code></td>
				<td><?php echo null === $r[2] ? '' : ( $r[2] ? '<span class="ltts-ok">✓</span>' : '<span class="ltts-bad">✕</span>' ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>

		<h2><?php esc_html_e( 'Server check', 'local-tts' ); ?></h2>
		<p><?php esc_html_e( 'Tests whether the server can download files and serves .wasm and .mjs files with the right type.', 'local-tts' ); ?></p>
		<p><button type="button" class="button" data-ltts-check><?php esc_html_e( 'Run check', 'local-tts' ); ?></button></p>
		<table class="widefat striped ltts-check-table" hidden><tbody>
			<tr data-check="outbound"><th><?php esc_html_e( 'Outgoing connection (for downloads)', 'local-tts' ); ?></th><td></td></tr>
			<tr data-check="wasm"><th>.wasm → application/wasm</th><td></td></tr>
			<tr data-check="mjs"><th>.mjs → JavaScript</th><td></td></tr>
		</tbody></table>

		<h2><?php esc_html_e( 'Check in the browser', 'local-tts' ); ?></h2>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'If the site has a Content Security Policy, it must allow: script-src \'self\' \'wasm-unsafe-eval\' blob:; worker-src \'self\' blob:; connect-src \'self\'.', 'local-tts' ); ?></li>
			<li><?php esc_html_e( 'Caching and minification plugins must not combine assets/js/player.js or tts-worker.js.', 'local-tts' ); ?></li>
			<li><?php esc_html_e( 'A second post must not download the voice again (Developer tools → Application → Cache Storage).', 'local-tts' ); ?></li>
		</ul>
		<?php
	}

	// ------------------------------------------------------------------ notices

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_transient( 'ltts_migrated_notice' ) ) {
			delete_transient( 'ltts_migrated_notice' );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Local TTS: settings, post options and downloaded voices were moved over from the previous plugin (Kulak Ver / asosyoloji-dinle), which has been deactivated. You can delete it from the Plugins page.', 'local-tts' )
			);
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'dashboard' ), true ) ) {
			return;
		}
		if ( LTTS_Storage::served_languages() || ! LTTS_Settings::get( 'enabled' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Local TTS: no voice is installed yet, so visitors don’t see the “Listen” button.', 'local-tts' ),
			esc_url( self::url( 'models' ) ),
			esc_html__( 'Finish the setup', 'local-tts' )
		);
	}

	// ------------------------------------------------------------------ post box

	public static function add_meta_box( $post_type ) {
		if ( in_array( $post_type, (array) LTTS_Settings::get( 'post_types' ), true ) ) {
			add_meta_box( 'ltts-meta', __( 'Listen', 'local-tts' ), array( __CLASS__, 'render_meta_box' ), $post_type, 'side', 'default' );
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ltts_meta', 'ltts_meta_nonce' );
		$mode  = (string) get_post_meta( $post->ID, '_ltts_mode', true );
		$lang  = (string) get_post_meta( $post->ID, '_ltts_lang', true );
		$langs = array( '' => __( 'Automatic', 'local-tts' ) );
		foreach ( array_unique( array_merge( array_keys( LTTS_I18n::LOCALES ), LTTS_I18n::catalog_languages() ) ) as $code ) {
			$langs[ $code ] = LTTS_I18n::language_name( $code );
		}
		?>
		<p>
			<label for="ltts_mode"><?php esc_html_e( 'Player', 'local-tts' ); ?></label><br>
			<select name="ltts_mode" id="ltts_mode">
				<option value="" <?php selected( $mode, '' ); ?>><?php esc_html_e( 'Use site settings', 'local-tts' ); ?></option>
				<option value="on" <?php selected( $mode, 'on' ); ?>><?php esc_html_e( 'Show', 'local-tts' ); ?></option>
				<option value="off" <?php selected( $mode, 'off' ); ?>><?php esc_html_e( 'Hide', 'local-tts' ); ?></option>
			</select>
		</p>
		<p>
			<label for="ltts_lang"><?php esc_html_e( 'Post language', 'local-tts' ); ?></label><br>
			<select name="ltts_lang" id="ltts_lang">
				<?php foreach ( $langs as $code => $label ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $lang, $code ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ltts_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ltts_meta_nonce'] ) ), 'ltts_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$mode = isset( $_POST['ltts_mode'] ) ? LTTS_Plugin::sanitize_mode( sanitize_key( wp_unslash( $_POST['ltts_mode'] ) ) ) : '';
		$lang = isset( $_POST['ltts_lang'] ) ? LTTS_Plugin::sanitize_lang( sanitize_key( wp_unslash( $_POST['ltts_lang'] ) ) ) : '';
		'' === $mode ? delete_post_meta( $post_id, '_ltts_mode' ) : update_post_meta( $post_id, '_ltts_mode', $mode );
		'' === $lang ? delete_post_meta( $post_id, '_ltts_lang' ) : update_post_meta( $post_id, '_ltts_lang', $lang );
	}
}
