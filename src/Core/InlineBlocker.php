<?php
declare( strict_types=1 );

namespace CodeUnloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class InlineBlocker {

	private array $head_rules   = [];
	private array $footer_rules = [];
	private bool  $debug;

	public function init(): void {
		// Bypass via ?nowpcu — disables all rules for this request.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bypass parameter, no data modification.
		if ( isset( $_GET['nowpcu'] ) ) {
			return;
		}

		if ( get_option( CDUNLOADER_OPTION_KILL ) ) {
			return;
		}

		$current_url   = PatternMatcher::normalize_url( home_url( $GLOBALS['wp']->request ?? '' ) );
		$inline_rules  = $this->get_matching_inline_rules( $current_url );

		if ( empty( $inline_rules ) ) {
			return; // zero overhead when no inline rules match
		}

		$this->debug        = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$this->head_rules   = $inline_rules;
		$this->footer_rules = $inline_rules;

		add_action( 'wp_head',   [ $this, 'start_head_buffer' ],   -1 );
		add_action( 'wp_head',   [ $this, 'end_head_buffer' ],    999 );
		add_action( 'wp_footer', [ $this, 'start_footer_buffer' ], -1 );
		add_action( 'wp_footer', [ $this, 'end_footer_buffer' ],  999 );
	}

	public function start_head_buffer(): void   { ob_start(); }
	public function start_footer_buffer(): void { ob_start(); }

	public function end_head_buffer(): void {
		$html = ob_get_clean();
		echo $this->filter_inline_blocks( (string) $html, $this->head_rules ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered wp_head output, selectively filtering inline blocks.
	}

	public function end_footer_buffer(): void {
		$html = ob_get_clean();
		echo $this->filter_inline_blocks( (string) $html, $this->footer_rules ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered wp_footer output, selectively filtering inline blocks.
	}

	private function filter_inline_blocks( string $html, array $rules ): string {
		// Match <script ...>...</script> and <style ...>...</style>
		return preg_replace_callback(
			'#(<(script|style)[^>]*>)(.*?)(</\2>)#si',
			function ( array $m ) use ( $rules ): string {
				$content = $m[3];
				$tag     = strtolower( $m[2] );
				$type    = ( 'script' === $tag ) ? 'inline_js' : 'inline_css';

				foreach ( $rules as $rule ) {
					if ( $rule->asset_type !== $type ) {
						continue;
					}
					if ( $this->inline_matches( $rule, $content ) ) {
						return $this->debug
							? "<!-- Code Unloader: blocked inline [{$tag}] handle={$rule->asset_handle} -->"
							: '';
					}
				}
				return $m[0]; // unchanged
			},
			$html
		) ?: $html;
	}

	private function inline_matches( object $rule, string $content ): bool {
		// asset_handle stores the match pattern for inline rules
		return PatternMatcher::match( $rule, $content );
	}

	private function get_matching_inline_rules( string $url ): array {
		$all = RuleRepository::get_all_rules();
		return array_filter( $all, function ( $rule ) use ( $url ): bool {
			if ( ! in_array( $rule->asset_type, [ 'inline_js', 'inline_css' ], true ) ) {
				return false;
			}
			if ( isset( $rule->group_id ) && $rule->group_id && ! (int) ( $rule->group_enabled ?? 1 ) ) {
				return false;
			}
			return PatternMatcher::match( $rule, $url );
		} );
	}
}
