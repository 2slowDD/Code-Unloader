<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CodeUnloader\Core\PatternMatcher;

class ConditionEvaluator {

	/**
	 * Evaluate whether a rule's condition permits dequeuing.
	 * Returns true if the dequeue should proceed.
	 *
	 * @param string|null $condition_type
	 * @param string|null $condition_value
	 * @param bool        $invert  If true, rule fires UNLESS condition is met
	 */
	public static function evaluate(
		?string $condition_type,
		?string $condition_value,
		bool    $invert
	): bool {
		if ( null === $condition_type || '' === $condition_type ) {
			return true; // unconditional — always dequeue
		}

		$result = self::check( $condition_type, $condition_value );
		return $invert ? ! $result : $result;
	}

	private static function check( string $type, ?string $value ): bool {
		// Allow custom conditions via filter
		$custom = apply_filters( 'code_unloader_conditions', null, $type, $value );
		if ( null !== $custom ) {
			return (bool) $custom;
		}

		return match ( true ) {
			$type === 'is_user_logged_in'    => is_user_logged_in(),
			$type === 'is_woocommerce_page'  => self::is_woocommerce_page(),
			str_starts_with( $type, 'has_shortcode:' )    => self::has_shortcode( substr( $type, 14 ) ),
			str_starts_with( $type, 'is_post_type:' )     => self::is_post_type( substr( $type, 13 ) ),
			str_starts_with( $type, 'is_page_template:' ) => self::is_page_template( substr( $type, 17 ) ),
			str_starts_with( $type, 'exact_url:' )        => self::is_exact_url( substr( $type, 10 ) ),
			default => false,
		};
	}

	private static function is_woocommerce_page(): bool {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return false;
		}
		return is_woocommerce() || is_cart() || is_checkout() || is_account_page();
	}

	private static function has_shortcode( string $shortcode ): bool {
		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		return has_shortcode( $post->post_content, $shortcode );
	}

	private static function is_post_type( string $type ): bool {
		return get_post_type() === $type;
	}

	private static function is_page_template( string $template ): bool {
		return (string) get_page_template_slug() === $template;
	}

	/**
	 * Check whether the current page URL matches a stored exact URL.
	 * Used by the "Everywhere except here" scope (condition_invert = true).
	 */
	private static function is_exact_url( string $stored_url ): bool {
		if ( empty( $stored_url ) ) {
			return false;
		}
		$current = PatternMatcher::normalize_url(
			home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) )
		);
		return $current === PatternMatcher::normalize_url( $stored_url );
	}

	/**
	 * Return all built-in condition keys for the UI.
	 */
	public static function get_builtin_conditions(): array {
		return [
			'is_user_logged_in'    => __( 'User is logged in', 'code-unloader' ),
			'is_woocommerce_page'  => __( 'WooCommerce page (requires WooCommerce)', 'code-unloader' ),
			'has_shortcode:{name}' => __( 'Post contains shortcode (e.g. has_shortcode:contact-form-7)', 'code-unloader' ),
			'is_post_type:{type}'  => __( 'Post type matches (e.g. is_post_type:product)', 'code-unloader' ),
			'is_page_template:{file}' => __( 'Page template matches', 'code-unloader' ),
		];
	}
}
