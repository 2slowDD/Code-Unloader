<?php
declare( strict_types=1 );

namespace CodeUnloader\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CodeUnloader\Core\RuleRepository;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class RulesListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'rule',
			'plural'   => 'rules',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'cb'          => '<input type="checkbox">',
			'url_pattern' => __( 'URL / Pattern', 'code-unloader' ),
			'match_type'  => __( 'Match', 'code-unloader' ),
			'asset_handle'=> __( 'Handle', 'code-unloader' ),
			'group_name'  => __( 'Group', 'code-unloader' ),
			'asset_type'  => __( 'Type', 'code-unloader' ),
			'device_type' => __( 'Device', 'code-unloader' ),
			'condition'   => __( 'Condition', 'code-unloader' ),
			'source_label'=> __( 'Source', 'code-unloader' ),
			'created_at'  => __( 'Date', 'code-unloader' ),
			'actions'     => __( 'Actions', 'code-unloader' ),
		];
	}

	public function get_sortable_columns(): array {
		return [
			'url_pattern'  => [ 'url_pattern', false ],
			'asset_handle' => [ 'asset_handle', false ],
			'created_at'   => [ 'created_at', true ],
		];
	}

	protected function get_bulk_actions(): array {
		return [
			'bulk-delete'        => __( 'Delete', 'code-unloader' ),
			'bulk-assign-group'  => __( 'Add to Group…', 'code-unloader' ),
		];
	}

	public function column_cb( $item ): string {
		return '<input type="checkbox" name="rule_ids[]" value="' . esc_attr( $item->id ) . '">';
	}

	public function column_url_pattern( $item ): string {
		$url = esc_html( $item->url_pattern );
		return '<code title="' . esc_attr( $item->url_pattern ) . '">' . ( strlen( $item->url_pattern ) > 60 ? esc_html( substr( $item->url_pattern, 0, 57 ) ) . '…' : $url ) . '</code>';
	}

	public function column_match_type( $item ): string {
		$classes = [ 'exact' => 'cu-pill-grey', 'wildcard' => 'cu-pill-blue', 'regex' => 'cu-pill-purple' ];
		$class   = $classes[ $item->match_type ] ?? 'cu-pill-grey';
		return '<span class="cu-pill ' . esc_attr( $class ) . '">' . esc_html( $item->match_type ) . '</span>';
	}

	public function column_asset_handle( $item ): string {
		$label = $item->label
			? '<br><em class="cu-rule-label">' . esc_html( $item->label ) . '</em>'
			: '';
		return esc_html( $item->asset_handle ) . $label;
	}

	public function column_group_name( $item ): string {
		// All Groups view returns group_names (unit-separator-delimited, \x1f);
		// specific group view returns group_name (single string).
		$names_string = $item->group_names ?? $item->group_name ?? '';
		if ( empty( $names_string ) ) {
			return '—';
		}
		// Split on unit separator (0x1f) — immune to commas in group names.
		$names = array_filter( array_map(
			static function ( string $name ): string { return trim( $name ); },
			explode( "\x1f", $names_string )
		) );
		if ( empty( $names ) ) {
			return '—';
		}
		$pills = array_map(
			static function ( string $name ): string {
				return '<span class="cu-pill cu-pill-teal">' . esc_html( $name ) . '</span>';
			},
			$names
		);
		return implode( ' ', $pills );
	}

	public function column_asset_type( $item ): string {
		$class = 'js' === $item->asset_type ? 'cu-badge-type-js' : 'cu-badge-type-css';
		return '<span class="cu-badge ' . esc_attr( $class ) . '">' . esc_html( strtoupper( $item->asset_type ) ) . '</span>';
	}

	public function column_device_type( $item ): string {
		return match ( $item->device_type ) {
			'desktop' => '🖥 Desktop',
			'mobile'  => '📱 Mobile',
			default   => '🌐 All',
		};
	}

	public function column_condition( $item ): string {
		if ( ! $item->condition_type ) {
			return '—';
		}
		$invert = $item->condition_invert ? ' <em>(inverted)</em>' : '';
		return esc_html( $item->condition_type ) . $invert;
	}

	public function column_source_label( $item ): string {
		return esc_html( $item->source_label ?: '—' );
	}

	public function column_created_at( $item ): string {
		return esc_html( $item->created_at );
	}

	public function column_actions( $item ): string {
		return '<button class="button button-small cu-delete-rule-btn" data-id="' . esc_attr( $item->id ) . '">'
			. esc_html__( 'Delete', 'code-unloader' ) . '</button>';
	}

	public function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '—';
	}

	public function prepare_items(): void {
		// Read per-page from the user's saved screen option (set via the Screen Options panel).
		// Falls back to 10 if not set. Allowed values: 10, 20, 50.
		$saved    = (int) get_user_option( 'cdunloader_rules_per_page' );
		$per_page = in_array( $saved, [ 10, 20, 50 ], true ) ? $saved : 10;
		$page     = $this->get_pagenum();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- WP_List_Table filtering, no data modification.
		$filters = [
			'search'     => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'match_type' => isset( $_REQUEST['match_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['match_type'] ) ) : '',
			'asset_type' => isset( $_REQUEST['asset_type'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['asset_type'] ) ) : '',
			'group_id'   => isset( $_REQUEST['group_id'] ) ? (int) $_REQUEST['group_id'] : 0,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = RuleRepository::get_rules_filtered( array_filter( $filters ), $per_page, $page );

		$this->items = $result['rows'];
		$this->set_pagination_args( [
			'total_items' => $result['total'],
			'per_page'    => $per_page,
		] );
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];
	}
}
