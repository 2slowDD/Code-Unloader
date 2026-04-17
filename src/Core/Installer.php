<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	public static function activate(): void {
		self::create_tables();
		update_option( CDUNLOADER_OPTION_DBVER, CDUNLOADER_DB_VERSION );
	}

	public static function deactivate(): void {
		// Rules are intentionally preserved on deactivation.
	}

	public static function uninstall(): void {
		// Called from uninstall.php when user explicitly deletes the plugin
		// and has confirmed data removal.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_log" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_group_items" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_rules" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cu_groups" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		delete_option( CDUNLOADER_OPTION_KILL );
		delete_option( CDUNLOADER_OPTION_DBVER );
		delete_transient( 'code_unloader_source_map' );
	}

	public static function maybe_upgrade(): void {
		$installed = (string) get_option( CDUNLOADER_OPTION_DBVER, '0' );
		if ( version_compare( $installed, CDUNLOADER_DB_VERSION, '>=' ) ) {
			return;
		}

		// 1.1 → 1.2: add group_id to uniq_rule index.
		// dbDelta cannot drop+recreate an existing index, so we do it explicitly.
		// SHOW INDEX returns one row per column in the index; the old key has 5 columns,
		// the new key has 6. Only run the ALTER when we see the old (5-column) key.
		if ( version_compare( $installed, '1.2', '<' ) ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$col_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE()
                   AND table_name = %s
                   AND index_name  = 'uniq_rule'",
					$wpdb->prefix . 'cu_rules'
				)
			);
			if ( 5 === $col_count ) {
				$wpdb->query(
					"ALTER TABLE {$wpdb->prefix}cu_rules
                     DROP INDEX uniq_rule,
                     ADD  UNIQUE KEY uniq_rule
                          (url_pattern(191), match_type, asset_handle(191), asset_type, device_type, group_id)"
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// 1.2 → 1.5: create cu_group_items table and add group_item_id to cu_rules.
		// Uses dbDelta for the new table (idempotent) and ADD COLUMN IF NOT EXISTS for the column.
		if ( version_compare( $installed, '1.5', '<' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			global $wpdb;
			$charset = $wpdb->get_charset_collate();

			// Create the group_items table if it doesn't exist yet.
			$sql_group_items = "CREATE TABLE {$wpdb->prefix}cu_group_items (
				id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
				group_id          INT UNSIGNED NOT NULL,
				url_pattern       VARCHAR(2048) NOT NULL,
				match_type        ENUM('exact','wildcard','regex') NOT NULL DEFAULT 'exact',
				asset_handle      VARCHAR(255) NOT NULL,
				asset_type        ENUM('js','css','inline_js','inline_css') NOT NULL,
				source_label      VARCHAR(255) NOT NULL DEFAULT '',
				device_type       ENUM('all','mobile','desktop') NOT NULL DEFAULT 'all',
				condition_type    VARCHAR(64) DEFAULT NULL,
				condition_value   VARCHAR(255) DEFAULT NULL,
				condition_invert  TINYINT(1) NOT NULL DEFAULT 0,
				label             VARCHAR(255) DEFAULT NULL,
				created_by        BIGINT UNSIGNED DEFAULT NULL,
				created_at        DATETIME NOT NULL,
				PRIMARY KEY (id),
				KEY idx_group_id (group_id),
				UNIQUE KEY uniq_group_item (group_id, url_pattern(191), match_type, asset_handle(191), asset_type, device_type, condition_type(64), condition_value(191), condition_invert)
			) ENGINE=InnoDB {$charset};";
			dbDelta( $sql_group_items ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

			// Add group_item_id to cu_rules if the column does not already exist.
			$col = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
					 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'group_item_id'",
					DB_NAME,
					$wpdb->prefix . 'cu_rules'
				)
			);
			if ( null === $col ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( "ALTER TABLE {$wpdb->prefix}cu_rules ADD COLUMN group_item_id INT UNSIGNED DEFAULT NULL AFTER group_id" );
			}
		}

		self::create_tables();
		update_option( CDUNLOADER_OPTION_DBVER, CDUNLOADER_DB_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Groups table first (rules has FK reference)
		$sql_groups = "CREATE TABLE {$wpdb->prefix}cu_groups (
			id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
			name         VARCHAR(255) NOT NULL,
			description  VARCHAR(1000) DEFAULT NULL,
			enabled      TINYINT(1) NOT NULL DEFAULT 1,
			created_at   DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_enabled (enabled)
		) ENGINE=InnoDB {$charset};";

		// Group items table (frozen rule snapshots for group membership)
		$sql_group_items = "CREATE TABLE {$wpdb->prefix}cu_group_items (
			id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id          INT UNSIGNED NOT NULL,
			url_pattern       VARCHAR(2048) NOT NULL,
			match_type        ENUM('exact','wildcard','regex') NOT NULL DEFAULT 'exact',
			asset_handle      VARCHAR(255) NOT NULL,
			asset_type        ENUM('js','css','inline_js','inline_css') NOT NULL,
			source_label      VARCHAR(255) NOT NULL DEFAULT '',
			device_type       ENUM('all','mobile','desktop') NOT NULL DEFAULT 'all',
			condition_type    VARCHAR(64) DEFAULT NULL,
			condition_value   VARCHAR(255) DEFAULT NULL,
			condition_invert  TINYINT(1) NOT NULL DEFAULT 0,
			label             VARCHAR(255) DEFAULT NULL,
			created_by        BIGINT UNSIGNED DEFAULT NULL,
			created_at        DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_group_id (group_id),
			UNIQUE KEY uniq_group_item (group_id, url_pattern(191), match_type, asset_handle(191), asset_type, device_type, condition_type(64), condition_value(191), condition_invert)
		) ENGINE=InnoDB {$charset};";

		// Rules table
		$sql_rules = "CREATE TABLE {$wpdb->prefix}cu_rules (
			id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
			url_pattern       VARCHAR(2048) NOT NULL,
			match_type        ENUM('exact','wildcard','regex') NOT NULL DEFAULT 'exact',
			asset_handle      VARCHAR(255) NOT NULL,
			asset_type        ENUM('js','css','inline_js','inline_css') NOT NULL,
			source_label      VARCHAR(255) NOT NULL DEFAULT '',
			device_type       ENUM('all','desktop','mobile') NOT NULL DEFAULT 'all',
			condition_type    VARCHAR(64) DEFAULT NULL,
			condition_value   VARCHAR(255) DEFAULT NULL,
			condition_invert  TINYINT(1) NOT NULL DEFAULT 0,
			group_id          INT UNSIGNED DEFAULT NULL,
			group_item_id     INT UNSIGNED DEFAULT NULL,
			label             VARCHAR(255) DEFAULT NULL,
			created_by        BIGINT UNSIGNED DEFAULT NULL,
			created_at        DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_url_pattern (url_pattern(191)),
			UNIQUE KEY uniq_rule (url_pattern(191), match_type, asset_handle(191), asset_type, device_type, group_id)
		) ENGINE=InnoDB {$charset};";

		// Audit log table
		$sql_log = "CREATE TABLE {$wpdb->prefix}cu_log (
			id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action       ENUM('create','delete','group_toggle','killswitch') NOT NULL,
			rule_id      INT UNSIGNED DEFAULT NULL,
			snapshot     TEXT DEFAULT NULL,
			created_at   DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_action (action),
			KEY idx_created (created_at)
		) ENGINE=InnoDB {$charset};";

		dbDelta( $sql_groups );
		dbDelta( $sql_group_items );
		dbDelta( $sql_rules );
		dbDelta( $sql_log );
	}
}
