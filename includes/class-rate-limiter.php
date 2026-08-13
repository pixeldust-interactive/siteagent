<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Rate_Limiter {
	public static function allow( string $bucket, int $limit, int $window_seconds ): bool {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		$key   = 'site_agent_rate_' . md5( $user_id . '|' . $bucket );
		$state = get_transient( $key );
		$now   = time();

		if ( ! is_array( $state ) || (int) ( $state['reset'] ?? 0 ) <= $now ) {
			set_transient(
				$key,
				array( 'count' => 1, 'reset' => $now + $window_seconds ),
				$window_seconds
			);
			return true;
		}

		if ( (int) $state['count'] >= $limit ) {
			return false;
		}

		$state['count'] = (int) $state['count'] + 1;
		set_transient( $key, $state, max( 1, (int) $state['reset'] - $now ) );
		return true;
	}
}
