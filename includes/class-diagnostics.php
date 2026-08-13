<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Diagnostics {
	public static function run(): array {
		global $wpdb;
		$items = array();

		$autoload = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(LENGTH(option_value)),0) FROM {$wpdb->options}
			WHERE autoload IN ('yes','on','auto','auto-on')"
		);
		$items[] = self::item( 'autoload', 'Autoloaded options', $autoload, 'bytes', 1048576, 3145728 );

		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options}
				WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);
		$items[] = self::item( 'expired_transients', 'Expired transients', $expired, 'count', 500, 5000 );

		$cron = _get_cron_array();
		$overdue = 0;
		$total = 0;
		foreach ( (array) $cron as $timestamp => $events ) {
			foreach ( (array) $events as $instances ) {
				$count = count( (array) $instances );
				$total += $count;
				if ( (int) $timestamp < time() - HOUR_IN_SECONDS ) {
					$overdue += $count;
				}
			}
		}
		$items[] = self::item( 'cron_overdue', 'Overdue WP-Cron events', $overdue, 'count', 1, 20, array( 'total' => $total ) );

		$action_table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $action_table ) ) === $action_table ) {
			$in_progress = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$action_table} WHERE status = 'in-progress'" );
			$overdue_actions = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$action_table} WHERE status = 'pending' AND scheduled_date_gmt < %s",
					Site_Agent_Database::utc_now()
				)
			);
			$items[] = self::item(
				'action_scheduler',
				'Action Scheduler pressure',
				max( $in_progress, $overdue_actions ),
				'count',
				10,
				25,
				array( 'in_progress' => $in_progress, 'overdue' => $overdue_actions )
			);
		} else {
			$items[] = self::unavailable( 'action_scheduler', 'Action Scheduler pressure', 'Action Scheduler is not installed.' );
		}

		$revisions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
		$items[] = self::item( 'revisions', 'Post revisions', $revisions, 'count', 1000, 10000 );

		$spam_trash = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" );
		$items[] = self::item( 'spam_trash', 'Spam and trashed comments', $spam_trash, 'count', 1000, 10000 );

		$wp_limit = wp_convert_hr_to_bytes( WP_MEMORY_LIMIT );
		$usage    = memory_get_usage( true );
		$peak     = memory_get_peak_usage( true );
		$ratio    = $wp_limit > 0 ? ( $peak / $wp_limit ) * 100 : 0;
		$items[]  = self::item(
			'php_memory',
			'Current request memory',
			round( $ratio, 1 ),
			'percent',
			65,
			85,
			array( 'usage_bytes' => $usage, 'peak_bytes' => $peak, 'limit_bytes' => $wp_limit )
		);

		$opcache = function_exists( 'opcache_get_status' ) ? opcache_get_status( false ) : false;
		$items[] = array(
			'id'       => 'opcache',
			'label'    => 'PHP OPcache',
			'status'   => $opcache ? 'green' : 'yellow',
			'value'    => (bool) $opcache,
			'unit'     => 'boolean',
			'evidence' => $opcache ? 'OPcache is active.' : 'OPcache was not detectable from PHP.',
		);

		$debug_file = defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
		if ( is_readable( $debug_file ) && is_file( $debug_file ) ) {
			$size = (int) filesize( $debug_file );
			$tail = self::safe_log_tail( $debug_file );
			$fatal = (bool) preg_match( '/PHP Fatal error|Uncaught (?:Error|Exception)|Allowed memory size exhausted/i', $tail );
			$status = $fatal || $size >= 104857600 ? 'red' : ( $size >= 10485760 ? 'yellow' : 'green' );
			$items[] = array(
				'id'       => 'debug_log',
				'label'    => 'WordPress debug log',
				'status'   => $status,
				'value'    => $size,
				'unit'     => 'bytes',
				'evidence' => $fatal ? 'Recent fatal patterns were detected. Log contents are not sent to AI.' : 'No recent fatal pattern was detected in the bounded tail.',
			);
		} else {
			$items[] = self::unavailable( 'debug_log', 'WordPress debug log', 'No readable debug log was detected.' );
		}

		$db_connections = self::db_status( 'Threads_connected' );
		$db_max         = self::db_variable( 'max_connections' );
		if ( null !== $db_connections && $db_max > 0 ) {
			$percent = ( $db_connections / $db_max ) * 100;
			$items[] = self::item(
				'db_connections',
				'Database connections',
				round( $percent, 1 ),
				'percent',
				70,
				90,
				array( 'current' => $db_connections, 'maximum' => $db_max )
			);
		} else {
			$items[] = self::unavailable( 'db_connections', 'Database connections', 'The database did not expose both current and maximum connections.' );
		}

		$running = self::db_status( 'Threads_running' );
		$items[] = null === $running
			? self::unavailable( 'queries_running', 'Queries running now', 'The database did not expose this counter.' )
			: self::item( 'queries_running', 'Queries running now', $running, 'count', 5, 20 );

		$tables = self::largest_tables();
		$summary = self::summary( $items );

		return array(
			'generated_gmt' => Site_Agent_Database::utc_now(),
			'summary'       => $summary,
			'items'         => $items,
			'largest_tables'=> $tables,
			'limitations'   => array(
				'Exact PHP worker utilization, request queues, server TTFB, traffic, and historical error rates normally require hosting telemetry.',
				'Shared-host CPU and RAM counters may include activity outside this site.',
				'These findings prioritize investigation; they do not prove causation.',
			),
		);
	}

	private static function item( string $id, string $label, float|int $value, string $unit, float $yellow, float $red, array $extra = array() ): array {
		$status = $value >= $red ? 'red' : ( $value >= $yellow ? 'yellow' : 'green' );
		return array_merge(
			array(
				'id'       => $id,
				'label'    => $label,
				'status'   => $status,
				'value'    => $value,
				'unit'     => $unit,
				'evidence' => 'Measured locally at request time.',
			),
			$extra
		);
	}

	private static function unavailable( string $id, string $label, string $why ): array {
		return array(
			'id'       => $id,
			'label'    => $label,
			'status'   => 'gray',
			'value'    => null,
			'unit'     => '',
			'evidence' => $why,
		);
	}

	private static function db_status( string $name ): ?int {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW GLOBAL STATUS LIKE %s', $name ), ARRAY_N );
		return is_array( $row ) && isset( $row[1] ) && is_numeric( $row[1] ) ? (int) $row[1] : null;
	}

	private static function db_variable( string $name ): int {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW VARIABLES LIKE %s', $name ), ARRAY_N );
		return is_array( $row ) && isset( $row[1] ) && is_numeric( $row[1] ) ? (int) $row[1] : 0;
	}

	private static function safe_log_tail( string $file ): string {
		$handle = @fopen( $file, 'rb' );
		if ( ! $handle ) {
			return '';
		}
		$size = (int) filesize( $file );
		$read = min( 65536, $size );
		if ( $size > $read ) {
			fseek( $handle, -$read, SEEK_END );
		}
		$data = (string) fread( $handle, $read );
		fclose( $handle );
		return Site_Agent_Redactor::redact_string( $data );
	}

	private static function largest_tables(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
				FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s
				ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC LIMIT 20',
				DB_NAME
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ) use ( $wpdb ): array {
				$name = (string) $row['TABLE_NAME'];
				$bytes = (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'];
				$family = str_starts_with( $name, $wpdb->prefix ) ? substr( $name, strlen( $wpdb->prefix ) ) : $name;
				$yellow = str_contains( $family, 'options' ) ? 52428800 : ( str_contains( $family, 'posts' ) ? 524288000 : 104857600 );
				$red    = str_contains( $family, 'options' ) ? 209715200 : ( str_contains( $family, 'posts' ) ? 2147483648 : 524288000 );
				return array(
					'name'   => $family,
					'rows'   => (int) $row['TABLE_ROWS'],
					'bytes'  => $bytes,
					'status' => $bytes >= $red ? 'red' : ( $bytes >= $yellow ? 'yellow' : 'green' ),
				);
			},
			$rows
		);
	}

	private static function summary( array $items ): array {
		$counts = array( 'red' => 0, 'yellow' => 0, 'green' => 0, 'gray' => 0 );
		foreach ( $items as $item ) {
			$status = (string) ( $item['status'] ?? 'gray' );
			$counts[ $status ] = ( $counts[ $status ] ?? 0 ) + 1;
		}
		return $counts;
	}
}
