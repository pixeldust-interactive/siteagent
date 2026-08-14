<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WPMU_PLUGIN_DIR', __DIR__ . '/uat' );

$GLOBALS['fixture_state'] = false;
$GLOBALS['fixture_filters'] = array();
$GLOBALS['approval_plan'] = array();
$GLOBALS['audit_events'] = array();

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
function current_user_can( string $capability ): bool { return in_array( $capability, array( 'manage_options', 'site_agent_propose', 'site_agent_execute_low', 'site_agent_execute_medium', 'site_agent_execute_high' ), true ); }
function get_current_user_id(): int { return 2; }
function wp_generate_uuid4(): string { return '96060000-0000-4000-8000-000000000001'; }
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
	public function __construct( private array $params, private string $route = '' ) {}
	public function get_param( string $name ): mixed { return $this->params[ $name ] ?? null; }
	public function get_route(): string { return $this->route; }
}
final class WP_Error {
	public function __construct( private string $code, private string $message, private mixed $data = null ) {}
	public function get_error_code(): string { return $this->code; }
	public function get_error_message(): string { return $this->message; }
	public function get_error_data(): mixed { return $this->data; }
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }

final class Site_Agent_Approval_Service {
	public static function consume( string $token, string $plan_hash = '' ): array|WP_Error {
		$regular = 'fixture-approval-token-that-is-long-enough' === $token && 'fixture-plan-hash' === $plan_hash;
		$rollback = 'fixture-rollback-approval-token-long-enough' === $token && 'fixture-rollback-plan-hash' === $plan_hash;
		if ( ! $regular && ! $rollback ) {
			return new WP_Error( 'invalid_approval', 'The approval token is invalid.' );
		}
		return (array) $GLOBALS['approval_plan'];
	}
	public static function issue( array $plan ): array {
		$GLOBALS['approval_plan'] = $plan;
		return array( 'approval_token' => 'fixture-rollback-approval-token-long-enough', 'plan_hash' => 'fixture-rollback-plan-hash', 'expires_gmt' => '2099-01-01 00:00:00' );
	}
}

final class Site_Agent_Audit_Log {
	public static function record( string $event, array $details = array(), string $severity = 'info' ): void {
		$GLOBALS['audit_events'][] = compact( 'event', 'details', 'severity' );
	}
}

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
$plan = static function ( array $actions, string $risk = 'medium' ): array {
	return array(
		'version' => 1,
		'plan_id' => '96060000-0000-4000-8000-000000000002',
		'created_gmt' => '2026-08-14 00:00:00',
		'created_by' => 2,
		'reason' => 'SA-6 deterministic no-write UAT.',
		'highest_risk' => $risk,
		'actions' => array_map(
			static function ( array $action, int $position ) use ( $risk ): array {
				$action['id'] = $position + 1;
				$action['risk'] = $risk;
				$action['required_capability'] = 'site_agent_execute_' . $risk;
				$action['preview'] = 'Fixture preview.';
				return $action;
			},
			$actions,
			array_keys( $actions )
		),
	);
};
$execute = static function (): mixed {
	return Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest(
		false,
		null,
		new WP_REST_Request(
			array( 'approval_token' => 'fixture-approval-token-that-is-long-enough', 'plan_hash' => 'fixture-plan-hash' ),
			'/site-agent/v1/actions/execute'
		)
	);
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
$assert( isset( $GLOBALS['fixture_filters']['rest_pre_dispatch'] ), 'fixture registers fail-closed Site Agent execution interceptor' );
$assert( '' === Site_Agent_UAT_Provider_Fixture::filter_key( '' ), 'dummy key is absent while disabled' );
$initial = $state();
$assert( '0.3.0' === $initial['fixture_version'], 'fixture version is reported' );
$assert( 'disabled' === $initial['phase'] && 0 === $initial['execution_count'] && array() === $initial['last_execution'], 'disabled fixture reports an inert no-write state' );
$assert( 64 === strlen( $initial['source_sha256'] ), 'fixture source SHA-256 is reported' );
$assert( 'wp-content/mu-plugins/site-agent-uat-provider-fixture.php' === $initial['install_path'], 'fixture install path is reported' );

foreach ( array( 'valid', 'fenced_json', 'surrounded_json', 'clarification', 'timeout' ) as $scenario ) {
	$select( $scenario );
	$result = $request();
	$current = $state();
	$assert( $current['consumed'] && 1 === $current['request_count'], "{$scenario} consumes exactly one provider request" );
	$assert( 'timeout' === $scenario ? is_wp_error( $result ) : is_array( $result ), "{$scenario} returns the expected result type" );
}

$select( 'proposal' );
$proposal_first = $request();
$proposal_after_first = $state();
$proposal_first_body = json_decode( (string) ( $proposal_first['body'] ?? '' ), true );
$proposal_first_text = (string) ( $proposal_first_body['output'][0]['content'][0]['text'] ?? '' );
$assert( is_array( $proposal_first ) && ! $proposal_after_first['consumed'] && 1 === $proposal_after_first['request_count'], 'proposal fixture remains selected after requesting bounded live evidence' );
$assert( str_contains( $proposal_first_text, '"read_calls":[{"name":"site.search"' ) && str_contains( $proposal_first_text, '"write_actions":[]' ), 'proposal fixture requests site.search before preparing a review card' );
$proposal_missing = $request( '{"validated_tool_data":[]}' );
$proposal_after_missing = $state();
$assert( is_wp_error( $proposal_missing ) && 'site_agent_uat_evidence_missing' === $proposal_missing->get_error_code(), 'proposal fixture fails closed without validated site.search evidence' );
$assert( ! $proposal_after_missing['consumed'] && 1 === $proposal_after_missing['request_count'], 'proposal fixture keeps the terminal step pending after missing evidence' );
$proposal_evidence = '{"validated_tool_data":[{"name":"site.search","result":{"results":[{"title":"WP Maisy"}]}}]}';
$proposal_second = $request( $proposal_evidence );
$proposal_after_second = $state();
$proposal_second_body = json_decode( (string) ( $proposal_second['body'] ?? '' ), true );
$proposal_second_text = (string) ( $proposal_second_body['output'][0]['content'][0]['text'] ?? '' );
$assert( is_array( $proposal_second ) && $proposal_after_second['consumed'] && 2 === $proposal_after_second['request_count'], 'proposal fixture consumes only after the post-read terminal response' );
$assert( 'proposal' === $proposal_after_second['last_scenario'], 'proposal fixture records the completed scenario' );
$assert( str_contains( $proposal_second_text, '"write_actions":[{"name":"option.update"' ) && str_contains( $proposal_second_text, 'Nothing has been executed' ), 'proposal fixture returns a review-only write action after evidence' );

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

$select( 'sa6_routine_success' );
$sa6_routine_provider = $request();
$sa6_routine_ready = $state();
$sa6_routine_body = json_decode( (string) ( $sa6_routine_provider['body'] ?? '' ), true );
$sa6_routine_text = (string) ( $sa6_routine_body['output'][0]['content'][0]['text'] ?? '' );
$assert( ! $sa6_routine_ready['consumed'] && 'awaiting_execution' === $sa6_routine_ready['phase'] && 1 === $sa6_routine_ready['request_count'], 'SA-6 routine scenario remains explicitly armed after proposal rendering' );
$assert( str_contains( $sa6_routine_text, 'SA-6 UAT routine success - simulated only' ) && str_contains( $sa6_routine_text, 'option.update' ), 'SA-6 routine scenario returns the exact sentinel proposal' );
$GLOBALS['approval_plan'] = $plan( array( array( 'name' => 'option.update', 'args' => array( 'option' => 'blogdescription', 'value' => 'SA-6 UAT routine success - simulated only' ) ) ) );
$sa6_routine_execution = $execute();
$sa6_routine_done = $state();
$assert( $sa6_routine_execution instanceof WP_REST_Response && true === ( $sa6_routine_execution->data['completed'] ?? false ), 'SA-6 routine execution returns the canonical successful REST shape' );
$assert( $sa6_routine_done['consumed'] && 'reversible_success' === ( $sa6_routine_done['last_execution']['outcome'] ?? '' ) && 1 === $sa6_routine_done['execution_count'], 'SA-6 routine simulator records a reversible success without a product write' );
$assert( 1 === count( $sa6_routine_done['simulated_changes'] ) && true === $sa6_routine_done['simulated_changes'][0]['reversible'], 'SA-6 routine simulator exposes one reversible read-only ledger record' );
$sa6_changes = Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest( false, null, new WP_REST_Request( array(), '/site-agent/v1/changes' ) );
$assert( $sa6_changes instanceof WP_REST_Response && 960601 === ( $sa6_changes->data['changes'][0]['id'] ?? 0 ), 'SA-6 read-only ledger replay uses the canonical changes route' );
$sa6_rollback = Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest( false, null, new WP_REST_Request( array( 'ledger_id' => 960601 ), '/site-agent/v1/rollback/propose' ) );
$sa6_rollback_ready = $state();
$assert( $sa6_rollback instanceof WP_REST_Response && 'rollback.perform' === ( $sa6_rollback->data['plan']['actions'][0]['name'] ?? '' ), 'SA-6 reversible result exposes a canonical rollback review plan' );
$assert( 'rollback_review' === $sa6_rollback_ready['phase'], 'SA-6 rollback remains review-only until a second approval' );
$sa6_rollback_blocked = Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest( false, null, new WP_REST_Request( array(), '/site-agent/v1/actions/execute' ) );
$assert( is_wp_error( $sa6_rollback_blocked ) && 'site_agent_uat_rollback_review_only' === $sa6_rollback_blocked->get_error_code(), 'SA-6 rollback remains review-only and cannot mutate WordPress' );

$select( 'sa6_partial_failure' );
$request();
$GLOBALS['approval_plan'] = $plan(
	array(
		array( 'name' => 'option.update', 'args' => array( 'option' => 'blogdescription', 'value' => 'SA-6 UAT partial step one - simulated only' ) ),
		array( 'name' => 'option.update', 'args' => array( 'option' => 'blogname', 'value' => 'SA-6 UAT partial step two - simulated only' ) ),
	)
);
$sa6_partial = $execute();
$sa6_partial_done = $state();
$assert( is_wp_error( $sa6_partial ) && 'action_failed' === $sa6_partial->get_error_code(), 'SA-6 partial-failure simulator returns the canonical action_failed error' );
$assert( 'partial_failure' === ( $sa6_partial_done['last_execution']['outcome'] ?? '' ) && 2 === count( (array) ( $sa6_partial->get_error_data()['results'] ?? array() ) ), 'SA-6 partial failure preserves completed and failed step details' );

$select( 'sa6_nonreversible_success' );
$request();
$GLOBALS['approval_plan'] = $plan( array( array( 'name' => 'transients.delete_expired', 'args' => array() ) ) );
$sa6_nonreversible = $execute();
$sa6_nonreversible_done = $state();
$assert( $sa6_nonreversible instanceof WP_REST_Response && 'nonreversible_success' === ( $sa6_nonreversible_done['last_execution']['outcome'] ?? '' ), 'SA-6 non-reversible simulator completes without deleting transient data' );
$assert( false === $sa6_nonreversible_done['simulated_changes'][0]['reversible'] && 0 === ( $sa6_nonreversible->data['results'][0]['result']['ledger_id'] ?? -1 ), 'SA-6 non-reversible result exposes no rollback ledger id' );
$sa6_no_rollback = Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest( false, null, new WP_REST_Request( array( 'ledger_id' => 960602 ), '/site-agent/v1/rollback/propose' ) );
$assert( is_wp_error( $sa6_no_rollback ) && 'site_agent_uat_rollback_not_supported' === $sa6_no_rollback->get_error_code(), 'SA-6 non-reversible result fails closed when rollback is requested' );

$select( 'sa6_high_risk' );
$sa6_high_provider = $request();
$sa6_high_ready = $state();
$sa6_high_body = json_decode( (string) ( $sa6_high_provider['body'] ?? '' ), true );
$sa6_high_text = (string) ( $sa6_high_body['output'][0]['content'][0]['text'] ?? '' );
$assert( 'awaiting_execution' === $sa6_high_ready['phase'] && str_contains( $sa6_high_text, '"post_status":"publish"' ), 'SA-6 high-risk scenario renders a publish-risk proposal without executing it' );
$GLOBALS['approval_plan'] = $plan( array( array( 'name' => 'post.create', 'args' => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'SA-6 UAT high-risk confirmation - simulated only', 'post_content' => 'This is a no-write UAT confirmation fixture. It must never be published.' ) ) ), 'high' );
$sa6_high_blocked = $execute();
$assert( is_wp_error( $sa6_high_blocked ) && 'site_agent_uat_high_risk_review_only' === $sa6_high_blocked->get_error_code(), 'SA-6 high-risk approval is intercepted and blocked without publishing content' );

$select( 'sa6_routine_success' );
$request();
$GLOBALS['approval_plan'] = $plan( array( array( 'name' => 'option.update', 'args' => array( 'option' => 'blogname', 'value' => 'wrong sentinel' ) ) ) );
$sa6_mismatch = $execute();
$assert( is_wp_error( $sa6_mismatch ) && 'site_agent_uat_plan_mismatch' === $sa6_mismatch->get_error_code(), 'SA-6 simulator rejects any approved plan outside the selected exact sentinel' );

$source = (string) file_get_contents( __DIR__ . '/uat/site-agent-uat-provider-fixture.php' );
foreach ( array( 'update_option(', 'add_option(', 'delete_option(', 'wp_insert_post(', 'wp_update_post(', 'wp_trash_post(', 'wp_delete_post(', 'activate_plugin(' ) as $forbidden_write ) {
	$assert( ! str_contains( $source, $forbidden_write ), "fixture source contains no {$forbidden_write} WordPress mutation call" );
}

$select( 'valid' );
$assert( str_starts_with( Site_Agent_UAT_Provider_Fixture::filter_key( '' ), 'sk-test-' ), 'enabled fixture exposes only a dummy key' );
$blocked = Site_Agent_UAT_Provider_Fixture::preempt_provider_request( false, array(), 'https://api.openai.com/v1/unknown' );
$assert( is_wp_error( $blocked ) && 'site_agent_uat_provider_blocked' === $blocked->get_error_code(), 'unknown provider endpoints fail closed' );
$unrelated = Site_Agent_UAT_Provider_Fixture::preempt_provider_request( false, array(), 'https://example.com/' );
$assert( false === $unrelated, 'non-provider requests are not altered' );
Site_Agent_UAT_Provider_Fixture::disable();
$assert( false === $state()['enabled'], 'disable clears fixture state' );
$assert( false === Site_Agent_UAT_Provider_Fixture::preempt_site_agent_rest( false, null, new WP_REST_Request( array(), '/site-agent/v1/actions/execute' ) ), 'disabled fixture no longer intercepts Site Agent execution' );

exit( $failures > 0 ? 1 : 0 );
