<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Ledger {
	private static ?self $instance = null;
	private static int $internal_depth = 0;
	private static array $meta_before = array();

	private const BUILDER_META = array(
		'_elementor_data',
		'_elementor_page_settings',
		'_elementor_template_type',
		'_et_pb_use_builder',
		'_et_pb_page_layout',
		'_fl_builder_data',
		'_fl_builder_draft',
		'_bricks_page_content_2',
		'_wpb_vc_js_status',
	);

	private const SEO_META = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
	);

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks(): void {
		add_action( 'post_updated', array( $this, 'post_updated' ), 20, 3 );
		add_filter( 'pre_update_post_metadata', array( $this, 'before_post_meta_update' ), 5, 5 );
		add_action( 'updated_post_meta', array( $this, 'post_meta_updated' ), 20, 4 );
		add_action( 'added_post_meta', array( $this, 'post_meta_added' ), 20, 4 );
		add_action( 'deleted_post_meta', array( $this, 'post_meta_deleted' ), 20, 4 );
		add_action( 'updated_option', array( $this, 'option_updated' ), 20, 3 );
		add_action( 'added_option', array( $this, 'option_added' ), 20, 2 );
		add_action( 'deleted_option', array( $this, 'option_deleted' ), 20, 1 );
		add_action( 'activated_plugin', array( $this, 'plugin_activated' ), 20, 2 );
		add_action( 'deactivated_plugin', array( $this, 'plugin_deactivated' ), 20, 2 );
		add_action( 'switch_theme', array( $this, 'theme_switched' ), 20, 3 );
	}

	public static function begin_internal(): void {
		self::$internal_depth++;
	}

	public static function end_internal(): void {
		self::$internal_depth = max( 0, self::$internal_depth - 1 );
	}

	private static function is_internal(): bool {
		return self::$internal_depth > 0;
	}

	public function post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( self::is_internal() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'revision' === $after->post_type ) {
			return;
		}
		$old = self::post_state( $before );
		$new = self::post_state( $after );
		if ( Site_Agent_Codec::fingerprint( $old ) === Site_Agent_Codec::fingerprint( $new ) ) {
			return;
		}
		self::record(
			'post.update',
			'post',
			(string) $post_id,
			$old,
			$new,
			'wordpress',
			self::post_risk( $old, $new ),
			array( 'post_type' => $after->post_type )
		);
	}

	public function before_post_meta_update( mixed $check, int $object_id, string $meta_key, mixed $meta_value, mixed $prev_value ): mixed {
		if ( self::is_internal() || ! self::track_meta_key( $meta_key ) ) {
			return $check;
		}
		self::$meta_before[ $object_id . '|' . $meta_key ] = get_post_meta( $object_id, $meta_key, false );
		return $check;
	}

	public function post_meta_updated( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( self::is_internal() || ! self::track_meta_key( $meta_key ) ) {
			return;
		}
		$key = $object_id . '|' . $meta_key;
		$before = self::$meta_before[ $key ] ?? array();
		unset( self::$meta_before[ $key ] );
		$after = get_post_meta( $object_id, $meta_key, false );
		self::record_meta( 'post_meta.update', $object_id, $meta_key, $before, $after );
	}

	public function post_meta_added( int $meta_id, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( self::is_internal() || ! self::track_meta_key( $meta_key ) ) {
			return;
		}
		self::record_meta( 'post_meta.add', $object_id, $meta_key, array(), get_post_meta( $object_id, $meta_key, false ) );
	}

	public function post_meta_deleted( array $meta_ids, int $object_id, string $meta_key, mixed $meta_value ): void {
		if ( self::is_internal() || ! self::track_meta_key( $meta_key ) ) {
			return;
		}
		$before = array();
		if ( null !== $meta_value && '' !== $meta_value ) {
			$before = array( $meta_value );
		}
		self::record_meta( 'post_meta.delete', $object_id, $meta_key, $before, get_post_meta( $object_id, $meta_key, false ) );
	}

	private static function record_meta( string $action, int $post_id, string $meta_key, array $before, array $after ): void {
		if ( Site_Agent_Codec::fingerprint( $before ) === Site_Agent_Codec::fingerprint( $after ) ) {
			return;
		}
		self::record(
			$action,
			'post_meta',
			$post_id . ':' . $meta_key,
			array( 'post_id' => $post_id, 'meta_key' => $meta_key, 'values' => $before ),
			array( 'post_id' => $post_id, 'meta_key' => $meta_key, 'values' => $after ),
			'wordpress',
			in_array( $meta_key, self::BUILDER_META, true ) ? 'high' : 'medium',
			array( 'post_id' => $post_id, 'meta_key' => $meta_key )
		);
	}

	public function option_updated( string $option, mixed $old, mixed $new ): void {
		if ( self::is_internal() || ! self::track_option( $option ) ) {
			return;
		}
		self::record(
			'option.update',
			'option',
			$option,
			array( 'exists' => true, 'value' => $old ),
			array( 'exists' => true, 'value' => $new ),
			'wordpress',
			self::option_risk( $option ),
			array( 'option' => $option )
		);
	}

	public function option_added( string $option, mixed $value ): void {
		if ( self::is_internal() || ! self::track_option( $option ) ) {
			return;
		}
		self::record(
			'option.add',
			'option',
			$option,
			array( 'exists' => false ),
			array( 'exists' => true, 'value' => $value ),
			'wordpress',
			self::option_risk( $option ),
			array( 'option' => $option )
		);
	}

	public function option_deleted( string $option ): void {
		// WordPress does not pass the deleted value here. Do not fake reversibility.
		if ( self::is_internal() || ! self::track_option( $option ) ) {
			return;
		}
		self::record(
			'option.delete',
			'option',
			$option,
			null,
			array( 'exists' => false ),
			'wordpress',
			self::option_risk( $option ),
			array( 'option' => $option, 'before_not_captured' => true ),
			false
		);
	}

	public function plugin_activated( string $plugin, bool $network_wide ): void {
		if ( self::is_internal() ) {
			return;
		}
		self::record(
			'plugin.activate',
			'plugin',
			plugin_basename( $plugin ),
			array( 'active' => false, 'network_wide' => $network_wide ),
			array( 'active' => true, 'network_wide' => $network_wide ),
			'wordpress',
			'high',
			array( 'network_wide' => $network_wide ),
			! $network_wide
		);
	}

	public function plugin_deactivated( string $plugin, bool $network_wide ): void {
		if ( self::is_internal() ) {
			return;
		}
		self::record(
			'plugin.deactivate',
			'plugin',
			plugin_basename( $plugin ),
			array( 'active' => true, 'network_wide' => $network_wide ),
			array( 'active' => false, 'network_wide' => $network_wide ),
			'wordpress',
			'high',
			array( 'network_wide' => $network_wide ),
			! $network_wide
		);
	}

	public function theme_switched( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ): void {
		if ( self::is_internal() ) {
			return;
		}
		self::record(
			'theme.switch',
			'theme',
			(string) $new_theme->get_stylesheet(),
			array( 'stylesheet' => (string) $old_theme->get_stylesheet() ),
			array( 'stylesheet' => (string) $new_theme->get_stylesheet() ),
			'wordpress',
			'high',
			array(),
			true
		);
	}

	public static function record(
		string $action,
		string $object_type,
		string $object_id,
		mixed $before,
		mixed $after,
		string $source = 'site_agent',
		string $risk = 'low',
		array $metadata = array(),
		?bool $reversible = null,
		int $rollback_of = 0
	): int {
		global $wpdb;
		$before_payload = Site_Agent_Codec::encode( $before );
		$after_payload  = Site_Agent_Codec::encode( $after );
		if ( null === $reversible ) {
			$reversible = null !== $before_payload && null !== $after_payload;
		}
		if ( null === $before_payload || null === $after_payload ) {
			$reversible = false;
		}
		$risk = in_array( $risk, array( 'low', 'medium', 'high' ), true ) ? $risk : 'high';

		$wpdb->insert(
			Site_Agent_Database::table( 'ledger' ),
			array(
				'actor_id'          => get_current_user_id(),
				'source'            => sanitize_key( $source ),
				'action_name'       => sanitize_key( str_replace( '.', '_', $action ) ),
				'object_type'       => sanitize_key( $object_type ),
				'object_id'         => substr( $object_id, 0, 191 ),
				'risk'              => $risk,
				'before_payload'    => $before_payload,
				'after_payload'     => $after_payload,
				'before_fingerprint'=> Site_Agent_Codec::fingerprint( $before ),
				'after_fingerprint' => Site_Agent_Codec::fingerprint( $after ),
				'metadata'          => Site_Agent_Redactor::safe_json( $metadata ),
				'reversible'        => $reversible ? 1 : 0,
				'status'            => 'completed',
				'rollback_of'       => $rollback_of,
				'created_gmt'       => Site_Agent_Database::utc_now(),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		Site_Agent_Audit_Log::record(
			'ledger_recorded',
			array( 'ledger_id' => $id, 'action' => $action, 'object_type' => $object_type, 'object_id' => $object_id, 'reversible' => $reversible )
		);
		return $id;
	}

	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$table = Site_Agent_Database::table( 'ledger' );
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		return array_map( array( self::class, 'public_row' ), $rows );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . Site_Agent_Database::table( 'ledger' ) . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function rollback( int $id, bool $force = false ): array|WP_Error {
		$row = self::get( $id );
		if ( ! $row || ! (int) $row['reversible'] || 'completed' !== $row['status'] ) {
			return new WP_Error( 'not_reversible', __( 'This change does not have a supported rollback snapshot.', 'site-agent' ) );
		}
		if ( ! current_user_can( 'site_agent_rollback' ) ) {
			return new WP_Error( 'forbidden', __( 'You are not allowed to roll changes back.', 'site-agent' ) );
		}

		$before = Site_Agent_Codec::decode( (string) $row['before_payload'] );
		$after  = Site_Agent_Codec::decode( (string) $row['after_payload'] );
		if ( null === $before || null === $after ) {
			return new WP_Error( 'snapshot_corrupt', __( 'The rollback snapshot could not be decoded or verified.', 'site-agent' ) );
		}

		$permission = self::can_rollback_target( $row, $before );
		if ( is_wp_error( $permission ) ) {
			return $permission;
		}

		$current = self::current_state( $row, $after );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$current_fingerprint = Site_Agent_Codec::fingerprint( $current );
		if ( ! hash_equals( (string) $row['after_fingerprint'], $current_fingerprint ) && ! $force ) {
			return new WP_Error(
				'rollback_conflict',
				__( 'The target changed again after this ledger entry. Review the newer state before forcing rollback.', 'site-agent' ),
				array( 'requires_force' => true )
			);
		}
		if ( $force && ! current_user_can( 'site_agent_execute_high' ) ) {
			return new WP_Error( 'force_forbidden', __( 'Forced rollback requires high-risk execution permission.', 'site-agent' ) );
		}

		self::begin_internal();
		try {
			$result = self::restore_state( $row, $before );
		} finally {
			self::end_internal();
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$restored = self::current_state( $row, $before );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}
		$rollback_id = self::record(
			'rollback.perform',
			(string) $row['object_type'],
			(string) $row['object_id'],
			$current,
			$restored,
			'site_agent',
			'high',
			array( 'rolled_back_ledger_id' => $id, 'forced' => $force ),
			true,
			$id
		);

		return array(
			'rolled_back'       => true,
			'ledger_id'         => $id,
			'rollback_ledger_id'=> $rollback_id,
		);
	}

	private static function current_state( array $row, mixed $fallback ): mixed {
		$type = (string) $row['object_type'];
		$id   = (string) $row['object_id'];
		if ( 'post' === $type ) {
			$post = get_post( (int) $id );
			return $post ? self::post_state( $post ) : array( 'exists' => false );
		}
		if ( 'post_meta' === $type ) {
			$metadata = json_decode( (string) $row['metadata'], true ) ?: array();
			$post_id = (int) ( $metadata['post_id'] ?? 0 );
			$key = (string) ( $metadata['meta_key'] ?? '' );
			return array( 'post_id' => $post_id, 'meta_key' => $key, 'values' => get_post_meta( $post_id, $key, false ) );
		}
		if ( 'option' === $type ) {
			$sentinel = new stdClass();
			$value = get_option( $id, $sentinel );
			return $value === $sentinel ? array( 'exists' => false ) : array( 'exists' => true, 'value' => $value );
		}
		if ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			return array( 'active' => is_plugin_active( $id ), 'network_wide' => false );
		}
		if ( 'theme' === $type ) {
			return array( 'stylesheet' => get_stylesheet() );
		}
		return $fallback;
	}

	private static function restore_state( array $row, mixed $before ): bool|WP_Error {
		$type = (string) $row['object_type'];
		$id   = (string) $row['object_id'];

		if ( 'post' === $type ) {
			if ( ! is_array( $before ) ) {
				return new WP_Error( 'invalid_snapshot', __( 'The post snapshot is invalid.', 'site-agent' ) );
			}
			if ( empty( $before['exists'] ) ) {
				return wp_trash_post( (int) $id )
					? true
					: new WP_Error( 'created_post_rollback_failed', __( 'The newly created post could not be moved to Trash.', 'site-agent' ) );
			}
			$data = $before;
			unset( $data['exists'], $data['post_type'] );
			$data['ID'] = (int) $id;
			$result = wp_update_post( wp_slash( $data ), true );
			return is_wp_error( $result ) ? $result : true;
		}

		if ( 'post_meta' === $type ) {
			$post_id = (int) ( $before['post_id'] ?? 0 );
			$key = (string) ( $before['meta_key'] ?? '' );
			if ( ! $post_id || ! self::track_meta_key( $key ) ) {
				return new WP_Error( 'invalid_snapshot', __( 'The metadata snapshot is invalid.', 'site-agent' ) );
			}
			delete_post_meta( $post_id, $key );
			foreach ( (array) ( $before['values'] ?? array() ) as $value ) {
				add_post_meta( $post_id, $key, $value );
			}
			return true;
		}

		if ( 'option' === $type ) {
			if ( ! is_array( $before ) ) {
				return new WP_Error( 'invalid_snapshot', __( 'The option snapshot is invalid.', 'site-agent' ) );
			}
			if ( ! empty( $before['exists'] ) ) {
				update_option( $id, $before['value'] ?? null );
			} else {
				delete_option( $id );
			}
			return true;
		}

		if ( 'plugin' === $type ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			if ( ! empty( $before['network_wide'] ) ) {
				return new WP_Error( 'network_rollback_unsupported', __( 'Network-wide plugin rollback is intentionally unsupported.', 'site-agent' ) );
			}
			if ( ! empty( $before['active'] ) ) {
				$result = activate_plugin( $id, '', false, true );
				return is_wp_error( $result ) ? $result : true;
			}
			deactivate_plugins( $id, true, false );
			return true;
		}

		if ( 'theme' === $type ) {
			$stylesheet = sanitize_key( (string) ( $before['stylesheet'] ?? '' ) );
			if ( ! $stylesheet || ! wp_get_theme( $stylesheet )->exists() ) {
				return new WP_Error( 'missing_theme', __( 'The previous theme is no longer installed.', 'site-agent' ) );
			}
			switch_theme( $stylesheet );
			return true;
		}

		return new WP_Error( 'unsupported_rollback', __( 'This target type is not supported by rollback.', 'site-agent' ) );
	}

	private static function can_rollback_target( array $row, mixed $before ): bool|WP_Error {
		$type = (string) $row['object_type'];
		if ( 'post' === $type && ! current_user_can( 'edit_post', (int) $row['object_id'] ) ) {
			return new WP_Error( 'forbidden_target', __( 'You cannot edit the target post.', 'site-agent' ) );
		}
		if ( 'post_meta' === $type ) {
			$post_id = (int) ( $before['post_id'] ?? 0 );
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'forbidden_target', __( 'You cannot edit the target post metadata.', 'site-agent' ) );
			}
		}
		if ( 'option' === $type && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden_target', __( 'You cannot change site options.', 'site-agent' ) );
		}
		if ( 'plugin' === $type && ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'forbidden_target', __( 'You cannot change plugin activation.', 'site-agent' ) );
		}
		if ( 'theme' === $type && ! current_user_can( 'switch_themes' ) ) {
			return new WP_Error( 'forbidden_target', __( 'You cannot switch themes.', 'site-agent' ) );
		}
		return true;
	}

	private static function post_state( WP_Post $post ): array {
		return array(
			'exists'       => true,
			'post_type'    => $post->post_type,
			'post_title'   => $post->post_title,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_name'    => $post->post_name,
			'post_parent'  => (int) $post->post_parent,
			'menu_order'   => (int) $post->menu_order,
			'post_author'  => (int) $post->post_author,
			'post_date'    => $post->post_date,
			'post_date_gmt'=> $post->post_date_gmt,
		);
	}

	private static function post_risk( array $old, array $new ): string {
		$status = strtolower( trim( (string) ( $new['post_status'] ?? '' ) ) );
		if ( in_array( $status, array( 'publish', 'future', 'trash', 'private' ), true ) || $status !== (string) ( $old['post_status'] ?? '' ) ) {
			return 'high';
		}
		return 'medium';
	}

	private static function option_risk( string $option ): string {
		$option = strtolower( trim( $option ) );
		if ( in_array( $option, array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'default_role', 'users_can_register' ), true ) ) {
			return 'high';
		}
		return 'medium';
	}

	private static function track_meta_key( string $key ): bool {
		return in_array( $key, array_merge( self::BUILDER_META, self::SEO_META ), true );
	}

	private static function track_option( string $option ): bool {
		if ( Site_Agent_Redactor::is_sensitive_name( $option ) ) {
			return false;
		}
		if ( str_starts_with( $option, '_transient_' ) || str_starts_with( $option, '_site_transient_' ) ) {
			return false;
		}
		return ! in_array(
			$option,
			array(
				'cron', 'site_agent_settings', 'site_agent_index_active_generation',
				'site_agent_index_build_generation', 'site_agent_index_last_completed_gmt',
				'site_agent_schema_version',
			),
			true
		);
	}

	private static function public_row( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'actor_id'    => (int) $row['actor_id'],
			'source'      => (string) $row['source'],
			'action'      => str_replace( '_', '.', (string) $row['action_name'] ),
			'object_type' => (string) $row['object_type'],
			'object_id'   => (string) $row['object_id'],
			'risk'        => (string) $row['risk'],
			'reversible'  => (bool) $row['reversible'],
			'status'      => (string) $row['status'],
			'rollback_of' => (int) $row['rollback_of'],
			'metadata'    => json_decode( (string) $row['metadata'], true ) ?: array(),
			'created_gmt' => (string) $row['created_gmt'],
		);
	}
}
