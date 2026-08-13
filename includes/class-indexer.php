<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Indexer {
	private const PHASES = array(
		'posts',
		'system',
		'plugins',
		'themes',
		'content_types',
		'builders',
		'forms',
		'cron',
		'safe_options',
		'database',
		'roles',
		'complete',
	);

	public static function begin(): array {
		$generation = wp_generate_uuid4();

		update_option( 'site_agent_index_build_generation', $generation, false );

		$state = array(
			'generation' => $generation,
			'phase'      => 'posts',
			'last_id'    => 0,
			'processed'  => 0,
			'started'    => time(),
		);

		Site_Agent_Audit_Log::record(
			'index_started',
			array( 'generation' => $generation )
		);

		return $state;
	}

	public static function batch( array $state ): array|WP_Error {
		$generation = sanitize_text_field( (string) ( $state['generation'] ?? '' ) );
		$current     = (string) get_option( 'site_agent_index_build_generation', '' );

		if ( ! wp_is_uuid( $generation ) || ! $current || ! hash_equals( $current, $generation ) ) {
			return new WP_Error(
				'stale_index_generation',
				__( 'This index rebuild has been replaced by a newer rebuild.', 'site-agent' )
			);
		}

		$phase = sanitize_key( (string) ( $state['phase'] ?? 'posts' ) );
		if ( ! in_array( $phase, self::PHASES, true ) ) {
			$phase = 'posts';
		}

		$started = microtime( true );
		$batch   = self::batch_size();
		$added   = 0;

		if ( 'posts' === $phase ) {
			$result = self::index_posts(
				(int) ( $state['last_id'] ?? 0 ),
				$batch,
				$generation
			);

			$added              = (int) $result['added'];
			$state['processed'] = (int) ( $state['processed'] ?? 0 ) + $added;

			if ( $result['done'] ) {
				$state['phase']   = 'system';
				$state['last_id'] = 0;
			} else {
				$state['last_id'] = (int) $result['last_id'];
			}
		} elseif ( 'complete' !== $phase ) {
			self::index_phase( $phase, $generation );
			$added              = 1;
			$state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;
			$state['phase']     = self::next_phase( $phase );
		}

		$done = 'complete' === $state['phase'];

		if ( $done ) {
			self::activate_generation( $generation, $state );
		}

		$state['done']           = $done;
		$state['added']          = $added;
		$state['elapsed_ms']     = (int) round( ( microtime( true ) - $started ) * 1000 );
		$state['time_budget_ms'] = 8000;
		$state['message']        = $done
			? __( 'Knowledge index rebuilt.', 'site-agent' )
			: sprintf(
				/* translators: %s: indexing phase. */
				__( 'Indexed a bounded batch. Next phase: %s.', 'site-agent' ),
				$state['phase']
			);

		return $state;
	}

	private static function activate_generation( string $generation, array $state ): void {
		global $wpdb;

		update_option( 'site_agent_index_active_generation', $generation, false );
		update_option( 'site_agent_index_build_generation', '', false );
		update_option( 'site_agent_index_last_completed_gmt', Site_Agent_Database::utc_now(), false );

		$table = Site_Agent_Database::table( 'index' );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE generation <> %s",
				$generation
			)
		);

		Site_Agent_Audit_Log::record(
			'index_completed',
			array(
				'generation' => $generation,
				'processed'  => (int) ( $state['processed'] ?? 0 ),
				'seconds'    => max( 0, time() - (int) ( $state['started'] ?? time() ) ),
			)
		);
	}

	private static function batch_size(): int {
		$settings = get_option( 'site_agent_settings', array() );

		return max(
			20,
			min( 500, (int) ( $settings['index_batch_size'] ?? 100 ) )
		);
	}

	private static function index_posts( int $last_id, int $limit, string $generation ): array {
		global $wpdb;

		$statuses       = get_post_stati( array(), 'names' );
		$statuses       = array_values( array_diff( $statuses, array( 'auto-draft', 'inherit' ) ) );
		$excluded_types = self::excluded_post_types();

		$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$type_placeholders   = implode( ',', array_fill( 0, count( $excluded_types ), '%s' ) );
		$args                = array_merge(
			array( $last_id ),
			$statuses,
			$excluded_types,
			array( $limit )
		);

		$sql = $wpdb->prepare(
			"SELECT ID, post_type, post_status, post_title, post_excerpt, post_content, post_modified_gmt
			FROM {$wpdb->posts}
			WHERE ID > %d
			AND post_status IN ({$status_placeholders})
			AND post_type NOT IN ({$type_placeholders})
			ORDER BY ID ASC
			LIMIT %d",
			$args
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$max  = $last_id;

		foreach ( $rows as $row ) {
			$id  = (int) $row['ID'];
			$max = max( $max, $id );

			$content = wp_strip_all_tags(
				strip_shortcodes( (string) $row['post_content'] )
			);
			$content = preg_replace( '/\s+/', ' ', $content ) ?? $content;

			$summary = array(
				'status'  => sanitize_key( (string) $row['post_status'] ),
				'excerpt' => self::truncate(
					wp_strip_all_tags( (string) $row['post_excerpt'] ),
					2000
				),
				'content' => self::truncate( $content, 10000 ),
				'url'     => get_permalink( $id ) ?: '',
			);

			self::upsert(
				'post',
				(string) $id,
				sanitize_key( (string) $row['post_type'] ),
				get_the_title( $id ) ?: '(untitled)',
				$summary,
				array(
					'post_type:' . sanitize_key( (string) $row['post_type'] ),
					'status:' . sanitize_key( (string) $row['post_status'] ),
				),
				$generation,
				(string) ( $row['post_modified_gmt'] ?: Site_Agent_Database::utc_now() )
			);
		}

		return array(
			'added'   => count( $rows ),
			'last_id' => $max,
			'done'    => count( $rows ) < $limit,
		);
	}

	private static function excluded_post_types(): array {
		$types = array(
			'revision',
			'attachment',
			'shop_order',
			'shop_order_refund',
			'shop_coupon',
			'nf_sub',
			'flamingo_inbound',
			'flamingo_contact',
			'wpforms_entry',
			'frm_entries',
			'edd_payment',
			'tribe_rsvp_attendees',
		);

		foreach ( get_post_types( array(), 'names' ) as $type ) {
			if ( preg_match( '/(?:submission|entry|lead|attendee|payment|transaction|order|customer|contact_log)/i', $type ) ) {
				$types[] = $type;
			}
		}

		$types = array_values(
			array_unique(
				array_map( 'sanitize_key', $types )
			)
		);

		return (array) apply_filters(
			'site_agent_excluded_index_post_types',
			$types
		);
	}

	private static function index_phase( string $phase, string $generation ): void {
		switch ( $phase ) {
			case 'system':
				self::index_system( $generation );
				break;

			case 'plugins':
				self::index_plugins( $generation );
				break;

			case 'themes':
				self::index_themes( $generation );
				break;

			case 'content_types':
				self::index_content_types( $generation );
				break;

			case 'builders':
				self::index_builders( $generation );
				break;

			case 'forms':
				self::index_forms( $generation );
				break;

			case 'cron':
				self::index_cron( $generation );
				break;

			case 'safe_options':
				self::index_safe_options( $generation );
				break;

			case 'database':
				self::index_database( $generation );
				break;

			case 'roles':
				self::index_roles( $generation );
				break;
		}
	}

	private static function index_system( string $generation ): void {
		global $wpdb;

		$theme = wp_get_theme();

		self::upsert(
			'system',
			'site',
			'wordpress',
			(string) get_bloginfo( 'name' ),
			array(
				'home_url'      => home_url( '/' ),
				'wordpress'     => get_bloginfo( 'version' ),
				'php'           => PHP_VERSION,
				'database'      => $wpdb->db_version(),
				'multisite'     => is_multisite(),
				'locale'        => get_locale(),
				'timezone'      => wp_timezone_string(),
				'active_theme'  => $theme->get( 'Name' ),
				'permalink'     => get_option( 'permalink_structure' ),
				'front_page_id' => (int) get_option( 'page_on_front' ),
				'posts_page_id' => (int) get_option( 'page_for_posts' ),
			),
			array( 'site', 'environment', 'wordpress', 'hosting' ),
			$generation
		);
	}

	private static function index_plugins( string $generation ): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite()
			? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
			: array();

		foreach ( get_plugins() as $file => $data ) {
			$status = in_array( $file, $network, true )
				? 'network-active'
				: ( in_array( $file, $active, true ) ? 'active' : 'inactive' );

			self::upsert(
				'plugin',
				$file,
				$status,
				(string) ( $data['Name'] ?? $file ),
				array(
					'version'      => (string) ( $data['Version'] ?? '' ),
					'author'       => wp_strip_all_tags(
						(string) ( $data['AuthorName'] ?? $data['Author'] ?? '' )
					),
					'description'  => wp_strip_all_tags( (string) ( $data['Description'] ?? '' ) ),
					'status'       => $status,
					'requires_wp'  => (string) ( $data['RequiresWP'] ?? '' ),
					'requires_php' => (string) ( $data['RequiresPHP'] ?? '' ),
				),
				array( 'plugin', $status ),
				$generation
			);
		}
	}

	private static function index_themes( string $generation ): void {
		$active = get_stylesheet();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$status = $slug === $active ? 'active' : 'inactive';

			self::upsert(
				'theme',
				$slug,
				$status,
				(string) $theme->get( 'Name' ),
				array(
					'version'     => (string) $theme->get( 'Version' ),
					'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
					'description' => wp_strip_all_tags( (string) $theme->get( 'Description' ) ),
					'status'      => $status,
					'template'    => (string) $theme->get_template(),
				),
				array( 'theme', $status ),
				$generation
			);
		}
	}

	private static function index_content_types( string $generation ): void {
		foreach ( get_post_types( array(), 'objects' ) as $name => $object ) {
			self::upsert(
				'post_type',
				$name,
				(bool) $object->public ? 'public' : 'private',
				(string) $object->labels->name,
				array(
					'name'         => $name,
					'public'       => (bool) $object->public,
					'show_ui'      => (bool) $object->show_ui,
					'hierarchical' => (bool) $object->hierarchical,
					'rest_base'    => (string) ( $object->rest_base ?: $name ),
				),
				array( 'content type', $name ),
				$generation
			);
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $name => $object ) {
			self::upsert(
				'taxonomy',
				$name,
				(bool) $object->public ? 'public' : 'private',
				(string) $object->labels->name,
				array(
					'name'         => $name,
					'public'       => (bool) $object->public,
					'hierarchical' => (bool) $object->hierarchical,
					'object_types' => array_values( (array) $object->object_type ),
				),
				array( 'taxonomy', $name ),
				$generation
			);
		}
	}

	private static function index_builders( string $generation ): void {
		global $wpdb;

		$builders = array(
			'Elementor'      => '_elementor_data',
			'Divi'           => '_et_pb_use_builder',
			'Beaver Builder' => '_fl_builder_enabled',
			'Bricks'         => '_bricks_page_content_2',
			'WPBakery'       => '_wpb_vc_js_status',
		);

		foreach ( $builders as $name => $meta_key ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT post_id)
					FROM {$wpdb->postmeta}
					WHERE meta_key = %s",
					$meta_key
				)
			);

			if ( $count <= 0 ) {
				continue;
			}

			self::upsert(
				'builder',
				sanitize_key( $name ),
				'detected',
				$name,
				array(
					'content_items' => $count,
					'marker'        => $meta_key,
					'note'          => 'Builder payloads are excluded from the AI knowledge index and retained only in supported rollback snapshots.',
				),
				array( 'page builder', $name ),
				$generation
			);
		}

		$divi_count = (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_content LIKE '%[et_pb_%'
			AND post_status NOT IN ('auto-draft','inherit')"
		);

		if ( $divi_count > 0 ) {
			self::upsert(
				'builder',
				'divi-shortcodes',
				'detected',
				'Divi shortcode layouts',
				array( 'content_items' => $divi_count ),
				array( 'Divi', 'shortcodes', 'page builder' ),
				$generation
			);
		}
	}

	private static function index_forms( string $generation ): void {
		global $wpdb;

		$post_form_types = array(
			'wpcf7_contact_form' => 'Contact Form 7',
			'wpforms'            => 'WPForms',
		);

		foreach ( $post_form_types as $post_type => $label ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*)
					FROM {$wpdb->posts}
					WHERE post_type = %s
					AND post_status NOT IN ('trash','auto-draft')",
					$post_type
				)
			);

			if ( $count > 0 ) {
				self::upsert(
					'forms',
					$post_type,
					'detected',
					$label,
					array(
						'form_count'          => $count,
						'submissions_indexed' => false,
					),
					array( 'forms', $label ),
					$generation
				);
			}
		}

		$gravity_table = $wpdb->prefix . 'gf_form';
		if ( self::table_exists( $gravity_table ) ) {
			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$gravity_table}` WHERE is_trash = 0"
			);

			self::upsert(
				'forms',
				'gravityforms',
				'detected',
				'Gravity Forms',
				array(
					'form_count'          => $count,
					'submissions_indexed' => false,
				),
				array( 'forms', 'Gravity Forms' ),
				$generation
			);
		}

		$table_forms = array(
			'nf3_forms' => 'Ninja Forms',
			'frm_forms' => 'Formidable Forms',
		);

		foreach ( $table_forms as $suffix => $label ) {
			$table = $wpdb->prefix . $suffix;

			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			$count = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM `{$table}`"
			);

			self::upsert(
				'forms',
				$suffix,
				'detected',
				$label,
				array(
					'form_count'          => $count,
					'submissions_indexed' => false,
				),
				array( 'forms', $label ),
				$generation
			);
		}
	}

	private static function index_cron( string $generation ): void {
		$cron    = _get_cron_array();
		$hooks   = array();
		$overdue = 0;
		$now     = time();

		foreach ( (array) $cron as $timestamp => $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				$count          = count( (array) $instances );
				$hooks[ $hook ] = ( $hooks[ $hook ] ?? 0 ) + $count;

				if ( (int) $timestamp < $now - HOUR_IN_SECONDS ) {
					$overdue += $count;
				}
			}
		}

		arsort( $hooks );

		self::upsert(
			'cron',
			'schedule',
			'wordpress',
			'WordPress scheduled events',
			array(
				'total_instances' => array_sum( $hooks ),
				'unique_hooks'     => count( $hooks ),
				'overdue'          => $overdue,
				'hooks'            => array_slice( $hooks, 0, 150, true ),
			),
			array( 'cron', 'scheduled tasks', 'background jobs' ),
			$generation
		);
	}

	private static function index_safe_options( string $generation ): void {
		$safe_options = array(
			'blogname',
			'blogdescription',
			'timezone_string',
			'date_format',
			'time_format',
			'start_of_week',
			'permalink_structure',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'default_role',
			'posts_per_page',
			'blog_public',
		);

		foreach ( $safe_options as $name ) {
			self::upsert(
				'option',
				$name,
				'safe',
				$name,
				array(
					'value' => Site_Agent_Redactor::redact( get_option( $name ) ),
				),
				array( 'setting', $name ),
				$generation
			);
		}
	}

	private static function index_database( string $generation ): void {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME, ENGINE, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
				LIMIT 100',
				DB_NAME
			),
			ARRAY_A
		);

		$tables = array();

		foreach ( $rows as $row ) {
			$name = (string) $row['TABLE_NAME'];

			$tables[] = array(
				'name'   => str_starts_with( $name, $wpdb->prefix )
					? substr( $name, strlen( $wpdb->prefix ) )
					: $name,
				'engine' => (string) $row['ENGINE'],
				'rows'   => (int) $row['TABLE_ROWS'],
				'bytes'  => (int) $row['DATA_LENGTH'] + (int) $row['INDEX_LENGTH'],
			);
		}

		self::upsert(
			'database',
			'tables',
			'inventory',
			'Database table inventory',
			array(
				'tables' => $tables,
				'count'  => count( $tables ),
			),
			array( 'database', 'tables', 'size', 'storage' ),
			$generation
		);
	}

	private static function index_roles( string $generation ): void {
		$counts = count_users();
		$roles  = wp_roles();

		foreach ( $roles->roles as $slug => $data ) {
			self::upsert(
				'role',
				$slug,
				'wordpress',
				(string) $data['name'],
				array(
					'user_count'       => (int) ( $counts['avail_roles'][ $slug ] ?? 0 ),
					'capability_count' => count(
						array_filter( (array) $data['capabilities'] )
					),
				),
				array( 'role', 'permissions', $slug ),
				$generation
			);
		}
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table;
	}

	private static function upsert(
		string $type,
		string $object_id,
		string $subtype,
		string $title,
		mixed $summary,
		array $keywords,
		string $generation,
		string $modified_gmt = ''
	): void {
		global $wpdb;

		$summary      = Site_Agent_Redactor::redact( $summary );
		$keywords     = Site_Agent_Redactor::redact( $keywords );
		$summary_json = wp_json_encode(
			$summary,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$keyword_text = implode(
			' ',
			array_map( 'strval', (array) $keywords )
		);

		if ( false === $summary_json ) {
			$summary_json = '{}';
		}

		$summary_json = self::truncate( $summary_json, 65535 );
		$keyword_text = self::truncate( $keyword_text, 12000 );

		$wpdb->replace(
			Site_Agent_Database::table( 'index' ),
			array(
				'object_type'         => sanitize_key( $type ),
				'object_id'           => self::truncate( $object_id, 191 ),
				'subtype'             => self::truncate( sanitize_key( $subtype ), 80 ),
				'title'               => self::truncate(
					Site_Agent_Redactor::redact_string(
						wp_strip_all_tags( $title )
					),
					2000
				),
				'summary'             => $summary_json,
				'keywords'            => $keyword_text,
				'fingerprint'         => hash(
					'sha256',
					$title . '|' . $summary_json . '|' . $keyword_text
				),
				'generation'          => $generation,
				'required_capability' => 'site_agent_inspect',
				'modified_gmt'        => $modified_gmt ?: Site_Agent_Database::utc_now(),
				'indexed_gmt'         => Site_Agent_Database::utc_now(),
			),
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}

	private static function next_phase( string $phase ): string {
		$index = array_search( $phase, self::PHASES, true );

		if ( false === $index || ! isset( self::PHASES[ $index + 1 ] ) ) {
			return 'complete';
		}

		return self::PHASES[ $index + 1 ];
	}

	private static function truncate( string $value, int $limit ): string {
		if ( strlen( $value ) <= $limit ) {
			return $value;
		}

		return substr( $value, 0, max( 0, $limit - 80 ) )
			. '… [truncated sha256:'
			. hash( 'sha256', $value )
			. ']';
	}

	public static function stats(): array {
		global $wpdb;

		$table  = Site_Agent_Database::table( 'index' );
		$active = (string) get_option( 'site_agent_index_active_generation', '' );

		$rows = $active
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT object_type, COUNT(*) AS total
					FROM {$table}
					WHERE generation = %s
					GROUP BY object_type
					ORDER BY total DESC",
					$active
				),
				ARRAY_A
			)
			: array();

		return array(
			'last_completed_gmt'  => get_option( 'site_agent_index_last_completed_gmt', '' ),
			'generation'          => $active,
			'building_generation' => get_option( 'site_agent_index_build_generation', '' ),
			'total'               => array_sum(
				array_map(
					static fn( array $row ): int => (int) $row['total'],
					$rows
				)
			),
			'types'               => $rows,
		);
	}
}
