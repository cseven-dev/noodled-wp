<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Noodled_Settings {

	private static $option_key = 'noodled_settings';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
	}

	public static function add_menu() {
		add_options_page(
			'Noodled',
			'Noodled',
			'manage_options',
			'noodled-settings',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting( 'noodled', self::$option_key, [
			'type'              => 'array',
			'sanitize_callback' => [ __CLASS__, 'sanitize' ],
		] );

		add_settings_section( 'noodled_github', 'GitHub Sync', '__return_false', 'noodled' );

		$fields = [
			'github_owner'   => 'Repository Owner',
			'github_repo'    => 'Repository Name',
			'github_token'   => 'Personal Access Token',
			'github_branch'  => 'Branch',
			'webhook_secret' => 'Webhook Secret',
		];

		foreach ( $fields as $key => $label ) {
			add_settings_field( $key, $label, [ __CLASS__, 'render_field' ], 'noodled', 'noodled_github', [
				'key'   => $key,
				'label' => $label,
			] );
		}
	}

	public static function sanitize( $input ) {
		$clean = [];
		$clean['github_owner']   = sanitize_text_field( $input['github_owner'] ?? '' );
		$clean['github_repo']    = sanitize_text_field( $input['github_repo'] ?? 'noodled-notes' );
		$clean['github_token']   = sanitize_text_field( $input['github_token'] ?? '' );
		$clean['github_branch']  = sanitize_text_field( $input['github_branch'] ?? 'main' );
		$clean['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] ?? '' );
		return $clean;
	}

	public static function render_field( $args ) {
		$opts  = get_option( self::$option_key, [] );
		$key   = $args['key'];
		$value = esc_attr( $opts[ $key ] ?? '' );

		$defaults = [ 'github_repo' => 'noodled-notes', 'github_branch' => 'main' ];
		if ( $value === '' && isset( $defaults[ $key ] ) ) {
			$value = $defaults[ $key ];
		}

		$type = ( $key === 'github_token' || $key === 'webhook_secret' ) ? 'password' : 'text';
		printf(
			'<input type="%s" name="%s[%s]" value="%s" class="regular-text" />',
			$type,
			self::$option_key,
			$key,
			$value
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) return;

		$webhook_url = rest_url( 'noodled/v1/webhook/github' );
		?>
		<div class="wrap">
			<h1>Noodled Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'noodled' );
				do_settings_sections( 'noodled' );
				submit_button();
				?>
			</form>

			<hr />
			<h2>Webhook URL</h2>
			<p>Add this URL as a webhook in your GitHub repository settings (push events only):</p>
			<code><?php echo esc_html( $webhook_url ); ?></code>

			<hr />
			<h2>App URL</h2>
			<p>Access the noodled app at: <a href="<?php echo esc_url( home_url( '/noodled/' ) ); ?>" target="_blank"><?php echo esc_html( home_url( '/noodled/' ) ); ?></a></p>

			<?php
			$opts = get_option( self::$option_key, [] );
			if ( ! empty( $opts['github_owner'] ) && ! empty( $opts['github_token'] ) ) :
			?>
			<hr />
			<h2>Import from GitHub</h2>
			<p>Pull all existing notes from the GitHub repo into WordPress.</p>
			<button type="button" class="button button-secondary" id="noodled-import-btn" onclick="noodledImport()">Import Notes</button>
			<span id="noodled-import-status"></span>
			<script>
			async function noodledImport() {
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
			<?php endif; ?>

			<hr />
			<h2>Users</h2>
			<p>Invite family members to access noodled. They'll log in via magic link (email).</p>

			<table class="widefat striped" style="max-width:600px;margin-bottom:16px">
				<thead><tr><th>Email</th><th>Name</th><th>Role</th><th>Last Login</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( Noodled_Auth::get_all_users() as $u ) : ?>
					<tr>
						<td><?php echo esc_html( $u['email'] ); ?></td>
						<td><?php echo esc_html( $u['display_name'] ); ?></td>
						<td><?php echo esc_html( $u['role'] ); ?></td>
						<td><?php echo $u['last_login'] ? esc_html( $u['last_login'] ) : 'Never'; ?></td>
						<td><button class="button button-small" onclick="noodledDeleteUser(<?php echo (int) $u['id']; ?>)">Remove</button></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div style="display:flex;gap:8px;max-width:600px;margin-bottom:8px">
				<input type="email" id="invite-email" placeholder="Email" class="regular-text" style="flex:1">
				<input type="text" id="invite-name" placeholder="Name (optional)" class="regular-text" style="flex:1">
				<select id="invite-role"><option value="member">Member</option><option value="admin">Admin</option></select>
				<button type="button" class="button button-primary" onclick="noodledInvite()">Invite</button>
			</div>
			<span id="invite-status"></span>

			<hr />
			<h2>Notebook Permissions</h2>
			<p>Control which notebooks each member can read or write.</p>
			<?php
			$users     = Noodled_Auth::get_all_users();
			$notebooks = Noodled_Notebooks::get_all();
			$perms     = Noodled_Permissions::get_matrix();
			$perm_map  = [];
			foreach ( $perms as $p ) {
				$perm_map[ $p['user_id'] . '_' . $p['notebook_id'] ] = $p;
			}
			?>
			<table class="widefat striped" style="max-width:800px">
				<thead>
					<tr>
						<th>User</th>
						<?php foreach ( $notebooks as $nb ) : ?>
							<th style="text-align:center"><?php echo esc_html( $nb['name'] ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $users as $u ) : ?>
					<tr>
						<td><?php echo esc_html( $u['display_name'] ?: $u['email'] ); ?></td>
						<?php foreach ( $notebooks as $nb ) :
							$key = $u['id'] . '_' . $nb['id'];
							$cr = ! empty( $perm_map[ $key ]['can_read'] );
							$cw = ! empty( $perm_map[ $key ]['can_write'] );
						?>
							<td style="text-align:center">
								<label><input type="checkbox" <?php checked( $cr ); ?> onchange="noodledSetPerm(<?php echo (int) $u['id']; ?>,<?php echo (int) $nb['id']; ?>,'read',this.checked)"> R</label>
								<label><input type="checkbox" <?php checked( $cw ); ?> onchange="noodledSetPerm(<?php echo (int) $u['id']; ?>,<?php echo (int) $nb['id']; ?>,'write',this.checked)"> W</label>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<script>
			const _nonce = '<?php echo wp_create_nonce( 'wp_rest' ); ?>';
			const _api = '<?php echo esc_url( rest_url( 'noodled/v1' ) ); ?>';

			async function noodledInvite() {
				const email = document.getElementById('invite-email').value;
				const name = document.getElementById('invite-name').value;
				const role = document.getElementById('invite-role').value;
				const status = document.getElementById('invite-status');
				if (!email) { status.textContent = 'Email required'; return; }
				const res = await fetch(_api + '/admin/users', {
					method: 'POST',
					headers: { 'X-WP-Nonce': _nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ email, name, role })
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

			async function noodledSetPerm(userId, notebookId, type, checked) {
				await fetch(_api + '/admin/permissions', {
					method: 'POST',
					headers: { 'X-WP-Nonce': _nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify({ user_id: userId, notebook_id: notebookId, type, value: checked })
				});
			}
			</script>
		</div>
		<?php
	}

	public static function get( $key = null ) {
		$opts = get_option( self::$option_key, [] );
		if ( $key ) return $opts[ $key ] ?? '';
		return $opts;
	}
}
