<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Admin {
	private static ?self $instance = null;
	private string $hook = '';

	private const CAPS = array(
		'site_agent_chat'           => 'Chat',
		'site_agent_inspect'        => 'Inspect',
		'site_agent_propose'        => 'Propose changes',
		'site_agent_execute_low'     => 'Execute low risk',
		'site_agent_execute_medium'  => 'Execute medium risk',
		'site_agent_execute_high'    => 'Execute high risk',
		'site_agent_rollback'        => 'Rollback',
		'site_agent_manage'          => 'Manage Site Agent',
	);

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_site_agent_save_roles', array( $this, 'save_roles' ) );
		add_action( 'admin_post_site_agent_save_settings', array( $this, 'save_settings' ) );
	}

	public function menu(): void {
		$this->hook = add_menu_page(
			__( 'Site Agent', 'site-agent' ),
			__( 'Site Agent', 'site-agent' ),
			'site_agent_chat',
			'site-agent',
			array( $this, 'render' ),
			'dashicons-admin-site-alt3',
			3
		);
	}

	public function assets( string $hook ): void {
		if ( $hook !== $this->hook ) {
			return;
		}
		wp_enqueue_style(
			'site-agent-admin',
			SITE_AGENT_URL . 'assets/admin.css',
			array(),
			SITE_AGENT_VERSION
		);
		wp_enqueue_script(
			'site-agent-admin',
			SITE_AGENT_URL . 'assets/admin.js',
			array(),
			SITE_AGENT_VERSION,
			true
		);
		wp_localize_script(
			'site-agent-admin',
			'SiteAgentAdmin',
			array(
				'restUrl'        => esc_url_raw( rest_url( 'site-agent/v1' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'currentTab'     => $this->current_tab(),
				'canManage'      => current_user_can( 'site_agent_manage' ),
				'canRollback'    => current_user_can( 'site_agent_rollback' ),
				'roles'          => Site_Agent_Agent::roles(),
				'providerReady'  => Site_Agent_OpenAI_Client::is_configured(),
				'strings'        => array(
					'working' => __( 'Working…', 'site-agent' ),
					'error'   => __( 'Site Agent could not complete the request.', 'site-agent' ),
					'confirmHigh' => __( 'This plan contains a high-risk action. Approve and execute the exact plan shown?', 'site-agent' ),
					'complete'=> __( 'Completed.', 'site-agent' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'site_agent_chat' ) ) {
			wp_die( esc_html__( 'You cannot use Site Agent.', 'site-agent' ) );
		}
		$tabs = $this->tabs();
		$tab  = $this->current_tab();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = array_key_first( $tabs );
		}
		?>
		<div class="wrap site-agent-wrap">
			<header class="site-agent-header">
				<div>
					<h1><?php esc_html_e( 'Site Agent', 'site-agent' ); ?></h1>
					<p><?php esc_html_e( 'Ask your site anything. Tell it what to do.', 'site-agent' ); ?></p>
				</div>
				<div class="site-agent-provider <?php echo Site_Agent_OpenAI_Client::is_configured() ? 'is-ready' : 'is-local'; ?>">
					<?php
					echo esc_html(
						Site_Agent_OpenAI_Client::is_configured()
							? sprintf( __( 'OpenAI connected · %s', 'site-agent' ), Site_Agent_OpenAI_Client::model() )
							: __( 'Local retrieval only', 'site-agent' )
					);
					?>
				</div>
			</header>

			<nav class="nav-tab-wrapper site-agent-tabs" aria-label="<?php esc_attr_e( 'Site Agent sections', 'site-agent' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=site-agent&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<main class="site-agent-main">
				<?php
				switch ( $tab ) {
					case 'knowledge':
						$this->render_knowledge();
						break;
					case 'changes':
						$this->render_changes();
						break;
					case 'diagnostics':
						$this->render_diagnostics();
						break;
					case 'roles':
						$this->render_roles();
						break;
					case 'settings':
						$this->render_settings();
						break;
					default:
						$this->render_chat();
				}
				?>
			</main>
		</div>
		<?php
	}

	private function render_chat(): void {
		?>
		<section class="site-agent-panel site-agent-chat-panel">
			<div class="site-agent-chat-toolbar">
				<label for="site-agent-role"><?php esc_html_e( 'Agent role', 'site-agent' ); ?></label>
				<select id="site-agent-role">
					<?php foreach ( Site_Agent_Agent::roles() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button" id="site-agent-new-chat"><?php esc_html_e( 'New conversation', 'site-agent' ); ?></button>
			</div>
			<div id="site-agent-chat" class="site-agent-chat" aria-live="polite">
				<div class="site-agent-message is-agent">
					<div class="site-agent-message-label"><?php esc_html_e( 'Site Agent', 'site-agent' ); ?></div>
					<div class="site-agent-message-body"><?php esc_html_e( 'Ask what changed, why something exists, what may break, how the site is configured, or tell me a specific result you want.', 'site-agent' ); ?></div>
				</div>
			</div>
			<div id="site-agent-proposals"></div>
			<form id="site-agent-chat-form" class="site-agent-chat-form">
				<label class="screen-reader-text" for="site-agent-prompt"><?php esc_html_e( 'Question or instruction', 'site-agent' ); ?></label>
				<textarea id="site-agent-prompt" rows="4" maxlength="12000" placeholder="<?php esc_attr_e( 'What changed on the homepage this week?', 'site-agent' ); ?>"></textarea>
				<div>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Ask Site Agent', 'site-agent' ); ?></button>
					<span id="site-agent-chat-status" class="site-agent-status"></span>
				</div>
			</form>
		</section>
		<?php
	}

	private function render_knowledge(): void {
		$stats = Site_Agent_Indexer::stats();
		?>
		<section class="site-agent-grid">
			<div class="site-agent-panel">
				<h2><?php esc_html_e( 'Local knowledge index', 'site-agent' ); ?></h2>
				<p><?php esc_html_e( 'Content, plugin metadata, builders, forms, cron, roles, safe settings, and database inventory are indexed locally. Credentials, submissions, user records, source code, and secret-like values are excluded.', 'site-agent' ); ?></p>
				<div class="site-agent-metrics">
					<div><strong id="site-agent-index-total"><?php echo esc_html( number_format_i18n( (int) $stats['total'] ) ); ?></strong><span><?php esc_html_e( 'indexed records', 'site-agent' ); ?></span></div>
					<div><strong><?php echo esc_html( $stats['last_completed_gmt'] ?: '—' ); ?></strong><span><?php esc_html_e( 'last completed (GMT)', 'site-agent' ); ?></span></div>
				</div>
				<?php if ( current_user_can( 'site_agent_manage' ) ) : ?>
					<button type="button" class="button button-primary" id="site-agent-rebuild-index"><?php esc_html_e( 'Rebuild knowledge index', 'site-agent' ); ?></button>
					<span id="site-agent-index-status" class="site-agent-status"></span>
					<progress id="site-agent-index-progress" value="0" max="1" hidden></progress>
				<?php endif; ?>
			</div>
			<div class="site-agent-panel">
				<h2><?php esc_html_e( 'Search the evidence', 'site-agent' ); ?></h2>
				<form id="site-agent-search-form" class="site-agent-inline-form">
					<input type="search" id="site-agent-search-query" placeholder="<?php esc_attr_e( 'Elementor homepage, Gravity Forms, cron…', 'site-agent' ); ?>">
					<button class="button" type="submit"><?php esc_html_e( 'Search', 'site-agent' ); ?></button>
				</form>
				<div id="site-agent-search-results"></div>
			</div>
			<div class="site-agent-panel site-agent-wide">
				<h2><?php esc_html_e( 'Plugin removal analysis', 'site-agent' ); ?></h2>
				<p><?php esc_html_e( 'Runs a bounded local scan for blocks, shortcodes, stored content, custom tables, options, cron hooks, REST routes, and custom-code references. It reports evidence—not apocalypse fan fiction.', 'site-agent' ); ?></p>
				<form id="site-agent-impact-form" class="site-agent-inline-form">
					<select id="site-agent-impact-plugin">
						<?php
						require_once ABSPATH . 'wp-admin/includes/plugin.php';
						foreach ( get_plugins() as $file => $data ) :
							if ( SITE_AGENT_BASENAME === $file ) {
								continue;
							}
							?>
							<option value="<?php echo esc_attr( $file ); ?>"><?php echo esc_html( (string) $data['Name'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<button class="button" type="submit"><?php esc_html_e( 'Analyze plugin', 'site-agent' ); ?></button>
				</form>
				<div id="site-agent-impact-results"></div>
			</div>
		</section>
		<?php
	}

	private function render_changes(): void {
		?>
		<section class="site-agent-panel">
			<div class="site-agent-section-heading">
				<div>
					<h2><?php esc_html_e( 'Change Ledger', 'site-agent' ); ?></h2>
					<p><?php esc_html_e( 'Supported changes include verified before/after snapshots. Rollback is offered only when the target and snapshot are supported.', 'site-agent' ); ?></p>
				</div>
				<button class="button" type="button" id="site-agent-refresh-changes"><?php esc_html_e( 'Refresh', 'site-agent' ); ?></button>
			</div>
			<div id="site-agent-rollback-proposal"></div>
			<div class="site-agent-table-scroll">
				<table class="widefat striped" id="site-agent-changes-table">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'site-agent' ); ?></th>
						<th><?php esc_html_e( 'Action', 'site-agent' ); ?></th>
						<th><?php esc_html_e( 'Target', 'site-agent' ); ?></th>
						<th><?php esc_html_e( 'Risk', 'site-agent' ); ?></th>
						<th><?php esc_html_e( 'Source', 'site-agent' ); ?></th>
						<th><?php esc_html_e( 'Rollback', 'site-agent' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>
			<p id="site-agent-changes-status" class="site-agent-status"></p>
		</section>
		<?php
	}

	private function render_diagnostics(): void {
		?>
		<section class="site-agent-panel">
			<div class="site-agent-section-heading">
				<div>
					<h2><?php esc_html_e( 'Site diagnostics', 'site-agent' ); ?></h2>
					<p><?php esc_html_e( 'Measured WordPress, database, cron, memory, storage, and error-log evidence. Gray means WordPress cannot obtain decisive evidence—not that the site is broken.', 'site-agent' ); ?></p>
				</div>
				<button class="button button-primary" type="button" id="site-agent-run-diagnostics"><?php esc_html_e( 'Run diagnostics', 'site-agent' ); ?></button>
			</div>
			<div id="site-agent-diagnostics"></div>
		</section>
		<?php
	}

	private function render_roles(): void {
		if ( ! current_user_can( 'site_agent_manage' ) ) {
			wp_die( esc_html__( 'You cannot manage Site Agent roles.', 'site-agent' ) );
		}
		$roles = wp_roles();
		?>
		<section class="site-agent-panel">
			<h2><?php esc_html_e( 'WordPress role permissions', 'site-agent' ); ?></h2>
			<p><?php esc_html_e( 'AI roles change emphasis only. These WordPress capabilities control what a person may inspect, propose, execute, or roll back.', 'site-agent' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="site_agent_save_roles">
				<?php wp_nonce_field( 'site_agent_save_roles' ); ?>
				<div class="site-agent-table-scroll">
					<table class="widefat striped site-agent-role-table">
						<thead><tr>
							<th><?php esc_html_e( 'Role', 'site-agent' ); ?></th>
							<?php foreach ( self::CAPS as $label ) : ?>
								<th><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
							<?php foreach ( $roles->roles as $slug => $data ) : ?>
								<tr>
									<th scope="row"><?php echo esc_html( (string) $data['name'] ); ?></th>
									<?php foreach ( self::CAPS as $cap => $label ) : ?>
										<td>
											<label>
												<input
													type="checkbox"
													name="role_caps[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $cap ); ?>]"
													value="1"
													<?php checked( ! empty( $data['capabilities'][ $cap ] ) ); ?>
													<?php disabled( 'administrator' === $slug ); ?>
												>
												<span class="screen-reader-text"><?php echo esc_html( $label ); ?></span>
											</label>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php submit_button( __( 'Save role permissions', 'site-agent' ) ); ?>
			</form>
		</section>
		<?php
	}

	private function render_settings(): void {
		if ( ! current_user_can( 'site_agent_manage' ) ) {
			wp_die( esc_html__( 'You cannot manage Site Agent settings.', 'site-agent' ) );
		}
		$settings = get_option( 'site_agent_settings', array() );
		?>
		<section class="site-agent-grid">
			<div class="site-agent-panel">
				<h2><?php esc_html_e( 'OpenAI connection', 'site-agent' ); ?></h2>
				<p><strong><?php echo esc_html( Site_Agent_OpenAI_Client::is_configured() ? __( 'Configured', 'site-agent' ) : __( 'Not configured', 'site-agent' ) ); ?></strong></p>
				<p><?php esc_html_e( 'Site Agent never stores an OpenAI API key in the WordPress database. Define SITE_AGENT_OPENAI_API_KEY in wp-config.php, provide it as an environment variable, or supply it through the site_agent_openai_api_key filter.', 'site-agent' ); ?></p>
				<pre>define( 'SITE_AGENT_OPENAI_API_KEY', 'your-key-here' );</pre>
			</div>
			<div class="site-agent-panel">
				<h2><?php esc_html_e( 'Privacy boundary', 'site-agent' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Authenticated wp-admin browser sessions only.', 'site-agent' ); ?></li>
					<li><?php esc_html_e( 'No public chatbot or generic prompt endpoint.', 'site-agent' ); ?></li>
					<li><?php esc_html_e( 'Secrets are excluded before indexing and redacted again before provider requests and logs.', 'site-agent' ); ?></li>
					<li><?php esc_html_e( 'Only retrieved evidence needed for the current turn is sent.', 'site-agent' ); ?></li>
					<li><?php esc_html_e( 'Model output cannot execute a write directly.', 'site-agent' ); ?></li>
				</ul>
			</div>
			<div class="site-agent-panel site-agent-wide">
				<h2><?php esc_html_e( 'Retention and provider model', 'site-agent' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="site_agent_save_settings">
					<?php wp_nonce_field( 'site_agent_save_settings' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="site-agent-model"><?php esc_html_e( 'OpenAI model', 'site-agent' ); ?></label></th>
							<td><input class="regular-text" id="site-agent-model" name="model" value="<?php echo esc_attr( (string) ( $settings['model'] ?? 'gpt-5-mini' ) ); ?>"></td>
						</tr>
						<tr>
							<th><label for="site-agent-audit-days"><?php esc_html_e( 'Audit retention', 'site-agent' ); ?></label></th>
							<td><input type="number" min="1" max="3650" id="site-agent-audit-days" name="audit_retention_days" value="<?php echo esc_attr( (int) ( $settings['audit_retention_days'] ?? 90 ) ); ?>"> <?php esc_html_e( 'days', 'site-agent' ); ?></td>
						</tr>
						<tr>
							<th><label for="site-agent-message-days"><?php esc_html_e( 'Chat retention', 'site-agent' ); ?></label></th>
							<td><input type="number" min="1" max="3650" id="site-agent-message-days" name="message_retention_days" value="<?php echo esc_attr( (int) ( $settings['message_retention_days'] ?? 30 ) ); ?>"> <?php esc_html_e( 'days', 'site-agent' ); ?></td>
						</tr>
						<tr>
							<th><label for="site-agent-ledger-days"><?php esc_html_e( 'Ledger retention', 'site-agent' ); ?></label></th>
							<td><input type="number" min="1" max="3650" id="site-agent-ledger-days" name="ledger_retention_days" value="<?php echo esc_attr( (int) ( $settings['ledger_retention_days'] ?? 365 ) ); ?>"> <?php esc_html_e( 'days', 'site-agent' ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Local chat history', 'site-agent' ); ?></th>
							<td><label><input type="checkbox" name="store_conversations" value="1" <?php checked( ! empty( $settings['store_conversations'] ) ); ?>> <?php esc_html_e( 'Store redacted conversations locally', 'site-agent' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Uninstall', 'site-agent' ); ?></th>
							<td><label><input type="checkbox" name="purge_on_uninstall" value="1" <?php checked( ! empty( $settings['purge_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Permanently delete Site Agent tables and settings on uninstall', 'site-agent' ); ?></label></td>
						</tr>
					</table>
					<?php submit_button( __( 'Save settings', 'site-agent' ) ); ?>
				</form>
			</div>
		</section>
		<?php
	}

	public function save_roles(): void {
		if ( ! current_user_can( 'site_agent_manage' ) ) {
			wp_die( esc_html__( 'You cannot manage Site Agent roles.', 'site-agent' ) );
		}
		check_admin_referer( 'site_agent_save_roles' );
		$submitted = isset( $_POST['role_caps'] ) && is_array( $_POST['role_caps'] )
			? wp_unslash( $_POST['role_caps'] )
			: array();

		foreach ( wp_roles()->roles as $slug => $data ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( self::CAPS ) as $cap ) {
				if ( 'administrator' === $slug || ! empty( $submitted[ $slug ][ $cap ] ) ) {
					$role->add_cap( $cap );
				} else {
					$role->remove_cap( $cap );
				}
			}
		}
		Site_Agent_Audit_Log::record( 'role_permissions_updated' );
		wp_safe_redirect( admin_url( 'admin.php?page=site-agent&tab=roles&updated=1' ) );
		exit;
	}

	public function save_settings(): void {
		if ( ! current_user_can( 'site_agent_manage' ) ) {
			wp_die( esc_html__( 'You cannot manage Site Agent settings.', 'site-agent' ) );
		}
		check_admin_referer( 'site_agent_save_settings' );
		$current = get_option( 'site_agent_settings', array() );
		$model = sanitize_text_field( (string) wp_unslash( $_POST['model'] ?? 'gpt-5-mini' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $model ) ) {
			$model = 'gpt-5-mini';
		}
		$settings = array(
			'audit_retention_days'   => max( 1, min( 3650, (int) ( $_POST['audit_retention_days'] ?? 90 ) ) ),
			'message_retention_days' => max( 1, min( 3650, (int) ( $_POST['message_retention_days'] ?? 30 ) ) ),
			'ledger_retention_days'  => max( 1, min( 3650, (int) ( $_POST['ledger_retention_days'] ?? 365 ) ) ),
			'store_conversations'    => empty( $_POST['store_conversations'] ) ? 0 : 1,
			'purge_on_uninstall'     => empty( $_POST['purge_on_uninstall'] ) ? 0 : 1,
			'model'                  => $model,
			'index_batch_size'       => max( 20, min( 500, (int) ( $current['index_batch_size'] ?? 100 ) ) ),
		);
		update_option( 'site_agent_settings', $settings, false );
		Site_Agent_Audit_Log::record( 'settings_updated', array( 'settings' => $settings ) );
		wp_safe_redirect( admin_url( 'admin.php?page=site-agent&tab=settings&updated=1' ) );
		exit;
	}

	private function tabs(): array {
		$tabs = array( 'chat' => __( 'Chat', 'site-agent' ) );
		if ( current_user_can( 'site_agent_inspect' ) ) {
			$tabs['knowledge']   = __( 'Knowledge', 'site-agent' );
			$tabs['changes']     = __( 'Changes', 'site-agent' );
			$tabs['diagnostics'] = __( 'Diagnostics', 'site-agent' );
		}
		if ( current_user_can( 'site_agent_manage' ) ) {
			$tabs['roles']    = __( 'Roles', 'site-agent' );
			$tabs['settings'] = __( 'Settings', 'site-agent' );
		}
		return $tabs;
	}

	private function current_tab(): string {
		return isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'chat';
	}
}
