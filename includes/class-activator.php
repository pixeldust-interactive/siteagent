<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Activator {
	public static function activate( bool $network_wide = false ): void {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( SITE_AGENT_BASENAME );
			wp_die( esc_html__( 'Site Agent requires PHP 8.0 or newer.', 'site-agent' ) );
		}

		if ( is_multisite() && $network_wide ) {
			$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::install_site();
				restore_current_blog();
			}
		} else {
			self::install_site();
		}
	}

	private static function install_site(): void {
		Site_Agent_Database::install();
		self::add_capabilities();
		add_option(
			'site_agent_settings',
			array(
				'audit_retention_days'   => 90,
				'message_retention_days' => 30,
				'ledger_retention_days'  => 365,
				'store_conversations'    => 1,
				'purge_on_uninstall'     => 0,
				'model'                  => 'gpt-5-mini',
				'index_batch_size'       => 100,
			),
			'',
			false
		);
		add_option( 'site_agent_index_active_generation', '', '', false );
		add_option( 'site_agent_index_build_generation', '', '', false );
		if ( ! wp_next_scheduled( 'site_agent_daily_retention' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'site_agent_daily_retention' );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'site_agent_daily_retention' );
	}

	public static function add_capabilities(): void {
		$all = array(
			'site_agent_chat',
			'site_agent_inspect',
			'site_agent_propose',
			'site_agent_execute_low',
			'site_agent_execute_medium',
			'site_agent_execute_high',
			'site_agent_rollback',
			'site_agent_manage',
		);

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( $all as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		$editor = get_role( 'editor' );
		if ( $editor ) {
			foreach ( array( 'site_agent_chat', 'site_agent_inspect', 'site_agent_propose', 'site_agent_execute_low' ) as $cap ) {
				$editor->add_cap( $cap );
			}
		}
	}
}
