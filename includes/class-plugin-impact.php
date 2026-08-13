<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Plugin_Impact {
	private const MAX_FILES       = 1800;
	private const MAX_FILE_BYTES  = 2097152;
	private const MAX_TOTAL_BYTES = 33554432;
	private const MAX_SECONDS     = 10.0;

	public static function analyze( string $plugin_file ): array|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugins     = get_plugins();
		$plugin_file = plugin_basename( $plugin_file );

		if ( ! isset( $plugins[ $plugin_file ] ) ) {
			return new WP_Error(
				'invalid_plugin',
				__( 'The selected plugin is not installed.', 'site-agent' )
			);
		}

		if ( SITE_AGENT_BASENAME === $plugin_file ) {
			return new WP_Error(
				'self_analysis',
				__( 'Site Agent does not analyze its own removal.', 'site-agent' )
			);
		}

		$root       = realpath( WP_PLUGIN_DIR );
		$entry_file = realpath( WP_PLUGIN_DIR . '/' . $plugin_file );

		if (
			! $root
			|| ! $entry_file
			|| ! is_file( $entry_file )
			|| ! str_starts_with( $entry_file, $root . DIRECTORY_SEPARATOR )
		) {
			return new WP_Error(
				'invalid_path',
				__( 'The plugin path could not be validated.', 'site-agent' )
			);
		}

		$relative_directory = dirname( $plugin_file );
		$is_single_file     = '.' === $relative_directory;
		$directory          = $is_single_file
			? $root
			: realpath( WP_PLUGIN_DIR . '/' . $relative_directory );

		if (
			! $directory
			|| (
				! $is_single_file
				&& ! str_starts_with(
					$directory . DIRECTORY_SEPARATOR,
					$root . DIRECTORY_SEPARATOR
				)
			)
		) {
			return new WP_Error(
				'invalid_directory',
				__( 'The plugin directory could not be validated.', 'site-agent' )
			);
		}

		$started = microtime( true );

		$inventory = array(
			'shortcodes'  => array(),
			'blocks'      => array(),
			'post_types'  => array(),
			'taxonomies'  => array(),
			'cron_hooks'  => array(),
			'rest_routes' => array(),
			'options'     => array(),
			'tables'      => array(),
			'meta_keys'   => array(),
		);

		$coverage = array(
			'files_scanned' => 0,
			'bytes_scanned' => 0,
			'truncated'     => false,
			'seconds'       => 0.0,
		);

		if ( $is_single_file ) {
			self::inspect_source_file(
				$entry_file,
				$directory,
				$started,
				$inventory,
				$coverage
			);
		} else {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$directory,
					FilesystemIterator::SKIP_DOTS
					| FilesystemIterator::CURRENT_AS_FILEINFO
				)
			);

			foreach ( $iterator as $info ) {
				if ( self::budget_exhausted( $started, $coverage ) ) {
					$coverage['truncated'] = true;
					break;
				}

				if (
					! $info instanceof SplFileInfo
					|| ! $info->isFile()
					|| $info->isLink()
					|| 'php' !== strtolower( $info->getExtension() )
				) {
					continue;
				}

				$file = $info->getRealPath();
				if ( ! $file ) {
					continue;
				}

				self::inspect_source_file(
					$file,
					$directory,
					$started,
					$inventory,
					$coverage
				);
			}
		}

		foreach ( $inventory as &$values ) {
			$values = array_values(
				array_slice(
					array_unique(
						array_filter( $values )
					),
					0,
					300
				)
			);
			sort( $values );
		}
		unset( $values );

		$evidence = self::inspect_site( $inventory, $started );
		$score    = self::score( $evidence );
		$answer   = self::answer( $score, $evidence );

		$coverage['seconds'] = round( microtime( true ) - $started, 3 );

		$result = array(
			'plugin' => array(
				'file'    => $plugin_file,
				'name'    => (string) ( $plugins[ $plugin_file ]['Name'] ?? $plugin_file ),
				'version' => (string) ( $plugins[ $plugin_file ]['Version'] ?? '' ),
				'active'  => is_plugin_active( $plugin_file ),
			),
			'score'    => $score,
			'answer'   => $answer,
			'evidence' => $evidence,
			'coverage' => $coverage,
			'caveats'  => array(
				'This reports detected evidence and plausible consequences, not certainty about every third-party runtime behavior.',
				'Plugin source, stored content, option values, metadata values, and absolute server paths are not included in this report or sent to AI.',
				'Hosting, SaaS, DNS, webhook, and vendor-account dependencies may require external confirmation.',
			),
		);

		Site_Agent_Audit_Log::record(
			'plugin_impact_scanned',
			array(
				'plugin'   => $plugin_file,
				'score'    => $score,
				'coverage' => $coverage,
			)
		);

		return $result;
	}

	private static function inspect_source_file(
		string $file,
		string $directory,
		float $started,
		array &$inventory,
		array &$coverage
	): void {
		if ( self::budget_exhausted( $started, $coverage ) ) {
			$coverage['truncated'] = true;
			return;
		}

		$real_file = realpath( $file );
		if ( ! $real_file || ! is_file( $real_file ) || is_link( $real_file ) ) {
			return;
		}

		$valid = hash_equals( $directory, dirname( $real_file ) )
			|| str_starts_with(
				$real_file,
				$directory . DIRECTORY_SEPARATOR
			);

		if ( ! $valid ) {
			return;
		}

		$size = (int) filesize( $real_file );
		if ( $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			return;
		}

		if ( (int) $coverage['bytes_scanned'] + $size > self::MAX_TOTAL_BYTES ) {
			$coverage['truncated'] = true;
			return;
		}

		$source = file_get_contents( $real_file );
		if ( false === $source ) {
			return;
		}

		$coverage['files_scanned']++;
		$coverage['bytes_scanned'] += strlen( $source );

		self::extract( $source, $inventory );
	}

	private static function budget_exhausted( float $started, array $coverage ): bool {
		return microtime( true ) - $started > self::MAX_SECONDS
			|| (int) $coverage['files_scanned'] >= self::MAX_FILES
			|| (int) $coverage['bytes_scanned'] >= self::MAX_TOTAL_BYTES;
	}

	private static function extract( string $source, array &$inventory ): void {
		$patterns = array(
			'shortcodes'  => '/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'blocks'      => '/register_block_type\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'post_types'  => '/register_post_type\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'taxonomies'  => '/register_taxonomy\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'rest_routes' => '/register_rest_route\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
			'options'     => '/(?:get|add|update|delete)_option\s*\(\s*[\'"]([^\'"]+)[\'"]/',
			'meta_keys'   => '/(?:get|add|update|delete)_(?:post|user|term)_meta\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/',
			'tables'      => '/\$wpdb->prefix\s*\.\s*[\'"]([a-zA-Z0-9_]+)[\'"]/',
		);

		foreach ( $patterns as $type => $pattern ) {
			if ( ! preg_match_all( $pattern, $source, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$value = 'rest_routes' === $type
					? trim( (string) $match[1], '/' )
						. '/'
						. trim( (string) $match[2], '/' )
					: (string) $match[1];

				if (
					'options' === $type
					&& Site_Agent_Redactor::is_sensitive_name( $value )
				) {
					continue;
				}

				$inventory[ $type ][] = sanitize_text_field( $value );
			}
		}

		$cron_patterns = array(
			'/wp_schedule_event\s*\(\s*[^,]+,\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
			'/wp_schedule_single_event\s*\(\s*[^,]+,\s*[\'"]([^\'"]+)[\'"]/',
			'/wp_(?:next_scheduled|unschedule_hook|clear_scheduled_hook)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
		);

		foreach ( $cron_patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $source, $matches ) ) {
				continue;
			}

			foreach ( (array) ( $matches[1] ?? array() ) as $hook ) {
				$inventory['cron_hooks'][] = sanitize_text_field( (string) $hook );
			}
		}
	}

	private static function inspect_site( array $inventory, float $started ): array {
		global $wpdb;

		$evidence = array(
			'content_references' => array(),
			'registered_content' => array(),
			'scheduled_events'   => array(),
			'stored_options'     => array(),
			'custom_tables'      => array(),
			'custom_code_refs'   => array(),
		);

		$markers = array_slice(
			array_unique(
				array_merge(
					$inventory['shortcodes'],
					$inventory['blocks']
				)
			),
			0,
			150
		);

		foreach ( $markers as $marker ) {
			if ( microtime( true ) - $started > self::MAX_SECONDS ) {
				break;
			}

			$needle = in_array( $marker, $inventory['blocks'], true )
				? '<!-- wp:' . $marker
				: '[' . $marker;

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts}
					WHERE post_status NOT IN ('auto-draft','inherit','trash')
					AND post_content LIKE %s",
					'%' . $wpdb->esc_like( $needle ) . '%'
				)
			);

			if ( $count > 0 ) {
				$evidence['content_references'][] = array(
					'marker' => $marker,
					'count'  => $count,
				);
			}
		}

		foreach ( $inventory['post_types'] as $post_type ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts}
					WHERE post_type = %s",
					$post_type
				)
			);

			if ( $count > 0 ) {
				$evidence['registered_content'][] = array(
					'post_type' => $post_type,
					'count'     => $count,
				);
			}
		}

		$cron = _get_cron_array();

		foreach ( $inventory['cron_hooks'] as $hook ) {
			$count = 0;

			foreach ( (array) $cron as $events ) {
				$count += isset( $events[ $hook ] )
					? count( (array) $events[ $hook ] )
					: 0;
			}

			if ( $count > 0 ) {
				$evidence['scheduled_events'][] = array(
					'hook'  => $hook,
					'count' => $count,
				);
			}
		}

		foreach ( $inventory['options'] as $name ) {
			$exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT 1
					FROM {$wpdb->options}
					WHERE option_name = %s
					LIMIT 1",
					$name
				)
			);

			if ( $exists ) {
				$evidence['stored_options'][] = array(
					'name'       => $name,
					'value_read' => false,
				);
			}
		}

		foreach ( $inventory['tables'] as $suffix ) {
			$table  = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SHOW TABLES LIKE %s',
					$table
				)
			);

			if ( $exists !== $table ) {
				continue;
			}

			$size = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COALESCE(DATA_LENGTH + INDEX_LENGTH, 0)
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s
					AND TABLE_NAME = %s',
					DB_NAME,
					$table
				)
			);

			$evidence['custom_tables'][] = array(
				'name'  => $suffix,
				'bytes' => $size,
			);
		}

		$code_markers = array_slice(
			array_unique(
				array_filter(
					array_merge(
						$inventory['shortcodes'],
						$inventory['blocks'],
						$inventory['post_types'],
						$inventory['cron_hooks'],
						$inventory['rest_routes']
					),
					static fn( string $value ): bool => strlen( $value ) >= 4
				)
			),
			0,
			150
		);

		$evidence['custom_code_refs'] = self::scan_custom_code(
			$code_markers,
			$started
		);

		return $evidence;
	}

	private static function scan_custom_code( array $markers, float $started ): array {
		if ( empty( $markers ) ) {
			return array();
		}

		$roots = array();

		$theme_directory = get_stylesheet_directory();
		if ( is_dir( $theme_directory ) ) {
			$roots[] = $theme_directory;
		}

		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$roots[] = WPMU_PLUGIN_DIR;
		}

		$found = array();
		$files = 0;

		foreach ( $roots as $root ) {
			$real_root = realpath( $root );
			if ( ! $real_root ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator(
					$real_root,
					FilesystemIterator::SKIP_DOTS
					| FilesystemIterator::CURRENT_AS_FILEINFO
				)
			);

			foreach ( $iterator as $info ) {
				if (
					$files >= 300
					|| microtime( true ) - $started > self::MAX_SECONDS
				) {
					break 2;
				}

				if (
					! $info instanceof SplFileInfo
					|| ! $info->isFile()
					|| $info->isLink()
					|| ! in_array(
						strtolower( $info->getExtension() ),
						array( 'php', 'js', 'json', 'css' ),
						true
					)
				) {
					continue;
				}

				$file = $info->getRealPath();
				$size = (int) $info->getSize();

				if (
					! $file
					|| ! str_starts_with(
						$file,
						$real_root . DIRECTORY_SEPARATOR
					)
					|| $size <= 0
					|| $size > 524288
				) {
					continue;
				}

				$source = file_get_contents( $file );
				if ( false === $source ) {
					continue;
				}

				$files++;

				foreach ( $markers as $marker ) {
					if ( str_contains( $source, $marker ) ) {
						$found[ $marker ] = ( $found[ $marker ] ?? 0 ) + 1;
					}
				}
			}
		}

		$output = array();

		foreach ( $found as $marker => $count ) {
			$output[] = array(
				'marker'     => $marker,
				'file_count' => $count,
			);
		}

		return $output;
	}

	private static function score( array $evidence ): int {
		$score  = 1;
		$score += min( 3, count( $evidence['content_references'] ) );
		$score += min( 2, count( $evidence['registered_content'] ) );
		$score += ! empty( $evidence['scheduled_events'] ) ? 1 : 0;
		$score += ! empty( $evidence['custom_tables'] ) ? 1 : 0;
		$score += ! empty( $evidence['custom_code_refs'] ) ? 2 : 0;

		return max( 1, min( 10, $score ) );
	}

	private static function answer( int $score, array $evidence ): string {
		if ( $score >= 8 ) {
			return 'Removal has several detected dependencies. Review the evidence, test on staging, and preserve a rollback path.';
		}

		if ( $score >= 5 ) {
			return 'Removal may leave content, data, scheduled work, or custom-code references. Review the detected evidence before deactivation.';
		}

		if ( array_filter( $evidence ) ) {
			return 'Some related artifacts were detected. They may be harmless, but they should be reviewed before removal.';
		}

		return 'No direct site dependency was detected within the bounded scan. That lowers risk but does not prove removal is consequence-free.';
	}
}
