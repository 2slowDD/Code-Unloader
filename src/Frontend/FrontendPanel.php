<?php
declare( strict_types=1 );

namespace CodeUnloader\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use CodeUnloader\Core\{AssetDetector, PatternMatcher, RuleRepository};

/**
 * Frontend Panel v8
 * - wp_head buffer scan for inline block detection
 * - inline_blocks patched into panel_data at wp_footer output time
 */
class FrontendPanel {

	private static bool $html_injected = false;
	private array $panel_data = [];
	private array $detected_inline_blocks = [];

	public function init(): void {
		if ( ! $this->should_load() ) {
			return;
		}
		// The toolbar button is always shown so admins can activate the panel.
		// The full panel (HTML + JS + CSS) only loads when ?wpcu is present in the URL,
		// keeping every other page load completely free of panel overhead.
		// Clicking the toolbar button on a non-?wpcu page redirects to current URL + ?wpcu.
		add_action( 'admin_bar_menu', [ $this, 'add_toolbar_button' ], 100 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only panel activation flag, no data modification.
		if ( isset( $_GET['wpcu'] ) ) {
			// Buffer wp_head to detect inline <script> and <style> blocks for the panel.
			add_action( 'wp_head', [ $this, 'start_head_scan' ], -999 );
			add_action( 'wp_head', [ $this, 'end_head_scan' ],    999 );
			add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_panel_assets' ], 999 );
			add_action( 'wp_footer',          [ $this, 'inject_panel_html' ],    1 );
		}
	}

	private function should_load(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	public function start_head_scan(): void {
		ob_start();
	}

	public function end_head_scan(): void {
		$html = ob_get_clean();
		$this->detected_inline_blocks = self::extract_inline_blocks( $html );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Re-outputting buffered wp_head HTML unchanged.
	}

	/**
	 * Extract inline <script> and <style> blocks from HTML.
	 * Returns an array of block metadata for the panel.
	 */
	private static function extract_inline_blocks( string $html ): array {
		$blocks = [];
		if ( ! preg_match_all( '#<(script|style)\b([^>]*)>(.*?)</\1>#si', $html, $matches, PREG_SET_ORDER ) ) {
			return $blocks;
		}

		foreach ( $matches as $i => $m ) {
			$tag     = strtolower( $m[1] );
			$attrs   = $m[2];
			$content = trim( $m[3] );

			// Skip empty blocks and blocks with src (those are external, not inline).
			if ( '' === $content || preg_match( '/\bsrc\s*=/i', $attrs ) ) {
				continue;
			}

			// Try to extract an id attribute for identification.
			$id = '';
			if ( preg_match( '/\bid=["\']([^"\']+)["\']/i', $attrs, $id_match ) ) {
				$id = $id_match[1];
			}

			// Create a short preview of the content (first 120 chars).
			$preview = mb_substr( preg_replace( '/\s+/', ' ', $content ), 0, 120 );

			$blocks[] = [
				'index'   => $i,
				'type'    => 'script' === $tag ? 'inline_js' : 'inline_css',
				'id'      => $id,
				'preview' => $preview,
				'size'    => strlen( $content ),
			];
		}

		return $blocks;
	}

	public function enqueue_panel_assets(): void {
		wp_enqueue_style( 'cu-panel', CDUNLOADER_URL . 'assets/css/panel.css', [], CDUNLOADER_VERSION );
		wp_enqueue_script( 'cu-panel', CDUNLOADER_URL . 'assets/js/panel.js', [], CDUNLOADER_VERSION, true );

		global $wp_scripts, $wp_styles;

		// Bail gracefully if script/style globals aren't ready.
		if ( ! ( $wp_scripts instanceof \WP_Scripts ) || ! ( $wp_styles instanceof \WP_Styles ) ) {
			$this->panel_data = [
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'api_base'      => rest_url( 'code-unloader/v1' ),
				'admin_url'     => admin_url( 'admin.php?page=code-unloader' ),
				'page_url'      => '',
				'kill_switch'   => (bool) get_option( CDUNLOADER_OPTION_KILL ),
				'auto_open'     => 1,
				'assets'        => [],
				'rule_map'      => [],
				'groups'        => [],
				'inline_blocks' => $this->detected_inline_blocks,
			];
			return;
		}

		// Collect queued assets
		// $seen_handles tracks "handle|type" keys to allow a plugin that registers
		// the same handle name for both JS and CSS (e.g. Enlighter's "enlighterjs")
		// to have both entries collected without one clobbering the other.
		$assets       = [];
		$seen_handles = [];
		foreach ( $wp_scripts->queue as $handle ) {
			$obj              = $wp_scripts->registered[ $handle ] ?? null;
			$seen_handles[]   = $handle . '|js';
			$assets[]         = [
				'handle'       => $handle,
				'type'         => 'js',
				'src'          => $obj ? (string) $obj->src : '',
				'source_label' => $obj ? AssetDetector::detect_source( (string) $obj->src ) : 'Unknown / External',
				'deps'         => $obj ? $obj->deps : [],
				'size'         => $obj ? self::get_asset_size( (string) $obj->src ) : 0,
			];
		}
		foreach ( $wp_styles->queue as $handle ) {
			$obj            = $wp_styles->registered[ $handle ] ?? null;
			$seen_handles[] = $handle . '|css';
			$assets[]       = [
				'handle'       => $handle,
				'type'         => 'css',
				'src'          => $obj ? (string) $obj->src : '',
				'source_label' => $obj ? AssetDetector::detect_source( (string) $obj->src ) : 'Unknown / External',
				'deps'         => $obj ? $obj->deps : [],
				'size'         => $obj ? self::get_asset_size( (string) $obj->src ) : 0,
			];
		}

		// Compute current URL before the rule loops so we can filter correctly.
		$url       = '';
		$rule_map  = [];

		try {
			$url       = PatternMatcher::normalize_url( home_url( add_query_arg( [], $GLOBALS['wp']->request ?? '' ) ) );
			$all_rules = RuleRepository::get_all_rules();

			// Build rule_map: rules that match the current URL (used by JS to show disabled state).
			// Key is "handle|type" (e.g. "enlighterjs|css") so that a plugin using the same
			// handle name for both its JS and CSS files (e.g. Enlighter) doesn't cause one
			// to overwrite the other in the map.
			// Skip rules whose group is disabled — those assets load normally and must not
			// appear as "Disabled" in the panel.
			foreach ( $all_rules as $rule ) {
				if ( isset( $rule->group_id ) && null !== $rule->group_id && ! (int) ( $rule->group_enabled ?? 1 ) ) {
					continue;
				}
				if ( PatternMatcher::match( $rule, $url ) ) {
					$key              = $rule->asset_handle . '|' . $rule->asset_type;
					$rule_map[ $key ] = $rule;
				}
			}

			// Also include assets that were dequeued by a matching rule on this page.
			foreach ( $rule_map as $key => $rule ) {
				$handle   = $rule->asset_handle;
				$type     = $rule->asset_type;
				$seen_key = $handle . '|' . $type;
				if ( in_array( $seen_key, $seen_handles, true ) ) {
					continue;
				}
				if ( $type === 'js' ) {
					$obj = $wp_scripts->registered[ $handle ] ?? null;
				} elseif ( $type === 'css' ) {
					$obj = $wp_styles->registered[ $handle ] ?? null;
				} else {
					continue;
				}
				if ( ! $obj ) {
					continue;
				}
				$seen_handles[] = $seen_key;
				$assets[]       = [
					'handle'       => $handle,
					'type'         => $type,
					'src'          => (string) $obj->src,
					'source_label' => AssetDetector::detect_source( (string) $obj->src ),
					'deps'         => $obj->deps,
					'was_dequeued' => true,
					'size'         => self::get_asset_size( (string) $obj->src ),
				];
			}
		} catch ( \Throwable $e ) {
			// Log the error for debugging; panel will show assets without rule matching.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Code Unloader: panel rule matching failed — ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
			}
		}

		$this->panel_data = [
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'api_base'      => rest_url( 'code-unloader/v1' ),
			'admin_url'     => admin_url( 'admin.php?page=code-unloader' ),
			'page_url'      => $url,
			'kill_switch'   => (bool) get_option( CDUNLOADER_OPTION_KILL ),
			'auto_open'     => 1, // panel only loads on ?wpcu pages — always open
			'assets'        => $assets,
			'rule_map'      => $rule_map,
			'groups'        => RuleRepository::get_all_groups(),
			'inline_blocks' => $this->detected_inline_blocks,
		];
	}

	public function inject_panel_html(): void {
		// Guard: only inject once, even if wp_footer fires multiple times
		if ( self::$html_injected ) {
			return;
		}
		self::$html_injected = true;

		// Inline blocks are detected via wp_head buffer — patch them in now
		// since the head scan finishes after enqueue_panel_assets runs.
		$this->panel_data['inline_blocks'] = $this->detected_inline_blocks;

		// Pass panel data to panel.js via wp_add_inline_script (avoids raw <script> tag).
		wp_add_inline_script(
			'cu-panel',
			'var CDUNLOADER_DATA = ' . wp_json_encode( $this->panel_data ) . ';',
			'before'
		);

		$is_kill = (bool) get_option( CDUNLOADER_OPTION_KILL );
		?>
<!-- Code Unloader Panel v<?php echo esc_html( CDUNLOADER_VERSION ); ?> | panel.js v9 | panel.css v9 -->
<div id="cu-panel" inert aria-label="Code Unloader">
	<div class="cu-panel-header">
		<div class="cu-panel-header-left">
			<div class="cu-branding">
				<div class="cu-branding-title">⚡ Code Unloader <span class="cu-version">v<?php echo esc_html( CDUNLOADER_VERSION ); ?></span></div>
				<div class="cu-branding-sub">Developed by <a href="https://wpservice.pro" target="_blank" rel="noopener noreferrer" class="cu-branding-link">Dalibor Druzinec / WPservice.pro</a></div>
			</div>
		</div>
		<div class="cu-panel-header-right">
			<button id="cu-dock-toggle" class="cu-icon-btn" title="Dock to left side" aria-label="Toggle dock side">◀</button>
			<a id="cu-settings-link" class="cu-icon-btn cu-icon-btn--link" title="Open Code Unloader settings" aria-label="Open settings" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( admin_url( 'admin.php?page=code-unloader' ) ); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg></a>
			<button id="cu-theme-toggle" class="cu-icon-btn" title="Toggle dark/light mode" aria-label="Toggle theme">🌙</button>
			<button id="cu-close-btn" class="cu-icon-btn" aria-label="Close panel">✕</button>
		</div>
	</div>

	<?php if ( $is_kill ) : ?>
	<div class="cu-kill-banner">⚠ Kill switch active — no assets are being unloaded</div>
	<?php endif; ?>

	<div class="cu-toolbar">
		<div class="cu-tabs">
			<button class="cu-tab cu-tab--active" data-tab="assets">Assets</button>
			<button class="cu-tab" data-tab="inline">Inline Blocks</button>
		</div>
		<input id="cu-search" type="text" class="cu-search" placeholder="Filter by handle or filename…" autocomplete="off">
	</div>

	<div id="cu-first-use-warning" class="cu-first-use-warning" hidden>
		<div class="cu-warning-body">
			<span class="cu-warning-icon">⚠️</span>
			<div class="cu-warning-text">
				<strong>Use with caution.</strong>
				<strong>Disabling the wrong assets can break your site's layout or functionality. Always test after making changes.</strong>
			</div>
			<div class="cu-warning-actions">
				<button id="cu-warning-dismiss" class="cu-warning-btn-dismiss">Dismiss</button>
				<button id="cu-warning-never" class="cu-warning-btn-never">Don't show again</button>
			</div>
		</div>
	</div>

	<div id="cu-assets-tab" class="cu-list"></div>
	<div id="cu-inline-tab" class="cu-list" hidden>
		<p class="cu-empty">Scanning for inline blocks…</p>
	</div>
</div>

<div id="cu-dialog" hidden>
	<div class="cu-dialog-overlay"></div>
	<div class="cu-dialog-box">
		<h3>Disable Asset</h3>
		<p id="cu-dialog-asset-info" class="cu-dialog-meta"></p>

		<div class="cu-field">
			<label class="cu-field-label">Scope
				<span class="cu-help" data-tip="Choose how broadly this rule applies.">?</span>
			</label>
			<div class="cu-scope-group cu-scope-group--6col">
				<label class="cu-scope-btn cu-scope-active" data-scope="exact">
					<input type="radio" name="cu-scope" value="exact" checked>
					<span class="cu-scope-icon">📄</span>
					<span class="cu-scope-label">This page</span>
					<span class="cu-scope-desc">Exact URL only</span>
				</label>
				<label class="cu-scope-btn" data-scope="except_here">
					<input type="radio" name="cu-scope" value="except_here">
					<span class="cu-scope-icon">🚫</span>
					<span class="cu-scope-label">Everywhere except here</span>
					<span class="cu-scope-desc">All pages except this URL</span>
				</label>
				<label class="cu-scope-btn" data-scope="sitewide">
					<input type="radio" name="cu-scope" value="sitewide">
					<span class="cu-scope-icon">🌐</span>
					<span class="cu-scope-label">Everywhere</span>
					<span class="cu-scope-desc">All pages & posts</span>
				</label>
				<label class="cu-scope-btn" data-scope="all_pages">
					<input type="radio" name="cu-scope" value="all_pages">
					<span class="cu-scope-icon">📑</span>
					<span class="cu-scope-label">All pages</span>
					<span class="cu-scope-desc">WordPress pages only</span>
				</label>
				<label class="cu-scope-btn" data-scope="all_posts">
					<input type="radio" name="cu-scope" value="all_posts">
					<span class="cu-scope-icon">📝</span>
					<span class="cu-scope-label">All posts</span>
					<span class="cu-scope-desc">Blog posts only</span>
				</label>
				<label class="cu-scope-btn" data-scope="custom">
					<input type="radio" name="cu-scope" value="custom">
					<span class="cu-scope-icon">✏️</span>
					<span class="cu-scope-label">Custom</span>
					<span class="cu-scope-desc">Wildcard or regex</span>
				</label>
			</div>
		</div>

		<div id="cu-custom-pattern-wrap" class="cu-field" hidden>
			<label class="cu-field-label">Match Type</label>
			<div class="cu-radio-group cu-match-type-group">
				<label class="cu-radio-label">
					<input type="radio" name="cu-match-type" value="wildcard" checked>
					<span>Wildcard</span>
					<span class="cu-help" data-tip="Use * for any characters, ? for a single character.&#10;Examples:&#10;  /shop/* — all shop sub-pages&#10;  /product/?????-* — product slugs matching a pattern&#10;  /* — entire site (same as Everywhere)">?</span>
				</label>
				<label class="cu-radio-label">
					<input type="radio" name="cu-match-type" value="regex">
					<span>Regex</span>
					<span class="cu-help" data-tip="PHP-compatible regular expression matched against the full URL.&#10;Examples:&#10;  ^/product/[0-9]+ — numeric product pages&#10;  ^/(shop|cart|checkout) — WooCommerce pages&#10;&#10;⚠ Regex runs on every page load — keep it specific.">?</span>
				</label>
			</div>
			<input type="text" id="cu-url-pattern" class="cu-input" placeholder="/shop/*">
			<div id="cu-regex-warning" class="cu-regex-warning" hidden>
				⚠ Regex runs on every page load. Keep patterns specific to avoid performance impact.
			</div>
		</div>

		<div class="cu-dialog-columns">
			<div class="cu-field">
				<label class="cu-field-label" for="cu-device-type">Device</label>
				<select id="cu-device-type" class="cu-input">
					<option value="all">All Devices</option>
					<option value="desktop">Desktop Only</option>
					<option value="mobile">Mobile Only</option>
				</select>
			</div>

			<div class="cu-field">
				<label class="cu-field-label" for="cu-group-id">Group</label>
				<select id="cu-group-id" class="cu-input">
					<option value="">Ungrouped</option>
				</select>
				<div id="cu-new-group-wrap" style="display:none;margin-top:6px;">
					<input type="text" id="cu-new-group-name" class="cu-input" placeholder="New group name…" style="width:100%;">
				</div>
			</div>
		</div>

		<div id="cu-condition-wrap">
		<div class="cu-field">
			<label class="cu-field-label" for="cu-condition-type">Condition
				<span class="cu-help" data-tip="Optionally restrict when this rule fires.&#10;Use 'Apply UNLESS' to invert: load for members, strip for guests, etc.">?</span>
			</label>
			<select id="cu-condition-type" class="cu-input">
				<option value="">None (always unload)</option>
				<option value="is_user_logged_in">User is logged in</option>
				<option value="is_woocommerce_page">WooCommerce page</option>
				<option value="has_shortcode">Post contains shortcode</option>
				<option value="is_post_type">Post type matches</option>
				<option value="is_page_template">Page template matches</option>
			</select>
		</div>

		<div id="cu-condition-value-wrap" class="cu-field" hidden>
			<label class="cu-field-label" for="cu-condition-value">Condition Value</label>
			<input type="text" id="cu-condition-value" class="cu-input" placeholder="e.g. contact-form-7">
		</div>

		<div class="cu-field cu-field-inline">
			<label class="cu-checkbox-label">
				<input type="checkbox" id="cu-condition-invert">
				Apply UNLESS this condition is true
			</label>
		</div>
		</div>

		<div class="cu-field">
			<label class="cu-field-label" for="cu-label">Note (optional)</label>
			<input type="text" id="cu-label" class="cu-input" placeholder="Why are you disabling this?" maxlength="255">
		</div>

		<div id="cu-dialog-error" class="cu-dialog-error" hidden></div>

		<div class="cu-dialog-actions">
			<button id="cu-dialog-cancel" class="cu-btn-secondary">Cancel</button>
			<button id="cu-dialog-save" class="cu-btn-primary">Save Rule</button>
		</div>
	</div>
</div>
		<?php
	}

	public function add_toolbar_button( \WP_Admin_Bar $wp_admin_bar ): void {
		$is_kill    = (bool) get_option( CDUNLOADER_OPTION_KILL );
		$panel_live = isset( $_GET['wpcu'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Panel activation flag.

		if ( $panel_live ) {
			// Panel is loaded — clicking toggles it open/closed inline
			$href    = '#';
			$onclick = 'cuTogglePanel(); return false;';
		} else {
			// Panel not loaded — clicking reloads the current page with ?wpcu appended,
			// which causes PHP to inject the panel on that load and auto-open it.
			$href    = esc_url( add_query_arg( 'wpcu', '1' ) );
			$onclick = '';
		}

		$wp_admin_bar->add_node( [
			'id'    => 'cu-panel-toggle',
			'title' => $is_kill
				? '<span class="cu-kill-pill">Code Unloader: DISABLED</span>'
				: '⚡ Assets',
			'href'  => $href,
			'meta'  => [
				'class'   => 'cu-toolbar-node',
				'onclick' => $onclick,
			],
		] );
	}

	/**
	 * Get file size in bytes for an asset src URL, resolved to a local file path.
	 * Returns 0 if the file cannot be found (external URLs, dynamic scripts, etc).
	 */
	private static function get_asset_size( string $src ): int {
		if ( empty( $src ) ) {
			return 0;
		}
		// Strip query string
		$src = strtok( $src, '?' );
		// Convert URL to file path
		$site_url  = site_url();
		$abspath   = untrailingslashit( ABSPATH );
		if ( str_starts_with( $src, $site_url ) ) {
			$rel  = substr( $src, strlen( $site_url ) );
			$path = $abspath . $rel;
		} elseif ( str_starts_with( $src, '/' ) ) {
			$path = $abspath . $src;
		} else {
			return 0; // external URL
		}
		// Normalize and check
		$path = wp_normalize_path( $path );
		if ( is_file( $path ) ) {
			return (int) filesize( $path );
		}
		return 0;
	}

}