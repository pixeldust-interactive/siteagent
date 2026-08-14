<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_OpenAI_Client {
	private const ENDPOINT = 'https://api.openai.com/v1/responses';
	private const KEY_OPTION = 'site_agent_openai_credential';
	private const TURN_BUDGET_SECONDS = 32;
	private const RESPONSE_HEADROOM_SECONDS = 8;
	private const MAX_REQUEST_TIMEOUT_SECONDS = 24;
	private const MIN_REQUEST_TIMEOUT_SECONDS = 5;

	public static function is_configured(): bool {
		return '' !== self::api_key();
	}

	public static function model(): string {
		$settings = get_option( 'site_agent_settings', array() );
		$model = defined( 'SITE_AGENT_OPENAI_MODEL' )
			? (string) SITE_AGENT_OPENAI_MODEL
			: (string) ( $settings['model'] ?? 'gpt-5-mini' );
		return sanitize_text_field( (string) apply_filters( 'site_agent_openai_model', $model ) );
	}

	public static function api_key(): string {
		$key = '';
		if ( defined( 'SITE_AGENT_OPENAI_API_KEY' ) ) {
			$key = (string) SITE_AGENT_OPENAI_API_KEY;
		} elseif ( getenv( 'SITE_AGENT_OPENAI_API_KEY' ) ) {
			$key = (string) getenv( 'SITE_AGENT_OPENAI_API_KEY' );
		} else {
			$key = self::decrypt_key( (string) get_option( self::KEY_OPTION, '' ) );
		}
		return trim( (string) apply_filters( 'site_agent_openai_api_key', $key ) );
	}

	public static function key_source(): string {
		if ( defined( 'SITE_AGENT_OPENAI_API_KEY' ) || getenv( 'SITE_AGENT_OPENAI_API_KEY' ) ) {
			return 'server';
		}
		return get_option( self::KEY_OPTION, '' ) ? 'wordpress_encrypted' : 'none';
	}

	public static function turn_deadline(): float {
		return microtime( true ) + self::TURN_BUDGET_SECONDS;
	}

	public static function save_key( string $key ): bool|WP_Error {
		$key = trim( $key );
		if ( strlen( $key ) < 20 || ! str_starts_with( $key, 'sk-' ) ) {
			return new WP_Error( 'invalid_api_key_format', __( 'Enter a complete OpenAI API key beginning with sk-.', 'site-agent' ) );
		}
		$valid = self::validate_key( $key );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$encrypted = self::encrypt_key( $key );
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
		update_option( self::KEY_OPTION, $encrypted, false );
		return true;
	}

	public static function remove_key(): void {
		delete_option( self::KEY_OPTION );
	}

	public static function validate_key( string $key = '' ): bool|WP_Error {
		$key = $key ?: self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'openai_not_configured', __( 'Add an OpenAI API key before testing the connection.', 'site-agent' ) );
		}
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider_request_failed', __( 'Site Agent could not reach OpenAI. Check the site network connection and try again.', 'site-agent' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'provider_auth_failed', __( 'OpenAI rejected this key. Check that it is active and that API billing is enabled.', 'site-agent' ) );
		}
		return true;
	}

	private static function encrypt_key( string $key ): string|WP_Error {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error( 'encryption_unavailable', __( 'This server cannot securely encrypt the API key. Configure it in wp-config.php instead.', 'site-agent' ) );
		}
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $key, 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			return new WP_Error( 'encryption_failed', __( 'The API key could not be encrypted.', 'site-agent' ) );
		}
		return base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt_key( string $stored ): string {
		if ( '' === $stored || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$decoded = base64_decode( $stored, true );
		if ( false === $decoded || strlen( $decoded ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $decoded, 28 ), 'aes-256-gcm', hash( 'sha256', wp_salt( 'auth' ), true ), OPENSSL_RAW_DATA, substr( $decoded, 0, 12 ), substr( $decoded, 12, 16 ) );
		return is_string( $plain ) ? $plain : '';
	}

	public static function complete_turn(
		string $system,
		array $history,
		string $prompt,
		array $context,
		array $tool_results = array(),
		bool $require_write_plan = false,
		?float $deadline = null
	): array|WP_Error {
		$deadline = $deadline ?? self::turn_deadline();
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'openai_not_configured', __( 'No OpenAI API key is available through wp-config.php, the environment, or the site_agent_openai_api_key filter.', 'site-agent' ) );
		}

		$payload = array(
			'model' => self::model(),
			'store' => false,
			'input' => self::messages( $system, $history, $prompt, $context, $tool_results ),
			'text'  => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'site_agent_turn',
					'strict' => false,
					'schema' => self::schema(),
				),
			),
		);
		if ( $require_write_plan ) {
			$payload['input'][0]['content'] .= "\nThe user made an explicit site-change request. If it is supported and sufficiently specified, include one or more exact write_actions using the supplied contracts. A write action creates a review proposal only and never executes the change. If required arguments are missing, set needs_clarification true and ask for them. Do not return prose that merely describes a plan with an empty write_actions array.";
		}

		$response = self::request( $payload, $key, $deadline );
		if ( is_wp_error( $response ) && self::retry_without_structured_output( $response ) ) {
			// Some compatible endpoints/models may not accept structured output. Retry once with a strict JSON instruction.
			unset( $payload['text'] );
			$payload['input'][0]['content'] .= "\nReturn exactly one valid JSON object matching the requested Site Agent response shape. Do not use Markdown fences or surrounding prose.";
			$response = self::request( $payload, $key, $deadline );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$require_answer = ! empty( $tool_results );
		$turn = self::enforce_write_plan( self::parse_response( $response, $require_answer ), $require_write_plan );
		if ( is_wp_error( $turn ) && in_array( $turn->get_error_code(), array( 'provider_parse_error', 'provider_schema_error', 'provider_write_plan_missing' ), true ) ) {
			// Retry one malformed or incomplete planning object without duplicating the user submission.
			$payload['input'][0]['content'] .= $require_write_plan
				? "\nYour previous response omitted the required proposal. Return exactly one complete JSON object containing supported write_actions, or set needs_clarification true with a specific question. Do not merely describe the plan in answer text."
				: "\nYour previous response could not be validated. Return exactly one complete JSON object with non-empty answer text, plus read_calls, write_actions, needs_clarification, and clarification_question. Do not use Markdown fences or surrounding prose.";
			$retry = self::request( $payload, $key, $deadline );
			if ( ! is_wp_error( $retry ) ) {
				$turn = self::enforce_write_plan( self::parse_response( $retry, $require_answer ), $require_write_plan );
			} elseif ( 'provider_deadline_exceeded' === $retry->get_error_code() || 'provider_request_timeout' === $retry->get_error_code() ) {
				$turn = $retry;
			}
		}

		return $turn;
	}

	private static function enforce_write_plan( array|WP_Error $turn, bool $required ): array|WP_Error {
		if ( ! $required || is_wp_error( $turn ) || ! empty( $turn['write_actions'] ) || ! empty( $turn['needs_clarification'] ) ) {
			return $turn;
		}
		return new WP_Error(
			'provider_write_plan_missing',
			__( 'OpenAI described the requested change but did not return a reviewable action plan. Nothing was executed; try again or clarify the target.', 'site-agent' )
		);
	}

	private static function parse_response( array $response, bool $require_answer = false ): array|WP_Error {
		$status = sanitize_key( (string) ( $response['status'] ?? 'completed' ) );
		if ( 'failed' === $status ) {
			$message = (string) ( $response['error']['message'] ?? __( 'OpenAI could not generate a response. Check the configured model and try again.', 'site-agent' ) );
			return new WP_Error( 'provider_generation_failed', Site_Agent_Redactor::redact_string( $message ), self::diagnostics( $response ) );
		}
		if ( 'incomplete' === $status ) {
			$reason = sanitize_key( (string) ( $response['incomplete_details']['reason'] ?? 'unknown' ) );
			$message = 'max_output_tokens' === $reason
				? __( 'OpenAI stopped before the planning response was complete. Shorten the request or increase the model output limit, then try again.', 'site-agent' )
				: __( 'OpenAI returned an incomplete response. Try again; if it continues, check the configured model and account limits.', 'site-agent' );
			return new WP_Error( 'provider_incomplete_response', $message, self::diagnostics( $response ) );
		}
		if ( ! in_array( $status, array( '', 'completed' ), true ) ) {
			return new WP_Error(
				'provider_unexpected_status',
				__( 'OpenAI did not finish the response. Try again in a moment.', 'site-agent' ),
				self::diagnostics( $response )
			);
		}

		foreach ( (array) ( $response['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( 'refusal' === (string) ( $content['type'] ?? '' ) ) {
					return new WP_Error(
						'provider_refusal',
						__( 'OpenAI declined this request. Rephrase it as a specific WordPress administration question and try again.', 'site-agent' ),
						self::diagnostics( $response )
					);
				}
			}
		}

		$text = self::extract_output_text( $response );
		$data = self::decode_planning_text( $text );
		if ( is_wp_error( $data ) ) {
			$data->add_data( self::diagnostics( $response, $text ) );
			return $data;
		}
		$call_shapes = self::tool_call_shapes( $data );
		$data = self::canonicalize_tool_calls( $data );

		$validation = self::validate_turn( $data, $require_answer );
		if ( is_wp_error( $validation ) ) {
			$diagnostics = self::diagnostics( $response, $text );
			$diagnostics['planning_fields'] = array_values( array_map( 'sanitize_key', array_keys( $data ) ) );
			$diagnostics['read_call_count'] = is_array( $data['read_calls'] ?? null ) ? count( $data['read_calls'] ) : null;
			$diagnostics['write_action_count'] = is_array( $data['write_actions'] ?? null ) ? count( $data['write_actions'] ) : null;
			$diagnostics['tool_call_shapes'] = $call_shapes;
			$validation->add_data( $diagnostics );
			return $validation;
		}

		return self::normalize( $data );
	}

	private static function decode_planning_text( string $text ): array|WP_Error {
		$text = trim( $text );
		$candidates = array( $text );

		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/is', $text, $match ) ) {
			$candidates[] = trim( $match[1] );
		}

		$object = self::first_json_object( $text );
		if ( '' !== $object ) {
			$candidates[] = $object;
		}

		foreach ( array_unique( array_filter( $candidates, 'strlen' ) ) as $candidate ) {
			$data = json_decode( $candidate, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return new WP_Error(
			'provider_parse_error',
			__( 'OpenAI returned text that Site Agent could not safely read as a plan. Site Agent retried once; try a shorter request or choose a supported model in Settings.', 'site-agent' )
		);
	}

	private static function first_json_object( string $text ): string {
		$length = strlen( $text );
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return '';
		}

		$depth = 0;
		$quoted = false;
		$escaped = false;
		for ( $index = $start; $index < $length; $index++ ) {
			$character = $text[ $index ];
			if ( $quoted ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $character ) {
					$escaped = true;
				} elseif ( '"' === $character ) {
					$quoted = false;
				}
				continue;
			}
			if ( '"' === $character ) {
				$quoted = true;
			} elseif ( '{' === $character ) {
				$depth++;
			} elseif ( '}' === $character ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $text, $start, $index - $start + 1 );
				}
			}
		}

		return '';
	}

	private static function canonicalize_tool_calls( array $data ): array {
		foreach ( array( 'read_calls', 'write_actions' ) as $field ) {
			if ( ! is_array( $data[ $field ] ?? null ) ) {
				continue;
			}
			foreach ( $data[ $field ] as $index => $call ) {
				$name = '';
				$args = array();
				if ( is_string( $call ) ) {
					$name = $call;
				} elseif ( is_array( $call ) ) {
					$name = $call['name'] ?? $call['tool'] ?? $call['action'] ?? $call['tool_name'] ?? '';
					if ( array_key_exists( 'args', $call ) ) {
						$args = $call['args'];
					} elseif ( array_key_exists( 'arguments', $call ) ) {
						$args = $call['arguments'];
					} elseif ( array_key_exists( 'parameters', $call ) ) {
						$args = $call['parameters'];
					} elseif ( 1 === count( $call ) && is_string( array_key_first( $call ) ) ) {
						$name = (string) array_key_first( $call );
						$args = reset( $call );
					}
				}
				if ( is_string( $args ) ) {
					$decoded_args = json_decode( $args, true );
					$args = is_array( $decoded_args ) ? $decoded_args : $args;
				}
				$data[ $field ][ $index ] = array(
					'name' => $name,
					'args' => $args,
				);
			}
		}
		return $data;
	}

	private static function tool_call_shapes( array $data ): array {
		$shapes = array();
		foreach ( array( 'read_calls', 'write_actions' ) as $field ) {
			$calls = $data[ $field ] ?? null;
			if ( ! is_array( $calls ) ) {
				$shapes[ $field ] = array( 'type' => gettype( $calls ) );
				continue;
			}
			$shapes[ $field ] = array();
			foreach ( array_slice( $calls, 0, 10 ) as $call ) {
				if ( ! is_array( $call ) ) {
					$shapes[ $field ][] = array( 'type' => gettype( $call ) );
					continue;
				}
				$shapes[ $field ][] = array(
					'type' => 'array',
					'keys' => array_values( array_map( 'sanitize_key', array_map( 'strval', array_keys( $call ) ) ) ),
					'name_type' => gettype( $call['name'] ?? $call['tool'] ?? $call['action'] ?? $call['tool_name'] ?? null ),
					'args_type' => gettype( $call['args'] ?? $call['arguments'] ?? $call['parameters'] ?? null ),
				);
			}
		}
		return $shapes;
	}

	private static function validate_turn( array $data, bool $require_answer = false ): bool|WP_Error {
		$required = array( 'read_calls', 'write_actions', 'needs_clarification', 'clarification_question' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $data ) ) {
				return new WP_Error( 'provider_schema_error', sprintf( __( 'OpenAI omitted the required planning field “%s”. Site Agent retried once; try again or choose a supported model.', 'site-agent' ), $field ) );
			}
		}
		if ( ! is_array( $data['read_calls'] ) || ! is_array( $data['write_actions'] ) || ! is_bool( $data['needs_clarification'] ) || ! is_string( $data['clarification_question'] ) ) {
			return new WP_Error( 'provider_schema_error', __( 'OpenAI returned planning fields in an unexpected format. Site Agent retried once; try again or choose a supported model.', 'site-agent' ) );
		}
		if ( array_key_exists( 'answer', $data ) && ! is_string( $data['answer'] ) ) {
			return new WP_Error( 'provider_schema_error', __( 'OpenAI returned the answer in an unexpected format. Site Agent retried once; try again or choose a supported model.', 'site-agent' ) );
		}
		if ( $data['needs_clarification'] && '' === trim( $data['clarification_question'] ) ) {
			return new WP_Error( 'provider_schema_error', __( 'OpenAI requested clarification without providing a question. Site Agent retried once; try again or choose a supported model.', 'site-agent' ) );
		}
		foreach ( array_merge( $data['read_calls'], $data['write_actions'] ) as $call ) {
			if ( ! is_array( $call ) || ! is_string( $call['name'] ?? null ) || '' === trim( $call['name'] ) || ! is_array( $call['args'] ?? null ) ) {
				return new WP_Error( 'provider_schema_error', __( 'OpenAI returned an invalid tool request. Nothing was executed; try again or choose a supported model.', 'site-agent' ) );
			}
		}
		$answer = array_key_exists( 'answer', $data ) ? trim( $data['answer'] ) : '';
		$has_intermediate_plan = ! empty( $data['read_calls'] ) || ! empty( $data['write_actions'] );
		$has_clarification = $data['needs_clarification'] && '' !== trim( $data['clarification_question'] );
		if ( '' === $answer && ( $require_answer || ( ! $has_intermediate_plan && ! $has_clarification ) ) ) {
			return new WP_Error( 'provider_schema_error', __( 'OpenAI returned an empty answer without a valid tool plan. Site Agent retried once; try a more specific question or choose a supported model.', 'site-agent' ) );
		}
		return true;
	}

	private static function diagnostics( array $response, string $text = '' ): array {
		$content_types = array();
		foreach ( (array) ( $response['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				$type = sanitize_key( (string) ( $content['type'] ?? '' ) );
				if ( '' !== $type ) {
					$content_types[] = $type;
				}
			}
		}
		return array(
			'provider_status'   => sanitize_key( (string) ( $response['status'] ?? 'unknown' ) ),
			'incomplete_reason' => sanitize_key( (string) ( $response['incomplete_details']['reason'] ?? '' ) ),
			'output_items'      => count( (array) ( $response['output'] ?? array() ) ),
			'content_types'     => array_values( array_unique( $content_types ) ),
			'output_text_bytes' => strlen( $text ),
			'json_error'        => function_exists( 'json_last_error_msg' ) ? sanitize_text_field( json_last_error_msg() ) : (string) json_last_error(),
		);
	}

	private static function request( array $payload, string $key, float $deadline ): array|WP_Error {
		$timeout = self::request_timeout( $deadline );
		if ( is_wp_error( $timeout ) ) {
			return $timeout;
		}
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $timeout,
				'redirection' => 0,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body' => wp_json_encode( Site_Agent_Redactor::redact( $payload ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = Site_Agent_Redactor::redact_string( $response->get_error_message() );
			if ( preg_match( '/(?:timed?\s*out|timeout|cURL error 28)/i', $message ) ) {
				return new WP_Error(
					'provider_request_timeout',
					__( 'OpenAI did not respond within this site\'s safe request window. Nothing was changed. Try again or ask a shorter question.', 'site-agent' ),
					array( 'status' => 504, 'timeout_seconds' => $timeout )
				);
			}
			return new WP_Error(
				'provider_request_failed',
				$message
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? 'OpenAI request failed.' ) : 'OpenAI request failed.';
			return new WP_Error(
				'provider_http_error',
				Site_Agent_Redactor::redact_string( $message ),
				array( 'status' => $code )
			);
		}
		return $data;
	}

	private static function request_timeout( float $deadline, ?float $now = null ): int|WP_Error {
		$now = $now ?? microtime( true );
		$available = (int) floor( $deadline - $now - self::RESPONSE_HEADROOM_SECONDS );
		if ( $available < self::MIN_REQUEST_TIMEOUT_SECONDS ) {
			return new WP_Error(
				'provider_deadline_exceeded',
				__( 'Site Agent stopped before the hosting request limit so it could return safely. Nothing was changed. Try again or ask a shorter question.', 'site-agent' ),
				array( 'status' => 504 )
			);
		}
		return min( self::MAX_REQUEST_TIMEOUT_SECONDS, $available );
	}

	private static function retry_without_structured_output( WP_Error $error ): bool {
		if ( 'provider_http_error' !== $error->get_error_code() ) {
			return false;
		}
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
		return in_array( $status, array( 400, 422 ), true );
	}

	private static function messages( string $system, array $history, string $prompt, array $context, array $tool_results ): array {
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system,
			),
		);
		foreach ( array_slice( $history, -12 ) as $message ) {
			$role = (string) ( $message['role'] ?? '' );
			if ( ! in_array( $role, array( 'user', 'assistant' ), true ) ) {
				continue;
			}
			$messages[] = array(
				'role'    => $role,
				'content' => substr( Site_Agent_Redactor::redact_string( (string) ( $message['content'] ?? '' ) ), 0, 12000 ),
			);
		}

		$input = array(
			'question'           => Site_Agent_Redactor::redact_string( $prompt ),
			'local_context'      => Site_Agent_Redactor::redact( $context ),
			'validated_tool_data'=> Site_Agent_Redactor::redact( $tool_results ),
		);
		$messages[] = array(
			'role'    => 'user',
			'content' => wp_json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
		);
		return $messages;
	}

	private static function schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'answer', 'read_calls', 'write_actions', 'needs_clarification', 'clarification_question' ),
			'properties'           => array(
				'answer' => array( 'type' => 'string' ),
				'read_calls' => array(
					'type'     => 'array',
					'maxItems' => 3,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'name', 'args' ),
						'properties'           => array(
							'name' => array( 'type' => 'string' ),
							'args' => array( 'type' => 'object', 'additionalProperties' => true ),
						),
					),
				),
				'write_actions' => array(
					'type'     => 'array',
					'maxItems' => 10,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'name', 'args' ),
						'properties'           => array(
							'name' => array( 'type' => 'string' ),
							'args' => array( 'type' => 'object', 'additionalProperties' => true ),
						),
					),
				),
				'needs_clarification' => array( 'type' => 'boolean' ),
				'clarification_question' => array( 'type' => 'string' ),
			),
		);
	}

	private static function extract_output_text( array $response ): string {
		if ( isset( $response['output_text'] ) && is_string( $response['output_text'] ) ) {
			return $response['output_text'];
		}
		$text = '';
		foreach ( (array) ( $response['output'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['content'] ?? array() ) as $content ) {
				if ( isset( $content['text'] ) && is_string( $content['text'] ) ) {
					$text .= $content['text'];
				}
			}
		}
		return trim( $text );
	}

	private static function normalize( array $data ): array {
		return array(
			'answer'                 => substr( Site_Agent_Redactor::redact_string( (string) ( $data['answer'] ?? '' ) ), 0, 30000 ),
			'read_calls'             => array_slice( is_array( $data['read_calls'] ?? null ) ? $data['read_calls'] : array(), 0, 3 ),
			'write_actions'          => array_slice( is_array( $data['write_actions'] ?? null ) ? $data['write_actions'] : array(), 0, 10 ),
			'needs_clarification'    => ! empty( $data['needs_clarification'] ),
			'clarification_question'=> substr( sanitize_text_field( (string) ( $data['clarification_question'] ?? '' ) ), 0, 1000 ),
		);
	}
}
