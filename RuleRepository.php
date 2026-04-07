<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RuleRepository {

	private static ?array $rules_cache = null;

	// -------------------------------------------------------------------------
	// Rules
	// -------------------------------------------------------------------------

	/** Load all rules (request-scoped cache). */
	public static function get_all_rules(): array {
		if ( null !== self::$rules_cache ) {
			return self::$rules_cache;
		}
		$cached = wp_cache_get( 'cdunloader_all_rules' );
		if ( false !== $cached ) {
			self::$rules_cache = $cached;
			return self::$rules_cache;
		}
		global $wpdb;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"SELECT r.*, g.enabled AS group_enabled
			 FROM {$wpdb->prefix}cu_rules r
			 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id"
		);
		self::$rules_cache = $results ?: [];
		wp_cache_set( 'cdunloader_all_rules', self::$rules_cache );
		return self::$rules_cache;
	}

	/** Get a single rule by ID. */
	public static function get_rule( int $id ): ?object {
		$cached = wp_cache_get( "cdunloader_rule_{$id}" );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cu_rules WHERE id = %d", $id )
		) ?: null;
		wp_cache_set( "cdunloader_rule_{$id}", $row ?: 0 );
		return $row;
	}

	/**
	 * Find an existing rule that matches all 9 duplicate-key fields in the same scope.
	 * NULL group_id (ungrouped) is treated as a distinct scope equal to other NULLs.
	 * Bypasses the request-scoped cache — always queries the DB directly.
	 *
	 * @param array $data       Rule field array (same shape as passed to create_rule).
	 * @param int   $exclude_id Optional rule ID to exclude from the match (used when reassigning group).
	 * @return object|null Matching row, or null if no duplicate exists.
	 */
	public static function find_duplicate( array $data, int $exclude_id = 0 ): ?object {
		global $wpdb;

		$device_type      = $data['device_type']      ?? 'all';
		$condition_invert = (int) ( $data['condition_invert'] ?? 0 );
		$group_id         = ( isset( $data['group_id'] ) && '' !== $data['group_id'] && null !== $data['group_id'] )
			? (int) $data['group_id']
			: null;

		// Nullable columns (condition_type, condition_value, group_id) are matched with
		// IFNULL() sentinels: '' for string columns (never a valid condition value), and
		// 0 for group_id (auto-increment IDs start at 1, so 0 is never a real group).
		// Exclude clause: (id != %d OR %d = 0) — when $exclude_id is 0 the OR branch is
		// always true so no row is excluded; when > 0 the rule's own ID is skipped.
		// This keeps the query a single static string with no interpolated variables.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cu_rules
			 WHERE url_pattern                = %s
			   AND match_type                 = %s
			   AND asset_handle               = %s
			   AND asset_type                 = %s
			   AND device_type                = %s
			   AND condition_invert           = %d
			   AND IFNULL(condition_type, '')  = %s
			   AND IFNULL(condition_value, '') = %s
			   AND IFNULL(group_id, 0)        = %d
			   AND (id != %d OR %d = 0)
			 LIMIT 1",
			$data['url_pattern'],
			$data['match_type'],
			$data['asset_handle'],
			$data['asset_type'],
			$device_type,
			$condition_invert,
			$data['condition_type']  ?? '',
			$data['condition_value'] ?? '',
			$group_id                ?? 0,
			$exclude_id,
			$exclude_id,
		) ) ?: null;
	}

	/** Insert a new rule. Returns new ID, or existing ID if an identical rule already exists in the same scope (silent deduplication). Returns WP_Error on DB failure. */
	public static function create_rule( array $data ): int|\WP_Error {
		global $wpdb;

		// Silently skip insert if an identical rule already exists in the same scope.
		$existing = self::find_duplicate( $data );
		if ( null !== $existing ) {
			return (int) $existing->id;
		}

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"{$wpdb->prefix}cu_rules",
			[
				'url_pattern'     => $data['url_pattern'],
				'match_type'      => $data['match_type'],
				'asset_handle'    => $data['asset_handle'],
				'asset_type'      => $data['asset_type'],
				'source_label'    => $data['source_label']    ?? '',
				'device_type'     => $data['device_type']     ?? 'all',
				'condition_type'  => $data['condition_type']  ?? null,
				'condition_value' => $data['condition_value'] ?? null,
				'condition_invert'=> (int) ( $data['condition_invert'] ?? 0 ),
				'group_id'        => ( isset( $data['group_id'] ) && '' !== $data['group_id'] && null !== $data['group_id'] ) ? (int) $data['group_id'] : null,
				'label'           => $data['label']           ?? null,
				'created_by'      => get_current_user_id() ?: null,
				'created_at'      => $now,
			],
			[ '%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%d','%s' ]
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', $wpdb->last_error );
		}

		self::$rules_cache = null; // invalidate cache
		$new_id = (int) $wpdb->insert_id;

		// Audit log
		self::log_action( 'create', get_current_user_id(), $new_id, self::get_rule( $new_id ) );

		// Purge relevant cache so the rule takes effect immediately
		CachePurger::purge_for_rule( $data['url_pattern'], $data['match_type'] );

		return $new_id;
	}

	/** Update label, group_id, condition fields of a rule. */
	public static function update_rule( int $id, array $data ): bool|\WP_Error {
		global $wpdb;

		$allowed = [ 'label', 'group_id', 'condition_type', 'condition_value', 'condition_invert' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );

		if ( empty( $update ) ) {
			return new \WP_Error( 'no_fields', 'No updatable fields provided.' );
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"{$wpdb->prefix}cu_rules",
			$update,
			[ 'id' => $id ]
		);

		self::$rules_cache = null;
		self::invalidate_caches();
		return $result !== false;
	}

	/** Delete a single rule. */
	public static function delete_rule( int $id ): bool {
		global $wpdb;

		$rule = self::get_rule( $id );
		$result = (bool) $wpdb->delete( "{$wpdb->prefix}cu_rules", [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $result ) {
			self::$rules_cache = null;
			self::invalidate_caches();
			self::log_action( 'delete', get_current_user_id(), $id, $rule );
			if ( $rule ) {
				CachePurger::purge_for_rule( $rule->url_pattern, $rule->match_type );
			}
		}

		return $result;
	}

	/** Bulk delete rules by array of IDs. */
	public static function delete_rules( array $ids ): int {
		if ( empty( $ids ) ) {
			return 0;
		}
		global $wpdb;
		$ids_int = array_map( 'intval', $ids );
		$deleted = 0;
		foreach ( $ids_int as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cu_rules WHERE id = %d", $id ) );
		}
		self::$rules_cache = null;
		self::invalidate_caches();
		return $deleted;
	}

	/** Delete every rule in the table. Returns number of rows deleted. */
	public static function delete_all_rules(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cu_rules" );
		self::$rules_cache = null;
		self::invalidate_caches();
		return $deleted;
	}

	/**
	 * Return handles stored in rules that are no longer registered in WordPress.
	 * Only meaningful on the FRONTEND after wp_enqueue_scripts has fired.
	 * On admin pages $wp_scripts->registered contains only admin scripts —
	 * every frontend plugin handle would falsely appear "stale".
	 *
	 * @return int[]  Array of rule IDs that reference stale handles.
	 */
	public static function get_stale_rule_ids(): array {
		global $wp_scripts, $wp_styles, $wpdb;

		// Only run on frontend. Admin pages never register frontend plugin scripts,
		// so running there would flag every valid frontend rule as stale.
		if ( ! did_action( 'wp_enqueue_scripts' ) ) {
			return [];
		}

		$registered_handles = array_merge(
			array_keys( $wp_scripts->registered ?? [] ),
			array_keys( $wp_styles->registered  ?? [] )
		);

		if ( empty( $registered_handles ) ) {
			return [];
		}

		// Only check handle-based rules (not inline rules which store patterns, not handles)
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id, asset_handle, asset_type FROM {$wpdb->prefix}cu_rules
			 WHERE asset_type IN ('js','css')"
		);

		$stale = [];
		foreach ( $rows as $row ) {
			if ( ! in_array( $row->asset_handle, $registered_handles, true ) ) {
				$stale[] = (int) $row->id;
			}
		}

		return $stale;
	}

	/** Get rules filtered for admin list table. */
	public static function get_rules_filtered( array $filters = [], int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$conditions = [];
		$params     = [];

		if ( ! empty( $filters['search'] ) ) {
			$s            = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$conditions[] = $wpdb->prepare( '(r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s)', $s, $s, $s );
		}

		if ( ! empty( $filters['match_type'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.match_type = %s', $filters['match_type'] );
		}

		if ( ! empty( $filters['asset_type'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.asset_type = %s', $filters['asset_type'] );
		}

		if ( ! empty( $filters['device_type'] ) ) {
			$conditions[] = $wpdb->prepare( 'r.device_type = %s', $filters['device_type'] );
		}

		if ( isset( $filters['group_id'] ) && (int) $filters['group_id'] !== 0 ) {
			$gid = (int) $filters['group_id'];
			if ( $gid === -1 ) {
				// Ungrouped: rules with no group
				$conditions[] = 'r.group_id IS NULL';
			} else {
				$conditions[] = $wpdb->prepare( 'r.group_id = %d', $gid );
			}
		}

		// Hide rules whose group is disabled in global/ungrouped views.
		// When browsing a specific group, always show all its rules regardless of enabled state.
		if ( ! isset( $filters['group_id'] ) || (int) $filters['group_id'] <= 0 ) {
			$conditions[] = '(g.enabled = 1 OR r.group_id IS NULL)';
		}

		// Each element in $conditions is already fully prepared — safe to join.
		$where_clause = empty( $conditions ) ? '1=1' : implode( ' AND ', $conditions );
		$offset       = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All filter values are individually $wpdb->prepare()'d above; only LIMIT/OFFSET remain.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, g.name AS group_name
			 FROM {$wpdb->prefix}cu_rules r
			 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
			 WHERE {$where_clause}
			 ORDER BY r.created_at DESC
			 LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cu_rules r LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id WHERE {$where_clause}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return [ 'rows' => $rows ?: [], 'total' => $count ];
	}

	// -------------------------------------------------------------------------
	// Groups
	// -------------------------------------------------------------------------

	public static function get_all_groups(): array {
		$cached = wp_cache_get( 'cdunloader_all_groups' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"SELECT g.*, COUNT(r.id) AS rule_count
			 FROM {$wpdb->prefix}cu_groups g
			 LEFT JOIN {$wpdb->prefix}cu_rules r ON r.group_id = g.id
			 GROUP BY g.id
			 ORDER BY g.name"
		) ?: [];
		wp_cache_set( 'cdunloader_all_groups', $results );
		return $results;
	}

	public static function get_group( int $id ): ?object {
		$cached = wp_cache_get( "cdunloader_group_{$id}" );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"SELECT * FROM {$wpdb->prefix}cu_groups WHERE id = %d", $id
		) ) ?: null;
		wp_cache_set( "cdunloader_group_{$id}", $row ?: 0 );
		return $row;
	}

	public static function create_group( string $name, string $description = '' ): int|\WP_Error {
		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"{$wpdb->prefix}cu_groups",
			[ 'name' => $name, 'description' => $description, 'enabled' => 1, 'created_at' => current_time( 'mysql', true ) ],
			[ '%s', '%s', '%d', '%s' ]
		);
		if ( $inserted ) {
			self::invalidate_caches();
			return (int) $wpdb->insert_id;
		}
		return new \WP_Error( 'db_error', $wpdb->last_error );
	}

	public static function update_group( int $id, array $data ): bool {
		global $wpdb;
		$allowed = [ 'name', 'description', 'enabled' ];
		$update  = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $update ) ) {
			return false;
		}
		$result = $wpdb->update( "{$wpdb->prefix}cu_groups", $update, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		self::invalidate_caches();

		if ( $result !== false && isset( $data['enabled'] ) ) {
			$group = self::get_group( $id );
			self::log_action( 'group_toggle', get_current_user_id(), null, $group );
		}

		return $result !== false;
	}

	public static function delete_group( int $id ): bool {
		global $wpdb;
		// Rules become ungrouped
		$wpdb->update( "{$wpdb->prefix}cu_rules", [ 'group_id' => null ], [ 'group_id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = (bool) $wpdb->delete( "{$wpdb->prefix}cu_groups", [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		self::invalidate_caches();
		return $result;
	}

	// -------------------------------------------------------------------------
	// Audit log
	// -------------------------------------------------------------------------

	public static function log_action( string $action, int $user_id, ?int $rule_id, $snapshot ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"{$wpdb->prefix}cu_log",
			[
				'user_id'    => $user_id,
				'action'     => $action,
				'rule_id'    => $rule_id,
				'snapshot'   => $snapshot ? wp_json_encode( $snapshot ) : null,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	public static function get_log( int $per_page = 50, int $page = 1, ?string $action_filter = null ): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $action_filter ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT l.*, u.user_login
				 FROM {$wpdb->prefix}cu_log l
				 LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				 WHERE l.action = %s
				 ORDER BY l.created_at DESC
				 LIMIT %d OFFSET %d",
				$action_filter,
				$per_page,
				$offset
			) );
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cu_log WHERE action = %s",
				$action_filter
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT l.*, u.user_login
				 FROM {$wpdb->prefix}cu_log l
				 LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
				 ORDER BY l.created_at DESC
				 LIMIT %d OFFSET %d",
				$per_page,
				$offset
			) );
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cu_log"
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return [ 'rows' => $rows ?: [], 'total' => $total ];
	}

	public static function clear_log(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cu_log WHERE 1 = %d", 1 ) );
	}

	/** Flush all object caches used by this repository. */
	private static function invalidate_caches(): void {
		self::$rules_cache = null; // clear static in-memory cache — must come before wp_cache_delete
		wp_cache_delete( 'cdunloader_all_rules' );
		wp_cache_delete( 'cdunloader_all_groups' );
		wp_cache_delete( 'cdunloader_rules_count' );
	}
}
