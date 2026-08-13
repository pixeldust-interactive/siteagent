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
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return false;
		}

		// This intentionally excludes Application Password/basic-auth access.
		if ( ! wp_validate_auth_cookie( '', 'logged_in' ) ) {
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
