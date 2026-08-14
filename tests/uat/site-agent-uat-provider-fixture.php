<?php
/**
 * Plugin Name: Site Agent UAT Provider Fixture
 * Description: Host-locked, no-network provider fault injection for Site Agent UAT.
 * Version: 0.3.0
 * Author: Pixeldust Interactive
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$site_agent_uat_mu_target = defined( 'WPMU_PLUGIN_DIR' )
	? trailingslashit( WPMU_PLUGIN_DIR ) . 'site-agent-uat-provider-fixture.php'
	: '';

if ( '' !== $site_agent_uat_mu_target && wp_normalize_path( __FILE__ ) !== wp_normalize_path( $site_agent_uat_mu_target ) ) {
	register_activation_hook(
		__FILE__,
		static function () use ( $site_agent_uat_mu_target ): void {
			$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
			if ( ! hash_equals( 'maisy.wpenginepowered.com', $host ) ) {
				wp_die( esc_html__( 'This UAT fixture can only be installed on the authorized Maisy test host.', 'site-agent' ) );
			}
			if ( file_exists( $site_agent_uat_mu_target ) ) {
				$current_hash = hash_file( 'sha256', $site_agent_uat_mu_target );
				$source_hash = hash_file( 'sha256', __FILE__ );
				$allowed_previous = array(
					'9ed08db9b9fa48128fb65b573b1796984c019972ec8862fe51d1d8edd3c8c622',
					'665854d2029ca70a537b344dcb3e9263a2dec068e0426884f553ff448f1ea3b8',
					'c5d6fc68b8e8224985cd5d6bd66162ab163a74c9d651a9db11910de3d9a7507c',
					'7f9e6792c82a4ee26bbb79bcbf1289bdf975c263492dedfd5001a9d4631ccd91',
					'fd706c300fef97e6060c7acf25596a218cf56192338138eced0c175509fda5b5',
				);
				if ( ! hash_equals( $source_hash, $current_hash ) && ! in_array( strtolower( $current_hash ), $allowed_previous, true ) ) {
					wp_die( esc_html__( 'A different Site Agent UAT fixture already exists. Nothing was overwritten.', 'site-agent' ) );
				}
			}
			if ( ! wp_mkdir_p( dirname( $site_agent_uat_mu_target ) ) || ! copy( __FILE__, $site_agent_uat_mu_target ) ) {
				wp_die( esc_html__( 'WordPress could not install the Site Agent UAT fixture as a must-use plugin.', 'site-agent' ) );
			}
		}
	);
	add_action(
		'activated_plugin',
		static function ( string $plugin ) use ( $site_agent_uat_mu_target ): void {
			if ( plugin_basename( __FILE__ ) === $plugin && file_exists( $site_agent_uat_mu_target ) ) {
				deactivate_plugins( $plugin, true );
			}
		}
	);
	return;
}

final class Site_Agent_UAT_Provider_Fixture {
	private const HOST = 'maisy.wpenginepowered.com';
	private const TRANSIENT = 'site_agent_uat_provider_fixture';
	private const ROUTE_NAMESPACE = 'site-agent-uat/v1';
	private const DUMMY_KEY = 'sk-test-site-agent-uat-fixture-no-network';
	private const TTL = HOUR_IN_SECONDS;
	private const SA6_ROUTINE_VALUE = 'SA-6 UAT routine success - simulated only';
	private const SA6_PARTIAL_TAGLINE = 'SA-6 UAT partial step one - simulated only';
	private const SA6_PARTIAL_TITLE = 'SA-6 UAT partial step two - simulated only';
	private const SA6_HIGH_RISK_TITLE = 'SA-6 UAT high-risk confirmation - simulated only';
	private const SA6_LEDGER_ID = 960601;

	private const SCENARIOS = array(
		'valid',
		'fenced_json',
		'surrounded_json',
		'malformed_then_valid',
		'empty_exhausted',
		'null_exhausted',
		'missing_exhausted',
		'clarification',
		'proposal',
		'read_tool_roundtrip',
		'sa16_homepage_flow',
		'sa6_routine_success',
		'sa6_partial_failure',
		'sa6_nonreversible_success',
		'sa6_high_risk',
		'timeout',
	);

	private const SA6_EXECUTION_SCENARIOS = array(
		'sa6_routine_success',
		'sa6_partial_failure',
		'sa6_nonreversible_success',
		'sa6_high_risk',
	);

	public static function boot(): void {
		if ( ! self::authorized_host() ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'site_agent_openai_api_key', array( self::class, 'filter_key' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( self::class, 'preempt_provider_request' ), PHP_INT_MIN, 3 );
		add_filter( 'rest_pre_dispatch', array( self::class, 'preempt_site_agent_rest' ), PHP_INT_MIN, 3 );
	}

	private static function authorized_host(): bool {
		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return hash_equals( self::HOST, $host );
	}

	public static function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/state',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => array( self::class, 'can_manage' ),
					'callback'            => array( self::class, 'read_state' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => array( self::class, 'can_manage' ),
					'callback'            => array( self::class, 'select_scenario' ),
					'args'                => array(
						'enabled'  => array( 'type' => 'boolean', 'required' => true ),
						'scenario' => array( 'type' => 'string', 'required' => false ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'permission_callback' => array( self::class, 'can_manage' ),
					'callback'            => array( self::class, 'disable' ),
				),
			)
		);
	}

	public static function can_manage(): bool {
		return self::authorized_host() && current_user_can( 'manage_options' );
	}

	public static function read_state(): WP_REST_Response {
		return rest_ensure_response( self::public_state( self::state() ) );
	}

	public static function select_scenario( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! (bool) $request->get_param( 'enabled' ) ) {
			return self::disable();
		}

		$scenario = sanitize_key( (string) $request->get_param( 'scenario' ) );
		if ( ! in_array( $scenario, self::SCENARIOS, true ) ) {
			return new WP_Error(
				'site_agent_uat_invalid_scenario',
				'Choose one of the fixture scenarios returned by this endpoint.',
				array( 'status' => 400, 'scenarios' => self::SCENARIOS )
			);
		}

		$state = array(
			'enabled'           => true,
			'scenario'          => $scenario,
			'last_scenario'     => '',
			'phase'             => 'provider',
			'consumed'          => false,
			'request_count'    => 0,
			'execution_count'  => 0,
			'evidence_verified' => false,
			'selected_gmt'     => gmdate( 'c' ),
			'last_execution'   => array(),
			'simulated_changes'=> array(),
			'events'           => array(),
		);
		set_transient( self::TRANSIENT, $state, self::TTL );
		return rest_ensure_response( self::public_state( $state ) );
	}

	public static function disable(): WP_REST_Response {
		delete_transient( self::TRANSIENT );
		return rest_ensure_response( self::public_state( self::empty_state() ) );
	}

	public static function filter_key( string $key ): string {
		return self::state()['enabled'] ? self::DUMMY_KEY : $key;
	}

	public static function preempt_provider_request( mixed $preempt, array $args, string $url ): mixed {
		$state = self::state();
		if ( ! $state['enabled'] ) {
			return $preempt;
		}

		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'api.openai.com' !== $host ) {
			return $preempt;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( '/v1/models' === $path ) {
			return self::http_response( array( 'object' => 'list', 'data' => array() ) );
		}
		if ( '/v1/responses' !== $path ) {
			return new WP_Error( 'site_agent_uat_provider_blocked', 'The UAT fixture blocked an unrecognized provider endpoint.' );
		}

		if ( '' === $state['scenario'] || $state['consumed'] ) {
			return new WP_Error( 'site_agent_uat_scenario_required', 'The UAT fixture is enabled but no unconsumed scenario is selected.' );
		}

		$state['request_count']++;
		$step = (int) $state['request_count'];
		$scenario = (string) $state['scenario'];
		$result = self::scenario_result( $scenario, $step, (string) ( $args['body'] ?? '' ), $state );
		$evidence_error = is_wp_error( $result ) && 'site_agent_uat_evidence_missing' === $result->get_error_code();
		if ( $evidence_error ) {
			$state['request_count'] = max( 0, $state['request_count'] - 1 );
		} elseif ( 'sa16_homepage_flow' === $scenario && 2 === $step ) {
			$state['evidence_verified'] = true;
		}
		$state['events'][] = array(
			'at_gmt'      => gmdate( 'c' ),
			'channel'     => 'provider',
			'scenario'    => $scenario,
			'step'        => $step,
			'outcome'     => is_wp_error( $result ) ? $result->get_error_code() : 'http_200',
			'payload_sha' => hash( 'sha256', (string) ( $args['body'] ?? '' ) ),
		);
		$state['events'] = array_slice( $state['events'], -20 );

		if ( ! $evidence_error && self::is_terminal_step( $scenario, $step ) ) {
			if ( in_array( $scenario, self::SA6_EXECUTION_SCENARIOS, true ) ) {
				$state['phase'] = 'awaiting_execution';
			} else {
				$state['last_scenario'] = $scenario;
				$state['scenario'] = '';
				$state['phase'] = 'consumed';
				$state['consumed'] = true;
				$state['consumed_gmt'] = gmdate( 'c' );
			}
		}
		set_transient( self::TRANSIENT, $state, self::TTL );

		return $result;
	}

	public static function preempt_site_agent_rest( mixed $preempt, mixed $server, WP_REST_Request $request ): mixed {
		$state = self::state();
		if ( ! $state['enabled'] ) {
			return $preempt;
		}

		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( ! str_starts_with( $route, '/site-agent/v1/' ) ) {
			return $preempt;
		}
		if ( ! self::can_manage() ) {
			return new WP_Error( 'site_agent_uat_forbidden', 'Only an administrator on the authorized UAT host can use the no-write simulator.', array( 'status' => 403 ) );
		}

		if ( '/site-agent/v1/actions/execute' === $route ) {
			if ( 'rollback_review' === $state['phase'] ) {
				return new WP_Error( 'site_agent_uat_rollback_review_only', 'The simulated rollback is review-only. WordPress was not changed.', array( 'status' => 409 ) );
			}
			if ( in_array( (string) $state['scenario'], self::SA6_EXECUTION_SCENARIOS, true ) && 'awaiting_execution' === $state['phase'] ) {
				return self::simulate_execution( $request, $state );
			}
			return new WP_Error( 'site_agent_uat_execution_blocked', 'The UAT fixture blocked an execution that did not match the explicitly selected no-write scenario.', array( 'status' => 409 ) );
		}

		if ( '/site-agent/v1/changes' === $route && ! empty( $state['last_execution'] ) ) {
			return rest_ensure_response( array( 'changes' => (array) $state['simulated_changes'] ) );
		}

		if ( '/site-agent/v1/rollback/propose' === $route ) {
			return self::simulate_rollback_proposal( $request, $state );
		}

		return $preempt;
	}

	private static function simulate_execution( WP_REST_Request $request, array $state ): WP_REST_Response|WP_Error {
		$plan = self::consume_approval( $request );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$scenario = (string) $state['scenario'];
		if ( ! self::plan_matches_scenario( $plan, $scenario ) ) {
			return new WP_Error( 'site_agent_uat_plan_mismatch', 'The approved plan did not exactly match the selected no-write scenario.', array( 'status' => 409 ) );
		}

		$plan_id = (string) ( $plan['plan_id'] ?? '' );
		$actions = (array) ( $plan['actions'] ?? array() );
		$state['execution_count'] = (int) $state['execution_count'] + 1;

		if ( 'sa6_high_risk' === $scenario ) {
			$state = self::finish_simulation( $state, $scenario, 'high_risk_blocked', array(), array( 'plan_id' => $plan_id ) );
			self::record_audit( 'action_plan_failed', array( 'plan_id' => $plan_id, 'failed_action' => 'post.create', 'error' => 'High-risk UAT plan intentionally blocked by the no-write simulator.', 'uat_simulated' => true ), 'error' );
			return new WP_Error( 'site_agent_uat_high_risk_review_only', 'The high-risk confirmation was accepted by the browser, but the UAT simulator blocked execution and changed nothing.', array( 'status' => 409 ) );
		}

		if ( 'sa6_partial_failure' === $scenario ) {
			$changes = array( self::simulated_change( self::SA6_LEDGER_ID, 'option.update', 'option', 'blogdescription', 'medium', true, 'The site tagline would have changed in simulated step one.' ) );
			$results = array(
				array( 'id' => 1, 'name' => 'option.update', 'result' => array( 'changed' => false, 'simulated' => true, 'summary' => 'Simulated step one passed without changing the site.', 'ledger_id' => self::SA6_LEDGER_ID, 'reversible' => true ) ),
				array( 'id' => 2, 'name' => 'option.update', 'result' => array( 'error' => 'Simulated validation failure. The site title was not changed.', 'code' => 'site_agent_uat_simulated_step_failure' ) ),
			);
			$state = self::finish_simulation( $state, $scenario, 'partial_failure', $changes, array( 'plan_id' => $plan_id, 'results' => $results ) );
			self::record_audit( 'action_plan_failed', array( 'plan_id' => $plan_id, 'failed_action' => 'option.update', 'error' => 'Simulated validation failure. No WordPress option was changed.', 'uat_simulated' => true ), 'error' );
			return new WP_Error(
				'action_failed',
				'The simulated plan stopped after step two. WordPress was not changed. Review Technical details, correct the failed step, and try the test again.',
				array( 'status' => 409, 'results' => $results, 'simulated' => true )
			);
		}

		$reversible = 'sa6_routine_success' === $scenario;
		$ledger_id = $reversible ? self::SA6_LEDGER_ID : 0;
		$summary = $reversible
			? 'The site tagline would have changed to the selected value.'
			: 'Expired temporary data would have been deleted; this maintenance action has no rollback snapshot.';
		$action = (array) ( $actions[0] ?? array() );
		$changes = array(
			self::simulated_change(
				$ledger_id ?: self::SA6_LEDGER_ID + 1,
				(string) ( $action['name'] ?? '' ),
				$reversible ? 'option' : 'maintenance',
				$reversible ? 'blogdescription' : 'expired_transients',
				(string) ( $action['risk'] ?? 'medium' ),
				$reversible,
				$summary
			),
		);
		$results = array(
			array(
				'id' => 1,
				'name' => (string) ( $action['name'] ?? '' ),
				'result' => array(
					'changed' => false,
					'simulated' => true,
					'summary' => $summary,
					'ledger_id' => $ledger_id,
					'reversible' => $reversible,
					'rollback_supported' => $reversible,
				),
			),
		);
		$state = self::finish_simulation( $state, $scenario, $reversible ? 'reversible_success' : 'nonreversible_success', $changes, array( 'plan_id' => $plan_id, 'results' => $results ) );
		self::record_audit( 'action_plan_completed', array( 'plan_id' => $plan_id, 'action_count' => 1, 'uat_simulated' => true ) );

		return rest_ensure_response( array( 'completed' => true, 'plan_id' => $plan_id, 'results' => $results, 'simulated' => true ) );
	}

	private static function simulate_rollback_proposal( WP_REST_Request $request, array $state ): WP_REST_Response|WP_Error {
		$ledger_id = max( 0, (int) $request->get_param( 'ledger_id' ) );
		$change = self::find_simulated_change( $state, $ledger_id );
		if ( ! $change || empty( $change['reversible'] ) ) {
			return new WP_Error( 'site_agent_uat_rollback_not_supported', 'The simulated result has no supported rollback.', array( 'status' => 409 ) );
		}
		if ( ! class_exists( 'Site_Agent_Approval_Service' ) ) {
			return new WP_Error( 'site_agent_uat_product_unavailable', 'Site Agent approval services are unavailable; the fixture failed closed.', array( 'status' => 503 ) );
		}

		$plan = array(
			'version' => 1,
			'plan_id' => wp_generate_uuid4(),
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'created_by' => get_current_user_id(),
			'reason' => 'UAT simulated rollback review. No WordPress mutation is possible.',
			'highest_risk' => 'high',
			'actions' => array(
				array( 'id' => 1, 'name' => 'rollback.perform', 'args' => array( 'ledger_id' => $ledger_id, 'force' => false ), 'risk' => 'high', 'required_capability' => 'site_agent_execute_high', 'preview' => sprintf( 'Roll back simulated ledger entry #%d.', $ledger_id ) ),
			),
		);
		$approval = Site_Agent_Approval_Service::issue( $plan );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}
		$state['phase'] = 'rollback_review';
		$state['events'][] = self::event( (string) $state['last_scenario'], 'rollback', 1, 'proposal_ready', wp_json_encode( array( 'ledger_id' => $ledger_id ) ) );
		$state['events'] = array_slice( $state['events'], -20 );
		set_transient( self::TRANSIENT, $state, self::TTL );
		self::record_audit( 'action_plan_proposed', array( 'plan_id' => $plan['plan_id'], 'highest_risk' => 'high', 'action_count' => 1, 'uat_simulated' => true ) );
		return rest_ensure_response( array_merge( array( 'plan' => $plan ), $approval ) );
	}

	private static function consume_approval( WP_REST_Request $request ): array|WP_Error {
		if ( ! class_exists( 'Site_Agent_Approval_Service' ) ) {
			return new WP_Error( 'site_agent_uat_product_unavailable', 'Site Agent approval services are unavailable; the fixture failed closed.', array( 'status' => 503 ) );
		}
		$plan = Site_Agent_Approval_Service::consume( (string) $request->get_param( 'approval_token' ), (string) $request->get_param( 'plan_hash' ) );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( (int) ( $plan['created_by'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'site_agent_uat_plan_owner_mismatch', 'The approved plan belongs to another user.', array( 'status' => 403 ) );
		}
		$actions = (array) ( $plan['actions'] ?? array() );
		if ( empty( $actions ) || count( $actions ) > 10 ) {
			return new WP_Error( 'site_agent_uat_invalid_plan', 'The approved UAT plan has an invalid action count.', array( 'status' => 409 ) );
		}
		foreach ( $actions as $action ) {
			$capability = (string) ( $action['required_capability'] ?? '' );
			if ( '' === $capability || ! current_user_can( $capability ) ) {
				return new WP_Error( 'site_agent_uat_capability_missing', 'The administrator cannot exercise this simulated risk level.', array( 'status' => 403 ) );
			}
		}
		return $plan;
	}

	private static function plan_matches_scenario( array $plan, string $scenario ): bool {
		$actions = array_values( (array) ( $plan['actions'] ?? array() ) );
		$names = array_map( static fn( array $action ): string => (string) ( $action['name'] ?? '' ), $actions );
		$args = array_map( static fn( array $action ): array => (array) ( $action['args'] ?? array() ), $actions );
		switch ( $scenario ) {
			case 'sa6_routine_success':
				return array( 'option.update' ) === $names && 'blogdescription' === ( $args[0]['option'] ?? '' ) && self::SA6_ROUTINE_VALUE === ( $args[0]['value'] ?? '' );
			case 'sa6_partial_failure':
				return array( 'option.update', 'option.update' ) === $names
					&& 'blogdescription' === ( $args[0]['option'] ?? '' ) && self::SA6_PARTIAL_TAGLINE === ( $args[0]['value'] ?? '' )
					&& 'blogname' === ( $args[1]['option'] ?? '' ) && self::SA6_PARTIAL_TITLE === ( $args[1]['value'] ?? '' );
			case 'sa6_nonreversible_success':
				return array( 'transients.delete_expired' ) === $names;
			case 'sa6_high_risk':
				return array( 'post.create' ) === $names
					&& 'post' === ( $args[0]['post_type'] ?? '' )
					&& 'publish' === ( $args[0]['post_status'] ?? '' )
					&& self::SA6_HIGH_RISK_TITLE === ( $args[0]['post_title'] ?? '' )
					&& 'This is a no-write UAT confirmation fixture. It must never be published.' === ( $args[0]['post_content'] ?? '' );
		}
		return false;
	}

	private static function finish_simulation( array $state, string $scenario, string $outcome, array $changes, array $details ): array {
		$state['last_scenario'] = $scenario;
		$state['scenario'] = '';
		$state['phase'] = 'executed';
		$state['consumed'] = true;
		$state['consumed_gmt'] = gmdate( 'c' );
		$state['simulated_changes'] = $changes;
		$state['last_execution'] = array_merge( array( 'outcome' => $outcome, 'simulated' => true ), $details );
		$state['events'][] = self::event( $scenario, 'execution', (int) $state['execution_count'], $outcome, wp_json_encode( $details ) );
		$state['events'] = array_slice( $state['events'], -20 );
		set_transient( self::TRANSIENT, $state, self::TTL );
		return $state;
	}

	private static function simulated_change( int $id, string $action, string $object_type, string $object_id, string $risk, bool $reversible, string $summary ): array {
		return array(
			'id' => $id,
			'actor_id' => get_current_user_id(),
			'source' => 'site-agent-uat-fixture',
			'action' => $action,
			'object_type' => $object_type,
			'object_id' => $object_id,
			'risk' => $risk,
			'reversible' => $reversible,
			'status' => 'completed',
			'rollback_of' => 0,
			'metadata' => array( 'simulated' => true, 'summary' => $summary, 'wordpress_mutated' => false ),
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function find_simulated_change( array $state, int $ledger_id ): array {
		foreach ( (array) $state['simulated_changes'] as $change ) {
			if ( (int) ( $change['id'] ?? 0 ) === $ledger_id ) {
				return (array) $change;
			}
		}
		return array();
	}

	private static function event( string $scenario, string $channel, int $step, string $outcome, string|false $payload ): array {
		return array(
			'at_gmt' => gmdate( 'c' ),
			'channel' => $channel,
			'scenario' => $scenario,
			'step' => $step,
			'outcome' => $outcome,
			'payload_sha' => hash( 'sha256', (string) $payload ),
		);
	}

	private static function record_audit( string $event, array $details, string $severity = 'info' ): void {
		if ( class_exists( 'Site_Agent_Audit_Log' ) ) {
			Site_Agent_Audit_Log::record( $event, $details, $severity );
		}
	}

	private static function scenario_result( string $scenario, int $step, string $request_body = '', array $state = array() ): array|WP_Error {
		$valid = self::turn_json( 'Fixture valid answer. Nothing was changed.' );

		switch ( $scenario ) {
			case 'valid':
				return self::response_envelope( $valid );
			case 'fenced_json':
				return self::response_envelope( "```json\n{$valid}\n```" );
			case 'surrounded_json':
				return self::response_envelope( "Fixture preface.\n{$valid}\nFixture suffix." );
			case 'malformed_then_valid':
				return self::response_envelope( 1 === $step ? '{"answer":' : self::turn_json( 'Fixture repair succeeded. Nothing was changed.' ) );
			case 'empty_exhausted':
				return self::response_envelope( self::turn_json( '' ) );
			case 'null_exhausted':
				return self::response_envelope( wp_json_encode( array( 'answer' => null, 'read_calls' => array(), 'write_actions' => array(), 'needs_clarification' => false, 'clarification_question' => '' ) ) );
			case 'missing_exhausted':
				return self::response_envelope( wp_json_encode( array( 'read_calls' => array(), 'write_actions' => array(), 'needs_clarification' => false, 'clarification_question' => '' ) ) );
			case 'clarification':
				return self::response_envelope( wp_json_encode( array( 'answer' => '', 'read_calls' => array(), 'write_actions' => array(), 'needs_clarification' => true, 'clarification_question' => 'Which page should I update?' ) ) );
			case 'proposal':
				if ( 1 === $step ) {
					return self::response_envelope(
						(string) wp_json_encode(
							array(
								'answer'                 => '',
								'read_calls'             => array(
									array(
										'name' => 'site.search',
										'args' => array(
											'query' => 'homepage Site Agent UAT proposal evidence',
											'limit' => 5,
										),
									),
								),
								'write_actions'          => array(),
								'needs_clarification'    => false,
								'clarification_question' => '',
							),
							JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
						)
					);
				}
				if ( ! self::contains_all( $request_body, array( 'validated_tool_data', 'site.search', 'result' ) ) ) {
					return new WP_Error( 'site_agent_uat_evidence_missing', 'The proposal fixture requires the bounded site.search result before preparing a review card.' );
				}
				return self::response_envelope( wp_json_encode( array( 'answer' => 'I prepared a review-only proposal. Nothing has been executed.', 'read_calls' => array(), 'write_actions' => array( array( 'name' => 'option.update', 'args' => array( 'option' => 'blogdescription', 'value' => 'UAT fixture proposal - do not execute' ) ) ), 'needs_clarification' => false, 'clarification_question' => '' ) ) );
			case 'read_tool_roundtrip':
				if ( 1 === $step ) {
					return self::response_envelope( wp_json_encode( array( 'answer' => '', 'read_calls' => array( array( 'name' => 'plugins.list', 'args' => array() ) ), 'write_actions' => array(), 'needs_clarification' => false, 'clarification_question' => '' ) ) );
				}
				return self::response_envelope( self::turn_json( 'Fixture read-tool answer completed. Nothing was changed.' ) );
			case 'sa16_homepage_flow':
				if ( 1 === $step ) {
					return self::response_envelope(
						(string) wp_json_encode(
							array(
								'answer'                 => 'I found the configured homepage and its editing system.',
								'read_calls'             => array(
									array(
										'name' => 'site.search',
										'args' => array(
											'query' => 'page_on_front WP Maisy home Twenty Twenty-Five WordPress editor',
											'limit' => 12,
										),
									),
									array( 'name' => 'content.get', 'args' => array( 'post_id' => 9 ) ),
								),
								'write_actions'          => array(),
								'needs_clarification'    => false,
								'clarification_question' => '',
							),
							JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
						)
					);
				}
				if ( 2 === $step ) {
					$required_evidence = array(
						'validated_tool_data',
						'site.search',
						'page_on_front',
						'front_page_id',
						'WP Maisy',
						'Twenty Twenty-Five',
						'content.get',
					);
					if ( ! self::contains_all( $request_body, $required_evidence ) ) {
						return new WP_Error( 'site_agent_uat_evidence_missing', 'The SA-16 fixture did not receive the required live homepage and editor evidence.' );
					}
					return self::response_envelope(
						(string) wp_json_encode(
							array(
								'answer'                 => 'I found the configured homepage and its editing system.',
								'read_calls'             => array(),
								'write_actions'          => array(),
								'needs_clarification'    => true,
								'clarification_question' => 'I found your homepage, WP Maisy. It uses the WordPress editor. What should the new section help visitors understand or do? For example: help visitors understand the eight focused tools and choose the right next step.',
							),
							JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
						)
					);
				}
				if ( empty( $state['evidence_verified'] ) || false === stripos( $request_body, 'Help visitors understand the eight focused WordPress tools and choose the right next step.' ) ) {
					return new WP_Error( 'site_agent_uat_evidence_missing', 'The SA-16 fixture requires the approved plain-language content goal before preparing a proposal.' );
				}
				return self::response_envelope(
					(string) wp_json_encode(
						array(
							'answer'                 => 'I prepared a reversible draft preview for a new section below the introduction. It explains the eight focused WordPress tools and helps visitors choose a next step. Nothing changes on the homepage unless you approve the exact preview.',
							'read_calls'             => array(),
							'write_actions'          => array(
								array(
									'name' => 'post.create',
									'args' => array(
										'post_type'    => 'page',
										'post_status'  => 'draft',
										'post_title'   => 'UAT preview - homepage tools section',
										'post_content' => '<section><h2>Find the right WordPress tool faster</h2><p>Explore eight focused tools for site administration, performance, workflow verification, search metadata, publishing, plugin-removal risk, operating knowledge, and targeted rollback.</p><p><a href="/site-agent/">Start with Site Agent</a></p></section>',
									),
								),
							),
							'needs_clarification'    => false,
							'clarification_question' => '',
						),
						JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
					)
				);
			case 'sa6_routine_success':
			case 'sa6_partial_failure':
			case 'sa6_nonreversible_success':
			case 'sa6_high_risk':
				return self::response_envelope( self::sa6_turn( $scenario ) );
			case 'timeout':
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after the fixture safe window.' );
		}

		return new WP_Error( 'site_agent_uat_invalid_scenario', 'The selected fixture scenario is unavailable.' );
	}

	private static function is_terminal_step( string $scenario, int $step ): bool {
		if ( 'sa16_homepage_flow' === $scenario ) {
			return $step >= 3;
		}
		$two_step = array( 'malformed_then_valid', 'empty_exhausted', 'null_exhausted', 'missing_exhausted', 'proposal', 'read_tool_roundtrip' );
		return ! in_array( $scenario, $two_step, true ) || $step >= 2;
	}

	private static function contains_all( string $haystack, array $needles ): bool {
		foreach ( $needles as $needle ) {
			if ( false === stripos( $haystack, (string) $needle ) ) {
				return false;
			}
		}
		return true;
	}

	private static function turn_json( string $answer ): string {
		return (string) wp_json_encode(
			array(
				'answer'                 => $answer,
				'read_calls'             => array(),
				'write_actions'          => array(),
				'needs_clarification'    => false,
				'clarification_question' => '',
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	private static function turn_with_actions( string $answer, array $actions ): string {
		return (string) wp_json_encode(
			array(
				'answer'                 => $answer,
				'read_calls'             => array(),
				'write_actions'          => $actions,
				'needs_clarification'    => false,
				'clarification_question' => '',
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	private static function sa6_turn( string $scenario ): string {
		$definitions = array(
			'sa6_routine_success' => array(
				'Routine review-only proposal. The simulator will not change WordPress.',
				array( array( 'name' => 'option.update', 'args' => array( 'option' => 'blogdescription', 'value' => self::SA6_ROUTINE_VALUE ) ) ),
			),
			'sa6_partial_failure' => array(
				'Two-step partial-failure simulation. WordPress will not be changed.',
				array(
					array( 'name' => 'option.update', 'args' => array( 'option' => 'blogdescription', 'value' => self::SA6_PARTIAL_TAGLINE ) ),
					array( 'name' => 'option.update', 'args' => array( 'option' => 'blogname', 'value' => self::SA6_PARTIAL_TITLE ) ),
				),
			),
			'sa6_nonreversible_success' => array(
				'Non-reversible maintenance simulation. No transient or WordPress data will be deleted.',
				array( array( 'name' => 'transients.delete_expired', 'args' => array() ) ),
			),
			'sa6_high_risk' => array(
				'High-risk confirmation simulation. Nothing can be published.',
				array( array( 'name' => 'post.create', 'args' => array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => self::SA6_HIGH_RISK_TITLE, 'post_content' => 'This is a no-write UAT confirmation fixture. It must never be published.' ) ) ),
			),
		);
		$definition = (array) ( $definitions[ $scenario ] ?? array( '', array() ) );
		return self::turn_with_actions( (string) $definition[0], (array) $definition[1] );
	}

	private static function response_envelope( string $text ): array {
		return self::http_response(
			array(
				'id'     => 'resp_site_agent_uat_fixture',
				'status' => 'completed',
				'output' => array(
					array(
						'type'    => 'message',
						'content' => array( array( 'type' => 'output_text', 'text' => $text ) ),
					),
				),
			)
		);
	}

	private static function http_response( array $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private static function state(): array {
		$state = get_transient( self::TRANSIENT );
		return is_array( $state ) ? array_merge( self::empty_state(), $state ) : self::empty_state();
	}

	private static function empty_state(): array {
		return array(
			'enabled'           => false,
			'scenario'          => '',
			'last_scenario'     => '',
			'phase'             => 'disabled',
			'consumed'          => false,
			'request_count'    => 0,
			'execution_count'  => 0,
			'evidence_verified' => false,
			'selected_gmt'     => '',
			'consumed_gmt'     => '',
			'last_execution'   => array(),
			'simulated_changes'=> array(),
			'events'           => array(),
		);
	}

	private static function public_state( array $state ): array {
		return array(
			'fixture_version'   => '0.3.0',
			'source_sha256'     => hash_file( 'sha256', __FILE__ ),
			'install_path'      => 'wp-content/mu-plugins/site-agent-uat-provider-fixture.php',
			'authorized_host'   => self::authorized_host(),
			'enabled'           => (bool) $state['enabled'],
			'scenario'          => (string) $state['scenario'],
			'last_scenario'     => (string) $state['last_scenario'],
			'phase'             => (string) $state['phase'],
			'consumed'          => (bool) $state['consumed'],
			'request_count'     => (int) $state['request_count'],
			'execution_count'   => (int) $state['execution_count'],
			'evidence_verified' => (bool) $state['evidence_verified'],
			'selected_gmt'      => (string) $state['selected_gmt'],
			'consumed_gmt'      => (string) $state['consumed_gmt'],
			'last_execution'    => (array) $state['last_execution'],
			'simulated_changes' => (array) $state['simulated_changes'],
			'events'            => (array) $state['events'],
			'scenarios'         => self::SCENARIOS,
			'network_policy'    => 'All api.openai.com requests are preempted locally while enabled.',
			'write_policy'      => 'All Site Agent execution requests fail closed or return deterministic simulated results while enabled; WordPress content and options are never mutated.',
		);
	}
}

Site_Agent_UAT_Provider_Fixture::boot();
