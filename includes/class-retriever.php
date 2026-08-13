<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Retriever {
	public static function search( string $query, int $limit = 12 ): array {
		global $wpdb;
		$limit  = max( 1, min( 30, $limit ) );
		$tokens = self::tokens( $query );
		$table  = Site_Agent_Database::table( 'index' );
		$generation = (string) get_option( 'site_agent_index_active_generation', '' );
		if ( ! $generation ) {
			return array();
		}

		if ( empty( $tokens ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE generation = %s ORDER BY modified_gmt DESC LIMIT %d", $generation, $limit ),
				ARRAY_A
			);
		} else {
			$clauses = array();
			$args    = array( $generation );
			foreach ( array_slice( $tokens, 0, 6 ) as $token ) {
				$like = '%' . $wpdb->esc_like( $token ) . '%';
				$clauses[] = '(title LIKE %s OR summary LIKE %s OR keywords LIKE %s OR object_id LIKE %s)';
				array_push( $args, $like, $like, $like, $like );
			}
			$args[] = 100;
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE generation = %s AND (" . implode( ' OR ', $clauses ) . ') ORDER BY modified_gmt DESC LIMIT %d',
					$args
				),
				ARRAY_A
			);
			usort(
				$rows,
				static function ( array $a, array $b ) use ( $tokens ): int {
					return self::score( $b, $tokens ) <=> self::score( $a, $tokens );
				}
			);
			$rows = array_slice( $rows, 0, $limit );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$cap = (string) ( $row['required_capability'] ?? 'site_agent_inspect' );
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			$summary = json_decode( (string) $row['summary'], true );
			$out[] = array(
				'id'          => (int) $row['id'],
				'type'        => (string) $row['object_type'],
				'object_id'   => (string) $row['object_id'],
				'subtype'     => (string) $row['subtype'],
				'title'       => (string) $row['title'],
				'summary'     => is_array( $summary ) ? $summary : array(),
				'modified_gmt'=> (string) $row['modified_gmt'],
			);
		}
		return $out;
	}

	public static function context( string $query, int $max_bytes = 18000 ): array {
		$items = self::search( $query, 20 );
		$out   = array();
		$bytes = 0;
		foreach ( $items as $item ) {
			$json = wp_json_encode( $item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				continue;
			}
			if ( $bytes + strlen( $json ) > $max_bytes ) {
				break;
			}
			$out[] = $item;
			$bytes += strlen( $json );
		}
		return $out;
	}

	private static function tokens( string $query ): array {
		$query = strtolower( remove_accents( wp_strip_all_tags( $query ) ) );
		$query = preg_replace( '/[^a-z0-9_\-]+/', ' ', $query ) ?? $query;
		$stop  = array( 'the', 'and', 'for', 'that', 'this', 'with', 'from', 'what', 'when', 'where', 'which', 'have', 'does', 'site', 'please', 'could', 'would' );
		$tokens = array_filter(
			array_unique( preg_split( '/\s+/', trim( $query ) ) ?: array() ),
			static fn( string $token ): bool => strlen( $token ) >= 3 && ! in_array( $token, $stop, true )
		);
		return array_values( $tokens );
	}

	private static function score( array $row, array $tokens ): int {
		$title    = strtolower( (string) $row['title'] );
		$keywords = strtolower( (string) $row['keywords'] );
		$summary  = strtolower( (string) $row['summary'] );
		$score    = 0;
		foreach ( $tokens as $token ) {
			$score += substr_count( $title, $token ) * 10;
			$score += substr_count( $keywords, $token ) * 5;
			$score += min( 3, substr_count( $summary, $token ) );
		}
		return $score;
	}
}
