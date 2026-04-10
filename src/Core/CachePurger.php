<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CachePurger
 *
 * After a rule is created or deleted, the pages that rule matches should have
 * their cache cleared so the change takes effect without a manual flush.
 * Supports WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache,
 * WP Fastest Cache, Autoptimize, and a generic filter hook for others.
 */
class CachePurger {

	/**
	 * Purge cache for a specific URL (exact match rules) or sitewide
	 * (wildcard/regex rules where we can't know all affected URLs cheaply).
	 *
	 * @param string $url_pattern  The stored url_pattern value from the rule.
	 * @param string $match_type   'exact', 'wildcard', or 'regex'.
	 */
	public static function purge_for_rule( string $url_pattern, string $match_type ): void {
		if ( 'exact' === $match_type ) {
			self::purge_url( $url_pattern );
		} else {
			// Wildcard/regex can affect many URLs — purge everything.
			self::purge_all();
		}

		do_action( 'code_unloader_after_cache_purge', $url_pattern, $match_type );
	}

	// -------------------------------------------------------------------------
	// Single-URL purge
	// -------------------------------------------------------------------------

	public static function purge_url( string $url ): void {
		// WP Rocket
		if ( function_exists( 'rocket_clean_post' ) ) {
			$post_id = url_to_postid( $url );
			if ( $post_id ) {
				rocket_clean_post( $post_id );
			}
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( [ $url ] );
		}

		// LiteSpeed Cache
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin hook.
		}

		// WP Super Cache
		if ( function_exists( 'wpsc_delete_url_cache' ) ) {
			wpsc_delete_url_cache( $url );
		}

		// WP Fastest Cache
		if ( class_exists( '\WpFastestCache' ) && method_exists( '\WpFastestCache', 'deleteCache' ) ) {
			( new \WpFastestCache() )->deleteCache( true );
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
		}

		// Autoptimize
		if ( class_exists( '\autoptimizeCache' ) ) {
			\autoptimizeCache::clearall();
		}

		// Breeze (Cloudways)
		if ( class_exists( '\Breeze_Admin' ) ) {
			do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin hook.
		}

		// SG Optimizer (SiteGround)
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}

		// Nginx Helper
		if ( class_exists( '\Nginx_Helper' ) ) {
			do_action( 'rt_nginx_helper_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin hook.
		}

		// Cloudflare (official plugin)
		// cloudflare_purge_by_url is a filter used to extend Cloudflare's own post-save
		// purge list — it cannot trigger an on-demand purge from outside the plugin.
		// Fall back to a full purge via the cloudflare_purge_everything_actions filter.
		if ( class_exists( '\CF\WordPress\Hooks' ) ) {
			self::purge_cloudflare_all();
		}

		do_action( 'code_unloader_purge_url', $url );
	}

	// -------------------------------------------------------------------------
	// Full-site purge
	// -------------------------------------------------------------------------

	public static function purge_all(): void {
		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin hook.
		}

		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		// WP Fastest Cache
		if ( class_exists( '\WpFastestCache' ) && method_exists( '\WpFastestCache', 'deleteCache' ) ) {
			( new \WpFastestCache() )->deleteCache( true );
		}

		// Autoptimize
		if ( class_exists( '\autoptimizeCache' ) ) {
			\autoptimizeCache::clearall();
		}

		// Breeze
		if ( class_exists( '\Breeze_Admin' ) ) {
			do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin hook.
		}

		// SG Optimizer
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}

		// Nginx Helper
		if ( class_exists( '\Nginx_Helper' ) ) {
			global $nginx_helper; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Third-party plugin global variable.
			if ( $nginx_helper && method_exists( $nginx_helper, 'purge_entire_cache' ) ) {
				$nginx_helper->purge_entire_cache();
			}
		}

		// Cloudflare (official plugin)
		// cloudflare_purge_everything is not a real action — the correct way to trigger
		// a full Cloudflare purge programmatically is to add a custom action name to the
		// cloudflare_purge_everything_actions filter, then fire that action.
		if ( class_exists( '\CF\WordPress\Hooks' ) ) {
			self::purge_cloudflare_all();
		}

		do_action( 'code_unloader_purge_all' );
	}

	// -------------------------------------------------------------------------
	// Cloudflare full-purge helper
	// -------------------------------------------------------------------------

	/**
	 * Trigger a full Cloudflare cache purge using the correct filter-based mechanism.
	 *
	 * The Cloudflare plugin exposes cloudflare_purge_everything_actions — a filter
	 * on the list of WP action names that trigger a full purge. We add our own
	 * prefixed action name to that list, fire it once, then immediately remove the
	 * filter so it only affects this single call.
	 */
	private static function purge_cloudflare_all(): void {
		$cb = function ( array $actions ): array {
			$actions[] = 'cdunloader_purge_cloudflare';
			return $actions;
		};

		add_filter( 'cloudflare_purge_everything_actions', $cb ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party plugin filter.
		do_action( 'cdunloader_purge_cloudflare' );
		remove_filter( 'cloudflare_purge_everything_actions', $cb );
	}
}
