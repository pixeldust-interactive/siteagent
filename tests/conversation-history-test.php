<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'ARRAY_A', 'ARRAY_A' );

final class WP_Error {
	public function __construct( public string $code, public string $message, public array $data = array() ) {}
}

final class Site_Agent_Database {
	public static function table( string $name ): string { return 'wp_site_agent_' . $name; }
}

final class Conversation_History_Wpdb {
	public array $queries = array();

	public function prepare( string $query, mixed ...$args ): string {
		$formatted = $query;
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
			$formatted = (string) preg_replace( '/%[ds]/', $replacement, $formatted, 1 );
		}
		$this->queries[] = $formatted;
		return $formatted;
	}

	public function get_results( string $query, string $output ): array {
		if ( str_contains( $query, 'GROUP BY conversation_id' ) ) {
			return array(
				array(
					'conversation_id' => '11111111-1111-4111-8111-111111111111',
					'updated_gmt' => '2026-08-14 12:00:00',
					'message_count' => '2',
				),
			);
		}
		if ( str_contains( $query, "conversation_id = '11111111-1111-4111-8111-111111111111'" ) ) {
			return array(
				array( 'role' => 'user', 'content' => 'Review the homepage', 'created_gmt' => '2026-08-14 11:59:00' ),
				array( 'role' => 'assistant', 'content' => 'The homepage is available.', 'created_gmt' => '2026-08-14 12:00:00' ),
			);
		}
		return array();
	}

	public function get_var( string $query ): string { return 'Review the homepage'; }
}

$history_enabled = true;
$wpdb = new Conversation_History_Wpdb();

function __( string $text, string $domain = '' ): string { return $text; }
function get_option( string $name, array $default = array() ): array {
	global $history_enabled;
	return $history_enabled ? array( 'store_conversations' => true ) : $default;
}
function get_current_user_id(): int { return 42; }
function sanitize_text_field( string $text ): string { return trim( (string) preg_replace( '/\s+/', ' ', strip_tags( $text ) ) ); }
function wp_html_excerpt( string $text, int $count, string $more = '' ): string { return strlen( $text ) > $count ? substr( $text, 0, $count ) . $more : $text; }
function wp_is_uuid( string $uuid ): bool { return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid ); }

require dirname( __DIR__ ) . '/includes/class-agent.php';

$failures = 0;
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, 'FAIL ' . $message . PHP_EOL );
		return;
	}
	echo 'PASS ' . $message . PHP_EOL;
};

$recent = Site_Agent_Agent::recent_conversations( 99 );
$assert( true === $recent['enabled'], 'history reports enabled storage' );
$assert( 1 === count( $recent['conversations'] ), 'history returns a bounded conversation list' );
$assert( 'Review the homepage' === $recent['conversations'][0]['title'], 'history uses the first user prompt as its title' );
$assert( 2 === $recent['conversations'][0]['message_count'], 'history reports the stored message count' );
$assert( str_contains( implode( "\n", $wpdb->queries ), 'WHERE user_id = 42' ), 'history queries remain scoped to the current user' );
$assert( str_contains( implode( "\n", $wpdb->queries ), 'LIMIT 20' ), 'history limit is capped server-side' );

$conversation = Site_Agent_Agent::conversation( '11111111-1111-4111-8111-111111111111' );
$assert( is_array( $conversation ) && 2 === count( $conversation['messages'] ), 'a current-user conversation can be restored' );
$assert( 'assistant' === $conversation['messages'][1]['role'], 'stored assistant turns retain their display role' );
$assert( str_contains( implode( "\n", $wpdb->queries ), "conversation_id = '11111111-1111-4111-8111-111111111111' AND user_id = 42" ), 'conversation restoration checks both id and current user' );

$missing = Site_Agent_Agent::conversation( '22222222-2222-4222-8222-222222222222' );
$assert( $missing instanceof WP_Error && 'conversation_not_found' === $missing->code, 'another or missing conversation is not exposed' );

$history_enabled = false;
$disabled = Site_Agent_Agent::recent_conversations();
$assert( false === $disabled['enabled'] && array() === $disabled['conversations'], 'disabled storage does not expose history' );
$disabled_conversation = Site_Agent_Agent::conversation( '11111111-1111-4111-8111-111111111111' );
$assert( $disabled_conversation instanceof WP_Error && 'conversation_history_disabled' === $disabled_conversation->code, 'disabled storage blocks conversation restoration' );

exit( $failures > 0 ? 1 : 0 );
