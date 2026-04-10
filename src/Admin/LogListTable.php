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

class LogListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'log_entry',
			'plural'   => 'log_entries',
			'ajax'     => false,
		] );
	}

	public function get_columns(): array {
		return [
			'created_at' => __( 'Date', 'code-unloader' ),
			'user_login' => __( 'User', 'code-unloader' ),
			'action'     => __( 'Action', 'code-unloader' ),
			'rule_id'    => __( 'Rule ID', 'code-unloader' ),
			'snapshot'   => __( 'Details', 'code-unloader' ),
		];
	}

	public function column_created_at( $item ): string {
		return esc_html( $item->created_at );
	}

	public function column_user_login( $item ): string {
		return esc_html( $item->user_login ?: '(deleted user)' );
	}

	public function column_action( $item ): string {
		$labels = [
			'create'        => '<span class="cu-pill cu-pill-blue">create</span>',
			'delete'        => '<span class="cu-pill cu-pill-red">delete</span>',
			'group_toggle'  => '<span class="cu-pill cu-pill-grey">group toggle</span>',
			'killswitch'    => '<span class="cu-pill cu-pill-purple">kill switch</span>',
		];
		return $labels[ $item->action ] ?? esc_html( $item->action );
	}

	public function column_rule_id( $item ): string {
		return $item->rule_id ? '#' . esc_html( $item->rule_id ) : '—';
	}

	public function column_snapshot( $item ): string {
		if ( ! $item->snapshot ) return '—';
		$id = 'cu-snap-' . esc_attr( $item->id );
		return '<details><summary>View</summary><pre class="cu-snapshot">' . esc_html( $item->snapshot ) . '</pre></details>';
	}

	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '—' ) );
	}

	public function prepare_items(): void {
		$per_page = 50;
		$page     = $this->get_pagenum();
		$result   = RuleRepository::get_log( $per_page, $page );

		$this->items = $result['rows'];
		$this->set_pagination_args( [
			'total_items' => $result['total'],
			'per_page'    => $per_page,
		] );
		$this->_column_headers = [ $this->get_columns(), [], [] ];
	}
}
