<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Agent_Database {
	public const SCHEMA_VERSION = '1.1.0';

	public static function table( string $name ): string {
		global $wpdb;
		$allowed = array( 'index', 'ledger', 'audit', 'messages', 'approvals', 'locks' );
		if ( ! in_array( $name, $allowed, true ) ) {
			throw new InvalidArgumentException( 'Unknown Site Agent table.' );
		}
		return $wpdb->prefix . 'site_agent_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$index   = self::table( 'index' );
		$ledger  = self::table( 'ledger' );
		$audit   = self::table( 'audit' );
		$msg     = self::table( 'messages' );
		$approve = self::table( 'approvals' );
		$locks   = self::table( 'locks' );

		// Version 1.0 used a cross-generation unique key, which prevented atomic rebuilds.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) === $index ) {
			$legacy_index = $wpdb->get_var( "SHOW INDEX FROM `{$index}` WHERE Key_name = 'object_key'" );
			if ( $legacy_index ) {
				$wpdb->query( "ALTER TABLE `{$index}` DROP INDEX `object_key`" );
			}
		}

		dbDelta(
			"CREATE TABLE {$index} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(40) NOT NULL,
				object_id varchar(191) NOT NULL DEFAULT '',
				subtype varchar(80) NOT NULL DEFAULT '',
				title text NOT NULL,
				summary longtext NOT NULL,
				keywords longtext NOT NULL,
				fingerprint char(64) NOT NULL,
				generation char(36) NOT NULL,
				required_capability varchar(80) NOT NULL DEFAULT 'site_agent_inspect',
				modified_gmt datetime NOT NULL,
				indexed_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY generation_object_key (generation,object_type,object_id,subtype),
				KEY object_type (object_type),
				KEY generation (generation),
				KEY modified_gmt (modified_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$ledger} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				actor_id bigint unsigned NOT NULL DEFAULT 0,
				source varchar(30) NOT NULL DEFAULT 'wordpress',
				action_name varchar(100) NOT NULL,
				object_type varchar(50) NOT NULL,
				object_id varchar(191) NOT NULL DEFAULT '',
				risk varchar(12) NOT NULL DEFAULT 'low',
				before_payload longtext NULL,
				after_payload longtext NULL,
				before_fingerprint char(64) NOT NULL DEFAULT '',
				after_fingerprint char(64) NOT NULL DEFAULT '',
				metadata longtext NULL,
				reversible tinyint(1) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'completed',
				rollback_of bigint unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY object_lookup (object_type,object_id),
				KEY actor_id (actor_id),
				KEY created_gmt (created_gmt),
				KEY rollback_of (rollback_of)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				actor_id bigint unsigned NOT NULL DEFAULT 0,
				event_name varchar(100) NOT NULL,
				severity varchar(12) NOT NULL DEFAULT 'info',
				prompt_hash char(64) NOT NULL DEFAULT '',
				details longtext NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY actor_id (actor_id),
				KEY event_name (event_name),
				KEY created_gmt (created_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$msg} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				conversation_id char(36) NOT NULL,
				user_id bigint unsigned NOT NULL,
				role varchar(12) NOT NULL,
				content longtext NOT NULL,
				metadata longtext NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY conversation_user (conversation_id,user_id),
				KEY created_gmt (created_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$approve} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				user_id bigint unsigned NOT NULL,
				plan_hash char(64) NOT NULL,
				plan_payload longtext NOT NULL,
				expires_gmt datetime NOT NULL,
				used_gmt datetime NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY user_id (user_id),
				KEY expires_gmt (expires_gmt)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$locks} (
				lock_key char(64) NOT NULL,
				owner varchar(191) NOT NULL,
				expires_gmt datetime NOT NULL,
				created_gmt datetime NOT NULL,
				PRIMARY KEY  (lock_key),
				KEY expires_gmt (expires_gmt)
			) {$charset};"
		);

		update_option( 'site_agent_schema_version', self::SCHEMA_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( self::SCHEMA_VERSION !== get_option( 'site_agent_schema_version' ) ) {
			self::install();
		}
	}

	public static function utc_now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
