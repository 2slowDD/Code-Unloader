<?php
/**
 * Regression tests for dependency-only asset visibility/unloading.
 */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/';
const DAY_IN_SECONDS = 86400;
const CDUNLOADER_OPTION_KILL = 'cdunloader_kill';
const CDUNLOADER_URL = 'https://example.test/wp-content/plugins/code-unloader/';
const CDUNLOADER_VERSION = 'test';

class WP_Dependencies {
	public array $queue = array();
	public array $registered = array();
}

class WP_Scripts extends WP_Dependencies {}
class WP_Styles extends WP_Dependencies {}

function get_transient(string $key): array {
	return array(
		'https://example.test/wp-content/plugins/woocommerce/' => 'WooCommerce',
		'https://example.test/wp-content/plugins/code-unloader/' => 'Code Unloader',
	);
}

function wp_dequeue_script(string $handle): void {
	$GLOBALS['dequeued_scripts'][] = $handle;
}

function wp_dequeue_style(string $handle): void {
	$GLOBALS['dequeued_styles'][] = $handle;
}

function get_option(string $key): bool {
	return false;
}

function wp_cache_get(string $key) {
	if ('cdunloader_all_rules' === $key) {
		return $GLOBALS['test_rules'] ?? array();
	}
	if ('cdunloader_all_groups' === $key || str_starts_with($key, 'cdunloader_snapshots_for_url_')) {
		return array();
	}
	if ('cdunloader_snapshots_version' === $key) {
		return 0;
	}
	return false;
}

function wp_cache_set(string $key, $value): void {}

function home_url(string $path = ''): string {
	return 'https://example.test/about/';
}

function add_query_arg(array $args, string $path = ''): string {
	return $path;
}

function remove_query_arg(string $key, string $url): string {
	return $url;
}

function apply_filters(string $hook, $value) {
	return $value;
}

function wp_parse_url(string $url, int $component = -1) {
	return -1 === $component ? parse_url($url) : parse_url($url, $component);
}

function wp_enqueue_style(string $handle, string $src, array $deps = array(), string $version = ''): void {}

function wp_enqueue_script(string $handle, string $src, array $deps = array(), string $version = '', bool $in_footer = false): void {}

function wp_create_nonce(string $action): string {
	return 'nonce';
}

function rest_url(string $path = ''): string {
	return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function admin_url(string $path = ''): string {
	return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function site_url(string $path = ''): string {
	return 'https://example.test' . $path;
}

function untrailingslashit(string $value): string {
	return rtrim($value, '/\\');
}

function wp_normalize_path(string $path): string {
	return str_replace('\\', '/', $path);
}

require_once __DIR__ . '/../src/Core/AssetDetector.php';
require_once __DIR__ . '/../src/Core/PatternMatcher.php';
require_once __DIR__ . '/../src/Core/DeviceDetector.php';
require_once __DIR__ . '/../src/Core/ConditionEvaluator.php';
require_once __DIR__ . '/../src/Core/RuleRepository.php';
require_once __DIR__ . '/../src/Core/DequeueEngine.php';
require_once __DIR__ . '/../src/Frontend/FrontendPanel.php';

use CodeUnloader\Core\AssetDetector;
use CodeUnloader\Core\DequeueEngine;
use CodeUnloader\Frontend\FrontendPanel;

function dep(string $src, array $deps = array()): object {
	return (object) array(
		'src'  => $src,
		'deps' => $deps,
	);
}

function assert_true(bool $condition, string $message): void {
	if (! $condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function assert_same($expected, $actual, string $message): void {
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
		exit(1);
	}
}

function setup_scripts(): WP_Scripts {
	$scripts = new WP_Scripts();
	$scripts->queue = array('woocommerce');
	$scripts->registered = array(
		'woocommerce'  => dep('https://example.test/wp-content/plugins/woocommerce/assets/js/frontend/woocommerce.min.js', array('jquery', 'wc-js-cookie')),
		'jquery'       => dep('/wp-includes/js/jquery/jquery.min.js'),
		'wc-js-cookie' => dep('https://example.test/wp-content/plugins/woocommerce/assets/js/js-cookie/js.cookie.min.js'),
	);
	return $scripts;
}

$GLOBALS['wp_scripts'] = setup_scripts();
$GLOBALS['wp_styles'] = new WP_Styles();
$GLOBALS['wp'] = (object) array('request' => 'about');

$assets = AssetDetector::get_enqueued_assets();
$cookie = array_values(array_filter($assets, static fn(array $asset): bool => 'wc-js-cookie' === $asset['handle']));

assert_true(1 === count($cookie), 'dependency-only wc-js-cookie should appear in detected assets.');
assert_same(array('woocommerce'), $cookie[0]['required_by'] ?? null, 'dependency-only asset should record parent handle.');

$panel = new FrontendPanel();
$panel->enqueue_panel_assets();
$prop = new ReflectionProperty($panel, 'panel_data');
$prop->setAccessible(true);
$panel_data = $prop->getValue($panel);
$panel_cookie = array_values(array_filter($panel_data['assets'], static fn(array $asset): bool => 'wc-js-cookie' === $asset['handle']));

assert_true(1 === count($panel_cookie), 'frontend panel data should include dependency-only wc-js-cookie.');
assert_same(array('woocommerce'), $panel_cookie[0]['required_by'] ?? null, 'frontend panel dependency asset should record parent handle.');

$panel_js = file_get_contents(__DIR__ . '/../assets/js/panel.js');
assert_true(false !== strpos($panel_js, 'a.required_by'), 'panel JS should search/render required_by metadata.');
assert_true(false !== strpos($panel_js, '_cu.bindEvents(newRow);'), 'panel row replacement should bind events only on the replaced row.');
assert_true(false === strpos($panel_js, 'if (parent) _cu.bindEvents(parent);'), 'panel row replacement must not re-bind the whole group after one toggle.');

$GLOBALS['dequeued_scripts'] = array();
$GLOBALS['wp_scripts'] = setup_scripts();
$rule_cache = new ReflectionProperty(\CodeUnloader\Core\RuleRepository::class, 'rules_cache');
$rule_cache->setAccessible(true);
$rule_cache->setValue(null, null);
$GLOBALS['test_rules'] = array(
	(object) array(
		'asset_handle'     => 'wc-js-cookie',
		'asset_type'       => 'js',
		'url_pattern'      => 'https://example.test/about',
		'match_type'       => 'exact',
		'device_type'      => 'all',
		'condition_type'   => null,
		'condition_value'  => null,
		'condition_invert' => false,
	),
);

(new DequeueEngine())->process_rules();

assert_same(array('jquery'), $GLOBALS['wp_scripts']->registered['woocommerce']->deps, 'dependency unload should prune wc-js-cookie from parent dependencies.');
assert_same(array('wc-js-cookie'), $GLOBALS['dequeued_scripts'], 'dependency unload should still dequeue the target handle.');

echo "dependency-assets-test passed\n";
