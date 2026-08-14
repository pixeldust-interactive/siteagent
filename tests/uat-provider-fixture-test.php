<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/uat' );

$GLOBALS['fixture_state'] = false;
$GLOBALS['fixture_filters'] = array();

function home_url( string $path = '' ): string { return 'https://maisy.wpenginepowered.com' . $path; }
function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
function trailingslashit( string $path ): string { return rtrim( $path, '/\\' ) . '/'; }
function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
function add_action(): void {}
function add_filter( string $hook, callable $callback ): void { $GLOBALS['fixture_filters'][ $hook ] = $callback; }
function get_transient(): mixed { return $GLOBALS['fixture_state']; }
function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['fixture_state'] = $value; return true; }
function delete_transient(): bool { $GLOBALS['fixture_state'] = false; return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' ); }
function current_user_can( string $capability ): bool { return 'manage_options' === $capability; }
function rest_ensure_response( mixed $value ): WP_REST_Response { return new WP_REST_Response( $value ); }
function register_rest_route(): void {}

final class WP_REST_Server {
	public const READABLE = 'GET';
	public const CREATABLE = 'POST';
	public const DELETABLE = 'DELETE';
}
final class WP_REST_Response {
	public function __construct( public mixed $data ) {}
}
final class WP_REST_Request {
	public function __construct( private array $params ) {}
	public function get_param( string $name ): mixed { return $this->params[ $name ] ?? null; }
}
final class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

require __DIR__ . '/uat/site-agent-uat-provider-fixture.php';

$failures = 0;
$select = static function ( string $scenario ): void {
	Site_Agent_UAT_Provider_Fixture::select_scenario( new WP_REST_Request( array( 'enabled' => true, 'scenario' => $scenario ) ) );
};
$request = static function ( string $body = '{"redacted":"payload"}' ): mixed {
	return Site_Agent_UAT_Provider_Fixture::preempt_provider_request(
		false,
		array( 'body' => $body ),
		'https://api.openai.com/v1/responses'
	);
};
$state = static function (): array {
	return Site_Agent_UAT_Provider_Fixture::read_state()->data;
};
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures++;
		fwrite( STDERR, "FAIL {$message}\n" );
	} else {
		echo "PASS {$message}\n";
	}
};

$assert( isset( $GLOBALS['fixture_filters']['pre_http_request'] ), 'fixture registers provider interceptor on authorized host' );
$assert( '' === Site_Agent_UAT_Provider_Fixture::filter_key( '' ), 'dummy key is absent while disabled' );
$initial = $state();
$assert( '0.2.1' === $initial['fixture_version'], 'fixture version is reported' );
$assert( 64 === strlen( $initial['source_sha256'] ), 'fixture source SHA-256 is reported' );
$assert( 'wp-content/mu-plugins/site-agent-uat-provider-fixture.php' === $initial['install_path'], 'fixture install path is reported' );

foreach ( array( 'valid', 'fenced_json', 'surrounded_json', 'clarification', 'proposal', 'timeout' ) as $scenario ) {
	$select( $scenario );
	$result = $request();
	$current = $state();
	$assert( $current['consumed'] && 1 === $current['request_count'], "{$scenario} consumes exactly one provider request" );
	$assert( 'timeout' === $scenario ? is_wp_error( $result ) : is_array( $result ), "{$scenario} returns the expected result type" );
}

foreach ( array( 'malformed_then_valid', 'empty_exhausted', 'null_exhausted', 'missing_exhausted', 'read_tool_roundtrip' ) as $scenario ) {
	$select( $scenario );
	$first = $request();
	$middle = $state();
	$second = $request();
	$current = $state();
	$assert( ! $middle['consumed'] && 1 === $middle['request_count'], "{$scenario} remains selected after step one" );
	$assert( $current['consumed'] && 2 === $current['request_count'], "{$scenario} consumes after step two" );
	$assert( is_array( $first ) && is_array( $second ), "{$scenario} returns local HTTP envelopes" );
}

$select( 'sa16_homepage_flow' );
$sa16_first = $request();
$sa16_after_first = $state();
$assert( is_array( $sa16_first ) && ! $sa16_after_first['consumed'] && 1 === $sa16_after_first['request_count'], 'SA-16 fixture requests live homepage evidence first' );
$sa16_missing = $request( '{"validated_tool_data":[]}' );
$sa16_after_missing = $state();
$assert( is_wp_error( $sa16_missing ) && 'site_agent_uat_evidence_missing' === $sa16_missing->get_error_code(), 'SA-16 fixture fails closed without homepage evidence' );
$assert( 1 === $sa16_after_missing['request_count'] && ! $sa16_after_missing['evidence_verified'], 'SA-16 fixture keeps the evidence step pending after a failed check' );
$sa16_evidence = '{"validated_tool_data":[{"name":"site.search","result":{"page_on_front":"9","front_page_id":9,"active_theme":"Twenty Twenty-Five","title":"WP Maisy"}},{"name":"content.get","result":{"id":9,"title":"WP Maisy"}}]}';
$sa16_second = $request( $sa16_evidence );
$sa16_after_second = $state();
$assert( is_array( $sa16_second ) && 2 === $sa16_after_second['request_count'] && $sa16_after_second['evidence_verified'] && ! $sa16_after_second['consumed'], 'SA-16 fixture verifies live evidence before clarification' );
$sa16_wrong_goal = $request( '{"question":"Change everything."}' );
$sa16_after_wrong_goal = $state();
$assert( is_wp_error( $sa16_wrong_goal ) && 'site_agent_uat_evidence_missing' === $sa16_wrong_goal->get_error_code(), 'SA-16 fixture rejects an unapproved content goal' );
$assert( 2 === $sa16_after_wrong_goal['request_count'] && ! $sa16_after_wrong_goal['consumed'], 'SA-16 fixture keeps the proposal step pending after a failed goal check' );
$sa16_goal = '{"question":"Help visitors understand the eight focused WordPress tools and choose the right next step."}';
$sa16_third = $request( $sa16_goal );
$sa16_after_third = $state();
$assert( is_array( $sa16_third ) && $sa16_after_third['consumed'] && 3 === $sa16_after_third['request_count'], 'SA-16 fixture consumes after the guidance and draft-proposal path' );
$sa16_second_body = json_decode( (string) ( $sa16_second['body'] ?? '' ), true );
$sa16_second_text = (string) ( $sa16_second_body['output'][0]['content'][0]['text'] ?? '' );
$sa16_third_body = json_decode( (string) ( $sa16_third['body'] ?? '' ), true );
$sa16_third_text = (string) ( $sa16_third_body['output'][0]['content'][0]['text'] ?? '' );
$assert( 1 === substr_count( $sa16_second_text, '?' ) && str_contains( $sa16_second_text, 'What should the new section help visitors understand or do?' ), 'SA-16 fixture asks one plain-language content question' );
$assert( str_contains( $sa16_second_text, '"answer":"I found the configured homepage and its editing system."' ), 'SA-16 clarification includes the non-empty provider answer required after live reads' );
$assert( str_contains( $sa16_third_text, '"post_status":"draft"' ) && str_contains( $sa16_third_text, 'Nothing changes on the homepage' ), 'SA-16 fixture returns a reversible draft-only review proposal' );

$select( 'valid' );
$assert( str_starts_with( Site_Agent_UAT_Provider_Fixture::filter_key( '' ), 'sk-test-' ), 'enabled fixture exposes only a dummy key' );
$blocked = Site_Agent_UAT_Provider_Fixture::preempt_provider_request( false, array(), 'https://api.openai.com/v1/unknown' );
$assert( is_wp_error( $blocked ) && 'site_agent_uat_provider_blocked' === $blocked->get_error_code(), 'unknown provider endpoints fail closed' );
$unrelated = Site_Agent_UAT_Provider_Fixture::preempt_provider_request( false, array(), 'https://example.com/' );
$assert( false === $unrelated, 'non-provider requests are not altered' );
Site_Agent_UAT_Provider_Fixture::disable();
$assert( false === $state()['enabled'], 'disable clears fixture state' );

exit( $failures > 0 ? 1 : 0 );
