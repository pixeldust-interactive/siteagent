<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Redactor {
	private const REDACTED = '[REDACTED]';

	private static array $sensitive_fragments = array(
		'password',
		'passwd',
		'pwd',
		'secret',
		'token',
		'api_key',
		'apikey',
		'authorization',
		'auth_key',
		'secure_auth',
		'logged_in_key',
		'nonce_key',
		'salt',
		'private_key',
		'client_secret',
		'consumer_secret',
		'access_key',
		'db_password',
		'database_password',
		'cookie',
		'session',
		'bearer',
	);

	public static function is_sensitive_name( string $name ): bool {
		$normalized = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', $name ) ?? $name );

		foreach ( self::$sensitive_fragments as $fragment ) {
			if ( str_contains( $normalized, $fragment ) ) {
				return true;
			}
		}

		return (bool) preg_match( '/(^|_)(key|pass|credential|jwt|oauth)(_|$)/', $normalized );
	}

	public static function redact( mixed $value, int $depth = 0 ): mixed {
		if ( $depth > 8 ) {
			return '[DEPTH LIMIT]';
		}

		if ( is_array( $value ) ) {
			$output = array();
			foreach ( $value as $key => $item ) {
				$key_string     = (string) $key;
				$output[ $key ] = self::is_sensitive_name( $key_string )
					? self::REDACTED
					: self::redact( $item, $depth + 1 );
			}
			return $output;
		}

		if ( is_object( $value ) ) {
			return self::redact( get_object_vars( $value ), $depth + 1 );
		}

		if ( is_string( $value ) ) {
			return self::redact_string( $value );
		}

		return $value;
	}

	public static function redact_string( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}

		$text = preg_replace(
			'/-----BEGIN(?: [A-Z]+)? PRIVATE KEY-----.*?-----END(?: [A-Z]+)? PRIVATE KEY-----/s',
			'[REDACTED PRIVATE KEY]',
			$text
		) ?? $text;

		$text = preg_replace(
			'/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
			'Bearer ' . self::REDACTED,
			$text
		) ?? $text;

		$text = preg_replace(
			'/\bBasic\s+[A-Za-z0-9+\/]+=*/i',
			'Basic ' . self::REDACTED,
			$text
		) ?? $text;

		$whole_value_patterns = array(
			'/\bsk-(?:proj-)?[A-Za-z0-9_-]{16,}\b/',
			'/\b(?:AKIA|ASIA)[A-Z0-9]{16}\b/',
			'/\bgh[opsu]_[A-Za-z0-9]{20,}\b/',
			'/\bAIza[0-9A-Za-z\-_]{20,}\b/',
			'/\beyJ[a-zA-Z0-9_-]{8,}\.[a-zA-Z0-9_-]{8,}\.[a-zA-Z0-9_-]{8,}\b/',
		);

		foreach ( $whole_value_patterns as $pattern ) {
			$text = preg_replace( $pattern, self::REDACTED, $text ) ?? $text;
		}

		$text = preg_replace(
			'/((?:password|passwd|pwd|secret|token|api[_-]?key|client[_-]?secret|authorization)\s*[:=]\s*)[^\s,;&]+/i',
			'$1' . self::REDACTED,
			$text
		) ?? $text;

		$constant_pattern = <<<'REGEX'
/((?:DB_PASSWORD|AUTH_KEY|SECURE_AUTH_KEY|LOGGED_IN_KEY|NONCE_KEY|AUTH_SALT|SECURE_AUTH_SALT|LOGGED_IN_SALT|NONCE_SALT)\s*['"]?\s*,?\s*['"])[^'"]+(['"])/
REGEX;
		$text = preg_replace(
			$constant_pattern,
			'$1' . self::REDACTED . '$2',
			$text
		) ?? $text;

		$text = preg_replace_callback(
			'/\b[A-Za-z0-9+\/_-]{40,}={0,2}\b/',
			static function ( array $match ): string {
				$value   = $match[0];
				$classes = 0;
				$classes += preg_match( '/[a-z]/', $value ) ? 1 : 0;
				$classes += preg_match( '/[A-Z]/', $value ) ? 1 : 0;
				$classes += preg_match( '/[0-9]/', $value ) ? 1 : 0;
				$classes += preg_match( '/[+\/_-]/', $value ) ? 1 : 0;

				return $classes >= 3 ? self::REDACTED : $value;
			},
			$text
		) ?? $text;

		return $text;
	}

	public static function safe_json( mixed $value, int $max_bytes = 65536 ): string {
		$json = wp_json_encode(
			self::redact( $value ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		if ( false === $json ) {
			return '{"error":"Unable to encode redacted data"}';
		}

		if ( strlen( $json ) > $max_bytes ) {
			$json = wp_json_encode(
				array(
					'truncated' => true,
					'bytes'     => strlen( $json ),
					'sha256'    => hash( 'sha256', $json ),
				)
			);
		}

		return (string) $json;
	}
}
