<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Approval_Service {
	private const TTL = 600;

	public static function issue( array $plan ): array|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! current_user_can( 'site_agent_propose' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot create action approvals.', 'site-agent' ) );
		}
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > 262144 ) {
			return new WP_Error( 'plan_too_large', __( 'The action plan is too large to approve safely.', 'site-agent' ) );
		}

		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$hash  = hash( 'sha256', $token );
		$plan_hash = hash( 'sha256', $json );
		$now   = Site_Agent_Database::utc_now();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TTL );

		global $wpdb;
		$ok = $wpdb->insert(
			Site_Agent_Database::table( 'approvals' ),
			array(
				'token_hash'  => $hash,
				'user_id'     => $user_id,
				'plan_hash'   => $plan_hash,
				'plan_payload'=> $json,
				'expires_gmt' => $expires,
				'used_gmt'    => null,
				'created_gmt' => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'approval_store_failed', __( 'The approval token could not be stored.', 'site-agent' ) );
		}

		return array(
			'approval_token' => $token,
			'plan_hash'      => $plan_hash,
			'expires_gmt'    => $expires,
		);
	}

	public static function consume( string $token, string $expected_plan_hash = '' ): array|WP_Error {
		$user_id = get_current_user_id();
		if ( ! $user_id || strlen( $token ) < 32 ) {
			return new WP_Error( 'invalid_approval', __( 'The approval token is invalid.', 'site-agent' ) );
		}
		$hash = hash( 'sha256', $token );
		global $wpdb;
		$table = Site_Agent_Database::table( 'approvals' );
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s AND user_id = %d LIMIT 1",
				$hash,
				$user_id
			),
			ARRAY_A
		);
		if ( ! $row || $row['used_gmt'] || strtotime( (string) $row['expires_gmt'] . ' UTC' ) < time() ) {
			return new WP_Error( 'expired_approval', __( 'The approval token is expired, used, or does not belong to this user.', 'site-agent' ) );
		}
		if ( $expected_plan_hash && ! hash_equals( (string) $row['plan_hash'], $expected_plan_hash ) ) {
			return new WP_Error( 'plan_mismatch', __( 'The approved plan does not match the submitted plan.', 'site-agent' ) );
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET used_gmt = %s WHERE id = %d AND used_gmt IS NULL AND expires_gmt >= %s",
				Site_Agent_Database::utc_now(),
				(int) $row['id'],
				Site_Agent_Database::utc_now()
			)
		);
		if ( 1 !== $updated ) {
			return new WP_Error( 'approval_already_used', __( 'This approval was already consumed.', 'site-agent' ) );
		}

		$plan = json_decode( (string) $row['plan_payload'], true );
		if ( ! is_array( $plan ) ) {
			return new WP_Error( 'invalid_plan', __( 'The approved plan could not be decoded.', 'site-agent' ) );
		}
		$json = wp_json_encode( $plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || ! hash_equals( (string) $row['plan_hash'], hash( 'sha256', $json ) ) ) {
			return new WP_Error( 'plan_integrity', __( 'The approved plan failed its integrity check.', 'site-agent' ) );
		}
		return $plan;
	}

	public static function cleanup(): void {
		global $wpdb;
		$table = Site_Agent_Database::table( 'approvals' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_gmt < %s OR (used_gmt IS NOT NULL AND used_gmt < %s)",
				Site_Agent_Database::utc_now(),
				gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
			)
		);
	}
}
