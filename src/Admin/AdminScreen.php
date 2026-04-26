<?php
declare( strict_types=1 );

namespace CodeUnloader\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CodeUnloader\Core\{RuleRepository, Installer, PatternMatcher};

class AdminScreen {

	/** Stored page hook suffix so load-{hook} and screen options can reference it. */
	private string $page_hook = '';

	public function init(): void {
		add_action( 'admin_menu',    [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init',    [ $this, 'handle_actions' ] );
		add_action( 'admin_notices', [ $this, 'show_notices' ] );
		add_action( 'current_screen',  [ $this, 'maybe_hook_delete_confirmation' ] );

		// Allow WordPress to save our per_page screen option value.
		add_filter( 'set_screen_option_cdunloader_rules_per_page', [ $this, 'save_screen_option' ], 10, 3 );

		// Upgrade check
		Installer::maybe_upgrade();
	}

	/**
	 * Callback for set_screen_option_{option} — return the sanitized value to save it.
	 *
	 * @param mixed  $status  False by default; return a value to save it.
	 * @param string $option  Option name.
	 * @param mixed  $value   Submitted value.
	 * @return int
	 */
	public function save_screen_option( $status, string $option, $value ): int {
		$value = (int) $value;
		// Clamp to allowed values: 10, 20, 50.
		return in_array( $value, [ 10, 20, 50 ], true ) ? $value : 10;
	}

	public function register_menu(): void {
		$this->page_hook = (string) add_options_page(
			__( 'Code Unloader', 'code-unloader' ),
			__( 'Code Unloader', 'code-unloader' ),
			'manage_options',
			'code-unloader',
			[ $this, 'render_page' ]
		);

		// Register the per-page screen option on the plugin's own admin page.
		add_action( 'load-' . $this->page_hook, [ $this, 'register_screen_options' ] );
	}

	/** Register per-page screen option for the Rules tab. */
	public function register_screen_options(): void {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Rules per page', 'code-unloader' ),
				'default' => 10,
				'option'  => 'cdunloader_rules_per_page',
			]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'settings_page_code-unloader' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'cu-admin', CDUNLOADER_URL . 'assets/css/admin.css', [], CDUNLOADER_VERSION );
		wp_enqueue_script( 'cu-bus', CDUNLOADER_URL . 'assets/js/cu-bus.js', [], CDUNLOADER_VERSION, true );
		wp_enqueue_script( 'cu-admin', CDUNLOADER_URL . 'assets/js/admin.js', [ 'cu-bus' ], CDUNLOADER_VERSION, true );
		wp_localize_script( 'cu-admin', 'CDUNLOADER_ADMIN', [
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'api_base'   => esc_url( rest_url( 'code-unloader/v1' ) ),
			'kill_switch'=> (bool) get_option( CDUNLOADER_OPTION_KILL ),
			'groups'     => RuleRepository::get_all_groups(),
		] );
	}

	public function handle_actions(): void {
		// Handle GET actions (export)
		if ( isset( $_GET['cdunloader_action'] ) && current_user_can( 'manage_options' ) ) {
			$action = sanitize_text_field( wp_unslash( $_GET['cdunloader_action'] ) );
			check_admin_referer( 'cdunloader_admin_action' );

			if ( 'export' === $action ) {
				$this->handle_export();
				exit;
			}
		}

		// Handle POST actions (import)
		if ( ! isset( $_POST['cdunloader_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_POST['cdunloader_action'] ) );
		check_admin_referer( 'cdunloader_admin_action' );

		switch ( $action ) {
			case 'import':
				$this->handle_import();
				// PRG pattern: redirect back to settings tab so the admin_notices
				// action fires outside our <div class="wrap">, preventing the notice
				// from appearing inside the plugin header.
				wp_safe_redirect( admin_url( 'options-general.php?page=code-unloader&tab=settings' ) );
				exit;
		}
	}

	private function handle_export(): void {
		// Use get_all_rules() so disabled-group rules are included in the backup.
		// Strip the runtime-only group_enabled JOIN column and stale group_item_id before export.
		$all_rules = RuleRepository::get_all_rules();
		$rules     = array_map( function ( $r ) {
			$arr = (array) $r;
			unset( $arr['group_enabled'] );
			unset( $arr['group_item_id'] );
			return $arr;
		}, $all_rules );

		$groups = RuleRepository::get_all_groups();

		// Collect group items for all groups.
		$group_items = [];
		foreach ( $groups as $group ) {
			$gid   = (int) $group->id;
			$items = RuleRepository::get_group_items( $gid );
			foreach ( $items as $item ) {
				$group_items[] = (array) $item;
			}
		}

		$payload = [
			'version'     => CDUNLOADER_VERSION,
			'exported_at' => gmdate( 'c' ),
			'groups'      => $groups,
			'group_items' => $group_items,
			'rules'       => $rules,
		];

		$filename = 'code-unloader-backup-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	private function handle_import(): void {
		if ( ! check_admin_referer( 'cdunloader_admin_action' ) ) {
			return;
		}
		if ( empty( $_FILES['cdunloader_import_file']['tmp_name'] ) ) {
			return;
		}
		$tmp_name = sanitize_text_field( wp_unslash( $_FILES['cdunloader_import_file']['tmp_name'] ) );

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();
		$json = $wp_filesystem->get_contents( $tmp_name );
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || ( empty( $data['rules'] ) && empty( $data['groups'] ) ) ) {
			set_transient( 'cdunloader_import_notice_' . get_current_user_id(), [ 'type' => 'error', 'msg' => __( 'Invalid import file.', 'code-unloader' ) ], 60 );
			return;
		}

		// ---- Step 1: Import groups, building old_id → new_id map ----
		// Match existing groups by name to avoid duplicates.
		$existing_groups = RuleRepository::get_all_groups();
		$existing_by_name = [];
		foreach ( $existing_groups as $g ) {
			$existing_by_name[ $g->name ] = (int) $g->id;
		}

		$group_id_map      = []; // old export ID => new local ID
		$groups_imported   = 0;
		$groups_reused     = 0;

		foreach ( $data['groups'] ?? [] as $g ) {
			$old_id = (int) ( $g['id'] ?? 0 );
			$name   = sanitize_text_field( $g['name'] ?? '' );
			if ( ! $name || ! $old_id ) {
				continue;
			}

			if ( isset( $existing_by_name[ $name ] ) ) {
				// Group already exists — reuse its ID, do not duplicate.
				$group_id_map[ $old_id ] = $existing_by_name[ $name ];
				$groups_reused++;
			} else {
				$desc   = sanitize_textarea_field( $g['description'] ?? '' );
				$new_id = RuleRepository::create_group( $name, $desc );
				if ( is_wp_error( $new_id ) ) {
					continue;
				}
				// Restore enabled state if the exported group was disabled.
				if ( isset( $g['enabled'] ) && ! (int) $g['enabled'] ) {
					RuleRepository::update_group( $new_id, [ 'enabled' => 0 ] );
				}
				$group_id_map[ $old_id ] = $new_id;
				$existing_by_name[ $name ] = $new_id; // prevent duplicate within same import
				$groups_imported++;
			}
		}

		// ---- Step 2: Import group items, remapping group_id ----
		if ( ! empty( $data['group_items'] ) && is_array( $data['group_items'] ) ) {
			foreach ( $data['group_items'] as $item ) {
				$old_gid = (int) ( $item['group_id'] ?? 0 );
				$new_gid = $group_id_map[ $old_gid ] ?? null;
				if ( null === $new_gid ) {
					continue; // Skip orphaned items whose group wasn't imported.
				}
				$snapshot = [
					'url_pattern'      => $item['url_pattern']      ?? '',
					'match_type'       => $item['match_type']        ?? 'exact',
					'asset_handle'     => $item['asset_handle']      ?? '',
					'asset_type'       => $item['asset_type']        ?? 'js',
					'source_label'     => $item['source_label']      ?? '',
					'device_type'      => $item['device_type']       ?? 'all',
					'condition_type'   => $item['condition_type']    ?: null,
					'condition_value'  => $item['condition_value']   ?: null,
					'condition_invert' => (int) ( $item['condition_invert'] ?? 0 ),
					'label'            => $item['label']             ?: null,
				];
				RuleRepository::create_group_item( $new_gid, $snapshot );
			}
		}

		// ---- Step 3: Import rules, remapping group_id through the map ----
		$imported = 0;
		foreach ( $data['rules'] ?? [] as $rule ) {
			// Remap group_id: if the rule belonged to a group in the export,
			// point it at the correct local group ID. If the group wasn't in
			// the export (legacy file), leave group_id as-is.
			if ( ! empty( $rule['group_id'] ) ) {
				$old_gid = (int) $rule['group_id'];
				if ( isset( $group_id_map[ $old_gid ] ) ) {
					$rule['group_id'] = $group_id_map[ $old_gid ];
				} else {
					// Group not found in this import — detach rule from the missing group.
					$rule['group_id'] = null;
				}
			}

			// Strip stale group_item_id from export — it will be re-linked after import.
			unset( $rule['group_item_id'] );

			$result = RuleRepository::create_rule( $rule );
			if ( ! is_wp_error( $result ) ) {
				$imported++;
			}
		}

		// ---- Build feedback message ----
		$parts = [
			/* translators: %d: number of imported rules */
			sprintf( _n( '%d rule', '%d rules', $imported, 'code-unloader' ), $imported ),
		];
		if ( $groups_imported > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of imported groups */
				_n( '%d group', '%d groups', $groups_imported, 'code-unloader' ),
				$groups_imported
			);
		}
		if ( $groups_reused > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of reused groups */
				_n( '%d existing group matched', '%d existing groups matched', $groups_reused, 'code-unloader' ),
				$groups_reused
			);
		}

		set_transient(
			'cdunloader_import_notice_' . get_current_user_id(),
			[
				'type' => 'updated',
				'msg'  => sprintf(
					/* translators: %s: summary of imported items */
					__( 'Import complete: %s.', 'code-unloader' ),
					implode( ', ', $parts )
				),
			],
			60
		);
	}

	public function show_notices(): void {
		$key    = 'cdunloader_import_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_code-unloader' !== $screen->id ) {
			return;
		}
		$type = ( 'updated' === $notice['type'] ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab navigation, no data modification.
		$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'rules';
		$valid_tabs = [ 'rules', 'groups', 'log', 'settings' ];
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'rules';
		}

		echo '<div class="wrap cu-admin-wrap">';

		// Plugin header with icon, title, and subtitle
		echo '<div class="cu-header">';
		echo '<img src="' . esc_url( CDUNLOADER_URL . 'assets/img/CU_icon_200x200.png' ) . '" alt="Code Unloader" class="cu-header-icon">';
		echo '<div class="cu-header-text">';
		echo '<h1 class="cu-header-title">' . esc_html__( 'Code Unloader', 'code-unloader' ) . ' <span class="cu-header-version">v' . esc_html( CDUNLOADER_VERSION ) . '</span></h1>';
		echo '<p class="cu-header-subtitle">' . wp_kses(
			sprintf(
				/* translators: %s: link to WPservice.pro */
				__( 'by Dalibor Druzinec / %s', 'code-unloader' ),
				'<a href="https://wpservice.pro/" target="_blank" rel="noopener noreferrer">WPservice.pro</a>'
			),
			[
				'a' => [
					'href'   => [],
					'target' => [],
					'rel'    => [],
				],
			]
		) . '</p>';
		echo '</div>';
		echo '</div>';

		// Kill switch alert
		if ( get_option( CDUNLOADER_OPTION_KILL ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( '⚠ Kill switch is ACTIVE — all assets are loading normally. No rules are being applied.', 'code-unloader' ) . '</strong></p></div>';
		}

		// Tabs
		$base = admin_url( 'options-general.php?page=code-unloader' );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( [
			'rules'    => __( 'Rules', 'code-unloader' ),
			'groups'   => __( 'Groups', 'code-unloader' ),
			'log'      => __( 'Audit Log', 'code-unloader' ),
			'settings' => __( 'Settings', 'code-unloader' ),
		] as $key => $label ) {
			$class = ( $tab === $key ) ? 'nav-tab-active' : '';
			echo '<a href="' . esc_url( add_query_arg( 'tab', $key, $base ) ) . '" class="nav-tab ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		echo '<div class="cu-admin-body">';
		echo '<div class="cu-tab-content">';

		switch ( $tab ) {
			case 'rules':    $this->render_rules_tab();    break;
			case 'groups':   $this->render_groups_tab();   break;
			case 'log':      $this->render_log_tab();      break;
			case 'settings': $this->render_settings_tab(); break;
		}

		echo '</div>'; // .cu-tab-content

		$this->render_sidebar();

		echo '</div></div>'; // .cu-admin-body / .cu-admin-wrap
	}

	private function render_sidebar(): void {
		?>
		<aside class="cu-admin-sidebar">
			<div class="cu-sidebar-box">
				<h3 class="cu-sidebar-heading"><?php esc_html_e( 'Ratings &amp; Reviews', 'code-unloader' ); ?></h3>
				<p class="cu-sidebar-text"><?php esc_html_e( 'If you like Code Unloader please consider leaving a ★★★★★ rating.', 'code-unloader' ); ?></p>
				<a href="https://wordpress.org/support/plugin/code-unloader/reviews/#new-post" target="_blank" rel="noopener noreferrer" class="button button-primary cu-sidebar-btn">
					<?php esc_html_e( 'Leave a rating', 'code-unloader' ); ?>
				</a>
			</div>
			<div class="cu-sidebar-box">
				<h3 class="cu-sidebar-heading"><?php esc_html_e( 'Having Issues?', 'code-unloader' ); ?></h3>
				<p class="cu-sidebar-text"><?php esc_html_e( "I'm always happy to help out! Support is handled exclusively through WordPress.org.", 'code-unloader' ); ?></p>
				<a href="https://wordpress.org/support/plugin/code-unloader/" target="_blank" rel="noopener noreferrer" class="button button-primary cu-sidebar-btn">
					<?php esc_html_e( 'Get Support', 'code-unloader' ); ?>
				</a>
			</div>
			<div class="cu-sidebar-box cu-sidebar-box--cta">
				<h3 class="cu-sidebar-heading"><?php esc_html_e( 'Measure Your Gains', 'code-unloader' ); ?></h3>
				<p class="cu-sidebar-text"><?php esc_html_e( 'Check by how much Code Unloader improved your pages with our Speed Analyzer plugin.', 'code-unloader' ); ?></p>
				<a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="cu-sidebar-sa-link">
					<img src="<?php echo esc_url( CDUNLOADER_URL . 'assets/img/iconSA-256x256.png' ); ?>" alt="<?php esc_attr_e( 'Speed Analyzer', 'code-unloader' ); ?>" class="cu-sidebar-sa-icon">
				</a>
				<a href="https://wordpress.org/plugins/speed-analyzer/" target="_blank" rel="noopener noreferrer" class="button button-primary cu-sidebar-btn">
					<?php esc_html_e( 'Get Speed Analyzer', 'code-unloader' ); ?>
				</a>
			</div>
		</aside>
		<?php
	}

	// -------------------------------------------------------------------------
	// Rules Tab
	// -------------------------------------------------------------------------
	private function render_rules_tab(): void {
		// Summary bar — count all active rules in cu_rules.
		global $wpdb;
		$count = wp_cache_get( 'cdunloader_rules_count' );
		if ( false === $count ) {
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				"SELECT COUNT(*) FROM (
				    SELECT 1
				    FROM {$wpdb->prefix}cu_rules r
				    GROUP BY r.url_pattern, r.match_type, r.asset_handle, r.asset_type,
				             r.device_type, r.condition_type, r.condition_value, r.condition_invert
				) AS unique_rules"
			);
			wp_cache_set( 'cdunloader_rules_count', $count );
		}

		// Stale rule detection — check after wp_enqueue_scripts has fired
		// We do this here because the admin screen loads after enqueue.
		$stale_ids = \CodeUnloader\Core\RuleRepository::get_stale_rule_ids();
		if ( ! empty( $stale_ids ) ) {
			$count_stale = count( $stale_ids );
			echo '<div class="notice notice-warning cu-stale-notice" data-stale-ids="' . esc_attr( (string) wp_json_encode( $stale_ids ) ) . '">';
			echo '<p><strong>' . sprintf(
				/* translators: %d: number of stale rules */
				esc_html( _n(
					'%d stale rule detected — the asset handle it references is no longer registered in WordPress.',
					'%d stale rules detected — these asset handles are no longer registered in WordPress.',
					$count_stale,
					'code-unloader'
				) ),
				(int) $count_stale
			) . '</strong> ';
			echo '<button type="button" class="button button-small" id="cu-delete-stale-btn">'
				. esc_html__( 'Delete stale rules', 'code-unloader' ) . '</button></p>';
			echo '</div>';
		}

		echo '<div class="cu-summary-bar">';
		/* translators: %d: number of rules */
		echo '<span id="cu-total-rules-count">' . sprintf( esc_html__( '%d total rules', 'code-unloader' ), (int) $count ) . '</span>';
		echo '<span> &bull; </span>';
		$kill = get_option( CDUNLOADER_OPTION_KILL ) ? '<span class="cu-kill-pill">' . esc_html__( 'Kill switch ON', 'code-unloader' ) . '</span>' : '<span class="cu-active-pill">' . esc_html__( 'Rules active', 'code-unloader' ) . '</span>';
		echo wp_kses_post( $kill );
		if ( $count > 0 ) {
			echo ' <button type="button" id="cu-delete-all-rules-btn" class="button button-small cu-btn-danger" style="margin-left:12px;">'
				. esc_html__( 'Delete All Rules', 'code-unloader' ) . '</button>';
		}
		echo '</div>';

		if ( 0 === (int) $count ) {
			echo '<p class="cu-rules-empty-hint">'
				. esc_html__( 'Navigate to any page or post on your site, click the Assets panel in the admin bar, and start unloading unnecessary code.', 'code-unloader' )
				. '</p>';
		}

		// List table
		$table = new RulesListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="code-unloader">';
		echo '<input type="hidden" name="tab"  value="rules">';

		// Group filter dropdown
		$all_groups     = RuleRepository::get_all_groups();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_group  = isset( $_GET['group_id'] ) ? (int) $_GET['group_id'] : 0;
		if ( ! empty( $all_groups ) ) {
			echo '<div class="alignleft actions" style="margin-bottom:8px;">';
			echo '<select name="group_id" id="cu-filter-group">';
			echo '<option value="0">' . esc_html__( 'All Groups', 'code-unloader' ) . '</option>';
			echo '<option value="-1"' . selected( $current_group, -1, false ) . '>' . esc_html__( 'Ungrouped', 'code-unloader' ) . '</option>';
			foreach ( $all_groups as $g ) {
				echo '<option value="' . esc_attr( $g->id ) . '"' . selected( $current_group, (int) $g->id, false ) . '>' . esc_html( $g->name ) . '</option>';
			}
			echo '</select> ';
			echo '<input type="submit" class="button" value="' . esc_attr__( 'Filter by Group', 'code-unloader' ) . '">';
			echo '</div>';
		}

		$table->search_box( __( 'Search rules by handle', 'code-unloader' ), 'cdunloader_search' );
		$table->display();
		echo '</form>';
	}

	// -------------------------------------------------------------------------
	// Groups Tab
	// -------------------------------------------------------------------------
	private function render_groups_tab(): void {
		$groups = RuleRepository::get_all_groups();

		if ( ! empty( $groups ) ) {
			echo '<div class="cu-groups-toolbar" style="margin-bottom:12px;">';
			echo '<button type="button" id="cu-delete-all-groups-btn" class="button button-small cu-btn-danger">'
				. esc_html__( 'Delete All Groups', 'code-unloader' ) . '</button>';
			echo '</div>';
		}

		echo '<div class="cu-groups-grid" id="cu-groups-grid">';

		foreach ( $groups as $group ) {
			$disabled_class = $group->enabled ? '' : ' cu-group-card-disabled';
			echo '<div class="cu-group-card' . esc_attr( $disabled_class ) . '" data-group-id="' . esc_attr( $group->id ) . '">';
			echo '<div class="cu-group-card-header">';
			echo '<strong>' . esc_html( $group->name ) . '</strong>';
			if ( ! empty( $group->created_at ) ) {
				$created_date = get_date_from_gmt( $group->created_at, 'F j Y' );
				// Skip the badge when the group name already encodes the same date
				// (e.g. auto-created "Previously active rules April 23 2026"
				// archive groups pushed by AI Assets Scanner) — avoids duplication.
				if ( false === strpos( $group->name, $created_date ) ) {
					/* translators: %s: human-readable creation date, e.g. "April 23 2026" */
					$created_label = sprintf( __( 'Created, %s', 'code-unloader' ), $created_date );
					echo '<span class="cu-group-created" style="font-size:11px;opacity:0.6;margin-left:8px;">' . esc_html( $created_label ) . '</span>';
				}
			}
			/* translators: %d: number of rules in a group */
			echo '<span class="cu-group-rule-count">' . sprintf( esc_html__( '%d rules', 'code-unloader' ), (int) $group->rule_count ) . '</span>';
			echo '</div>';
			if ( $group->description ) {
				echo '<p class="cu-group-desc">' . esc_html( $group->description ) . '</p>';
			}
			echo '<div class="cu-group-card-actions">';
			echo '<button class="button cu-group-view-rules-btn" data-id="' . esc_attr( $group->id ) . '" data-name="' . esc_attr( $group->name ) . '">' . esc_html__( 'View Rules', 'code-unloader' ) . '</button> ';
			echo '<button class="button cu-group-toggle-btn" data-id="' . esc_attr( $group->id ) . '" data-enabled="' . esc_attr( $group->enabled ) . '">'
				. ( $group->enabled ? esc_html__( 'Disable Group', 'code-unloader' ) : esc_html__( 'Enable Group', 'code-unloader' ) )
				. '</button> ';
			echo '<button class="button button-link-delete cu-group-delete-btn" data-id="' . esc_attr( $group->id ) . '">' . esc_html__( 'Delete Group', 'code-unloader' ) . '</button>';
			echo '</div></div>';
		}

		echo '</div>';

		// Create group form
		echo '<hr>';
		echo '<h3>' . esc_html__( 'Create New Group', 'code-unloader' ) . '</h3>';
		echo '<div id="cu-create-group-form">';
		echo '<input type="text" id="cu-new-group-name" placeholder="' . esc_attr__( 'Group name', 'code-unloader' ) . '" class="regular-text"> ';
		echo '<input type="text" id="cu-new-group-desc" placeholder="' . esc_attr__( 'Description (optional)', 'code-unloader' ) . '" class="regular-text"> ';
		echo '<button class="button button-primary" id="cu-create-group-btn">' . esc_html__( 'Create Group', 'code-unloader' ) . '</button>';
		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Log Tab
	// -------------------------------------------------------------------------
	private function render_log_tab(): void {
		$table = new LogListTable();
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="code-unloader">';
		echo '<input type="hidden" name="tab"  value="log">';
		$table->display();
		echo '</form>';

		echo '<hr>';
		echo '<button class="button button-link-delete" id="cu-clear-log-btn">' . esc_html__( 'Clear Log', 'code-unloader' ) . '</button>';
	}

	// -------------------------------------------------------------------------
	// Settings Tab
	// -------------------------------------------------------------------------
	private function render_settings_tab(): void {
		$kill = (bool) get_option( CDUNLOADER_OPTION_KILL );

		echo '<table class="form-table"><tbody>';

		// Kill switch
		echo '<tr><th scope="row">' . esc_html__( 'Global Kill Switch', 'code-unloader' ) . '</th><td>';
		if ( $kill ) {
			echo '<p><span class="cu-kill-pill">' . esc_html__( '⚠ Kill switch is ACTIVE', 'code-unloader' ) . '</span></p>';
			echo '<button class="button button-secondary" id="cu-killswitch-btn" data-active="1">' . esc_html__( 'Deactivate Kill Switch', 'code-unloader' ) . '</button>';
		} else {
			echo '<button class="button button-secondary cu-btn-danger" id="cu-killswitch-btn" data-active="0">' . esc_html__( 'Activate Kill Switch', 'code-unloader' ) . '</button>';
		}
		echo '<p class="description">' . esc_html__( 'When active, all dequeue rules are bypassed sitewide. Rules are not deleted.', 'code-unloader' ) . '</p>';
		echo '</td></tr>';

		// Export
		echo '<tr><th scope="row">' . esc_html__( 'Export Rules', 'code-unloader' ) . '</th><td>';
		$export_url = wp_nonce_url(
			add_query_arg( [ 'cdunloader_action' => 'export' ], admin_url( 'options-general.php?page=code-unloader&tab=settings' ) ),
			'cdunloader_admin_action'
		);
		echo '<a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Download JSON Export', 'code-unloader' ) . '</a>';
		echo '<p class="description">' . esc_html__( 'Exports all rules and groups as a JSON file. Log entries are not exported.', 'code-unloader' ) . '</p>';
		echo '</td></tr>';

		// Import
		echo '<tr><th scope="row">' . esc_html__( 'Import Rules', 'code-unloader' ) . '</th><td>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'cdunloader_admin_action' );
		echo '<input type="hidden" name="cdunloader_action" value="import">';
		echo '<input type="file" name="cdunloader_import_file" accept=".json"> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Import JSON', 'code-unloader' ) . '</button>';
		echo '</form>';
		echo '</td></tr>';

		echo '</tbody></table>';
	}


	// -------------------------------------------------------------------------
	// Plugins-page delete confirmation
	// -------------------------------------------------------------------------
	public function inject_delete_confirmation(): void {
		$plugin_file = plugin_basename( CDUNLOADER_FILE );
		?>
<div id="cu-delete-modal" style="display:none;position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.7);align-items:center;justify-content:center;">
	<div style="background:#fff;border-radius:10px;padding:32px;max-width:480px;width:90%;box-shadow:0 8px 40px rgba(0,0,0,.4);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
		<h2 style="margin:0 0 12px;font-size:18px;color:#b00020;">⚠️ Delete Code Unloader?</h2>
		<p style="margin:0 0 12px;color:#333;line-height:1.6;">Deleting this plugin will <strong>permanently remove all dequeue rules, groups, and audit logs</strong>.</p>
		<p style="margin:0 0 24px;color:#333;line-height:1.6;">All assets that were being unloaded will <strong>immediately start loading again</strong> on every page — your site will be returned to its state before Code Unloader was installed.</p>
		<div style="display:flex;gap:12px;justify-content:flex-end;">
			<button id="cu-delete-cancel" style="background:#f0f0f0;color:#333;border:1px solid #ccc;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;">Cancel</button>
			<a id="cu-delete-confirm" href="#" style="background:#b00020;color:#fff;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;text-decoration:none;display:inline-block;">Delete Plugin &amp; All Data</a>
		</div>
	</div>
</div>
		<?php
		wp_enqueue_script( 'cu-delete-confirm', CDUNLOADER_URL . 'assets/js/delete-confirm.js', [], CDUNLOADER_VERSION, true );
		wp_localize_script( 'cu-delete-confirm', 'CDUNLOADER_DELETE_DATA', [ 'plugin_file' => $plugin_file ] );
	}

	public function maybe_hook_delete_confirmation(): void {
		$screen = get_current_screen();
		if ( $screen && 'plugins' === $screen->id ) {
			add_action( 'admin_footer', [ $this, 'inject_delete_confirmation' ] );
		}
	}
}