<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Browser_Guard {
	public static function authorize( string $capability ): bool {
		if ( ! is_user_logged_in() || ! current_user_can( $capability ) ) {
			return false;
		}

		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) )
			: '';

		/*
		 * WordPress REST cookie authentication requires X-WP-Nonce and leaves
		 * unauthenticated requests without a current user. Application Passwords
		 * authenticate the current user independently and do not issue REST
		 * nonces. Only apply browser CSRF checks when a nonce is present; a
		 * capability-bearing current user without one has already been accepted
		 * by another WordPress REST authentication handler.
		 */
		if ( '' === $nonce ) {
			$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] )
				? trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) )
				: '';
			$is_basic_auth = str_starts_with( strtolower( $authorization ), 'basic ' )
				|| ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) && '' !== (string) $_SERVER['PHP_AUTH_USER'] );
			return $is_basic_auth;
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) || ! wp_validate_auth_cookie( '', 'logged_in' ) ) {
			return false;
		}

		$expected_host = wp_parse_url( admin_url(), PHP_URL_HOST );
		$source        = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$source = (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$source = (string) wp_unslash( $_SERVER['HTTP_REFERER'] );
		}
		$source_host = $source ? wp_parse_url( $source, PHP_URL_HOST ) : '';

		return $expected_host && $source_host && hash_equals( strtolower( $expected_host ), strtolower( $source_host ) );
	}
}
