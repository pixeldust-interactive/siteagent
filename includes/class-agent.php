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
		$deadline = Site_Agent_OpenAI_Client::turn_deadline();
		$redacted_prompt = Site_Agent_Redactor::redact_string( $prompt );
		$context = Site_Agent_Retriever::context( $redacted_prompt );
		$history = self::history( $conversation_id );
		$live_answer = self::authoritative_live_answer( $redacted_prompt );
		if ( null !== $live_answer ) {
			$ids = self::store_turn( $conversation_id, $redacted_prompt, $live_answer['answer'], array( 'provider' => 'live_wordpress', 'read_tools' => $live_answer['read_tools'] ) );
			$completion_token = self::issue_completion_receipt( $conversation_id, $live_answer['read_tools'], false );
			Site_Agent_Audit_Log::record(
				'chat_response_ready',
				array( 'conversation_id' => $conversation_id, 'provider' => 'live_wordpress', 'read_tools' => $live_answer['read_tools'], 'has_proposal' => false ),
				'info',
				get_current_user_id(),
				$redacted_prompt
			);
			return array(
				'conversation_id'  => $conversation_id,
				'message_ids'      => $ids,
				'answer'           => $live_answer['answer'],
				'sources'          => $live_answer['sources'],
				'tool_results'     => array(),
				'proposal'         => null,
				'provider'         => 'live_wordpress',
				'completion_token' => $completion_token,
			);
		}

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

		$system = self::system_prompt( $role, $redacted_prompt );
		$turn = Site_Agent_OpenAI_Client::complete_turn( $system, $history, $redacted_prompt, $context, array(), false, $deadline );
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

		$read_calls = self::ensure_discovery_read_calls( (array) $turn['read_calls'], $redacted_prompt );
		$tool_results = self::run_read_calls( $read_calls );
		if ( ! empty( $tool_results ) ) {
			$second = Site_Agent_OpenAI_Client::complete_turn( $system, $history, $redacted_prompt, $context, $tool_results, false, $deadline );
			if ( is_wp_error( $second ) ) {
				$error_data = $second->get_error_data();
				Site_Agent_Audit_Log::record(
					'chat_provider_failed',
					array(
						'phase'       => 'after_read_tools',
						'error_code'  => sanitize_key( $second->get_error_code() ),
						'error'       => Site_Agent_Redactor::redact_string( $second->get_error_message() ),
						'diagnostics' => is_array( $error_data ) ? Site_Agent_Redactor::redact( $error_data ) : array(),
					),
					'error',
					get_current_user_id(),
					$redacted_prompt
				);
				return $second;
			}
			$turn = $second;
		}

		if ( self::is_explicit_write_request( $redacted_prompt ) && empty( $turn['write_actions'] ) && empty( $turn['needs_clarification'] ) ) {
			$write_turn = Site_Agent_OpenAI_Client::complete_turn( $system, $history, $redacted_prompt, $context, $tool_results, true, $deadline );
			if ( is_wp_error( $write_turn ) ) {
				$error_data = $write_turn->get_error_data();
				Site_Agent_Audit_Log::record(
					'chat_provider_failed',
					array(
						'phase'       => 'write_plan_required',
						'error_code'  => sanitize_key( $write_turn->get_error_code() ),
						'error'       => Site_Agent_Redactor::redact_string( $write_turn->get_error_message() ),
						'diagnostics' => is_array( $error_data ) ? Site_Agent_Redactor::redact( $error_data ) : array(),
					),
					'error',
					get_current_user_id(),
					$redacted_prompt
				);
				return $write_turn;
			}
			$turn = $write_turn;
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
		if ( '' === trim( $answer ) && null === $proposal ) {
			$error = new WP_Error( 'provider_empty_answer', __( 'OpenAI completed the plan without a displayable answer. Nothing was executed; retry the request or choose a supported model.', 'site-agent' ) );
			Site_Agent_Audit_Log::record(
				'chat_provider_failed',
				array(
					'phase'      => 'final_answer',
					'error_code' => 'provider_empty_answer',
					'error'      => $error->get_error_message(),
				),
				'error',
				get_current_user_id(),
				$redacted_prompt
			);
			return $error;
		}
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
		$completion_token = self::issue_completion_receipt( $conversation_id, array_column( $tool_results, 'name' ), null !== $proposal );
		Site_Agent_Audit_Log::record(
			'chat_response_ready',
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
			'completion_token' => $completion_token,
		);
	}

	public static function mark_rendered( string $completion_token ): array|WP_Error {
		$completion_token = trim( $completion_token );
		if ( ! wp_is_uuid( $completion_token ) ) {
			return new WP_Error( 'invalid_completion_token', __( 'The visible-completion receipt is invalid.', 'site-agent' ) );
		}
		$key = 'site_agent_completion_' . str_replace( '-', '', $completion_token );
		$receipt = get_transient( $key );
		if ( ! is_array( $receipt ) || (int) ( $receipt['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'completion_receipt_expired', __( 'The visible-completion receipt expired or belongs to another user.', 'site-agent' ) );
		}
		delete_transient( $key );
		Site_Agent_Audit_Log::record(
			'chat_completed',
			array(
				'conversation_id' => (string) ( $receipt['conversation_id'] ?? '' ),
				'read_tools'      => (array) ( $receipt['read_tools'] ?? array() ),
				'has_proposal'    => ! empty( $receipt['has_proposal'] ),
				'rendered'        => true,
			),
			'info',
			get_current_user_id()
		);
		return array( 'completed' => true );
	}

	private static function authoritative_live_answer( string $prompt ): ?array {
		if ( 1 !== preg_match( '/\b(?:what|which|current|installed|running|verify|version)\b/i', $prompt )
			|| 1 !== preg_match( '/\bsite\s*agent\b/i', $prompt )
			|| 1 !== preg_match( '/\b(?:version|installed|active|running)\b/i', $prompt ) ) {
			return null;
		}
		return array(
			'answer'      => sprintf(
				/* translators: %s: installed plugin version. */
				__( 'Site Agent %s is installed and active. I verified this from the live plugin status just now.', 'site-agent' ),
				SITE_AGENT_VERSION
			),
			'read_tools'  => array( 'plugins.list' ),
			'sources'     => array(
				array( 'title' => __( 'Installed plugins (live)', 'site-agent' ), 'type' => 'live_status', 'object_id' => 'site-agent' ),
			),
		);
	}

	private static function issue_completion_receipt( string $conversation_id, array $read_tools, bool $has_proposal ): string {
		$token = wp_generate_uuid4();
		set_transient(
			'site_agent_completion_' . str_replace( '-', '', $token ),
			array(
				'user_id'         => get_current_user_id(),
				'conversation_id' => $conversation_id,
				'read_tools'      => array_values( array_map( 'sanitize_key', $read_tools ) ),
				'has_proposal'    => $has_proposal,
			),
			10 * MINUTE_IN_SECONDS
		);
		return $token;
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

	private static function system_prompt( string $role, string $prompt = '' ): string {
		$catalog = Site_Agent_Action_Registry::catalog();
		$instructions = array(
			'You are Site Agent, a private WordPress administration planner operating inside one authenticated wp-admin session.',
			'God Phrase: “Ask your site anything. Tell it what to do.”',
			'You may explain evidence and request only tools listed below. You never execute writes.',
			'The server—not you—validates arguments, permissions, risk, approvals, execution, logging, and rollback.',
			'Never request, reveal, infer, reconstruct, or store credentials, passwords, API keys, tokens, cookies, private keys, database credentials, salts, or authentication secrets.',
			'Do not claim certainty when hosting telemetry, external SaaS state, logs, or historical snapshots are unavailable.',
			'Do not claim that a rollback exists until the server returns a reversible ledger entry.',
			'Prefer the minimum necessary change. Ask for clarification when the target or intended result is ambiguous.',
			'When the user explicitly requests a supported change and provides the required arguments, populate write_actions so the server can produce the review card. Never substitute prose-only plan narration for write_actions. Proposing is not executing.',
			'Provide a useful non-empty answer for every final response. An intermediate read-tool plan may omit answer; after validated tool data is supplied, answer must be non-empty.',
			'AI role focus: ' . self::ROLES[ $role ]['focus'],
			'Read tools: ' . wp_json_encode( $catalog['read'] ),
			'Write actions that may be proposed: ' . wp_json_encode( $catalog['write'] ),
			'Exact write-action argument contracts: ' . wp_json_encode( Site_Agent_Action_Registry::write_contracts() ),
		);
		if ( self::is_homepage_change_request( $prompt ) ) {
			$instructions[] = 'This is a homepage change conversation. Use the supplied site-search evidence to identify the configured homepage and its editing system before asking the user. Briefly say what you found in ordinary language, without a page ID or URL. If the content goal is missing, ask exactly one concise question: “What should the new section help visitors understand or do?” Then offer one short example, such as: “For example, help visitors understand what you offer and choose the right next step.” Do not ask for a heading, paragraph, bullets, button text, link, image, placement, editing system, search description, page ID, URL, saved-review state, and publishing choice all at once. When those choices later matter, say “button” instead of CTA, “search description” instead of SEO metadata, “editing system” instead of page builder, and explain saved review versus publishing in plain language. Recommend a visual placement such as “below the introduction,” prepare a reversible reviewable proposal, and say that nothing changes until the user approves the exact preview.';
		}
		return implode(
			"\n",
			$instructions
		);
	}

	private static function ensure_discovery_read_calls( array $calls, string $prompt ): array {
		if ( ! self::is_homepage_change_request( $prompt ) || self::has_read_call( $calls, 'site.search' ) ) {
			return $calls;
		}

		array_unshift(
			$calls,
			array(
				'name' => 'site.search',
				'args' => array(
					'query' => 'configured homepage front page editing system template builder Divi Elementor block editor',
					'limit' => 12,
				),
			)
		);
		return $calls;
	}

	private static function is_homepage_change_request( string $prompt ): bool {
		return 1 === preg_match( '/\b(?:home\s*page|front\s*page)\b/i', $prompt )
			&& 1 === preg_match( '/\b(?:add|create|change|update|improve|section|content|copy|design)\b/i', $prompt );
	}

	private static function has_read_call( array $calls, string $name ): bool {
		foreach ( $calls as $call ) {
			if ( is_array( $call ) && strtolower( trim( (string) ( $call['name'] ?? '' ) ) ) === $name ) {
				return true;
			}
		}
		return false;
	}

	private static function is_explicit_write_request( string $prompt ): bool {
		return 1 === preg_match(
			'/^\s*(?:(?:please|kindly)\s+|(?:can|could|would)\s+you\s+|i\s+(?:(?:want|need)\s+(?:you\s+)?to|would\s+like\s+to)\s+)?(?:add|create|update|change|replace|remove|delete|trash|activate|deactivate|publish|schedule|set|rollback|roll\s+back)\b/i',
			$prompt
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
