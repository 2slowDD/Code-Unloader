<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AssetDetector {

	/**
	 * Build a full list of enqueued assets for the current page.
	 * Must be called after wp_enqueue_scripts has fired.
	 */
	public static function get_enqueued_assets(): array {
		global $wp_scripts, $wp_styles;

		$assets = [];

		self::append_dependency_tree_assets( $assets, $wp_scripts, 'js' );
		self::append_dependency_tree_assets( $assets, $wp_styles, 'css' );

		// Sort by source label then handle
		usort( $assets, fn( $a, $b ) => strcmp( $a['source_label'], $b['source_label'] ) ?: strcmp( $a['handle'], $b['handle'] ) );

		return $assets;
	}

	private static function append_dependency_tree_assets( array &$assets, \WP_Dependencies $deps, string $type ): void {
		$seen        = [];
		$required_by = self::build_required_by_map( $deps );

		foreach ( $deps->queue as $handle ) {
			$seen[ $handle ] = true;
			$assets[]        = self::build_asset( $handle, $type, $deps, $required_by[ $handle ] ?? [] );
		}

		foreach ( $required_by as $handle => $parents ) {
			if ( isset( $seen[ $handle ] ) || ! isset( $deps->registered[ $handle ] ) ) {
				continue;
			}
			$seen[ $handle ] = true;
			$assets[]        = self::build_asset( $handle, $type, $deps, $parents );
		}
	}

	private static function build_required_by_map( \WP_Dependencies $deps ): array {
		$required_by = [];
		$visited     = [];

		foreach ( $deps->queue as $handle ) {
			self::walk_dependencies( $handle, $deps, $required_by, $visited );
		}

		foreach ( $required_by as $handle => $parents ) {
			$required_by[ $handle ] = array_values( array_keys( $parents ) );
		}

		return $required_by;
	}

	private static function walk_dependencies( string $handle, \WP_Dependencies $deps, array &$required_by, array &$visited ): void {
		if ( isset( $visited[ $handle ] ) ) {
			return;
		}

		$visited[ $handle ] = true;
		$obj               = $deps->registered[ $handle ] ?? null;
		$children          = $obj && is_array( $obj->deps ?? null ) ? $obj->deps : [];

		foreach ( $children as $child ) {
			if ( ! is_string( $child ) || '' === $child ) {
				continue;
			}

			$required_by[ $child ][ $handle ] = true;
			self::walk_dependencies( $child, $deps, $required_by, $visited );
		}
	}

	private static function build_asset( string $handle, string $type, \WP_Dependencies $deps, array $required_by ): array {
		$obj = $deps->registered[ $handle ] ?? null;
		$src = $obj ? (string) $obj->src : '';

		return [
			'handle'       => $handle,
			'type'         => $type,
			'src'          => $src,
			'source_label' => self::detect_source( $src ),
			'deps'         => $obj ? $obj->deps : [],
			'required_by'  => $required_by,
		];
	}

	public static function detect_source( string $src ): string {
		if ( '' === $src ) {
			return 'Unknown / External';
		}

		// Cache the map in a transient
		$map = get_transient( 'code_unloader_source_map' );
		if ( ! is_array( $map ) ) {
			$map = self::build_source_map();
			set_transient( 'code_unloader_source_map', $map, DAY_IN_SECONDS );
		}

		foreach ( $map as $prefix => $label ) {
			if ( str_starts_with( $src, $prefix ) ) {
				return $label;
			}
		}

		return 'Unknown / External';
	}

	private static function build_source_map(): array {
		$map = [];

		// WordPress Core
		$map[ includes_url() ] = 'WordPress Core';
		$map[ admin_url() ]    = 'WordPress Core';

		// Active theme
		$map[ get_template_directory_uri() ]   = wp_get_theme()->get( 'Name' ) . ' (Theme)';
		$stylesheet = get_stylesheet_directory_uri();
		if ( $stylesheet !== get_template_directory_uri() ) {
			$map[ $stylesheet ] = wp_get_theme()->get( 'Name' ) . ' (Child Theme)';
		}

		// Plugins
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $plugin_file => $plugin_data ) {
			$slug   = dirname( $plugin_file );
			$prefix = plugins_url( '', WP_PLUGIN_DIR . '/' . $plugin_file );
			if ( $prefix ) {
				$map[ $prefix . '/' ] = $plugin_data['Name'];
			}
		}

		return $map;
	}

	/**
	 * Detect inline script/style blocks in the DOM output (for the panel).
	 * Returns an array of detected blocks.
	 */
	public static function get_inline_blocks( string $html ): array {
		$blocks = [];
		preg_match_all( '#(<(script|style)[^>]*>)(.*?)(</\2>)#si', $html, $matches, PREG_SET_ORDER );

		foreach ( $matches as $i => $m ) {
			$tag     = strtolower( $m[2] );
			$content = trim( $m[3] );
			if ( '' === $content ) {
				continue;
			}
			$blocks[] = [
				'index'   => $i,
				'type'    => 'script' === $tag ? 'inline_js' : 'inline_css',
				'preview' => mb_substr( $content, 0, 120 ),
			];
		}

		return $blocks;
	}
}
