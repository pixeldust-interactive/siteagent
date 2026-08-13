<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Retention {
	public static function register(): void {
		add_action( 'site_agent_daily_retention', array( self::class, 'run' ) );
	}

	public static function run(): void {
		global $wpdb;
		$settings = get_option( 'site_agent_settings', array() );
		$map = array(
			'audit'    => max( 1, (int) ( $settings['audit_retention_days'] ?? 90 ) ),
			'messages' => max( 1, (int) ( $settings['message_retention_days'] ?? 30 ) ),
			'ledger'   => max( 1, (int) ( $settings['ledger_retention_days'] ?? 365 ) ),
		);
		foreach ( $map as $table_name => $days ) {
			$table = Site_Agent_Database::table( $table_name );
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_gmt < %s", $cutoff ) );
		}
		Site_Agent_Approval_Service::cleanup();
		$locks = Site_Agent_Database::table( 'locks' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$locks} WHERE expires_gmt < %s", Site_Agent_Database::utc_now() ) );
	}
}
