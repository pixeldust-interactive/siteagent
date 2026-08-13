<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function site_agent_uninstall_site(): void {
	global $wpdb;
	$settings = get_option( 'site_agent_settings', array() );
	if ( empty( $settings['purge_on_uninstall'] ) ) {
		return;
	}

	foreach ( array( 'index', 'ledger', 'audit', 'messages', 'approvals', 'locks' ) as $suffix ) {
		$table = $wpdb->prefix . 'site_agent_' . $suffix;
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed allowlist only.
	}

	delete_option( 'site_agent_settings' );
	delete_option( 'site_agent_schema_version' );
	delete_option( 'site_agent_index_active_generation' );
	delete_option( 'site_agent_index_build_generation' );
	delete_option( 'site_agent_index_generation' ); // Remove the pre-1.1 option if present.
	delete_option( 'site_agent_index_last_completed_gmt' );
	wp_clear_scheduled_hook( 'site_agent_daily_retention' );

	foreach ( wp_roles()->roles as $slug => $data ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			continue;
		}
		foreach (
			array(
				'site_agent_chat',
				'site_agent_inspect',
				'site_agent_propose',
				'site_agent_execute_low',
				'site_agent_execute_medium',
				'site_agent_execute_high',
				'site_agent_rollback',
				'site_agent_manage',
			) as $cap
		) {
			$role->remove_cap( $cap );
		}
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( (int) $site_id );
		site_agent_uninstall_site();
		restore_current_blog();
	}
} else {
	site_agent_uninstall_site();
}
