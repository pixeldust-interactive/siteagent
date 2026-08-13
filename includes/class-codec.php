<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Codec {
	private const MAX_RAW_BYTES = 2097152;

	public static function encode( mixed $value ): ?string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json || strlen( $json ) > self::MAX_RAW_BYTES ) {
			return null;
		}

		$envelope = array(
			'v'      => 1,
			'codec'  => 'json',
			'sha256' => hash( 'sha256', $json ),
			'data'   => $json,
		);

		if ( function_exists( 'gzencode' ) && strlen( $json ) > 4096 ) {
			$compressed = gzencode( $json, 6 );
			if ( false !== $compressed ) {
				$envelope['codec'] = 'gzip-base64';
				$envelope['data']  = base64_encode( $compressed );
			}
		}

		$encoded = wp_json_encode( $envelope, JSON_UNESCAPED_SLASHES );
		return false === $encoded ? null : $encoded;
	}

	public static function decode( ?string $payload ): mixed {
		if ( null === $payload || '' === $payload ) {
			return null;
		}

		$envelope = json_decode( $payload, true );
		if ( ! is_array( $envelope ) || 1 !== (int) ( $envelope['v'] ?? 0 ) ) {
			return null;
		}

		$data  = (string) ( $envelope['data'] ?? '' );
		$codec = (string) ( $envelope['codec'] ?? '' );

		if ( 'gzip-base64' === $codec ) {
			$binary = base64_decode( $data, true );
			if ( false === $binary || ! function_exists( 'gzdecode' ) ) {
				return null;
			}
			$data = gzdecode( $binary );
			if ( false === $data ) {
				return null;
			}
		} elseif ( 'json' !== $codec ) {
			return null;
		}

		if ( ! hash_equals( (string) ( $envelope['sha256'] ?? '' ), hash( 'sha256', $data ) ) ) {
			return null;
		}

		return json_decode( $data, true );
	}

	public static function fingerprint( mixed $value ): string {
		$normalized = self::normalize( $value );
		$json       = wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $json ? serialize( $normalized ) : $json );
	}

	private static function normalize( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value );
			}
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::normalize( $item );
			}
		} elseif ( is_object( $value ) ) {
			$value = self::normalize( get_object_vars( $value ) );
		}
		return $value;
	}
}
