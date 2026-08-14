<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Build_Integrity {
	private const MANIFEST = 'build-manifest.json';

	public static function status(): array {
		$path = SITE_AGENT_DIR . self::MANIFEST;
		if ( ! is_readable( $path ) ) {
			return array(
				'verified' => false,
				'reason'   => 'manifest_missing',
			);
		}

		$raw = file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || ! is_array( $data['files'] ?? null ) ) {
			return array(
				'verified'        => false,
				'reason'          => 'manifest_invalid',
				'manifest_sha256' => hash_file( 'sha256', $path ) ?: '',
			);
		}

		$mismatches = array();
		foreach ( $data['files'] as $relative => $expected ) {
			$relative = str_replace( '\\', '/', (string) $relative );
			if ( '' === $relative || str_contains( $relative, '../' ) || str_starts_with( $relative, '/' ) ) {
				$mismatches[] = $relative;
				continue;
			}
			$file = SITE_AGENT_DIR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$actual = is_file( $file ) ? hash_file( 'sha256', $file ) : false;
			if ( ! is_string( $actual ) || ! hash_equals( (string) $expected, $actual ) ) {
				$mismatches[] = $relative;
			}
		}

		return array(
			'verified'        => empty( $mismatches ),
			'reason'          => empty( $mismatches ) ? 'verified' : 'file_mismatch',
			'release_id'      => sanitize_text_field( (string) ( $data['release_id'] ?? '' ) ),
			'version'         => sanitize_text_field( (string) ( $data['version'] ?? '' ) ),
			'manifest_sha256' => hash_file( 'sha256', $path ) ?: '',
			'file_count'      => count( $data['files'] ),
			'mismatches'      => array_slice( $mismatches, 0, 20 ),
		);
	}
}
