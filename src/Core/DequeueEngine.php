<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DequeueEngine {

	public function init(): void {
		// Run at priority 100 so other plugins can enqueue at default (10)
		add_action( 'wp_enqueue_scripts', [ $this, 'process_rules' ], 100 );
	}

	public function process_rules(): void {
		// Bypass via ?nowpcu — disables all rules for this request.
		// Follows the same convention as ?nowprocket (WP Rocket) and ?ao_noptimize=1 (Autoptimize).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bypass parameter, no data modification.
		if ( isset( $_GET['nowpcu'] ) ) {
			return;
		}

		// Kill switch check — one option read, no DB query
		if ( get_option( CDUNLOADER_OPTION_KILL ) ) {
			return;
		}

		$current_url = $this->get_current_url();
		$all_rules   = RuleRepository::get_all_rules(); // single DB query, cached

		if ( empty( $all_rules ) ) {
			return;
		}

		$is_mobile = DeviceDetector::is_mobile();

		foreach ( $all_rules as $rule ) {
			// Skip inline rules — handled by InlineBlocker
			if ( in_array( $rule->asset_type, [ 'inline_js', 'inline_css' ], true ) ) {
				continue;
			}

			// 1. Group enabled check — cast to int because wpdb returns all columns as strings.
			if ( isset( $rule->group_id ) && null !== $rule->group_id && isset( $rule->group_enabled ) && ! (int) $rule->group_enabled ) {
				continue;
			}

			// 2. URL match
			if ( ! PatternMatcher::match( $rule, $current_url ) ) {
				continue;
			}

			// 3. Device type
			if ( ! DeviceDetector::matches_device( $rule->device_type ) ) {
				continue;
			}

			// 4. Condition
			if ( ! ConditionEvaluator::evaluate( $rule->condition_type, $rule->condition_value, (bool) $rule->condition_invert ) ) {
				continue;
			}

			// All checks passed — dequeue only (do NOT deregister).
			// Deregistering removes the handle from $wp_scripts->registered,
			// which breaks the frontend panel (can't show disabled assets) and
			// incorrectly triggers the stale-rule detector in the admin screen.
			// wp_dequeue_* alone is sufficient to prevent the asset from being output.
			if ( 'js' === $rule->asset_type ) {
				wp_dequeue_script( $rule->asset_handle );
			} else {
				wp_dequeue_style( $rule->asset_handle );
			}
		}
	}

	private function get_current_url(): string {
		$url = home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) );
		// Remove ?wpcu before normalization
		$url = remove_query_arg( 'wpcu', $url );
		return PatternMatcher::normalize_url( $url );
	}
}
