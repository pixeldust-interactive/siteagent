<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Audit_Log {
	public static function record(
		string $event,
		array $details = array(),
		string $severity = 'info',
		int $actor_id = 0,
		string $prompt = ''
	): void {
		global $wpdb;
		$severity = in_array( $severity, array( 'info', 'warning', 'error', 'critical' ), true ) ? $severity : 'info';
		$actor_id = $actor_id ?: get_current_user_id();

		$wpdb->insert(
			Site_Agent_Database::table( 'audit' ),
			array(
				'actor_id'   => $actor_id,
				'event_name' => sanitize_key( $event ),
				'severity'   => $severity,
				'prompt_hash'=> $prompt ? hash( 'sha256', $prompt ) : '',
				'details'    => Site_Agent_Redactor::safe_json( $details ),
				'created_gmt'=> Site_Agent_Database::utc_now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = Site_Agent_Database::table( 'audit' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		foreach ( $rows as &$row ) {
			$row['details'] = json_decode( (string) $row['details'], true ) ?: array();
		}
		return $rows;
	}
}
