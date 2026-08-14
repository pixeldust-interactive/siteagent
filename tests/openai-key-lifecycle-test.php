<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

$GLOBALS['site_agent_test_options'] = array();
$GLOBALS['site_agent_test_http_code'] = 200;

function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' ); }
function sanitize_text_field( string $text ): string { return trim( strip_tags( $text ) ); }
function apply_filters( string $hook, mixed $value ): mixed { return $value; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['site_agent_test_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['site_agent_test_options'][ $name ] = $value; return true; }
function delete_option( string $name ): bool { unset( $GLOBALS['site_agent_test_options'][ $name ] ); return true; }
function wp_salt( string $scheme = 'auth' ): string { return 'deterministic-site-agent-test-salt'; }
function wp_remote_get( string $url, array $args = array() ): array { return array( 'response' => array( 'code' => $GLOBALS['site_agent_test_http_code'] ) ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }

final class WP_Error {
	public function __construct( private string $code, private string $message, mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return null; }
}

final class Site_Agent_Redactor {
	public static function redact_string( string $text ): string { return $text; }
	public static function redact( mixed $value ): mixed { return $value; }
}

require dirname( __DIR__ ) . '/includes/class-openai-client.php';

$failures = 0;

function check( bool $condition, string $name ): void {
	global $failures;
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL {$name}" . PHP_EOL );
		return;
	}
	echo "PASS {$name}" . PHP_EOL;
}

function fake_key( string $suffix ): string {
	return 'sk-' . str_repeat( $suffix, 12 );
}

check( ! Site_Agent_OpenAI_Client::is_configured(), 'missing key is not configured' );
check( 'none' === Site_Agent_OpenAI_Client::key_source(), 'missing key reports no source' );

$invalid = Site_Agent_OpenAI_Client::save_key( 'not-a-key' );
check( is_wp_error( $invalid ) && 'invalid_api_key_format' === $invalid->get_error_code(), 'invalid key format is rejected' );
check( ! Site_Agent_OpenAI_Client::is_configured(), 'invalid key is not stored' );

$first = fake_key( 'a1' );
$GLOBALS['site_agent_test_http_code'] = 200;
$saved = Site_Agent_OpenAI_Client::save_key( $first );
check( true === $saved, 'valid key is accepted' );
check( Site_Agent_OpenAI_Client::is_configured(), 'valid key becomes configured' );
check( 'wordpress_encrypted' === Site_Agent_OpenAI_Client::key_source(), 'valid key reports encrypted WordPress source' );
check( $first === Site_Agent_OpenAI_Client::api_key(), 'encrypted key round trips' );
check( $first !== get_option( 'site_agent_openai_credential', '' ), 'stored value is not plaintext' );

$rejected = fake_key( 'b2' );
$GLOBALS['site_agent_test_http_code'] = 401;
$replacement = Site_Agent_OpenAI_Client::save_key( $rejected );
check( is_wp_error( $replacement ) && 'provider_auth_failed' === $replacement->get_error_code(), 'rejected replacement reports provider authentication failure' );
check( $first === Site_Agent_OpenAI_Client::api_key(), 'rejected replacement preserves the existing credential' );

$second = fake_key( 'c3' );
$GLOBALS['site_agent_test_http_code'] = 200;
$replacement = Site_Agent_OpenAI_Client::save_key( $second );
check( true === $replacement, 'valid replacement is accepted' );
check( $second === Site_Agent_OpenAI_Client::api_key(), 'valid replacement becomes active' );

Site_Agent_OpenAI_Client::remove_key();
check( ! Site_Agent_OpenAI_Client::is_configured(), 'removed key is no longer configured' );
check( 'none' === Site_Agent_OpenAI_Client::key_source(), 'removed key reports no source' );

exit( $failures > 0 ? 1 : 0 );
