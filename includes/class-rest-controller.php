<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Rest_Controller {
	private static ?self $instance = null;
	private const NS = 'site-agent/v1';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => self::permission( 'site_agent_chat' ),
			)
		);
		register_rest_route(
			self::NS,
			'/chat',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'chat' ),
				'permission_callback' => self::permission( 'site_agent_chat' ),
			)
		);
		register_rest_route(
			self::NS,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'search' ),
				'permission_callback' => self::permission( 'site_agent_inspect' ),
			)
		);
		register_rest_route(
			self::NS,
			'/index/start',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_start' ),
				'permission_callback' => self::permission( 'site_agent_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/index/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_batch' ),
				'permission_callback' => self::permission( 'site_agent_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/diagnostics',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => self::permission( 'site_agent_inspect' ),
			)
		);
		register_rest_route(
			self::NS,
			'/changes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'changes' ),
				'permission_callback' => self::permission( 'site_agent_inspect' ),
			)
		);
		register_rest_route(
			self::NS,
			'/plugin-impact',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'plugin_impact' ),
				'permission_callback' => self::permission( 'site_agent_inspect' ),
			)
		);
		register_rest_route(
			self::NS,
			'/actions/propose',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'actions_propose' ),
				'permission_callback' => self::permission( 'site_agent_propose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/actions/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'actions_execute' ),
				'permission_callback' => self::permission( 'site_agent_propose' ),
			)
		);
		register_rest_route(
			self::NS,
			'/rollback/propose',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback_propose' ),
				'permission_callback' => self::permission( 'site_agent_rollback' ),
			)
		);
		register_rest_route(
			self::NS,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit' ),
				'permission_callback' => self::permission( 'site_agent_manage' ),
			)
		);
	}

	private static function permission( string $capability ): Closure {
		return static fn(): bool => Site_Agent_Browser_Guard::authorize( $capability );
	}

	public function status(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'version'       => SITE_AGENT_VERSION,
				'provider'      => Site_Agent_OpenAI_Client::is_configured() ? 'openai' : 'local',
				'model'         => Site_Agent_OpenAI_Client::model(),
				'key_stored'    => false,
				'index'         => Site_Agent_Indexer::stats(),
				'roles'         => Site_Agent_Agent::roles(),
				'capabilities'  => array(
					'inspect'  => current_user_can( 'site_agent_inspect' ),
					'propose'  => current_user_can( 'site_agent_propose' ),
					'low'      => current_user_can( 'site_agent_execute_low' ),
					'medium'   => current_user_can( 'site_agent_execute_medium' ),
					'high'     => current_user_can( 'site_agent_execute_high' ),
					'rollback' => current_user_can( 'site_agent_rollback' ),
					'manage'   => current_user_can( 'site_agent_manage' ),
				),
			)
		);
	}

	public function chat( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Site_Agent_Agent::chat(
			(string) $request->get_param( 'prompt' ),
			(string) $request->get_param( 'conversation_id' ),
			(string) $request->get_param( 'role' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function search( WP_REST_Request $request ): WP_REST_Response {
		$query = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$requested = (int) $request->get_param( 'limit' );
		$limit = $requested > 0 ? max( 1, min( 30, $requested ) ) : 12;
		return rest_ensure_response( array( 'results' => Site_Agent_Retriever::search( $query, $limit ) ) );
	}

	public function index_start(): WP_REST_Response {
		return rest_ensure_response( Site_Agent_Indexer::begin() );
	}

	public function index_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$state = $request->get_param( 'state' );
		if ( ! is_array( $state ) ) {
			return new WP_Error( 'invalid_index_state', __( 'The index cursor is missing.', 'site-agent' ) );
		}
		$state = array(
			'generation' => sanitize_text_field( (string) ( $state['generation'] ?? '' ) ),
			'phase'      => sanitize_key( (string) ( $state['phase'] ?? 'posts' ) ),
			'last_id'    => max( 0, (int) ( $state['last_id'] ?? 0 ) ),
			'processed'  => max( 0, (int) ( $state['processed'] ?? 0 ) ),
			'started'    => max( 0, (int) ( $state['started'] ?? time() ) ),
		);
		$result = Site_Agent_Indexer::batch( $state );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function diagnostics(): WP_REST_Response {
		if ( ! Site_Agent_Rate_Limiter::allow( 'diagnostics', 30, HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'message' => __( 'The diagnostics limit has been reached.', 'site-agent' ) ), 429 );
		}
		$result = Site_Agent_Diagnostics::run();
		Site_Agent_Audit_Log::record( 'diagnostics_run', array( 'summary' => $result['summary'] ) );
		return rest_ensure_response( $result );
	}

	public function changes( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 500, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
		return rest_ensure_response( array( 'changes' => Site_Agent_Ledger::recent( $limit ) ) );
	}

	public function plugin_impact( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! Site_Agent_Rate_Limiter::allow( 'impact', 20, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'The plugin-analysis limit has been reached.', 'site-agent' ), array( 'status' => 429 ) );
		}
		$result = Site_Agent_Plugin_Impact::analyze( (string) $request->get_param( 'plugin' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function actions_propose( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$actions = $request->get_param( 'actions' );
		if ( ! is_array( $actions ) ) {
			return new WP_Error( 'invalid_actions', __( 'No action list was provided.', 'site-agent' ) );
		}
		$result = Site_Agent_Action_Registry::propose( $actions, (string) $request->get_param( 'reason' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function actions_execute( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = Site_Agent_Action_Registry::execute_approved(
			(string) $request->get_param( 'approval_token' ),
			(string) $request->get_param( 'plan_hash' )
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function rollback_propose( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ledger_id = max( 0, (int) $request->get_param( 'ledger_id' ) );
		$force     = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$result = Site_Agent_Action_Registry::propose(
			array(
				array(
					'name' => 'rollback.perform',
					'args' => array( 'ledger_id' => $ledger_id, 'force' => $force ),
				),
			),
			'Administrator requested rollback from the local change ledger.'
		);
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response {
		$limit = max( 1, min( 500, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
		return rest_ensure_response( array( 'events' => Site_Agent_Audit_Log::recent( $limit ) ) );
	}
}
