<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Settings {

	private static $option_key = 'noodled_settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_landing_upload' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_evernote_upload' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect_to_setup' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin' ] );
	}

	/** Load the landing-styled admin skin + fonts, only on the Noodled page. */
	public static function enqueue_admin( $hook ) {
		if ( $hook !== 'toplevel_page_noodled' ) return;
		wp_enqueue_style( 'noodled-admin-fonts', 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700;9..144,900&family=Inter:wght@400;500;600;700&display=swap', [], null );
		wp_enqueue_style( 'noodled-admin', NOODLED_URL . 'assets/css/admin.css', [], NOODLED_VERSION );
	}

	/**
	 * Backend theme — follows the app theme stored in the admin's noodled config,
	 * and the ?nood_theme toggle writes back so the app and backend stay in sync.
	 */
	private static function admin_theme(): string {
		global $wpdb;
		$email = wp_get_current_user()->user_email;
		$nid   = $email ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}noodled_users WHERE email = %s", $email ) ) : 0;
		$cfg   = $nid ? get_option( 'noodled_config_' . $nid, [] ) : [];
		$theme = ( $cfg['theme'] ?? '' ) ?: 'dark';
		if ( isset( $_GET['nood_theme'] ) && in_array( $_GET['nood_theme'], [ 'dark', 'light' ], true ) ) {
			$theme = $_GET['nood_theme'];
			if ( $nid ) { $cfg['theme'] = $theme; update_option( 'noodled_config_' . $nid, $cfg ); }
		}
		return $theme;
	}

	/* ────────────────────────────────────────────────────────────
	   MENU
	   ──────────────────────────────────────────────────────────── */
	public static function add_menu() {
		$pending = Noodled_Auth::get_pending_count();
		$bubble  = $pending ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>' : '';

		add_menu_page(
			__( 'Noodled', 'noodled' ),
			__( 'Noodled', 'noodled' ) . $bubble,
			'manage_options',
			'noodled',
			[ __CLASS__, 'page_dashboard' ],
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30"><path d="M5 19 C5 11, 12 8, 15 13 C17 16, 12 20, 11 16 C10 12, 17 9, 21 13 C24 16, 23 21, 26 19" fill="none" stroke="black" stroke-width="3" stroke-linecap="round"/></svg>' ),
			30
		);

		// Everything (Overview, Users, Branding, Import, Sync, Settings) is a tab on
		// the single Dashboard page now — no separate submenu items.
		if ( ! self::is_setup_complete() ) {
			add_submenu_page( null, __( 'Setup', 'noodled' ), __( 'Setup', 'noodled' ), 'manage_options', 'noodled-setup', [ __CLASS__, 'render_setup' ] );
		}
	}

	/** Tab definitions for the Dashboard. */
	private static function tabs(): array {
		return [
			'overview' => __( 'Overview', 'noodled' ),
			'users'    => __( 'Users', 'noodled' ),
			'branding' => __( 'Branding', 'noodled' ),
			'import'   => __( 'Import', 'noodled' ),
			'sync'     => __( 'Sync', 'noodled' ),
			'settings' => __( 'Settings', 'noodled' ),
		];
	}

	/* ────────────────────────────────────────────────────────────
	   SETTINGS REGISTRATION
	   ──────────────────────────────────────────────────────────── */
	public static function register_settings() {
		register_setting( 'noodled_general', self::$option_key, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
		register_setting( 'noodled_branding', self::$option_key, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
		register_setting( 'noodled_sync', self::$option_key, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );
	}

	public static function sanitize( $input ) {
		$old   = get_option( self::$option_key, [] );
		$clean = $old; // preserve existing values across tabs

		// General
		if ( isset( $input['app_path'] ) ) {
			$path = sanitize_text_field( $input['app_path'] );
			$clean['app_path'] = trim( $path, '/ ' ) ?: 'noodled';
		}
		if ( isset( $input['takeover_homepage'] ) || isset( $input['_tab'] ) && $input['_tab'] === 'general' ) {
			$clean['takeover_homepage']  = ! empty( $input['takeover_homepage'] ) ? '1' : '';
			$clean['allow_registration'] = ! empty( $input['allow_registration'] ) ? '1' : '';
			$clean['require_approval']   = ! empty( $input['require_approval'] ) ? '1' : '';
		}
		if ( isset( $input['notify_email'] ) ) $clean['notify_email'] = sanitize_email( $input['notify_email'] );
		if ( isset( $input['trash_retention'] ) ) {
			$clean['trash_retention'] = max( 0, (int) $input['trash_retention'] );
			update_option( 'noodled_trash_retention', $clean['trash_retention'] );
		}

		// Branding
		if ( isset( $input['brand_name'] ) )   $clean['brand_name']    = sanitize_text_field( $input['brand_name'] );
		if ( isset( $input['brand_tagline'] ) ) $clean['brand_tagline'] = sanitize_text_field( $input['brand_tagline'] );
		if ( isset( $input['accent_color'] ) )  $clean['accent_color']  = sanitize_hex_color( $input['accent_color'] ) ?: '';

		// Sync
		if ( isset( $input['github_owner'] ) )   $clean['github_owner']   = sanitize_text_field( $input['github_owner'] );
		if ( isset( $input['github_repo'] ) )    $clean['github_repo']    = sanitize_text_field( $input['github_repo'] ) ?: 'noodled-notes';
		if ( isset( $input['github_token'] ) )   $clean['github_token']   = sanitize_text_field( $input['github_token'] );
		if ( isset( $input['github_branch'] ) )  $clean['github_branch']  = sanitize_text_field( $input['github_branch'] ) ?: 'main';
		if ( isset( $input['webhook_secret'] ) ) $clean['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] );

		// Flush rewrite rules when path changes
		if ( ( $old['app_path'] ?? 'noodled' ) !== ( $clean['app_path'] ?? 'noodled' ) ) {
			delete_option( 'rewrite_rules' );
		}

		$clean['setup_complete'] = '1';
		return $clean;
	}

	/* ────────────────────────────────────────────────────────────
	   FILE UPLOAD HANDLERS
	   ──────────────────────────────────────────────────────────── */
	public static function handle_landing_upload() {
		if ( empty( $_FILES['landing_html'] ) || $_FILES['landing_html']['error'] !== UPLOAD_ERR_OK ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['noodled_landing_nonce'] ) || ! wp_verify_nonce( $_POST['noodled_landing_nonce'], 'noodled_landing_upload' ) ) return;
		$html = file_get_contents( $_FILES['landing_html']['tmp_name'] );
		if ( $html ) {
			update_option( 'noodled_landing_html', $html, false );
			add_settings_error( 'noodled_branding', 'landing_uploaded', __( 'Landing page uploaded.', 'noodled' ), 'success' );
		}
	}

	public static function handle_evernote_upload() {
		if ( empty( $_FILES['enex_file'] ) || $_FILES['enex_file']['error'] !== UPLOAD_ERR_OK ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'noodled_evernote_import' ) ) return;

		$result = Noodled_Evernote::import( $_FILES['enex_file']['tmp_name'], 0 );
		if ( isset( $result['error'] ) ) {
			add_settings_error( 'noodled_import', 'import_error', $result['error'], 'error' );
		} else {
			/* translators: 1: number of notes imported, 2: number of duplicate notes skipped, 3: number of errors */
			$message = sprintf( __( 'Imported %1$d notes. Skipped %2$d duplicates. %3$d errors.', 'noodled' ), $result['imported'], $result['skipped'], $result['errors'] );
			add_settings_error( 'noodled_import', 'import_done', $message, 'success' );
		}
	}

	public static function maybe_redirect_to_setup() {
		if ( self::is_setup_complete() ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		$screen = $_GET['page'] ?? '';
		if ( str_starts_with( $screen, 'noodled' ) ) return;
		if ( wp_doing_ajax() ) return;
		if ( get_transient( 'noodled_setup_redirect' ) ) return;
		set_transient( 'noodled_setup_redirect', 1, 60 );
		wp_redirect( admin_url( 'admin.php?page=noodled-setup' ) );
		exit;
	}

	/* ────────────────────────────────────────────────────────────
	   PAGE: DASHBOARD
	   ──────────────────────────────────────────────────────────── */
	public static function page_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$tabs    = self::tabs();
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
		$pending = Noodled_Auth::get_pending_count();
		$bubble  = $pending ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $pending . '</span></span>' : '';
		$theme   = self::admin_theme();
		$other   = $theme === 'dark' ? 'light' : 'dark';
		$brand   = self::get_brand_name();
		$noodle  = '<svg viewBox="0 0 30 30" fill="none" aria-hidden="true" focusable="false"><path d="M5 19 C5 11, 12 8, 15 13 C17 16, 12 20, 11 16 C10 12, 17 9, 21 13 C24 16, 23 21, 26 19" stroke-width="3" stroke-linecap="round"/></svg>';
		?>
		<div class="wrap"><div class="nood-admin" data-nood-theme="<?php echo esc_attr( $theme ); ?>">
			<h1 class="screen-reader-text"><?php
				/* translators: %s is the app/brand name */
				echo esc_html( sprintf( __( '%s dashboard', 'noodled' ), $brand ) );
			?></h1>
			<div class="nood-bar">
				<div class="nood-brand"><?php echo $noodle; // phpcs:ignore ?>
					<span style="display:flex;flex-direction:column;line-height:1.05"><b><?php echo esc_html( $brand ); ?></b><span><?php esc_html_e( 'control room', 'noodled' ); ?></span></span>
				</div>
				<div class="nood-spacer"></div>
				<a class="nood-toggle" href="<?php echo esc_url( add_query_arg( [ 'tab' => $tab, 'nood_theme' => $other ] ) ); ?>"><?php echo $theme === 'dark' ? '&#9728;&#65038; ' . esc_html__( 'Light', 'noodled' ) : '&#9789; ' . esc_html__( 'Dark', 'noodled' ); ?></a>
				<a class="nood-link" href="<?php echo esc_url( admin_url() ); ?>"><?php echo esc_html__( 'WP', 'noodled' ); ?>&nbsp;<?php echo esc_html__( 'Admin', 'noodled' ); ?> &#8599;</a>
			</div>
			<div class="nood-wrap">
				<nav class="nood-tabs" aria-label="<?php esc_attr_e( 'Noodled sections', 'noodled' ); ?>">
					<?php foreach ( $tabs as $k => $label ) :
						$url = admin_url( 'admin.php?page=noodled&tab=' . $k );
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo $tab === $k ? 'active' : ''; ?>"<?php echo $tab === $k ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ) . ( $k === 'users' ? $bubble : '' ); ?></a>
					<?php endforeach; ?>
				</nav>
				<?php
				switch ( $tab ) {
					case 'users':    self::render_user_management(); break;
					case 'branding': self::render_branding();        break;
					case 'import':   self::render_import();           break;
					case 'sync':     self::render_sync();             break;
					case 'settings': self::render_settings();         break;
					default:         self::render_overview();
				}
				?>
			</div>
		</div></div>
		<?php
	}

	/** Overview tab: stat cards, quick links, landing preview. */
	private static function render_overview() {
		$app_url   = home_url( '/' . self::get_app_path() . '/' );
		$users     = Noodled_Auth::get_all_users();
		$notebooks = Noodled_Notebooks::get_all();
		global $wpdb;
		$p           = $wpdb->prefix;
		$note_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}noodled_notes WHERE deleted_at IS NULL" );
		$trash_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}noodled_notes WHERE deleted_at IS NOT NULL" );
		$att_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}noodled_attachments" );
		$att_bytes   = (int) $wpdb->get_var( "SELECT COALESCE(SUM(file_size),0) FROM {$p}noodled_attachments" );
		$drop_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}noodled_notebooks WHERE drop_to > 0" );
		$pending     = Noodled_Auth::get_pending_count();
		$last_sync   = get_option( 'noodled_last_sync' );
		$preview_url = add_query_arg( 'noodled_preview', 'landing', self::is_homepage_mode() ? home_url( '/' ) : $app_url );

		// 14-day notes-created activity for the chart.
		$rows = $wpdb->get_results(
			"SELECT DATE(created_at) d, COUNT(*) c FROM {$p}noodled_notes
			 WHERE deleted_at IS NULL AND created_at >= (UTC_TIMESTAMP() - INTERVAL 13 DAY)
			 GROUP BY DATE(created_at)", OBJECT_K );
		$days = [];
		for ( $i = 13; $i >= 0; $i-- ) {
			$date = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$days[ $date ] = isset( $rows[ $date ] ) ? (int) $rows[ $date ]->c : 0;
		}
		$peak = max( 1, max( $days ) );

		$cards = [
			[ 'v' => $note_count,                       'l' => __( 'Notes', 'noodled' ) ],
			[ 'v' => count( $notebooks ),               'l' => __( 'Notebooks', 'noodled' ) ],
			[ 'v' => count( $users ),                   'l' => __( 'Users', 'noodled' ) ],
			[ 'v' => $pending,                          'l' => __( 'Pending', 'noodled' ),      'hot' => $pending > 0 ],
			[ 'v' => $att_count,                        'l' => __( 'Attachments', 'noodled' ) ],
			[ 'v' => size_format( $att_bytes ) ?: '0 B','l' => __( 'Storage', 'noodled' ) ],
			[ 'v' => $trash_count,                      'l' => __( 'In Trash', 'noodled' ) ],
			[ 'v' => $drop_count,                       'l' => __( 'Drop folders', 'noodled' ) ],
			[ 'v' => $last_sync ? esc_html( $last_sync ) : esc_html__( 'Never', 'noodled' ), 'l' => __( 'Last sync', 'noodled' ), 'small' => true ],
			[ 'v' => 'v' . NOODLED_VERSION,             'l' => __( 'Version', 'noodled' ) ],
		];
		?>
		<h2 class="screen-reader-text"><?php esc_html_e( 'At a glance', 'noodled' ); ?></h2>
			<div class="nood-cards">
			<?php foreach ( $cards as $c ) : ?>
			<div class="noodled-card<?php echo ! empty( $c['hot'] ) ? ' noodled-card--hot' : ''; ?>">
				<div class="noodled-card-value"<?php echo ! empty( $c['small'] ) ? ' style="font-size:17px"' : ''; ?>><?php echo $c['v']; ?></div>
				<p><?php echo esc_html( $c['l'] ); ?></p>
			</div>
			<?php endforeach; ?>
		</div>

		<div class="nood-panel">
			<h2><?php esc_html_e( 'Notes created', 'noodled' ); ?></h2>
			<div class="nood-panel-sub"><?php esc_html_e( 'Last 14 days', 'noodled' ); ?></div>
			<div class="nood-chart">
				<?php foreach ( $days as $date => $count ) :
					$h = $count ? max( 6, (int) round( $count / $peak * 80 ) ) : 3;
					/* translators: 1: date, 2: number of notes created that day */
					$bar_title = sprintf( _n( '%1$s · %2$d note', '%1$s · %2$d notes', $count, 'noodled' ), $date, $count );
					?>
					<div class="bar" title="<?php echo esc_attr( $bar_title ); ?>">
						<b><?php echo $count ?: ''; ?></b>
						<i style="height:<?php echo (int) $h; ?>%"></i>
						<span><?php echo esc_html( gmdate( 'j', strtotime( $date ) ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<h2><?php esc_html_e( 'Quick links', 'noodled' ); ?></h2>
		<p><a href="<?php echo esc_url( $app_url ); ?>" target="_blank" class="button button-primary"><?php esc_html_e( 'Open App', 'noodled' ); ?></a>
		<?php if ( self::is_homepage_mode() ) : ?>
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" class="button"><?php esc_html_e( 'Open Homepage', 'noodled' ); ?></a>
		<?php endif; ?>
			<a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button"><?php esc_html_e( 'Preview Landing Page', 'noodled' ); ?></a>
		</p>
		<p class="description"><?php
			/* translators: %s is the bolded "Preview Landing Page" button label */
			printf( esc_html__( 'The landing page only shows to logged-out visitors, so use %s to see it while signed in.', 'noodled' ), '<strong>' . esc_html__( 'Preview Landing Page', 'noodled' ) . '</strong>' );
		?></p>
		<?php
	}

	/* ────────────────────────────────────────────────────────────
	   PAGE: SETTINGS
	   ──────────────────────────────────────────────────────────── */
	private static function render_settings() {
		$opts = get_option( self::$option_key, [] );
		?>
			<form method="post" action="options.php">
				<?php settings_fields( 'noodled_general' ); ?>
				<input type="hidden" name="<?php echo self::$option_key; ?>[_tab]" value="general">
				<table class="form-table">
					<tr>
						<th><label for="noodled-app-path"><?php esc_html_e( 'App URL Path', 'noodled' ); ?></label></th>
						<td>
							<input type="text" id="noodled-app-path" name="<?php echo self::$option_key; ?>[app_path]" value="<?php echo esc_attr( $opts['app_path'] ?? 'noodled' ); ?>" class="regular-text">
							<p class="description"><?php echo esc_html( home_url( '/' ) ); ?><strong><?php echo esc_html( $opts['app_path'] ?? 'noodled' ); ?></strong>/</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Homepage Mode', 'noodled' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo self::$option_key; ?>[takeover_homepage]" value="1" <?php checked( ! empty( $opts['takeover_homepage'] ) ); ?>> <?php esc_html_e( 'Use as the site homepage', 'noodled' ); ?></label></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Registration', 'noodled' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo self::$option_key; ?>[allow_registration]" value="1" <?php checked( ! empty( $opts['allow_registration'] ) ); ?>> <?php esc_html_e( 'Allow open registration', 'noodled' ); ?></label><br>
							<label><input type="checkbox" name="<?php echo self::$option_key; ?>[require_approval]" value="1" <?php checked( ! empty( $opts['require_approval'] ) ); ?>> <?php esc_html_e( 'Require admin approval', 'noodled' ); ?></label>
							<p class="description"><?php
								/* translators: %s is the bolded "Get a noodle" button label */
								printf( esc_html__( 'The landing page\'s %s button always queues requests for your approval, regardless of these options.', 'noodled' ), '<strong>' . esc_html__( 'Get a noodle', 'noodled' ) . '</strong>' );
							?></p>
						</td>
					</tr>
					<tr>
						<th><label for="noodled-notify-email"><?php esc_html_e( 'Request Notifications', 'noodled' ); ?></label></th>
						<td>
							<input type="email" id="noodled-notify-email" name="<?php echo self::$option_key; ?>[notify_email]" value="<?php echo esc_attr( $opts['notify_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							<p class="description"><?php esc_html_e( 'Where to email new noodle requests. Defaults to the site admin email.', 'noodled' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="noodled-trash-retention"><?php esc_html_e( 'Auto-empty Trash', 'noodled' ); ?></label></th>
						<td>
							<input type="number" id="noodled-trash-retention" min="0" max="3650" name="<?php echo self::$option_key; ?>[trash_retention]" value="<?php echo esc_attr( (int) ( $opts['trash_retention'] ?? 0 ) ); ?>" class="small-text"> <?php esc_html_e( 'days', 'noodled' ); ?>
							<p class="description"><?php
								/* translators: %s is the bolded "0 = keep forever" note */
								printf( esc_html__( 'Permanently delete trashed notes (and their attachments) older than this many days. %s. Runs once a day.', 'noodled' ), '<strong>' . esc_html__( '0 = keep forever', 'noodled' ) . '</strong>' );
							?></p>
						</td>
					</tr>
				</table>
				<p class="description" style="max-width:700px"><?php
					/* translators: %s is the bolded "My Notes" notebook name */
					printf( esc_html__( 'Each new member gets their own private %s notebook. Notes are private by default; users share their own notebooks/notes from inside the app. Admins do not have a god-view of members\' notes.', 'noodled' ), '<strong>' . esc_html__( 'My Notes', 'noodled' ) . '</strong>' );
				?></p>
				<?php submit_button(); ?>
			</form>
		<?php
	}

	/* ────────────────────────────────────────────────────────────
	   TAB: BRANDING
	   ──────────────────────────────────────────────────────────── */
	private static function render_branding() {
		$opts = get_option( self::$option_key, [] );
		$has_landing = ! empty( get_option( 'noodled_landing_html' ) );
		settings_errors( 'noodled_branding' );
		?>
			<form method="post" action="options.php" enctype="multipart/form-data">
				<?php settings_fields( 'noodled_branding' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="noodled-brand-name"><?php esc_html_e( 'App Name', 'noodled' ); ?></label></th>
						<td><input type="text" id="noodled-brand-name" name="<?php echo self::$option_key; ?>[brand_name]" value="<?php echo esc_attr( $opts['brand_name'] ?? 'noodled' ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="noodled-brand-tagline"><?php esc_html_e( 'Tagline', 'noodled' ); ?></label></th>
						<td><input type="text" id="noodled-brand-tagline" name="<?php echo self::$option_key; ?>[brand_tagline]" value="<?php echo esc_attr( $opts['brand_tagline'] ?? __( 'Your notes, everywhere', 'noodled' ) ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="noodled-accent-color"><?php esc_html_e( 'Accent Color', 'noodled' ); ?></label></th>
						<td>
							<input type="color" id="noodled-accent-color" name="<?php echo self::$option_key; ?>[accent_color]" value="<?php echo esc_attr( $opts['accent_color'] ?? '#0078d4' ); ?>" style="width:60px;height:34px;padding:2px;cursor:pointer">
							<span style="color:#666;margin-left:8px"><?php
								/* translators: %s is the default accent color hex code */
								printf( esc_html__( 'Default: %s', 'noodled' ), '#0078d4' );
							?></span>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Landing Page', 'noodled' ); ?></th>
						<td>
							<?php if ( $has_landing ) : ?>
								<p style="color:green;margin-bottom:8px">&#10003; <?php esc_html_e( 'Landing page is set.', 'noodled' ); ?>
								<button type="button" class="button-link" style="color:#b32d2e" onclick="if(confirm('<?php echo esc_js( __( 'Remove landing page?', 'noodled' ) ); ?>')){fetch('<?php echo esc_url( rest_url( 'noodled/v1/admin/landing' ) ); ?>',{method:'DELETE',headers:{'X-WP-Nonce':'<?php echo wp_create_nonce( 'wp_rest' ); ?>'},credentials:'same-origin'}).then(()=>location.reload())}"><?php esc_html_e( 'Remove', 'noodled' ); ?></button></p>
							<?php else : ?>
								<p style="margin-bottom:8px"><?php esc_html_e( 'Upload an HTML file to show as a landing page for visitors.', 'noodled' ); ?></p>
							<?php endif; ?>
							<input type="file" name="landing_html" accept=".html,.htm" aria-label="<?php esc_attr_e( 'Upload landing page HTML file', 'noodled' ); ?>">
							<?php wp_nonce_field( 'noodled_landing_upload', 'noodled_landing_nonce' ); ?>
							<p class="description"><?php esc_html_e( 'A login modal is automatically injected.', 'noodled' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		<?php
	}

	/**
	 * Reusable user-management block (pending requests, members, invite).
	 * Rendered as the Users tab on the Dashboard.
	 */
	public static function render_user_management() {
		$users   = Noodled_Auth::get_all_users();
		$pending = array_values( array_filter( $users, function( $u ) { return $u['role'] === 'pending'; } ) );
		$members = array_values( array_filter( $users, function( $u ) { return $u['role'] !== 'pending'; } ) );
		?>
		<?php if ( $pending ) : ?>
		<h2><?php esc_html_e( 'Pending requests', 'noodled' ); ?> <span class="awaiting-mod"><span class="pending-count"><?php echo count( $pending ); ?></span></span></h2>
		<table class="widefat striped" style="max-width:860px;margin-bottom:24px">
			<thead><tr><th><?php esc_html_e( 'Email', 'noodled' ); ?></th><th><?php esc_html_e( 'Name', 'noodled' ); ?></th><th><?php esc_html_e( 'Requested', 'noodled' ); ?></th><th><?php esc_html_e( 'Actions', 'noodled' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $pending as $u ) : ?>
				<tr>
					<td><?php echo esc_html( $u['email'] ); ?></td>
					<td><?php echo esc_html( $u['display_name'] ); ?></td>
					<td><?php echo $u['created_at'] ? esc_html( $u['created_at'] ) : '&mdash;'; ?></td>
					<td>
						<button class="button button-primary button-small" onclick="noodledApprove(<?php echo (int) $u['id']; ?>)"><?php esc_html_e( 'Approve', 'noodled' ); ?></button>
						<button class="button button-small" onclick="noodledDeny(<?php echo (int) $u['id']; ?>)"><?php esc_html_e( 'Deny', 'noodled' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Members', 'noodled' ); ?></h2>
		<table class="widefat striped" style="max-width:860px;margin-bottom:16px">
			<thead><tr><th><?php esc_html_e( 'Email', 'noodled' ); ?></th><th><?php esc_html_e( 'Name', 'noodled' ); ?></th><th><?php esc_html_e( 'Role', 'noodled' ); ?></th><th><?php esc_html_e( 'Last Login', 'noodled' ); ?></th><th title="<?php esc_attr_e( 'When on, this member gets a folder whose contents are shared with you, shown under their name', 'noodled' ); ?>"><?php esc_html_e( 'Drop folder', 'noodled' ); ?></th><th><?php esc_html_e( 'Actions', 'noodled' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $members as $u ) :
				$is_member = $u['role'] === 'member';
				$has_drop  = $is_member && Noodled_Notebooks::drop_folder_active( (int) $u['id'] );
			?>
				<tr>
					<td><?php echo esc_html( $u['email'] ); ?></td>
					<td><?php echo esc_html( $u['display_name'] ); ?></td>
					<td><?php echo esc_html( $u['role'] ); ?></td>
					<td><?php echo $u['last_login'] ? esc_html( $u['last_login'] ) : '<em>' . esc_html__( 'Never', 'noodled' ) . '</em>'; ?></td>
					<td>
						<?php if ( $is_member ) : ?>
							<label><input type="checkbox" <?php checked( (bool) $has_drop ); ?> onchange="noodledToggleDrop(<?php echo (int) $u['id']; ?>, this.checked, this)"> <?php esc_html_e( 'Share', 'noodled' ); ?></label>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
					<td>
						<button class="button button-small" onclick="noodledSendPin(<?php echo (int) $u['id']; ?>)"><?php esc_html_e( 'Send PIN', 'noodled' ); ?></button>
						<span class="noodled-pin" id="pin-<?php echo (int) $u['id']; ?>" role="status" aria-live="polite" style="margin:0 6px;font-size:12px"></span>
						<button class="button button-small" onclick="noodledDeleteUser(<?php echo (int) $u['id']; ?>)"><?php esc_html_e( 'Remove', 'noodled' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Invite', 'noodled' ); ?></h3>
		<div style="display:flex;gap:8px;align-items:center;max-width:860px;margin-bottom:8px;flex-wrap:wrap">
			<input type="email" id="invite-email" placeholder="<?php esc_attr_e( 'Email', 'noodled' ); ?>" aria-label="<?php esc_attr_e( 'Invite email', 'noodled' ); ?>" class="regular-text" style="flex:1;min-width:160px">
			<input type="text" id="invite-name" placeholder="<?php esc_attr_e( 'Name (optional)', 'noodled' ); ?>" aria-label="<?php esc_attr_e( 'Invite name (optional)', 'noodled' ); ?>" class="regular-text" style="flex:1;min-width:140px">
			<select id="invite-role" aria-label="<?php esc_attr_e( 'Invite role', 'noodled' ); ?>"><option value="member"><?php esc_html_e( 'Member', 'noodled' ); ?></option><option value="admin"><?php esc_html_e( 'Admin', 'noodled' ); ?></option></select>
			<label style="white-space:nowrap"><input type="checkbox" id="invite-drop"> <?php esc_html_e( 'Drop folder', 'noodled' ); ?></label>
			<button type="button" class="button button-primary" onclick="noodledInvite()"><?php esc_html_e( 'Invite', 'noodled' ); ?></button>
		</div>
		<span id="invite-status" role="status" aria-live="polite"></span>

		<p class="description" style="max-width:860px;margin-top:24px"><?php
			/* translators: %s is the bolded "Drop folder" label */
			printf( esc_html__( 'Notes are private per user. Members share their own notebooks and notes from inside the app. %s is the one exception: enabling it gives the member a folder whose contents are shared read/write with you, shown in your account under their name.', 'noodled' ), '<strong>' . esc_html__( 'Drop folder', 'noodled' ) . '</strong>' );
		?></p>

		<script>
		const _nonce = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';
		const _api = '<?php echo esc_url( rest_url( 'noodled/v1' ) ); ?>';

		async function noodledInvite() {
			const email = document.getElementById('invite-email').value;
			const name = document.getElementById('invite-name').value;
			const role = document.getElementById('invite-role').value;
			const drop = document.getElementById('invite-drop').checked;
			const status = document.getElementById('invite-status');
			if (!email) { status.textContent = 'Email required'; return; }
			const res = await fetch(_api + '/admin/users', {
				method: 'POST',
				headers: { 'X-WP-Nonce': _nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ email, name, role, drop })
			});
			const data = await res.json();
			status.textContent = data.error || 'Invited!';
			if (!data.error) setTimeout(() => location.reload(), 500);
		}

		async function noodledDeleteUser(id) {
			if (!confirm('Remove this user?')) return;
			await fetch(_api + '/admin/users/' + id, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': _nonce },
				credentials: 'same-origin'
			});
			location.reload();
		}

		async function noodledApprove(id) {
			const res = await fetch(_api + '/admin/users/' + id + '/approve', {
				method: 'POST',
				headers: { 'X-WP-Nonce': _nonce },
				credentials: 'same-origin'
			});
			const data = await res.json();
			if (data.error) { alert(data.error); return; }
			location.reload();
		}

		async function noodledDeny(id) {
			if (!confirm('Deny and remove this request?')) return;
			await fetch(_api + '/admin/users/' + id, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': _nonce },
				credentials: 'same-origin'
			});
			location.reload();
		}

		async function noodledToggleDrop(id, enabled, el) {
			el.disabled = true;
			const res = await fetch(_api + '/admin/users/' + id + '/drop', {
				method: 'POST',
				headers: { 'X-WP-Nonce': _nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ enabled })
			});
			const data = await res.json();
			el.disabled = false;
			if (data.error) { alert(data.error); el.checked = !enabled; }
		}

		async function noodledSendPin(id) {
			const out = document.getElementById('pin-' + id);
			out.textContent = '…';
			const res = await fetch(_api + '/admin/users/' + id + '/pin', {
				method: 'POST',
				headers: { 'X-WP-Nonce': _nonce },
				credentials: 'same-origin'
			});
			const data = await res.json();
			if (data.error) { out.textContent = data.error; return; }
			out.innerHTML = 'PIN <strong>' + data.pin + '</strong>' + (data.emailed ? ' — emailed ✓' : ' — email failed');
		}
		</script>
		<?php
	}

	/* ────────────────────────────────────────────────────────────
	   PAGE: IMPORT
	   ──────────────────────────────────────────────────────────── */
	private static function render_import() {
		settings_errors( 'noodled_import' );
		?>
			<div class="noodled-import-card">
				<h2><?php esc_html_e( 'From Evernote (admin)', 'noodled' ); ?></h2>
				<p><?php
					/* translators: %s is the .enex file extension shown in a code tag */
					printf( esc_html__( 'Export your notes from Evernote desktop as an %s file, then upload it here. Members can also import their own from inside the app (toolbar → Import).', 'noodled' ), '<code>.enex</code>' );
				?></p>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'noodled_evernote_import' ); ?>
					<input type="file" name="enex_file" accept=".enex" aria-label="<?php esc_attr_e( 'Choose Evernote .enex file to import', 'noodled' ); ?>" style="margin-right:8px">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'noodled' ); ?></button>
				</form>
				<p class="description" style="margin-top:8px"><?php esc_html_e( 'Notes are created in an "Evernote Import" notebook (or grouped by first tag). Attachments, checklists, and tables are converted. Duplicates are skipped.', 'noodled' ); ?></p>
			</div>

			<?php
			$opts = get_option( self::$option_key, [] );
			if ( ! empty( $opts['github_owner'] ) && ! empty( $opts['github_token'] ) ) :
			?>
			<div class="noodled-import-card" style="margin-top:20px">
				<h2><?php esc_html_e( 'From GitHub', 'noodled' ); ?></h2>
				<p><?php esc_html_e( 'Pull all notes from your connected GitHub repository.', 'noodled' ); ?></p>
				<button type="button" class="button button-primary" id="noodled-import-btn" onclick="noodledGitImport()"><?php esc_html_e( 'Import from GitHub', 'noodled' ); ?></button>
				<span id="noodled-import-status" role="status" aria-live="polite"></span>
				<script>
				async function noodledGitImport() {
					const btn = document.getElementById('noodled-import-btn');
					const status = document.getElementById('noodled-import-status');
					btn.disabled = true;
					status.textContent = ' Importing...';
					try {
						const res = await fetch('<?php echo esc_url( rest_url( 'noodled/v1/sync/import' ) ); ?>', {
							method: 'POST',
							headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>' },
							credentials: 'same-origin'
						});
						const data = await res.json();
						status.textContent = data.error ? ' Error: ' + data.error : ' Imported ' + (data.notebooks || 0) + ' notebooks, ' + (data.notes || 0) + ' notes';
					} catch (e) {
						status.textContent = ' Failed: ' + e.message;
					}
					btn.disabled = false;
				}
				</script>
			</div>
			<?php endif; ?>

			<style>
			.noodled-import-card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;max-width:600px}
			.noodled-import-card h2{margin:0 0 8px;font-size:16px}
			.noodled-import-card p{color:#555;font-size:13px;margin:0 0 12px}
			</style>
		<?php
	}

	/* ────────────────────────────────────────────────────────────
	   TAB: SYNC
	   ──────────────────────────────────────────────────────────── */
	private static function render_sync() {
		$opts = get_option( self::$option_key, [] );
		$webhook_url = rest_url( 'noodled/v1/webhook/github' );
		?>
			<h2><?php esc_html_e( 'GitHub', 'noodled' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'noodled_sync' ); ?>
				<table class="form-table">
					<tr><th><label for="noodled-gh-owner"><?php esc_html_e( 'Repository Owner', 'noodled' ); ?></label></th><td><input type="text" id="noodled-gh-owner" name="<?php echo self::$option_key; ?>[github_owner]" value="<?php echo esc_attr( $opts['github_owner'] ?? '' ); ?>" class="regular-text"></td></tr>
					<tr><th><label for="noodled-gh-repo"><?php esc_html_e( 'Repository Name', 'noodled' ); ?></label></th><td><input type="text" id="noodled-gh-repo" name="<?php echo self::$option_key; ?>[github_repo]" value="<?php echo esc_attr( $opts['github_repo'] ?? 'noodled-notes' ); ?>" class="regular-text"></td></tr>
					<tr><th><label for="noodled-gh-token"><?php esc_html_e( 'Personal Access Token', 'noodled' ); ?></label></th><td><input type="password" id="noodled-gh-token" name="<?php echo self::$option_key; ?>[github_token]" value="<?php echo esc_attr( $opts['github_token'] ?? '' ); ?>" class="regular-text"></td></tr>
					<tr><th><label for="noodled-gh-branch"><?php esc_html_e( 'Branch', 'noodled' ); ?></label></th><td><input type="text" id="noodled-gh-branch" name="<?php echo self::$option_key; ?>[github_branch]" value="<?php echo esc_attr( $opts['github_branch'] ?? 'main' ); ?>" class="regular-text"></td></tr>
					<tr><th><label for="noodled-webhook-secret"><?php esc_html_e( 'Webhook Secret', 'noodled' ); ?></label></th><td><input type="password" id="noodled-webhook-secret" name="<?php echo self::$option_key; ?>[webhook_secret]" value="<?php echo esc_attr( $opts['webhook_secret'] ?? '' ); ?>" class="regular-text"></td></tr>
				</table>
				<?php submit_button( __( 'Save Sync Settings', 'noodled' ) ); ?>
			</form>

			<h3><?php esc_html_e( 'Webhook URL', 'noodled' ); ?></h3>
			<p><?php esc_html_e( 'Add this as a push webhook in your GitHub repo settings:', 'noodled' ); ?></p>
			<code style="display:inline-block;padding:8px 14px;background:#f5f5f5;border-radius:4px"><?php echo esc_html( $webhook_url ); ?></code>

			<?php if ( Noodled_Plaud::is_configured() ) : ?>
			<h2 style="margin-top:30px"><?php esc_html_e( 'Plaud', 'noodled' ); ?></h2>
			<p style="color:green">&#10003; <?php
				/* translators: %s is the .env filename shown in a code tag */
				printf( esc_html__( 'Plaud token detected from %s file. Voice recordings will sync via the app toolbar.', 'noodled' ), '<code>.env</code>' );
			?></p>
			<?php endif; ?>
		<?php
	}

	/* ────────────────────────────────────────────────────────────
	   FIELD RENDERERS (kept for setup wizard)
	   ──────────────────────────────────────────────────────────── */
	public static function render_field( $args ) {
		$opts  = get_option( self::$option_key, [] );
		$key   = $args['key'];
		$value = esc_attr( $opts[ $key ] ?? '' );
		$defaults = [ 'app_path' => 'noodled', 'github_repo' => 'noodled-notes', 'github_branch' => 'main' ];
		if ( $value === '' && isset( $defaults[ $key ] ) ) $value = $defaults[ $key ];
		$type = ( $key === 'github_token' || $key === 'webhook_secret' ) ? 'password' : 'text';
		printf( '<input type="%s" name="%s[%s]" value="%s" class="regular-text" />', $type, self::$option_key, $key, $value );
	}

	/* ────────────────────────────────────────────────────────────
	   GETTERS
	   ──────────────────────────────────────────────────────────── */
	public static function get( $key = null ) {
		$opts = get_option( self::$option_key, [] );
		if ( $key ) return $opts[ $key ] ?? '';
		return $opts;
	}

	public static function get_app_path(): string {
		return get_option( self::$option_key, [] )['app_path'] ?? 'noodled';
	}

	public static function is_homepage_mode(): bool {
		return ! empty( get_option( self::$option_key, [] )['takeover_homepage'] );
	}

	public static function get_brand_name(): string {
		$name = get_option( self::$option_key, [] )['brand_name'] ?? '';
		return $name ?: 'noodled';
	}

	public static function get_brand_tagline(): string {
		$val = get_option( self::$option_key, [] )['brand_tagline'] ?? '';
		return $val ?: 'Your notes, everywhere';
	}

	public static function get_accent_color(): string {
		$val = get_option( self::$option_key, [] )['accent_color'] ?? '';
		return $val ?: '#0078d4';
	}

	public static function allow_registration(): bool {
		return ! empty( get_option( self::$option_key, [] )['allow_registration'] );
	}

	public static function require_approval(): bool {
		return ! empty( get_option( self::$option_key, [] )['require_approval'] );
	}

	/** Where new-request notifications are sent (defaults to the WP admin email). */
	public static function get_notify_email(): string {
		$val = get_option( self::$option_key, [] )['notify_email'] ?? '';
		return is_email( $val ) ? $val : get_option( 'admin_email' );
	}

	public static function get_landing_html(): string {
		return get_option( 'noodled_landing_html', '' );
	}

	public static function is_setup_complete(): bool {
		return ! empty( get_option( self::$option_key, [] )['setup_complete'] );
	}

	/* ────────────────────────────────────────────────────────────
	   SETUP WIZARD
	   ──────────────────────────────────────────────────────────── */
	public static function render_setup() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		if ( ! empty( $_POST['noodled_setup_submit'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'noodled_setup' ) ) {
			$opts = [
				'app_path'           => sanitize_text_field( $_POST['app_path'] ?? 'noodled' ),
				'takeover_homepage'  => ! empty( $_POST['takeover_homepage'] ) ? '1' : '',
				'brand_name'         => sanitize_text_field( $_POST['brand_name'] ?? '' ) ?: 'noodled',
				'brand_tagline'      => sanitize_text_field( $_POST['brand_tagline'] ?? '' ),
				'accent_color'       => sanitize_hex_color( $_POST['accent_color'] ?? '' ) ?: '#0078d4',
				'allow_registration' => ! empty( $_POST['allow_registration'] ) ? '1' : '',
				'require_approval'   => ! empty( $_POST['require_approval'] ) ? '1' : '',
				'github_owner'       => sanitize_text_field( $_POST['github_owner'] ?? '' ),
				'github_repo'        => sanitize_text_field( $_POST['github_repo'] ?? 'noodled-notes' ),
				'github_token'       => sanitize_text_field( $_POST['github_token'] ?? '' ),
				'github_branch'      => sanitize_text_field( $_POST['github_branch'] ?? 'main' ),
				'webhook_secret'     => '',
				'setup_complete'     => '1',
			];
			update_option( self::$option_key, $opts );
			delete_option( 'rewrite_rules' );
			Noodled_App::register_rewrite();
			flush_rewrite_rules();
			wp_redirect( admin_url( 'admin.php?page=noodled' ) );
			exit;
		}
		?>
		<style>
		.setup-wrap{max-width:600px;margin:40px auto;font-family:-apple-system,BlinkMacSystemFont,sans-serif}
		.setup-card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:32px;margin-bottom:24px}
		.setup-card h2{margin:0 0 4px;font-size:18px}
		.setup-card p{color:#666;font-size:13px;margin:0 0 20px}
		.setup-field{margin-bottom:16px}
		.setup-field label{display:block;font-size:13px;font-weight:500;margin-bottom:4px}
		.setup-field input[type=text],.setup-field input[type=password]{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px}
		.setup-field input[type=color]{width:50px;height:34px;padding:2px;cursor:pointer;border:1px solid #ddd;border-radius:6px}
		.setup-check{margin-bottom:12px;font-size:13px}
		.setup-check label{cursor:pointer}
		.setup-btn{background:#0078d4;color:#fff;border:none;padding:12px 32px;border-radius:8px;font-size:15px;font-weight:500;cursor:pointer}
		.setup-btn:hover{background:#006abc}
		.setup-header{text-align:center;margin-bottom:32px}
		.setup-header h1{font-size:28px;margin:0}
		.setup-header p{color:#595959;font-size:14px;margin:8px 0 0}
		.setup-step{font-size:11px;color:#6c6c6c;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}
		</style>
		<div class="setup-wrap">
			<div class="setup-header">
				<h1><?php esc_html_e( 'Welcome to Noodled', 'noodled' ); ?></h1>
				<p><?php esc_html_e( 'Let\'s get your note-taking app set up.', 'noodled' ); ?></p>
			</div>
			<form method="post">
				<?php wp_nonce_field( 'noodled_setup' ); ?>
				<input type="hidden" name="noodled_setup_submit" value="1">

				<div class="setup-card">
					<div class="setup-step"><?php esc_html_e( 'Step 1 — Branding', 'noodled' ); ?></div>
					<h2><?php esc_html_e( 'Make it yours', 'noodled' ); ?></h2>
					<p><?php esc_html_e( 'Customize the name and look. Change anytime in Noodled > Branding.', 'noodled' ); ?></p>
					<div class="setup-field"><label for="setup-brand-name"><?php esc_html_e( 'App Name', 'noodled' ); ?></label><input id="setup-brand-name" type="text" name="brand_name" value="noodled"></div>
					<div class="setup-field"><label for="setup-brand-tagline"><?php esc_html_e( 'Tagline', 'noodled' ); ?></label><input id="setup-brand-tagline" type="text" name="brand_tagline" value="<?php esc_attr_e( 'Your notes, everywhere', 'noodled' ); ?>"></div>
					<div class="setup-field"><label for="setup-accent-color"><?php esc_html_e( 'Accent Color', 'noodled' ); ?></label><input id="setup-accent-color" type="color" name="accent_color" value="#0078d4"></div>
				</div>

				<div class="setup-card">
					<div class="setup-step"><?php esc_html_e( 'Step 2 — Access', 'noodled' ); ?></div>
					<h2><?php esc_html_e( 'Where and who', 'noodled' ); ?></h2>
					<p><?php esc_html_e( 'Pick the URL and decide who can sign up.', 'noodled' ); ?></p>
					<div class="setup-field"><label for="setup-app-path"><?php esc_html_e( 'App URL Path', 'noodled' ); ?></label><input id="setup-app-path" type="text" name="app_path" value="noodled"><span style="font-size:12px;color:#595959"><?php echo esc_html( home_url( '/' ) ); ?>noodled/</span></div>
					<div class="setup-check"><label><input type="checkbox" name="takeover_homepage"> <?php esc_html_e( 'Also use as site homepage', 'noodled' ); ?></label></div>
					<div class="setup-check"><label><input type="checkbox" name="allow_registration"> <?php esc_html_e( 'Allow open registration', 'noodled' ); ?></label></div>
					<div class="setup-check"><label><input type="checkbox" name="require_approval"> <?php esc_html_e( 'Require admin approval', 'noodled' ); ?></label></div>
				</div>

				<div class="setup-card">
					<div class="setup-step"><?php esc_html_e( 'Step 3 — GitHub Sync (optional)', 'noodled' ); ?></div>
					<h2><?php esc_html_e( 'Connect to desktop', 'noodled' ); ?></h2>
					<p><?php esc_html_e( 'Sync notes with a GitHub repo. Skip this and set it up later in Noodled > Sync.', 'noodled' ); ?></p>
					<div class="setup-field"><label for="setup-gh-owner"><?php esc_html_e( 'Repository Owner', 'noodled' ); ?></label><input id="setup-gh-owner" type="text" name="github_owner" placeholder="your-username"></div>
					<div class="setup-field"><label for="setup-gh-repo"><?php esc_html_e( 'Repository Name', 'noodled' ); ?></label><input id="setup-gh-repo" type="text" name="github_repo" value="noodled-notes"></div>
					<div class="setup-field"><label for="setup-gh-token"><?php esc_html_e( 'Access Token', 'noodled' ); ?></label><input id="setup-gh-token" type="password" name="github_token" placeholder="ghp_..."></div>
					<div class="setup-field"><label for="setup-gh-branch"><?php esc_html_e( 'Branch', 'noodled' ); ?></label><input id="setup-gh-branch" type="text" name="github_branch" value="main"></div>
				</div>

				<div style="text-align:center">
					<button type="submit" class="setup-btn"><?php esc_html_e( 'Finish Setup', 'noodled' ); ?></button>
					<p style="margin-top:12px"><a href="<?php echo esc_url( admin_url( 'admin.php?page=noodled' ) ); ?>" style="color:#595959;font-size:12px"><?php esc_html_e( 'Skip', 'noodled' ); ?></a></p>
				</div>
			</form>
		</div>
		<?php
	}
}
