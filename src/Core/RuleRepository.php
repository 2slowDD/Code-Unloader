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

		$device_type = $data['device_type'] ?? 'all';
		$group_id    = ( isset( $data['group_id'] ) && '' !== $data['group_id'] && null !== $data['group_id'] )
			? (int) $data['group_id']
			: null;

		// Matches the UNIQUE KEY uniq_rule (url_pattern, match_type, asset_handle, asset_type, device_type, group_id).
		// Condition columns are intentionally excluded — the DB allows only one rule per this 6-column scope;
		// conditions are attributes of a rule, not part of its identity.
		// group_id uses IFNULL sentinel (0) because NULL != NULL in SQL unique indexes.
		// Exclude clause: (id != %d OR %d = 0) — no-op when $exclude_id = 0.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cu_rules
			 WHERE url_pattern        = %s
			   AND match_type         = %s
			   AND asset_handle       = %s
			   AND asset_type         = %s
			   AND device_type        = %s
			   AND IFNULL(group_id, 0) = %d
			   AND (id != %d OR %d = 0)
			 LIMIT 1",
			$data['url_pattern'],
			$data['match_type'],
			$data['asset_handle'],
			$data['asset_type'],
			$device_type,
			$group_id ?? 0,
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

		// If the new rule belongs to a group, ensure a cu_group_items snapshot exists
		// and link the rule back to it. This keeps group membership alive independently
		// of active rule lifecycle.
		if ( isset( $data['group_id'] ) && null !== $data['group_id'] && '' !== $data['group_id'] ) {
			$group_id = (int) $data['group_id'];
			$item_id  = self::create_group_item( $group_id, $data );
			if ( ! is_wp_error( $item_id ) && $item_id > 0 ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					"{$wpdb->prefix}cu_rules",
					[ 'group_item_id' => $item_id ],
					[ 'id'            => $new_id ],
					[ '%d' ],
					[ '%d' ]
				);
			}
		}

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
		// Bulk delete affects potentially many URLs — purge 3rd-party page cache so
		// stale HTML (with already-stripped inline localizes) doesn't keep serving.
		// Without this, console errors like missing wp_localize_script globals can
		// persist after rule deletion until the cache plugin's TTL expires or the
		// CU plugin is deactivated/reactivated (which fires WP plugin-lifecycle
		// hooks that optimizers auto-purge on).
		if ( $deleted > 0 ) {
			CachePurger::purge_all();
		}
		return $deleted;
	}

	/** Delete every rule in the table. Returns number of rows deleted. */
	public static function delete_all_rules(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}cu_rules" );
		self::$rules_cache = null;
		self::invalidate_caches();
		// Sitewide change — purge 3rd-party page cache (see delete_rules() comment).
		if ( $deleted > 0 ) {
			CachePurger::purge_all();
		}
		return $deleted;
	}

	/**
	 * Remove active rules that match the given asset on the specified scope.
	 *
	 * Scope 'page'   — removes only rules whose URL pattern matches $page_url for this asset.
	 * Scope 'global' — removes all active rules for this asset regardless of URL.
	 *
	 * Group item snapshots in cu_group_items are never touched by this method.
	 *
	 * @param string $handle   Asset handle.
	 * @param string $type     Asset type (js, css, inline_js, inline_css).
	 * @param string $device   Device type filter (all, mobile, desktop).
	 * @param string $scope    'page' or 'global'.
	 * @param string $page_url Current page URL (used only when scope = 'page').
	 * @return int Number of rules deleted.
	 */
	public static function delete_active_rules_by_scope(
		string $handle,
		string $type,
		string $device,
		string $scope,
		string $page_url
	): int {
		global $wpdb;

		if ( 'global' === $scope ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows_to_delete = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url_pattern, match_type FROM {$wpdb->prefix}cu_rules
					 WHERE asset_handle = %s AND asset_type = %s AND device_type = %s",
					$handle, $type, $device
				)
			) ?: [];
		} else {
			// 'page' scope: fetch candidates and filter by URL matching.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$candidates = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, url_pattern, match_type FROM {$wpdb->prefix}cu_rules
					 WHERE asset_handle = %s AND asset_type = %s AND device_type = %s",
					$handle, $type, $device
				)
			) ?: [];

			$normalized_url = PatternMatcher::normalize_url( $page_url );
			$rows_to_delete = array_filter(
				$candidates,
				static function ( $rule ) use ( $normalized_url ): bool {
					return PatternMatcher::match( $rule, $normalized_url );
				}
			);
		}

		if ( empty( $rows_to_delete ) ) {
			return 0;
		}

		$ids     = array_map( static fn( $r ) => (int) $r->id, $rows_to_delete );
		$deleted = 0;
		$user_id = get_current_user_id();

		foreach ( $ids as $id ) {
			$rule = self::get_rule( $id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = (bool) $wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}cu_rules WHERE id = %d", $id )
			);
			if ( $result ) {
				++$deleted;
				self::log_action( 'delete', $user_id, $id, $rule );
				if ( $rule ) {
					CachePurger::purge_for_rule( $rule->url_pattern, $rule->match_type );
				}
			}
		}

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

		$offset = ( $page - 1 ) * $per_page;

		// Resolve filter values for static SQL templates.
		// Optional filters use a bypass pattern: (%s = '' OR condition).
		// When a filter is inactive, pass '' as the bypass arg — '' = '' is TRUE
		// and short-circuits the condition. When active, pass a non-empty sentinel
		// so the real condition is evaluated.
		$search_active = ! empty( $filters['search'] );
		$search_bypass = $search_active ? 'active' : '';
		$search_like   = $search_active
			? '%' . $wpdb->esc_like( $filters['search'] ) . '%'
			: '';
		$match_type    = $filters['match_type'] ?? '';
		$asset_type    = $filters['asset_type'] ?? '';
		$device_type   = $filters['device_type'] ?? '';
		$gid           = isset( $filters['group_id'] ) ? (int) $filters['group_id'] : 0;

		if ( $gid === 0 ) {
			// All Groups view: collapse cross-group duplicates into one row per unique
			// 8-field combination. Enabled-group filter is always active here.

			// Raise GROUP_CONCAT limit so group names are never silently truncated.
			$wpdb->query( 'SET SESSION group_concat_max_len = 65536' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT MIN(r.id)                                                      AS id,
					        r.url_pattern, r.match_type, r.asset_handle, r.asset_type,
					        r.device_type, r.condition_type, r.condition_value, r.condition_invert,
					        MIN(r.source_label)                                             AS source_label,
					        MIN(r.label)                                                    AS label,
					        MIN(r.created_at)                                               AS created_at,
					        GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR '\x1f')  AS group_names
					 FROM {$wpdb->prefix}cu_rules r
					 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					 WHERE (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					   AND (%s = '' OR r.match_type = %s)
					   AND (%s = '' OR r.asset_type = %s)
					   AND (%s = '' OR r.device_type = %s)
					 GROUP BY r.url_pattern, r.match_type, r.asset_handle, r.asset_type,
					          r.device_type, r.condition_type, r.condition_value, r.condition_invert
					 ORDER BY MIN(r.created_at) DESC
					 LIMIT %d OFFSET %d",
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type,
					$per_page, $offset
				)
			);

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM (
					    SELECT 1
					    FROM {$wpdb->prefix}cu_rules r
					    LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					    WHERE (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					      AND (%s = '' OR r.match_type = %s)
					      AND (%s = '' OR r.asset_type = %s)
					      AND (%s = '' OR r.device_type = %s)
					    GROUP BY r.url_pattern, r.match_type, r.asset_handle, r.asset_type,
					             r.device_type, r.condition_type, r.condition_value, r.condition_invert
					) AS unique_rules",
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		} elseif ( $gid === -1 ) {
			// Ungrouped view: rules with no group assignment.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, g.name AS group_name
					 FROM {$wpdb->prefix}cu_rules r
					 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					 WHERE r.group_id IS NULL
					   AND (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					   AND (%s = '' OR r.match_type = %s)
					   AND (%s = '' OR r.asset_type = %s)
					   AND (%s = '' OR r.device_type = %s)
					 ORDER BY r.created_at DESC
					 LIMIT %d OFFSET %d",
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type,
					$per_page, $offset
				)
			);

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cu_rules r
					 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					 WHERE r.group_id IS NULL
					   AND (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					   AND (%s = '' OR r.match_type = %s)
					   AND (%s = '' OR r.asset_type = %s)
					   AND (%s = '' OR r.device_type = %s)",
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		} else {
			// Specific group view: show all rules in the group regardless of enabled state.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.*, g.name AS group_name
					 FROM {$wpdb->prefix}cu_rules r
					 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					 WHERE r.group_id = %d
					   AND (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					   AND (%s = '' OR r.match_type = %s)
					   AND (%s = '' OR r.asset_type = %s)
					   AND (%s = '' OR r.device_type = %s)
					 ORDER BY r.created_at DESC
					 LIMIT %d OFFSET %d",
					$gid,
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type,
					$per_page, $offset
				)
			);

			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}cu_rules r
					 LEFT JOIN {$wpdb->prefix}cu_groups g ON g.id = r.group_id
					 WHERE r.group_id = %d
					   AND (%s = '' OR (r.url_pattern LIKE %s OR r.asset_handle LIKE %s OR r.source_label LIKE %s))
					   AND (%s = '' OR r.match_type = %s)
					   AND (%s = '' OR r.asset_type = %s)
					   AND (%s = '' OR r.device_type = %s)",
					$gid,
					$search_bypass, $search_like, $search_like, $search_like,
					$match_type, $match_type,
					$asset_type, $asset_type,
					$device_type, $device_type
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

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
			// Enabled groups float to the top so the active set is visible at a glance,
			// then alphabetical within each enabled/disabled bucket.
			"SELECT g.*, COUNT(gi.id) AS rule_count
			 FROM {$wpdb->prefix}cu_groups g
			 LEFT JOIN {$wpdb->prefix}cu_group_items gi ON gi.group_id = g.id
			 GROUP BY g.id
			 ORDER BY g.enabled DESC, g.name"
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
			if ( ! empty( $data['enabled'] ) ) {
				self::activate_group_items( $id );
				$action = 'group_activate';
			} else {
				self::deactivate_group_items( $id );
				$action = 'group_deactivate';
			}
			$group = self::get_group( $id );
			self::log_action( $action, get_current_user_id(), null, $group );
			// Toggling a group changes which rules apply at runtime — purge
			// 3rd-party page cache so stale HTML doesn't keep serving (e.g.
			// inline localizes that were stripped while the group was active
			// would still be missing in cached pages until cache TTL expires
			// or the plugin is deactivated/reactivated).
			CachePurger::purge_all();
			self::bump_snapshots_version();
		}

		return $result !== false;
	}

	public static function delete_group( int $id ): bool {
		global $wpdb;
		self::deactivate_group_items( $id );
		self::delete_group_items( $id );
		$result = (bool) $wpdb->delete( "{$wpdb->prefix}cu_groups", [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		self::invalidate_caches();
		if ( $result ) {
			// Group rules went inactive — purge 3rd-party page cache.
			CachePurger::purge_all();
		}
		return $result;
	}

	/**
	 * Wipe every group and every snapshot, but keep active rules alive.
	 *
	 * Semantics (differs deliberately from delete_group):
	 *   - all rows in cu_groups are deleted
	 *   - all rows in cu_group_items are deleted (non-active "orphan" rules)
	 *   - active rules in cu_rules are KEPT; their group_id and group_item_id
	 *     columns are nulled out so they don't dangle at now-deleted groups.
	 *
	 * @return int Number of groups deleted.
	 */
	public static function delete_all_groups(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- static SQL, no user input, bulk admin maintenance.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cu_groups" );
		if ( 0 === $count ) {
			return 0;
		}

		// Keep active rules but clear their group references before the parent rows disappear.
		$wpdb->query( "UPDATE {$wpdb->prefix}cu_rules SET group_id = NULL, group_item_id = NULL WHERE group_id IS NOT NULL OR group_item_id IS NOT NULL" );

		// Delete all non-active snapshot rows, then all groups.
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cu_group_items" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cu_groups" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		self::bump_snapshots_version();
		self::invalidate_caches();
		// Wiping every group is a sitewide change — purge 3rd-party page cache.
		CachePurger::purge_all();
		return $count;
	}

	// -------------------------------------------------------------------------
	// Group Items
	// -------------------------------------------------------------------------

	/**
	 * Get all saved snapshots for a group.
	 *
	 * @param int $group_id
	 * @return object[]
	 */
	public static function get_group_items( int $group_id ): array {
		$cache_key = "cdunloader_group_items_{$group_id}";
		$cached    = wp_cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cu_group_items WHERE group_id = %d ORDER BY created_at DESC",
				$group_id
			)
		) ?: [];
		wp_cache_set( $cache_key, $results );
		return $results;
	}

	/**
	 * Return snapshot rows from cu_group_items whose group is enabled
	 * AND whose url_pattern matches the given URL.
	 *
	 * Used by the frontend panel to keep user-managed assets visible on
	 * pages where their URL pattern matches, even when no active rule
	 * currently dequeues them. Mirrors how DequeueEngine::process_rules
	 * filters: SQL handles the enabled-group join + ordering, PHP handles
	 * url_pattern matching (since match_type can be regex/wildcard).
	 *
	 * Deterministic two-group tie-break: group_id ASC, id ASC — so when a
	 * snapshot exists in two enabled groups for the same URL, the lower-
	 * numbered (older) group wins reproducibly.
	 *
	 * @param string $url Already-normalized current URL (caller normalizes).
	 * @return object[]   Snapshot rows with cu_group_items columns.
	 */
	public static function get_group_item_snapshots_for_url( string $url ): array {
		$version   = (int) wp_cache_get( 'cdunloader_snapshots_version' );
		$cache_key = 'cdunloader_snapshots_for_url_' . md5( $url ) . '_v' . $version;
		$cached    = wp_cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		// INNER JOIN cu_groups on enabled = 1 — disabled-group snapshots are inert.
		// No FK exists between cu_group_items.group_id and cu_groups.id, so the
		// INNER JOIN also drops orphaned snapshots whose parent group was deleted
		// outside the normal delete_group path.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT gi.*
			 FROM {$wpdb->prefix}cu_group_items gi
			 INNER JOIN {$wpdb->prefix}cu_groups g
			         ON g.id = gi.group_id AND g.enabled = 1
			 ORDER BY gi.group_id ASC, gi.id ASC"
		) ?: [];

		$matched = array_values( array_filter(
			$rows,
			static fn( $snap ) => PatternMatcher::match( $snap, $url )
		) );

		wp_cache_set( $cache_key, $matched );
		return $matched;
	}

	/**
	 * Get a single group item snapshot by ID.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public static function get_group_item( int $id ): ?object {
		$cache_key = "cdunloader_group_item_{$id}";
		$cached    = wp_cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached ?: null;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cu_group_items WHERE id = %d",
				$id
			)
		) ?: null;
		wp_cache_set( $cache_key, $row ?: 0 );
		return $row;
	}

	/**
	 * Find an existing snapshot in a group matching the given rule fields.
	 * Uses the same NULL-safe matching as the migration to avoid false positives.
	 *
	 * @param int   $group_id
	 * @param array $snapshot  Keys: url_pattern, match_type, asset_handle, asset_type,
	 *                                device_type, condition_type, condition_value, condition_invert
	 * @return object|null
	 */
	public static function find_duplicate_group_item( int $group_id, array $snapshot ): ?object {
		global $wpdb;
		$condition_type  = $snapshot['condition_type']  ?? null;
		$condition_value = $snapshot['condition_value'] ?? null;

		if ( null === $condition_type && null === $condition_value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}cu_group_items
					 WHERE group_id         = %d
					   AND url_pattern      = %s
					   AND match_type       = %s
					   AND asset_handle     = %s
					   AND asset_type       = %s
					   AND device_type      = %s
					   AND condition_invert = %d
					   AND condition_type   IS NULL
					   AND condition_value  IS NULL",
					$group_id,
					$snapshot['url_pattern'],
					$snapshot['match_type'],
					$snapshot['asset_handle'],
					$snapshot['asset_type'],
					$snapshot['device_type'] ?? 'all',
					(int) ( $snapshot['condition_invert'] ?? 0 )
				)
			) ?: null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cu_group_items
				 WHERE group_id         = %d
				   AND url_pattern      = %s
				   AND match_type       = %s
				   AND asset_handle     = %s
				   AND asset_type       = %s
				   AND device_type      = %s
				   AND condition_invert = %d
				   AND IFNULL(condition_type, '')  = %s
				   AND IFNULL(condition_value, '') = %s",
				$group_id,
				$snapshot['url_pattern'],
				$snapshot['match_type'],
				$snapshot['asset_handle'],
				$snapshot['asset_type'],
				$snapshot['device_type'] ?? 'all',
				(int) ( $snapshot['condition_invert'] ?? 0 ),
				(string) ( $condition_type  ?? '' ),
				(string) ( $condition_value ?? '' )
			)
		) ?: null;
	}

	/**
	 * Create a new group item snapshot. Returns the new ID, or existing ID if duplicate,
	 * or WP_Error on DB failure.
	 *
	 * @param int   $group_id
	 * @param array $snapshot  Same shape as find_duplicate_group_item $snapshot param.
	 * @return int|\WP_Error
	 */
	public static function create_group_item( int $group_id, array $snapshot ): int|\WP_Error {
		$existing = self::find_duplicate_group_item( $group_id, $snapshot );
		if ( null !== $existing ) {
			return (int) $existing->id;
		}
		global $wpdb;
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			"{$wpdb->prefix}cu_group_items",
			[
				'group_id'         => $group_id,
				'url_pattern'      => $snapshot['url_pattern'],
				'match_type'       => $snapshot['match_type'],
				'asset_handle'     => $snapshot['asset_handle'],
				'asset_type'       => $snapshot['asset_type'],
				'source_label'     => $snapshot['source_label']     ?? '',
				'device_type'      => $snapshot['device_type']      ?? 'all',
				'condition_type'   => $snapshot['condition_type']   ?: null,
				'condition_value'  => $snapshot['condition_value']  ?: null,
				'condition_invert' => (int) ( $snapshot['condition_invert'] ?? 0 ),
				'label'            => $snapshot['label']            ?: null,
				'created_by'       => get_current_user_id()         ?: null,
				'created_at'       => current_time( 'mysql', true ),
			],
			[ '%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%s' ]
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'db_error', $wpdb->last_error );
		}
		$new_id = (int) $wpdb->insert_id;
		wp_cache_delete( "cdunloader_group_items_{$group_id}" );
		self::bump_snapshots_version();
		return $new_id;
	}

	/**
	 * Delete all snapshot items for a group. Returns count of deleted rows.
	 *
	 * @param int $group_id
	 * @return int
	 */
	public static function delete_group_items( int $group_id ): int {
		global $wpdb;
		$deleted = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}cu_group_items WHERE group_id = %d",
				$group_id
			)
		);
		wp_cache_delete( "cdunloader_group_items_{$group_id}" );
		self::bump_snapshots_version();
		return $deleted;
	}

	/**
	 * Materialise active rules from a group's saved snapshots.
	 * Skips snapshots that already have an identical active rule in cu_rules.
	 * Sets group_item_id on each created active rule.
	 *
	 * @param int $group_id
	 * @return int Number of active rules created.
	 */
	public static function activate_group_items( int $group_id ): int {
		$items   = self::get_group_items( $group_id );
		$created = 0;

		foreach ( $items as $item ) {
			$data = [
				'url_pattern'      => $item->url_pattern,
				'match_type'       => $item->match_type,
				'asset_handle'     => $item->asset_handle,
				'asset_type'       => $item->asset_type,
				'source_label'     => $item->source_label     ?? '',
				'device_type'      => $item->device_type      ?? 'all',
				'condition_type'   => $item->condition_type   ?: null,
				'condition_value'  => $item->condition_value  ?: null,
				'condition_invert' => (int) ( $item->condition_invert ?? 0 ),
				'group_id'         => $group_id,
				'group_item_id'    => (int) $item->id,
				'label'            => $item->label            ?: null,
			];

			// find_duplicate checks cu_rules for an identical row in the same group scope.
			// If one exists, skip — don't create a duplicate active rule.
			if ( null !== self::find_duplicate( $data ) ) {
				continue;
			}

			$result = self::create_rule( $data );
			if ( ! is_wp_error( $result ) ) {
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Remove active rules in cu_rules that were created from this group's snapshots.
	 * Identified by group_item_id pointing to a snapshot in this group.
	 * Does NOT delete cu_group_items rows.
	 *
	 * @param int $group_id
	 * @return int Number of active rules deleted.
	 */
	public static function deactivate_group_items( int $group_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id FROM {$wpdb->prefix}cu_rules r
				 INNER JOIN {$wpdb->prefix}cu_group_items gi ON gi.id = r.group_item_id
				 WHERE gi.group_id = %d",
				$group_id
			)
		) ?: [];

		if ( empty( $ids ) ) {
			// Fallback: also remove rules still linked only via legacy group_id.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cu_rules WHERE group_id = %d AND group_item_id IS NULL",
					$group_id
				)
			) ?: [];
		}

		if ( empty( $ids ) ) {
			return 0;
		}

		return self::delete_rules( array_map( 'intval', $ids ) );
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

	/** Bug 1 (1.4.6): bump the snapshot cache version so per-URL caches are bypassed on next read. */
	private static function bump_snapshots_version(): void {
		$current = (int) wp_cache_get( 'cdunloader_snapshots_version' );
		wp_cache_set( 'cdunloader_snapshots_version', $current + 1 );
	}

	/** Flush all object caches used by this repository. */
	private static function invalidate_caches(): void {
		self::$rules_cache = null; // clear static in-memory cache — must come before wp_cache_delete
		wp_cache_delete( 'cdunloader_all_rules' );
		wp_cache_delete( 'cdunloader_all_groups' );
		wp_cache_delete( 'cdunloader_rules_count' );
	}
}
