<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Action_Registry {
	private const MAX_ACTIONS = 10;
	private const MAX_CONTENT_BYTES = 200000;

	public static function catalog(): array {
		return array(
			'read' => array(
				'site.search'     => 'Search the local knowledge index.',
				'changes.recent'  => 'Read recent change-ledger entries.',
				'diagnostics.run' => 'Measure current WordPress, database, cron, and runtime signals.',
				'plugin.impact'   => 'Analyze what may break or remain if an installed plugin is removed.',
				'content.get'     => 'Read one post or page the current user can access.',
				'plugins.list'    => 'List installed plugins and activation state.',
				'cron.list'       => 'List bounded scheduled-event evidence.',
				'seo.gaps'        => 'Find published content missing common SEO title or description metadata.',
			),
			'write' => array(
				'post.create'               => 'Create a post or page.',
				'post.update'               => 'Update approved fields on an existing post or page.',
				'post.trash'                => 'Move a post or page to Trash.',
				'post.meta.update'          => 'Update supported builder or SEO metadata.',
				'seo.update'                => 'Update Yoast or Rank Math title, description, and focus keyword.',
				'option.update'             => 'Update a small allowlist of non-secret WordPress settings.',
				'plugin.activate'           => 'Activate an installed plugin on this site.',
				'plugin.deactivate'         => 'Deactivate an installed plugin on this site.',
				'transients.delete_expired' => 'Delete expired transients only.',
				'rollback.perform'          => 'Roll back one supported ledger entry after conflict checks.',
			),
		);
	}

	public static function run_read( string $name, array $args = array() ): array|WP_Error {
		if ( ! current_user_can( 'site_agent_inspect' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot inspect this site.', 'site-agent' )
			);
		}

		$name = strtolower( trim( $name ) );
		$args = self::canonical_args( $name, $args );

		switch ( $name ) {
			case 'site.search':
				return array(
					'results' => Site_Agent_Retriever::search(
						(string) ( $args['query'] ?? '' ),
						(int) ( $args['limit'] ?? 12 )
					),
				);

			case 'changes.recent':
				return array(
					'changes' => Site_Agent_Ledger::recent(
						(int) ( $args['limit'] ?? 50 )
					),
				);

			case 'diagnostics.run':
				return Site_Agent_Diagnostics::run();

			case 'plugin.impact':
				return Site_Agent_Plugin_Impact::analyze(
					(string) ( $args['plugin'] ?? '' )
				);

			case 'content.get':
				return self::content_get(
					(int) ( $args['post_id'] ?? 0 )
				);

			case 'plugins.list':
				return self::plugins_list();

			case 'cron.list':
				return self::cron_list();

			case 'seo.gaps':
				return self::seo_gaps(
					(int) ( $args['limit'] ?? 50 )
				);
		}

		return new WP_Error(
			'unknown_read_action',
			__( 'The requested inspection tool is not registered.', 'site-agent' )
		);
	}

	public static function propose(
		array $requested_actions,
		string $reason = ''
	): array|WP_Error {
		if ( ! current_user_can( 'site_agent_propose' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot propose site changes.', 'site-agent' )
			);
		}

		$count = count( $requested_actions );
		if ( $count < 1 || $count > self::MAX_ACTIONS ) {
			return new WP_Error(
				'invalid_action_count',
				__( 'A plan must contain between one and ten actions.', 'site-agent' )
			);
		}

		$plan_actions = array();
		$highest_risk = 'low';

		foreach ( $requested_actions as $position => $requested ) {
			if ( ! is_array( $requested ) ) {
				return new WP_Error(
					'invalid_action',
					__( 'Every proposed action must be an object.', 'site-agent' )
				);
			}

			$name = strtolower(
				trim(
					sanitize_text_field(
						(string) ( $requested['name'] ?? '' )
					)
				)
			);

			$args = self::canonical_args(
				$name,
				(array) ( $requested['args'] ?? array() )
			);

			if (
				Site_Agent_Codec::fingerprint( $args )
				!== Site_Agent_Codec::fingerprint(
					Site_Agent_Redactor::redact( $args )
				)
			) {
				return new WP_Error(
					'secret_like_action_payload',
					__( 'The proposed action contains a credential- or token-like value and was blocked.', 'site-agent' )
				);
			}

			$validation = self::validate_write( $name, $args );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			$risk         = self::risk( $name, $args );
			$required_cap = 'site_agent_execute_' . $risk;
			$highest_risk = self::max_risk( $highest_risk, $risk );

			$plan_actions[] = array(
				'id'                  => (int) $position + 1,
				'name'                => $name,
				'args'                => $args,
				'risk'                => $risk,
				'required_capability' => $required_cap,
				'preview'             => self::preview( $name, $args ),
			);
		}

		$plan = array(
			'version'      => 1,
			'plan_id'      => wp_generate_uuid4(),
			'created_gmt'  => Site_Agent_Database::utc_now(),
			'created_by'   => get_current_user_id(),
			'reason'       => substr(
				Site_Agent_Redactor::redact_string(
					wp_strip_all_tags( $reason )
				),
				0,
				2000
			),
			'highest_risk' => $highest_risk,
			'actions'      => $plan_actions,
		);

		$approval = Site_Agent_Approval_Service::issue( $plan );
		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		Site_Agent_Audit_Log::record(
			'action_plan_proposed',
			array(
				'plan_id'      => $plan['plan_id'],
				'highest_risk' => $highest_risk,
				'action_count' => count( $plan_actions ),
			)
		);

		return array_merge(
			array( 'plan' => $plan ),
			$approval
		);
	}

	public static function execute_approved(
		string $approval_token,
		string $plan_hash = ''
	): array|WP_Error {
		$plan = Site_Agent_Approval_Service::consume(
			$approval_token,
			$plan_hash
		);

		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		if ( (int) ( $plan['created_by'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error(
				'plan_owner_mismatch',
				__( 'The plan belongs to another user.', 'site-agent' )
			);
		}

		$actions = (array) ( $plan['actions'] ?? array() );
		if ( empty( $actions ) || count( $actions ) > self::MAX_ACTIONS ) {
			return new WP_Error(
				'invalid_approved_plan',
				__( 'The approved plan contains an invalid action count.', 'site-agent' )
			);
		}

		foreach ( $actions as $action ) {
			$capability = (string) ( $action['required_capability'] ?? '' );

			if ( ! $capability || ! current_user_can( $capability ) ) {
				return new WP_Error(
					'risk_capability_missing',
					__( 'Your role cannot execute one or more actions in this plan.', 'site-agent' )
				);
			}
		}

		$plan_id  = (string) ( $plan['plan_id'] ?? '' );
		$lock_key = hash( 'sha256', 'plan|' . $plan_id );
		$locked   = self::acquire_lock( $lock_key, 300 );

		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		$results = array();

		try {
			foreach ( $actions as $action ) {
				$name = (string) ( $action['name'] ?? '' );
				$args = (array) ( $action['args'] ?? array() );
				$risk = (string) ( $action['risk'] ?? 'high' );

				$result = self::execute_one( $name, $args, $risk );

				$results[] = array(
					'id'   => (int) ( $action['id'] ?? 0 ),
					'name' => $name,
					'result' => is_wp_error( $result )
						? array(
							'error' => $result->get_error_message(),
							'code'  => $result->get_error_code(),
						)
						: $result,
				);

				if ( is_wp_error( $result ) ) {
					Site_Agent_Audit_Log::record(
						'action_plan_failed',
						array(
							'plan_id'       => $plan_id,
							'failed_action' => $name,
							'error'         => $result->get_error_message(),
						),
						'error'
					);

					return new WP_Error(
						'action_failed',
						__( 'The plan stopped after an action failed. Earlier successful actions remain individually visible and rollbackable when supported.', 'site-agent' ),
						array( 'results' => $results )
					);
				}
			}
		} finally {
			self::release_lock( $lock_key );
		}

		Site_Agent_Audit_Log::record(
			'action_plan_completed',
			array(
				'plan_id'      => $plan_id,
				'action_count' => count( $results ),
			)
		);

		return array(
			'completed' => true,
			'plan_id'   => $plan_id,
			'results'   => $results,
		);
	}

	public static function canonical_args( string $name, array $args ): array {
		$name   = strtolower( trim( $name ) );
		$output = array();

		if ( in_array( $name, array( 'post.create', 'post.update' ), true ) ) {
			foreach ( array( 'post_id', 'post_parent', 'menu_order' ) as $key ) {
				if ( array_key_exists( $key, $args ) ) {
					$output[ $key ] = max( 0, (int) $args[ $key ] );
				}
			}

			foreach ( array( 'post_status', 'post_name' ) as $key ) {
				if ( array_key_exists( $key, $args ) ) {
					$output[ $key ] = sanitize_key(
						strtolower(
							trim( (string) $args[ $key ] )
						)
					);
				}
			}

			if ( 'post.create' === $name ) {
				$output['post_type'] = sanitize_key(
					strtolower(
						trim(
							(string) ( $args['post_type'] ?? 'post' )
						)
					)
				);
			}

			foreach ( array( 'post_title', 'post_excerpt' ) as $key ) {
				if ( array_key_exists( $key, $args ) ) {
					$output[ $key ] = sanitize_text_field(
						(string) $args[ $key ]
					);
				}
			}

			if ( array_key_exists( 'post_content', $args ) ) {
				$output['post_content'] = (string) $args['post_content'];
			}

			if ( array_key_exists( 'post_date', $args ) ) {
				$output['post_date'] = sanitize_text_field(
					(string) $args['post_date']
				);
			}
		} elseif ( 'post.trash' === $name ) {
			$output['post_id'] = max(
				0,
				(int) ( $args['post_id'] ?? 0 )
			);
		} elseif ( 'post.meta.update' === $name ) {
			$output['post_id'] = max(
				0,
				(int) ( $args['post_id'] ?? 0 )
			);
			$output['meta_key'] = sanitize_text_field(
				trim( (string) ( $args['meta_key'] ?? '' ) )
			);
			$output['value'] = $args['value'] ?? '';
		} elseif ( 'seo.update' === $name ) {
			$output['post_id'] = max(
				0,
				(int) ( $args['post_id'] ?? 0 )
			);
			$output['provider'] = sanitize_key(
				strtolower(
					trim(
						(string) ( $args['provider'] ?? 'yoast' )
					)
				)
			);

			foreach ( array( 'title', 'description', 'focus_keyword' ) as $key ) {
				if ( array_key_exists( $key, $args ) ) {
					$output[ $key ] = sanitize_text_field(
						(string) $args[ $key ]
					);
				}
			}
		} elseif ( 'option.update' === $name ) {
			$output['option'] = sanitize_key(
				strtolower(
					trim( (string) ( $args['option'] ?? '' ) )
				)
			);
			$output['value'] = is_scalar( $args['value'] ?? null )
				? (string) $args['value']
				: ( $args['value'] ?? null );
		} elseif (
			in_array(
				$name,
				array(
					'plugin.activate',
					'plugin.deactivate',
					'plugin.impact',
				),
				true
			)
		) {
			$output['plugin'] = plugin_basename(
				trim( (string) ( $args['plugin'] ?? '' ) )
			);
		} elseif ( 'rollback.perform' === $name ) {
			$output['ledger_id'] = max(
				0,
				(int) ( $args['ledger_id'] ?? 0 )
			);
			$output['force'] = ! empty( $args['force'] );
		} elseif ( 'site.search' === $name ) {
			$output['query'] = sanitize_text_field(
				(string) ( $args['query'] ?? '' )
			);
			$output['limit'] = max(
				1,
				min( 30, (int) ( $args['limit'] ?? 12 ) )
			);
		} elseif (
			in_array(
				$name,
				array( 'changes.recent', 'seo.gaps' ),
				true
			)
		) {
			$output['limit'] = max(
				1,
				min( 200, (int) ( $args['limit'] ?? 50 ) )
			);
		} elseif ( 'content.get' === $name ) {
			$output['post_id'] = max(
				0,
				(int) ( $args['post_id'] ?? 0 )
			);
		}

		return $output;
	}

	private static function validate_write(
		string $name,
		array $args
	): bool|WP_Error {
		$catalog = self::catalog();

		if ( ! isset( $catalog['write'][ $name ] ) ) {
			return new WP_Error(
				'unknown_write_action',
				sprintf(
					/* translators: %s: action name. */
					__( 'The action “%s” is not registered.', 'site-agent' ),
					$name
				)
			);
		}

		$json = wp_json_encode( $args );
		if ( false === $json || strlen( $json ) > self::MAX_CONTENT_BYTES ) {
			return new WP_Error(
				'action_payload_too_large',
				__( 'An action payload is too large to execute safely.', 'site-agent' )
			);
		}

		if ( 'post.create' === $name ) {
			$post_type = (string) ( $args['post_type'] ?? 'post' );
			$object    = get_post_type_object( $post_type );

			if (
				! $object
				|| self::sensitive_post_type( $post_type )
				|| ! current_user_can( $object->cap->create_posts )
			) {
				return new WP_Error(
					'cannot_create',
					__( 'You cannot create that content type.', 'site-agent' )
				);
			}

			if (
				empty( $args['post_title'] )
				&& empty( $args['post_content'] )
			) {
				return new WP_Error(
					'empty_post',
					__( 'A new post needs a title or content.', 'site-agent' )
				);
			}

			if (
				in_array(
					(string) ( $args['post_status'] ?? 'draft' ),
					array( 'publish', 'future', 'private' ),
					true
				)
				&& ! current_user_can( $object->cap->publish_posts )
			) {
				return new WP_Error(
					'cannot_publish',
					__( 'You cannot publish that content type.', 'site-agent' )
				);
			}
		}

		if (
			in_array(
				$name,
				array(
					'post.update',
					'post.trash',
					'post.meta.update',
					'seo.update',
				),
				true
			)
		) {
			$post_id = (int) ( $args['post_id'] ?? 0 );
			$post    = get_post( $post_id );

			if ( ! $post ) {
				return new WP_Error(
					'missing_post',
					__( 'The target post does not exist.', 'site-agent' )
				);
			}

			$native_cap = 'post.trash' === $name
				? 'delete_post'
				: 'edit_post';

			if ( ! current_user_can( $native_cap, $post_id ) ) {
				return new WP_Error(
					'cannot_change_post',
					__( 'You do not have permission for the target post.', 'site-agent' )
				);
			}

			if ( self::sensitive_post_type( $post->post_type ) ) {
				return new WP_Error(
					'sensitive_content_type',
					__( 'Orders, submissions, transactions, and similar sensitive records are excluded from Site Agent actions.', 'site-agent' )
				);
			}

			if (
				'post.update' === $name
				&& in_array(
					(string) ( $args['post_status'] ?? '' ),
					array( 'publish', 'future', 'private' ),
					true
				)
			) {
				$object = get_post_type_object( $post->post_type );

				if (
					! $object
					|| ! current_user_can( $object->cap->publish_posts )
				) {
					return new WP_Error(
						'cannot_publish',
						__( 'You cannot publish that content type.', 'site-agent' )
					);
				}
			}
		}

		if ( 'post.meta.update' === $name ) {
			if (
				! in_array(
					(string) ( $args['meta_key'] ?? '' ),
					self::supported_meta_keys(),
					true
				)
			) {
				return new WP_Error(
					'meta_not_supported',
					__( 'That metadata key is not in the supported action allowlist.', 'site-agent' )
				);
			}
		}

		if (
			'seo.update' === $name
			&& ! in_array(
				(string) ( $args['provider'] ?? '' ),
				array( 'yoast', 'rank_math' ),
				true
			)
		) {
			return new WP_Error(
				'seo_provider',
				__( 'Only Yoast and Rank Math metadata are supported.', 'site-agent' )
			);
		}

		if ( 'option.update' === $name ) {
			$allowed = array(
				'blogname',
				'blogdescription',
				'timezone_string',
				'date_format',
				'time_format',
				'start_of_week',
				'show_on_front',
				'page_on_front',
				'page_for_posts',
				'posts_per_page',
				'blog_public',
			);

			if (
				! current_user_can( 'manage_options' )
				|| ! in_array(
					(string) ( $args['option'] ?? '' ),
					$allowed,
					true
				)
			) {
				return new WP_Error(
					'option_not_allowed',
					__( 'That WordPress setting is not in the action allowlist.', 'site-agent' )
				);
			}
		}

		if (
			in_array(
				$name,
				array( 'plugin.activate', 'plugin.deactivate' ),
				true
			)
		) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin  = (string) ( $args['plugin'] ?? '' );
			$plugins = get_plugins();

			if (
				! current_user_can( 'activate_plugins' )
				|| ! isset( $plugins[ $plugin ] )
				|| SITE_AGENT_BASENAME === $plugin
			) {
				return new WP_Error(
					'plugin_not_allowed',
					__( 'That plugin cannot be changed by Site Agent.', 'site-agent' )
				);
			}
		}

		if (
			'transients.delete_expired' === $name
			&& ! current_user_can( 'manage_options' )
		) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot delete expired transients.', 'site-agent' )
			);
		}

		if ( 'rollback.perform' === $name ) {
			$row = Site_Agent_Ledger::get(
				(int) ( $args['ledger_id'] ?? 0 )
			);

			if (
				! current_user_can( 'site_agent_rollback' )
				|| ! $row
				|| ! (int) $row['reversible']
			) {
				return new WP_Error(
					'rollback_not_allowed',
					__( 'That ledger entry is missing, not reversible, or not available to your role.', 'site-agent' )
				);
			}
		}

		return true;
	}

	private static function risk( string $name, array $args ): string {
		if (
			in_array(
				$name,
				array(
					'plugin.activate',
					'plugin.deactivate',
					'post.trash',
					'rollback.perform',
				),
				true
			)
		) {
			return 'high';
		}

		if ( 'post.create' === $name ) {
			return in_array(
				(string) ( $args['post_status'] ?? 'draft' ),
				array( 'publish', 'future', 'private' ),
				true
			)
				? 'high'
				: 'low';
		}

		if ( 'post.update' === $name ) {
			$status = (string) ( $args['post_status'] ?? '' );

			if (
				in_array(
					$status,
					array( 'publish', 'future', 'private', 'trash' ),
					true
				)
				|| isset( $args['post_date'] )
			) {
				return 'high';
			}

			return 'medium';
		}

		if ( 'post.meta.update' === $name ) {
			$key = (string) ( $args['meta_key'] ?? '' );

			if (
				str_starts_with( $key, '_elementor_' )
				|| str_starts_with( $key, '_et_pb_' )
				|| str_starts_with( $key, '_fl_builder_' )
				|| str_starts_with( $key, '_bricks_' )
				|| str_starts_with( $key, '_wpb_' )
			) {
				return 'high';
			}
		}

		return 'medium';
	}

	private static function preview( string $name, array $args ): string {
		switch ( $name ) {
			case 'post.create':
				return sprintf(
					'Create %s “%s” with status %s.',
					(string) ( $args['post_type'] ?? 'post' ),
					(string) ( $args['post_title'] ?? '(untitled)' ),
					(string) ( $args['post_status'] ?? 'draft' )
				);

			case 'post.update':
				return sprintf(
					'Update approved fields on post #%d.',
					(int) ( $args['post_id'] ?? 0 )
				);

			case 'post.trash':
				return sprintf(
					'Move post #%d to Trash.',
					(int) ( $args['post_id'] ?? 0 )
				);

			case 'post.meta.update':
				return sprintf(
					'Replace supported metadata %s on post #%d.',
					(string) ( $args['meta_key'] ?? '' ),
					(int) ( $args['post_id'] ?? 0 )
				);

			case 'seo.update':
				return sprintf(
					'Update %s SEO metadata on post #%d.',
					(string) ( $args['provider'] ?? '' ),
					(int) ( $args['post_id'] ?? 0 )
				);

			case 'option.update':
				return sprintf(
					'Change the WordPress setting %s.',
					(string) ( $args['option'] ?? '' )
				);

			case 'plugin.activate':
				return sprintf(
					'Activate %s.',
					(string) ( $args['plugin'] ?? '' )
				);

			case 'plugin.deactivate':
				return sprintf(
					'Deactivate %s.',
					(string) ( $args['plugin'] ?? '' )
				);

			case 'transients.delete_expired':
				return 'Delete only transients whose timeout is already expired.';

			case 'rollback.perform':
				return sprintf(
					'Roll back ledger entry #%d%s.',
					(int) ( $args['ledger_id'] ?? 0 ),
					! empty( $args['force'] )
						? ' and override a newer-state conflict'
						: ''
				);
		}

		return $name;
	}

	private static function execute_one(
		string $name,
		array $args,
		string $risk
	): array|WP_Error {
		$validation = self::validate_write( $name, $args );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$capability = 'site_agent_execute_' . $risk;
		if ( ! current_user_can( $capability ) ) {
			return new WP_Error(
				'risk_forbidden',
				__( 'Your role cannot execute this action’s risk level.', 'site-agent' )
			);
		}

		Site_Agent_Ledger::begin_internal();

		try {
			switch ( $name ) {
				case 'post.create':
					return self::execute_post_create( $args, $risk );

				case 'post.update':
					return self::execute_post_update( $args, $risk );

				case 'post.trash':
					return self::execute_post_trash( $args, $risk );

				case 'post.meta.update':
					return self::execute_meta_update( $args, $risk );

				case 'seo.update':
					return self::execute_seo_update( $args, $risk );

				case 'option.update':
					return self::execute_option_update( $args, $risk );

				case 'plugin.activate':
					return self::execute_plugin( $args, true, $risk );

				case 'plugin.deactivate':
					return self::execute_plugin( $args, false, $risk );

				case 'transients.delete_expired':
					return self::execute_transients( $risk );

				case 'rollback.perform':
					return Site_Agent_Ledger::rollback(
						(int) ( $args['ledger_id'] ?? 0 ),
						! empty( $args['force'] )
					);
			}
		} finally {
			Site_Agent_Ledger::end_internal();
		}

		return new WP_Error(
			'unknown_action',
			__( 'The approved action is no longer registered.', 'site-agent' )
		);
	}

	private static function execute_post_create(
		array $args,
		string $risk
	): array|WP_Error {
		$data = array_merge(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
			),
			$args
		);

		$post_id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post   = get_post( $post_id );
		$after  = self::post_snapshot( $post );
		$ledger = Site_Agent_Ledger::record(
			'post.create',
			'post',
			(string) $post_id,
			array( 'exists' => false ),
			$after,
			'site_agent',
			$risk,
			array(
				'post_type' => $post ? $post->post_type : (string) $data['post_type'],
			),
			true
		);

		return array(
			'post_id'   => $post_id,
			'ledger_id' => $ledger,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function execute_post_update(
		array $args,
		string $risk
	): array|WP_Error {
		$post_id = (int) $args['post_id'];
		$before  = self::post_snapshot( get_post( $post_id ) );

		unset( $args['post_id'] );
		$args['ID'] = $post_id;

		$result = wp_update_post( wp_slash( $args ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$after  = self::post_snapshot( get_post( $post_id ) );
		$ledger = Site_Agent_Ledger::record(
			'post.update',
			'post',
			(string) $post_id,
			$before,
			$after,
			'site_agent',
			$risk
		);

		return array(
			'post_id'   => $post_id,
			'ledger_id' => $ledger,
			'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function execute_post_trash(
		array $args,
		string $risk
	): array|WP_Error {
		$post_id = (int) $args['post_id'];
		$before  = self::post_snapshot( get_post( $post_id ) );
		$trashed = wp_trash_post( $post_id );

		if ( ! $trashed ) {
			return new WP_Error(
				'trash_failed',
				__( 'WordPress could not move the post to Trash.', 'site-agent' )
			);
		}

		$after  = self::post_snapshot( get_post( $post_id ) );
		$ledger = Site_Agent_Ledger::record(
			'post.trash',
			'post',
			(string) $post_id,
			$before,
			$after,
			'site_agent',
			$risk
		);

		return array(
			'post_id'   => $post_id,
			'ledger_id' => $ledger,
		);
	}

	private static function execute_meta_update(
		array $args,
		string $risk
	): array|WP_Error {
		$post_id  = (int) $args['post_id'];
		$meta_key = (string) $args['meta_key'];

		$before = array(
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'values'   => get_post_meta( $post_id, $meta_key, false ),
		);

		delete_post_meta( $post_id, $meta_key );
		add_post_meta( $post_id, $meta_key, $args['value'] );

		$after = array(
			'post_id'  => $post_id,
			'meta_key' => $meta_key,
			'values'   => get_post_meta( $post_id, $meta_key, false ),
		);

		$ledger = Site_Agent_Ledger::record(
			'post.meta.update',
			'post_meta',
			$post_id . ':' . $meta_key,
			$before,
			$after,
			'site_agent',
			$risk,
			array(
				'post_id'  => $post_id,
				'meta_key' => $meta_key,
			)
		);

		return array(
			'post_id'   => $post_id,
			'meta_key'  => $meta_key,
			'ledger_id' => $ledger,
		);
	}

	private static function execute_seo_update(
		array $args,
		string $risk
	): array|WP_Error {
		$post_id = (int) $args['post_id'];
		$provider = (string) $args['provider'];

		$map = 'yoast' === $provider
			? array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			)
			: array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			);

		$ledger_ids = array();

		foreach ( $map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $args ) ) {
				continue;
			}

			$result = self::execute_meta_update(
				array(
					'post_id'  => $post_id,
					'meta_key' => $meta_key,
					'value'    => $args[ $field ],
				),
				$risk
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$ledger_ids[] = (int) $result['ledger_id'];
		}

		return array(
			'post_id'    => $post_id,
			'provider'   => $provider,
			'ledger_ids' => $ledger_ids,
		);
	}

	private static function execute_option_update(
		array $args,
		string $risk
	): array|WP_Error {
		$name     = (string) $args['option'];
		$sentinel = new stdClass();
		$old      = get_option( $name, $sentinel );

		$before = $old === $sentinel
			? array( 'exists' => false )
			: array(
				'exists' => true,
				'value'  => $old,
			);

		update_option( $name, $args['value'] );

		$new = get_option( $name, $sentinel );

		$after = $new === $sentinel
			? array( 'exists' => false )
			: array(
				'exists' => true,
				'value'  => $new,
			);

		if (
			Site_Agent_Codec::fingerprint( $before )
			=== Site_Agent_Codec::fingerprint( $after )
		) {
			return array(
				'option'    => $name,
				'changed'   => false,
				'ledger_id' => 0,
			);
		}

		$ledger = Site_Agent_Ledger::record(
			'option.update',
			'option',
			$name,
			$before,
			$after,
			'site_agent',
			$risk
		);

		return array(
			'option'    => $name,
			'changed'   => true,
			'ledger_id' => $ledger,
		);
	}

	private static function execute_plugin(
		array $args,
		bool $activate,
		string $risk
	): array|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin = (string) $args['plugin'];
		$before = array(
			'active'       => is_plugin_active( $plugin ),
			'network_wide' => false,
		);

		if ( $before['active'] === $activate ) {
			return array(
				'plugin'    => $plugin,
				'active'    => $activate,
				'changed'   => false,
				'ledger_id' => 0,
			);
		}

		if ( $activate ) {
			$result = activate_plugin( $plugin, '', false, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		} else {
			deactivate_plugins( $plugin, true, false );
		}

		$after = array(
			'active'       => is_plugin_active( $plugin ),
			'network_wide' => false,
		);

		if ( $after['active'] !== $activate ) {
			return new WP_Error(
				'plugin_state_unchanged',
				__( 'The plugin activation state did not change.', 'site-agent' )
			);
		}

		$ledger = Site_Agent_Ledger::record(
			$activate ? 'plugin.activate' : 'plugin.deactivate',
			'plugin',
			$plugin,
			$before,
			$after,
			'site_agent',
			$risk,
			array( 'network_wide' => false ),
			true
		);

		return array(
			'plugin'    => $plugin,
			'active'    => $after['active'],
			'changed'   => true,
			'ledger_id' => $ledger,
		);
	}

	private static function execute_transients( string $risk ): array|WP_Error {
		global $wpdb;

		$timeout_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND CAST(option_value AS UNSIGNED) < %d
				LIMIT 5000",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				time()
			)
		);

		$deleted = 0;

		foreach ( $timeout_rows as $timeout_name ) {
			$name = substr(
				(string) $timeout_name,
				strlen( '_transient_timeout_' )
			);

			if ( delete_transient( $name ) ) {
				$deleted++;
			}
		}

		$ledger = Site_Agent_Ledger::record(
			'transients.delete_expired',
			'maintenance',
			'expired_transients',
			array( 'expired_count' => count( $timeout_rows ) ),
			array( 'deleted_count' => $deleted ),
			'site_agent',
			$risk,
			array(),
			false
		);

		return array(
			'deleted'    => $deleted,
			'ledger_id'  => $ledger,
			'reversible' => false,
		);
	}

	private static function content_get( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post || ! current_user_can( 'read_post', $post_id ) ) {
			return new WP_Error(
				'cannot_read_post',
				__( 'The content is missing or you cannot read it.', 'site-agent' )
			);
		}

		if ( self::sensitive_post_type( $post->post_type ) ) {
			return new WP_Error(
				'sensitive_content_type',
				__( 'Orders, submissions, transactions, and similar sensitive records are excluded from Site Agent reads.', 'site-agent' )
			);
		}

		return array(
			'id'           => $post_id,
			'type'         => $post->post_type,
			'status'       => $post->post_status,
			'title'        => $post->post_title,
			'excerpt'      => $post->post_excerpt,
			'content'      => substr(
				Site_Agent_Redactor::redact_string(
					wp_strip_all_tags( $post->post_content )
				),
				0,
				50000
			),
			'modified_gmt' => $post->post_modified_gmt,
			'edit_url'     => get_edit_post_link( $post_id, 'raw' ),
		);
	}

	private static function plugins_list(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active = (array) get_option( 'active_plugins', array() );
		$output = array();

		foreach ( get_plugins() as $file => $data ) {
			$output[] = array(
				'file'    => $file,
				'name'    => (string) ( $data['Name'] ?? $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => in_array( $file, $active, true ),
			);
		}

		return array( 'plugins' => $output );
	}

	private static function cron_list(): array {
		$cron   = _get_cron_array();
		$output = array();

		foreach ( (array) $cron as $timestamp => $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				$output[] = array(
					'hook'           => $hook,
					'next_run_gmt'   => gmdate(
						'Y-m-d H:i:s',
						(int) $timestamp
					),
					'instance_count' => count( (array) $instances ),
					'overdue'        => (int) $timestamp < time() - HOUR_IN_SECONDS,
				);

				if ( count( $output ) >= 200 ) {
					break 2;
				}
			}
		}

		return array(
			'events'    => $output,
			'truncated' => count( $output ) >= 200,
		);
	}

	private static function seo_gaps( int $limit ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					p.ID,
					p.post_type,
					p.post_title,
					MAX(
						CASE
							WHEN pm.meta_key IN ('_yoast_wpseo_title','rank_math_title')
							THEN NULLIF(pm.meta_value,'')
						END
					) AS seo_title,
					MAX(
						CASE
							WHEN pm.meta_key IN ('_yoast_wpseo_metadesc','rank_math_description')
							THEN NULLIF(pm.meta_value,'')
						END
					) AS seo_description
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
				WHERE p.post_status = 'publish'
				AND p.post_type IN ('post','page')
				GROUP BY p.ID
				HAVING seo_title IS NULL
					OR seo_description IS NULL
				ORDER BY p.post_modified_gmt DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return array(
			'items' => array_map(
				static fn( array $row ): array => array(
					'post_id'             => (int) $row['ID'],
					'post_type'           => (string) $row['post_type'],
					'title'               => (string) $row['post_title'],
					'missing_seo_title'   => empty( $row['seo_title'] ),
					'missing_description' => empty( $row['seo_description'] ),
				),
				$rows
			),
		);
	}

	private static function post_snapshot( ?WP_Post $post ): array {
		if ( ! $post ) {
			return array( 'exists' => false );
		}

		return array(
			'exists'        => true,
			'post_type'     => $post->post_type,
			'post_title'    => $post->post_title,
			'post_content'  => $post->post_content,
			'post_excerpt'  => $post->post_excerpt,
			'post_status'   => $post->post_status,
			'post_name'     => $post->post_name,
			'post_parent'   => (int) $post->post_parent,
			'menu_order'    => (int) $post->menu_order,
			'post_author'   => (int) $post->post_author,
			'post_date'     => $post->post_date,
			'post_date_gmt' => $post->post_date_gmt,
		);
	}

	private static function supported_meta_keys(): array {
		return array(
			'_elementor_data',
			'_elementor_page_settings',
			'_elementor_template_type',
			'_et_pb_use_builder',
			'_et_pb_page_layout',
			'_fl_builder_data',
			'_fl_builder_draft',
			'_bricks_page_content_2',
			'_wpb_vc_js_status',
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'_yoast_wpseo_focuskw',
			'rank_math_title',
			'rank_math_description',
			'rank_math_focus_keyword',
		);
	}

	private static function sensitive_post_type( string $post_type ): bool {
		if (
			in_array(
				$post_type,
				array(
					'shop_order',
					'shop_order_refund',
					'nf_sub',
					'flamingo_inbound',
					'wpforms_entry',
					'edd_payment',
				),
				true
			)
		) {
			return true;
		}

		return (bool) preg_match(
			'/(?:submission|entry|lead|attendee|payment|transaction|order|customer|contact_log)/i',
			$post_type
		);
	}

	private static function max_risk( string $first, string $second ): string {
		$order = array(
			'low'    => 1,
			'medium' => 2,
			'high'   => 3,
		);

		return ( $order[ $second ] ?? 3 ) > ( $order[ $first ] ?? 3 )
			? $second
			: $first;
	}

	private static function acquire_lock(
		string $key,
		int $ttl
	): bool|WP_Error {
		global $wpdb;

		$table = Site_Agent_Database::table( 'locks' );
		$now   = Site_Agent_Database::utc_now();

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table}
				WHERE expires_gmt < %s",
				$now
			)
		);

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
					(lock_key, owner, expires_gmt, created_gmt)
				VALUES (%s, %s, %s, %s)",
				$key,
				(string) get_current_user_id(),
				gmdate( 'Y-m-d H:i:s', time() + $ttl ),
				$now
			)
		);

		if ( 1 !== $inserted ) {
			return new WP_Error(
				'duplicate_action_lock',
				__( 'This exact action plan is already running or was just submitted.', 'site-agent' )
			);
		}

		return true;
	}

	private static function release_lock( string $key ): void {
		global $wpdb;

		$wpdb->delete(
			Site_Agent_Database::table( 'locks' ),
			array( 'lock_key' => $key ),
			array( '%s' )
		);
	}
}
