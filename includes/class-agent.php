<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Agent {
	private const MAX_PROMPT_BYTES = 12000;
	private const ROLES = array(
		'site_administrator' => array(
			'label' => 'Site Administrator',
			'focus' => 'Prioritize overall site operation, safe administration, permissions, dependencies, and recoverability.',
		),
		'content_manager' => array(
			'label' => 'Content Manager',
			'focus' => 'Prioritize posts, pages, publishing workflow, menus, metadata, and editorial consequences.',
		),
		'seo_manager' => array(
			'label' => 'SEO Manager',
			'focus' => 'Prioritize indexable content, SEO metadata, redirects, site structure, and search visibility.',
		),
		'developer' => array(
			'label' => 'Developer',
			'focus' => 'Prioritize technical evidence, builders, plugins, hooks, cron, database state, and implementation constraints.',
		),
		'maintenance_manager' => array(
			'label' => 'Maintenance Manager',
			'focus' => 'Prioritize updates, performance evidence, scheduled work, storage, diagnostics, and recovery paths.',
		),
	);

	public static function roles(): array {
		$out = array();
		foreach ( self::ROLES as $key => $data ) {
			$out[ $key ] = $data['label'];
		}
		return $out;
	}

	public static function chat( string $prompt, string $conversation_id = '', string $role = 'site_administrator' ): array|WP_Error {
		$prompt = trim( wp_strip_all_tags( $prompt ) );
		if ( '' === $prompt || strlen( $prompt ) > self::MAX_PROMPT_BYTES ) {
			return new WP_Error( 'invalid_prompt', __( 'Enter a question or instruction under 12,000 bytes.', 'site-agent' ) );
		}
		if ( ! current_user_can( 'site_agent_chat' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot use Site Agent chat.', 'site-agent' ) );
		}
		if ( ! Site_Agent_Rate_Limiter::allow( 'chat', 30, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', __( 'The per-user hourly chat limit has been reached.', 'site-agent' ), array( 'status' => 429 ) );
		}
		$role = isset( self::ROLES[ $role ] ) ? $role : 'site_administrator';
		$conversation_id = wp_is_uuid( $conversation_id ) ? $conversation_id : wp_generate_uuid4();
		$redacted_prompt = Site_Agent_Redactor::redact_string( $prompt );
		$context = Site_Agent_Retriever::context( $redacted_prompt );
		$history = self::history( $conversation_id );

		if ( ! Site_Agent_OpenAI_Client::is_configured() ) {
			$answer = self::local_fallback( $redacted_prompt, $context );
			$ids = self::store_turn( $conversation_id, $redacted_prompt, $answer, array( 'provider' => 'local' ) );
			Site_Agent_Audit_Log::record(
				'chat_local_fallback',
				array( 'conversation_id' => $conversation_id, 'sources' => count( $context ) ),
				'info',
				get_current_user_id(),
				$redacted_prompt
			);
			return array(
				'conversation_id' => $conversation_id,
				'message_ids'     => $ids,
				'answer'          => $answer,
				'sources'         => array_slice( $context, 0, 8 ),
				'proposal'        => null,
				'provider'        => 'local',
				'notice'          => __( 'OpenAI is not configured. This answer uses local indexed evidence only.', 'site-agent' ),
			);
		}

		$system = self::system_prompt( $role );
		$turn = Site_Agent_OpenAI_Client::complete_turn( $system, $history, $redacted_prompt, $context );
		if ( is_wp_error( $turn ) ) {
			$error_data = $turn->get_error_data();
			Site_Agent_Audit_Log::record(
				'chat_provider_failed',
				array(
					'error_code'  => sanitize_key( $turn->get_error_code() ),
					'error'       => Site_Agent_Redactor::redact_string( $turn->get_error_message() ),
					'diagnostics' => is_array( $error_data ) ? Site_Agent_Redactor::redact( $error_data ) : array(),
				),
				'error',
				get_current_user_id(),
				$redacted_prompt
			);
			return $turn;
		}

		$tool_results = self::run_read_calls( (array) $turn['read_calls'] );
		if ( ! empty( $tool_results ) ) {
			$second = Site_Agent_OpenAI_Client::complete_turn( $system, $history, $redacted_prompt, $context, $tool_results );
			if ( ! is_wp_error( $second ) ) {
				$turn = $second;
			}
		}

		$proposal = null;
		if ( ! empty( $turn['write_actions'] ) ) {
			$proposal = Site_Agent_Action_Registry::propose(
				(array) $turn['write_actions'],
				$redacted_prompt
			);
			if ( is_wp_error( $proposal ) ) {
				$turn['answer'] .= "\n\nI could not create a safe action plan: " . $proposal->get_error_message();
				$proposal = null;
			}
		}

		$answer = $turn['needs_clarification'] && $turn['clarification_question']
			? $turn['clarification_question']
			: (string) $turn['answer'];
		$ids = self::store_turn(
			$conversation_id,
			$redacted_prompt,
			$answer,
			array(
				'provider'      => 'openai',
				'model'         => Site_Agent_OpenAI_Client::model(),
				'read_tools'    => array_column( $tool_results, 'name' ),
				'has_proposal'  => null !== $proposal,
			)
		);
		Site_Agent_Audit_Log::record(
			'chat_completed',
			array(
				'conversation_id' => $conversation_id,
				'role'            => $role,
				'model'           => Site_Agent_OpenAI_Client::model(),
				'read_tools'      => array_column( $tool_results, 'name' ),
				'has_proposal'    => null !== $proposal,
			),
			'info',
			get_current_user_id(),
			$redacted_prompt
		);

		return array(
			'conversation_id' => $conversation_id,
			'message_ids'     => $ids,
			'answer'          => $answer,
			'sources'         => array_slice( $context, 0, 8 ),
			'tool_results'    => $tool_results,
			'proposal'        => $proposal,
			'provider'        => 'openai',
		);
	}

	private static function run_read_calls( array $calls ): array {
		$catalog = Site_Agent_Action_Registry::catalog()['read'];
		$out = array();
		foreach ( array_slice( $calls, 0, 3 ) as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}
			$name = strtolower( trim( (string) ( $call['name'] ?? '' ) ) );
			if ( ! isset( $catalog[ $name ] ) ) {
				continue;
			}
			$args = Site_Agent_Action_Registry::canonical_args( $name, (array) ( $call['args'] ?? array() ) );
			$result = Site_Agent_Action_Registry::run_read( $name, $args );
			$out[] = array(
				'name'   => $name,
				'result' => is_wp_error( $result )
					? array( 'error' => Site_Agent_Redactor::redact_string( $result->get_error_message() ) )
					: self::bounded_result( $result ),
			);
		}
		return $out;
	}

	private static function bounded_result( mixed $result ): mixed {
		$redacted = Site_Agent_Redactor::redact( $result );
		$json = wp_json_encode( $redacted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false !== $json && strlen( $json ) <= 30000 ) {
			return $redacted;
		}
		return array(
			'truncated' => true,
			'bytes'     => false === $json ? 0 : strlen( $json ),
			'sha256'    => false === $json ? '' : hash( 'sha256', $json ),
		);
	}

	private static function system_prompt( string $role ): string {
		$catalog = Site_Agent_Action_Registry::catalog();
		return implode(
			"\n",
			array(
				'You are Site Agent, a private WordPress administration planner operating inside one authenticated wp-admin session.',
				'God Phrase: “Ask your site anything. Tell it what to do.”',
				'You may explain evidence and request only tools listed below. You never execute writes.',
				'The server—not you—validates arguments, permissions, risk, approvals, execution, logging, and rollback.',
				'Never request, reveal, infer, reconstruct, or store credentials, passwords, API keys, tokens, cookies, private keys, database credentials, salts, or authentication secrets.',
				'Do not claim certainty when hosting telemetry, external SaaS state, logs, or historical snapshots are unavailable.',
				'Do not claim that a rollback exists until the server returns a reversible ledger entry.',
				'Prefer the minimum necessary change. Ask for clarification when the target or intended result is ambiguous.',
				'Always provide a useful non-empty answer, including when asking a clarification question or requesting a read tool.',
				'AI role focus: ' . self::ROLES[ $role ]['focus'],
				'Read tools: ' . wp_json_encode( $catalog['read'] ),
				'Write actions that may be proposed: ' . wp_json_encode( $catalog['write'] ),
			)
		);
	}

	private static function local_fallback( string $prompt, array $context ): string {
		if ( empty( $context ) ) {
			return 'The local knowledge index did not find relevant evidence. Rebuild the index or configure the OpenAI API for broader interpretation.';
		}
		$lines = array( 'The closest local evidence is:' );
		foreach ( array_slice( $context, 0, 5 ) as $item ) {
			$summary = wp_json_encode( $item['summary'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$lines[] = sprintf( '• %s (%s #%s): %s', $item['title'], $item['type'], $item['object_id'], substr( (string) $summary, 0, 500 ) );
		}
		$lines[] = 'This is retrieval, not an AI interpretation or authorization to change the site.';
		return implode( "\n", $lines );
	}

	private static function history( string $conversation_id ): array {
		$settings = get_option( 'site_agent_settings', array() );
		if ( empty( $settings['store_conversations'] ) ) {
			return array();
		}
		global $wpdb;
		$table = Site_Agent_Database::table( 'messages' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT role, content FROM {$table}
				WHERE conversation_id = %s AND user_id = %d ORDER BY id DESC LIMIT 12",
				$conversation_id,
				get_current_user_id()
			),
			ARRAY_A
		);
		return array_reverse( $rows );
	}

	private static function store_turn( string $conversation_id, string $prompt, string $answer, array $metadata ): array {
		$settings = get_option( 'site_agent_settings', array() );
		if ( empty( $settings['store_conversations'] ) ) {
			return array();
		}
		global $wpdb;
		$table = Site_Agent_Database::table( 'messages' );
		$ids = array();
		foreach ( array( 'user' => $prompt, 'assistant' => $answer ) as $role => $content ) {
			$wpdb->insert(
				$table,
				array(
					'conversation_id' => $conversation_id,
					'user_id'         => get_current_user_id(),
					'role'            => $role,
					'content'         => substr( Site_Agent_Redactor::redact_string( $content ), 0, 50000 ),
					'metadata'        => Site_Agent_Redactor::safe_json( $metadata ),
					'created_gmt'     => Site_Agent_Database::utc_now(),
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s' )
			);
			$ids[ $role ] = (int) $wpdb->insert_id;
		}
		return $ids;
	}
}
