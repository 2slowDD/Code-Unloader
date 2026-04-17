<?php
declare( strict_types=1 );

namespace CodeUnloader\Api;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CodeUnloader\Core\{PatternMatcher, RuleRepository, ConditionEvaluator, DeviceDetector};

class RestController {

	private const NS = 'code-unloader/v1';

	public function register_routes(): void {
		// Rules
		register_rest_route( self::NS, '/rules', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'get_rules' ],    'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_rule' ],  'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/bulk-delete', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'bulk_delete_rules' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/delete-all', [
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_all_rules' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/bulk-assign-group', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'bulk_assign_group' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/enable', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'enable_asset' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/validate-pattern', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'validate_pattern' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/rules/(?P<id>\\d+)', [
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_rule' ],  'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'PATCH',  'callback' => [ $this, 'update_rule' ],  'permission_callback' => [ $this, 'check_permission' ] ],
		] );

		// Groups
		register_rest_route( self::NS, '/groups', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'get_groups' ],   'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'create_group' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/groups/(?P<id>\\d+)', [
			[ 'methods' => 'PATCH',  'callback' => [ $this, 'update_group' ], 'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'delete_group' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
		register_rest_route( self::NS, '/groups/(?P<id>\\d+)/items', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_group_items' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );

		// Log
		register_rest_route( self::NS, '/log', [
			[ 'methods' => 'GET',    'callback' => [ $this, 'get_log' ],   'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'clear_log' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );

		// Kill switch
		register_rest_route( self::NS, '/killswitch', [
			[ 'methods' => 'GET',  'callback' => [ $this, 'get_killswitch' ],    'permission_callback' => [ $this, 'check_permission' ] ],
			[ 'methods' => 'POST', 'callback' => [ $this, 'toggle_killswitch' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );

		// Assets for current page (used by frontend panel)
		register_rest_route( self::NS, '/assets', [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_page_assets' ], 'permission_callback' => [ $this, 'check_permission' ] ],
		] );
	}

	public function check_permission(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', __( 'Insufficient permissions.', 'code-unloader' ), [ 'status' => 403 ] );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Rules endpoints
	// -------------------------------------------------------------------------

	public function get_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$page_url = $request->get_param( 'page_url' );

		if ( $page_url ) {
			$url  = PatternMatcher::normalize_url( (string) $page_url );
			$all  = RuleRepository::get_all_rules();
			$rows = array_values( array_filter( $all, function ( $r ) use ( $url ): bool {
				if ( isset( $r->group_id ) && null !== $r->group_id && ! (int) ( $r->group_enabled ?? 1 ) ) {
					return false;
				}
				return PatternMatcher::match( $r, $url );
			} ) );
			return new \WP_REST_Response( $rows );
		}

		$filters = [
			'search'     => $request->get_param( 'search' ),
			'match_type' => $request->get_param( 'match_type' ),
			'asset_type' => $request->get_param( 'asset_type' ),
			'device_type'=> $request->get_param( 'device_type' ),
			'group_id'   => $request->get_param( 'group_id' ),
		];

		$result = RuleRepository::get_rules_filtered(
			array_filter( $filters ),
			(int) ( $request->get_param( 'per_page' ) ?: 20 ),
			(int) ( $request->get_param( 'page' ) ?: 1 )
		);

		return new \WP_REST_Response( $result );
	}

	public function create_rule( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $request->get_json_params();

		// Validate required fields
		foreach ( [ 'url_pattern', 'match_type', 'asset_handle', 'asset_type' ] as $field ) {
			if ( empty( $data[ $field ] ) ) {
				return new \WP_Error( 'missing_field', "Missing required field: {$field}", [ 'status' => 400 ] );
			}
		}

		// Validate match_type
		if ( ! in_array( $data['match_type'], [ 'exact', 'wildcard', 'regex' ], true ) ) {
			return new \WP_Error( 'invalid_match_type', 'Invalid match_type.', [ 'status' => 400 ] );
		}

		// Validate asset_type
		if ( ! in_array( $data['asset_type'], [ 'js', 'css', 'inline_js', 'inline_css' ], true ) ) {
			return new \WP_Error( 'invalid_asset_type', 'Invalid asset_type.', [ 'status' => 400 ] );
		}

		// Validate pattern
		$validation_error = PatternMatcher::validate( $data['url_pattern'], $data['match_type'] );
		if ( $validation_error ) {
			return new \WP_Error( 'invalid_pattern', $validation_error, [ 'status' => 422 ] );
		}

		// Normalize URL patterns for exact/wildcard
		if ( 'exact' === $data['match_type'] ) {
			$data['url_pattern'] = PatternMatcher::normalize_url( $data['url_pattern'] );
		}

		$result = RuleRepository::create_rule( $data );
		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response( [ 'error' => $result->get_error_message() ], 500 );
		}

		$rule = RuleRepository::get_rule( $result );
		return new \WP_REST_Response( $rule, 201 );
	}

	public function delete_rule( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		RuleRepository::delete_rule( $id );
		return new \WP_REST_Response( [ 'deleted' => true ] );
	}

	public function update_rule( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_json_params();
		RuleRepository::update_rule( $id, $data );
		return new \WP_REST_Response( RuleRepository::get_rule( $id ) );
	}

	public function bulk_delete_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$ids     = (array) $request->get_param( 'ids' );
		$deleted = RuleRepository::delete_rules( $ids );
		return new \WP_REST_Response( [ 'deleted' => $deleted ] );
	}

	public function delete_all_rules( \WP_REST_Request $request ): \WP_REST_Response {
		$deleted = RuleRepository::delete_all_rules();
		return new \WP_REST_Response( [ 'deleted' => $deleted ] );
	}

	/**
	 * Enable an asset on the current page or globally by removing the matching active rule(s).
	 * Group item snapshots are never removed by this endpoint.
	 *
	 * POST /rules/enable
	 * Body: { asset_handle, asset_type, device_type, page_url, scope }
	 */
	public function enable_asset( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$handle   = sanitize_text_field( (string) $request->get_param( 'asset_handle' ) );
		$type     = sanitize_text_field( (string) $request->get_param( 'asset_type' ) );
		$device   = sanitize_text_field( (string) ( $request->get_param( 'device_type' ) ?? 'all' ) );
		$page_url = esc_url_raw( (string) $request->get_param( 'page_url' ) );
		$scope    = sanitize_text_field( (string) $request->get_param( 'scope' ) );

		if ( empty( $handle ) || empty( $type ) || empty( $page_url ) ) {
			return new \WP_Error( 'missing_params', 'asset_handle, asset_type, and page_url are required.', [ 'status' => 400 ] );
		}

		if ( ! in_array( $scope, [ 'page', 'global' ], true ) ) {
			return new \WP_Error( 'invalid_scope', 'scope must be "page" or "global".', [ 'status' => 400 ] );
		}

		$deleted = RuleRepository::delete_active_rules_by_scope( $handle, $type, $device, $scope, $page_url );

		return new \WP_REST_Response( [ 'deleted' => $deleted ], 200 );
	}

	public function bulk_assign_group( \WP_REST_Request $request ): \WP_REST_Response {
		$data     = $request->get_json_params();
		$ids      = array_map( 'intval', (array) ( $data['ids']      ?? [] ) );
		$group_id = isset( $data['group_id'] ) && $data['group_id'] !== '' ? (int) $data['group_id'] : null;
		$updated  = 0;
		foreach ( $ids as $id ) {
			$existing_rule = RuleRepository::get_rule( $id );
			if ( null === $existing_rule ) {
				continue;
			}
			// Check if assigning this rule to $group_id would create a duplicate
			// in the target scope. Exclude the rule's own ID so it doesn't match itself.
			$candidate = [
				'url_pattern'      => $existing_rule->url_pattern,
				'match_type'       => $existing_rule->match_type,
				'asset_handle'     => $existing_rule->asset_handle,
				'asset_type'       => $existing_rule->asset_type,
				'device_type'      => $existing_rule->device_type,
				'condition_type'   => $existing_rule->condition_type,
				'condition_value'  => $existing_rule->condition_value,
				'condition_invert' => $existing_rule->condition_invert,
				'group_id'         => $group_id,
			];
			if ( null !== RuleRepository::find_duplicate( $candidate, $id ) ) {
				continue; // Would be a duplicate in the target scope — skip silently.
			}
			if ( RuleRepository::update_rule( $id, [ 'group_id' => $group_id ] ) ) {
				$updated++;
			}
		}
		return new \WP_REST_Response( [ 'updated' => $updated ] );
	}

	public function validate_pattern( \WP_REST_Request $request ): \WP_REST_Response {
		$data       = $request->get_json_params();
		$pattern    = (string) ( $data['pattern']    ?? '' );
		$match_type = (string) ( $data['match_type'] ?? 'regex' );
		$test_url   = (string) ( $data['url']        ?? '' );

		$error = PatternMatcher::validate( $pattern, $match_type );
		if ( $error ) {
			return new \WP_REST_Response( [ 'valid' => false, 'error' => $error ] );
		}

		$matches_current = false;
		if ( $test_url ) {
			$rule = (object) [ 'url_pattern' => $pattern, 'match_type' => $match_type ];
			$matches_current = PatternMatcher::match( $rule, PatternMatcher::normalize_url( $test_url ) );
		}

		return new \WP_REST_Response( [ 'valid' => true, 'matches_current' => $matches_current ] );
	}

	// -------------------------------------------------------------------------
	// Groups endpoints
	// -------------------------------------------------------------------------

	public function get_groups(): \WP_REST_Response {
		return new \WP_REST_Response( RuleRepository::get_all_groups() );
	}

	public function create_group( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $request->get_json_params();
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error( 'missing_name', 'Group name is required.', [ 'status' => 400 ] );
		}
		$id = RuleRepository::create_group( $name, $data['description'] ?? '' );
		if ( is_wp_error( $id ) ) {
			return new \WP_REST_Response( [ 'error' => $id->get_error_message() ], 500 );
		}
		return new \WP_REST_Response( RuleRepository::get_group( $id ), 201 );
	}

	public function update_group( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_json_params();
		RuleRepository::update_group( $id, $data );
		return new \WP_REST_Response( RuleRepository::get_group( $id ) );
	}

	public function delete_group( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		RuleRepository::delete_group( $id );
		return new \WP_REST_Response( [ 'deleted' => true ] );
	}

	/**
	 * Get saved group item snapshots for a group.
	 *
	 * GET /groups/{id}/items
	 */
	public function get_group_items( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id    = (int) $request->get_param( 'id' );
		$group = RuleRepository::get_group( $id );

		if ( ! $group ) {
			return new \WP_Error( 'not_found', 'Group not found.', [ 'status' => 404 ] );
		}

		$items = RuleRepository::get_group_items( $id );
		return new \WP_REST_Response( $items, 200 );
	}

	// -------------------------------------------------------------------------
	// Log endpoints
	// -------------------------------------------------------------------------

	public function get_log( \WP_REST_Request $request ): \WP_REST_Response {
		$result = RuleRepository::get_log(
			(int) ( $request->get_param( 'per_page' ) ?: 50 ),
			(int) ( $request->get_param( 'page' ) ?: 1 ),
			$request->get_param( 'action' ) ?: null
		);
		return new \WP_REST_Response( $result );
	}

	public function clear_log( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! $request->get_param( 'confirmed' ) ) {
			return new \WP_Error( 'confirmation_required', 'Pass confirmed=true to clear the log.', [ 'status' => 400 ] );
		}
		RuleRepository::clear_log();
		return new \WP_REST_Response( [ 'cleared' => true ] );
	}

	// -------------------------------------------------------------------------
	// Kill switch
	// -------------------------------------------------------------------------

	public function get_killswitch(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'active' => (bool) get_option( CDUNLOADER_OPTION_KILL ) ] );
	}

	public function toggle_killswitch( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$data = $request->get_json_params();
		if ( empty( $data['confirmed'] ) ) {
			return new \WP_Error( 'confirmation_required', 'Pass confirmed:true to toggle kill switch.', [ 'status' => 400 ] );
		}

		$active = (bool) get_option( CDUNLOADER_OPTION_KILL );
		$new    = ! $active;

		if ( $new ) {
			update_option( CDUNLOADER_OPTION_KILL, 1 );
		} else {
			delete_option( CDUNLOADER_OPTION_KILL );
		}

		RuleRepository::log_action( 'killswitch', get_current_user_id(), null, [ 'activated' => $new ] );

		return new \WP_REST_Response( [ 'active' => $new ] );
	}

	// -------------------------------------------------------------------------
	// Page assets (used by frontend panel)
	// -------------------------------------------------------------------------

	public function get_page_assets( \WP_REST_Request $request ): \WP_REST_Response {
		// Assets are returned from a REST call with page_url context.
		// The panel sends the current URL; we match rules against it.
		$page_url = (string) $request->get_param( 'page_url' );
		$url      = PatternMatcher::normalize_url( $page_url );
		$all_rules = RuleRepository::get_all_rules();

		// Build a map of "handle|type" => matching_rule for the panel.
		// Keying by both handle AND type ensures a plugin that registers the same
		// handle name for both JS and CSS (e.g. Enlighter) keeps both entries.
		// Skip rules whose group is disabled — those assets should load normally.
		$matched = [];
		foreach ( $all_rules as $rule ) {
			if ( isset( $rule->group_id ) && null !== $rule->group_id && ! (int) ( $rule->group_enabled ?? 1 ) ) {
				continue;
			}
			if ( PatternMatcher::match( $rule, $url ) ) {
				$key             = $rule->asset_handle . '|' . $rule->asset_type;
				$matched[ $key ] = $rule;
			}
		}

		return new \WP_REST_Response( [
			'rules'      => array_values( $matched ),
			'kill_switch'=> (bool) get_option( CDUNLOADER_OPTION_KILL ),
		] );
	}
}
