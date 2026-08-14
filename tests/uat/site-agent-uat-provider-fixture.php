<?php
/**
 * Plugin Name: Site Agent UAT Provider Fixture
 * Description: Host-locked, no-network provider fault injection for Site Agent UAT.
 * Version: 0.2.1
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
				);
				if ( ! hash_equals( $source_hash, $current_hash ) && ! in_array( strtolower( $current_hash ), $allowed_previous, true ) ) {
					wp_die( esc_html__( 'A different Site Agent UAT fixture already exists. Nothing was overwritten.', 'site-agent' ) );
				}
			}
			if ( ! wp_mkdir_p( dirname( $site_agent_uat_mu_target ) ) || ! {ÚÚ$z{-®éÜj×n',
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
		'timeout',
	);

	public static function boot(): void {
		if ( ! self::authorized_host() ) {
			return;
		}

		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_filter( 'site_agent_openai_api_key', array( self::class, 'filter_key' ), PHP_INT_MAX );
		add_filter( 'pre_http_request', array( self::class, 'preempt_provider_request' ), PHP_INT_MIN, 3 );
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
			'enabled'       => true,
			'scenario'      => $scenario,
			'last_scenario' => '',
			'consumed'      => false,
			'request_count'    => 0,
			'evidence_verified' => false,
			'selected_gmt'     => gmdate( 'c' ),
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
			'scenario'    => $scenario,
			'step'        => $step,
			'outcome'     => is_wp_error( $result ) ? $result->get_error_code() : 'http_200',
			'payload_sha' => hash( 'sha256', (string) ( $args['body'] ?? '' ) ),
		);
		$state['events'] = array_slice( $state['events'], -20 );

		if ( ! $evidence_error && self::is_terminal_step( $scenario, $step ) ) {
			$state['last_scenario'] = $scenario;
			$state['scenario'] = '';
			$state['consumed'] = true;
			$state['consumed_gmt'] = gmdate( 'c' );
		}
		set_transient( self::TRANSIENT, $state, self::TTL );

		return $result;
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
			case 'timeout':
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after the fixture safe window.' );
		}

		return new WP_Error( 'site_agent_uat_invalid_scenario', 'The selected fixture scenario is unavailable.' );
	}

	private static function is_terminal_step( string $scenario, int $step ): bool {
		if ( 'sa16_homepage_flow' === $scenario ) {
			return $step >= 3;
		}
		$two_step = array( 'malformed_then_valid', 'empty_exhausted', 'null_exhausted', 'missing_exhausted', 'read_tool_roundtrip' );
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
			'enabled'       => false,
			'scenario'      => '',
			'last_scenario' => '',
			'consumed'      => false,
			'request_count'    => 0,
			'evidence_verified' => false,
			'selected_gmt'     => '',
			'consumed_gmt'     => '',
			'events'           => array(),
		);
	}

	private static function public_state( array $state ): array {
		return array(
			'fixture_version'   => '0.2.1',
			'source_sha256'     => hash_file( 'sha256', __FILE__ ),
			'install_path'      => 'wp-content/mu-plugins/site-agent-uat-provider-fixture.php',
			'authorized_host'   => self::authorized_host(),
			'enabled'           => (bool) $state['enabled'],
			'scenario'          => (string) $state['scenario'],
			'last_scenario'     => (string) $state['last_scenario'],
			'consumed'          => (bool) $state['consumed'],
			'request_count'     => (int) $state['request_count'],
			'evidence_verified' => (bool) $state['evidence_verified'],
			'selected_gmt'      => (string) $state['selected_gmt'],
			'consumed_gmt'      => (string) $state['consumed_gmt'],
			'events'            => (array) $state['events'],
			'scenarios'         => self::SCENARIOS,
			'network_policy'    => 'All api.openai.com requests are preempted locally while enabled.',
		);
	}
}

Site_Agent_UAT_Provider_Fixture::boot();
