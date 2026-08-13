<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Plugin {
	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'loaded' ) );
	}

	public function loaded(): void {
		Site_Agent_Database::maybe_upgrade();

		Site_Agent_Ledger::instance()->register_hooks();
		Site_Agent_Rest_Controller::instance()->register();
		Site_Agent_Admin::instance()->register();
		Site_Agent_Retention::register();

		add_action(
			'wp_initialize_site',
			static function ( WP_Site $site ): void {
				if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				if ( is_plugin_active_for_network( SITE_AGENT_BASENAME ) ) {
					switch_to_blog( (int) $site->blog_id );
					Site_Agent_Database::install();
					Site_Agent_Activator::add_capabilities();
					restore_current_blog();
				}
			}
		);
	}
}
