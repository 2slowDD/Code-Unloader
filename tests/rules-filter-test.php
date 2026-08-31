<?php
/**
 * Regression tests for the Rules screen URL/Group filter mode (1.4.11).
 *
 * The screen filters by URL *or* by Group, never both. That exclusivity lives in
 * RulesListTable::resolve_filters() — server-side — so a stale or hand-edited
 * query string cannot apply the mode that is not selected. These tests pin that
 * behaviour without needing WordPress.
 */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/';

// Minimal stand-in so the class file can be loaded outside WordPress.
class WP_List_Table {}

require_once __DIR__ . '/../src/Admin/RulesListTable.php';

use CodeUnloader\Admin\RulesListTable;

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

// ---------------------------------------------------------------------------
// Mode resolution — URL is the default so the screen opens on "All URLs".
// ---------------------------------------------------------------------------

assert_same('url', RulesListTable::resolve_filter_mode([]), 'empty request should default to URL mode.');
assert_same('url', RulesListTable::resolve_filter_mode(['filter_by' => '']), 'blank filter_by should default to URL mode.');
assert_same('url', RulesListTable::resolve_filter_mode(['filter_by' => 'bogus']), 'unrecognized filter_by should fall back to URL mode.');
assert_same('group', RulesListTable::resolve_filter_mode(['filter_by' => 'group']), 'explicit group should select Group mode.');

// ---------------------------------------------------------------------------
// Exclusivity — the inactive mode's parameter is dropped, not merely hidden.
// ---------------------------------------------------------------------------

$url_mode = RulesListTable::resolve_filters([
	'filter_by'   => 'url',
	'url_pattern' => 'https://example.test/about',
	'group_id'    => 7, // stale value left in the query string
]);
assert_same('https://example.test/about', $url_mode['url_pattern'], 'URL mode should keep the selected url_pattern.');
assert_same(0, $url_mode['group_id'], 'URL mode must drop a stale group_id so no second filter applies.');

$group_mode = RulesListTable::resolve_filters([
	'filter_by'   => 'group',
	'group_id'    => 7,
	'url_pattern' => 'https://example.test/about', // stale value left in the query string
]);
assert_same(7, $group_mode['group_id'], 'Group mode should keep the selected group_id.');
assert_same('', $group_mode['url_pattern'], 'Group mode must drop a stale url_pattern so no second filter applies.');

// ---------------------------------------------------------------------------
// Defaults — "All URLs" and "All Groups" must reach the repository as no-ops.
// ---------------------------------------------------------------------------

$defaults = RulesListFilterDefaults();
assert_same('', $defaults['url_pattern'], 'default view should send an empty url_pattern (All URLs).');
assert_same(0, $defaults['group_id'], 'default view should send group_id 0 (All Groups).');
assert_same([], array_filter($defaults), 'default view should array_filter down to no filters at all.');

function RulesListFilterDefaults(): array {
	return RulesListTable::resolve_filters([]);
}

// ---------------------------------------------------------------------------
// Ungrouped (-1) must survive array_filter(), which prepare_items() applies
// before handing the set to RuleRepository::get_rules_filtered().
// ---------------------------------------------------------------------------

$ungrouped = array_filter(RulesListTable::resolve_filters([
	'filter_by' => 'group',
	'group_id'  => -1,
]));
assert_same(-1, $ungrouped['group_id'] ?? null, 'Ungrouped (-1) must survive array_filter() and reach the repository.');

// A selected group of 0 is "All Groups" and is correctly filtered away.
$all_groups = array_filter(RulesListTable::resolve_filters([
	'filter_by' => 'group',
	'group_id'  => 0,
]));
assert_same([], $all_groups, 'All Groups (0) should filter away to an empty filter set.');

// ---------------------------------------------------------------------------
// Search and type filters are mode-independent — they apply in both modes.
// ---------------------------------------------------------------------------

foreach (['url', 'group'] as $mode) {
	$with_search = RulesListTable::resolve_filters([
		'filter_by'  => $mode,
		's'          => 'dashicons',
		'match_type' => 'exact',
		'asset_type' => 'css',
	]);
	assert_same('dashicons', $with_search['search'], "search should survive in {$mode} mode.");
	assert_same('exact', $with_search['match_type'], "match_type should survive in {$mode} mode.");
	assert_same('css', $with_search['asset_type'], "asset_type should survive in {$mode} mode.");
}

// ---------------------------------------------------------------------------
// Wiring: the toolbar, the $_REQUEST read, and resolve_filters() must agree on
// the same three parameter names. Testing resolve_filters() in isolation cannot
// see a rename on one side only — the unit tests above would stay green while
// the screen silently stopped filtering. Renaming any single site turns this red.
//
// This checks name agreement across the three sites, not that WordPress delivers
// the values; the latter needs a real WP install and is on the manual check list.
// ---------------------------------------------------------------------------

$admin_src = file_get_contents(__DIR__ . '/../src/Admin/AdminScreen.php');
$table_src = file_get_contents(__DIR__ . '/../src/Admin/RulesListTable.php');

preg_match_all('/name="([a-z_]+)" id="cu-filter-/', $admin_src, $matches);
$submitted = $matches[1];
sort($submitted);
assert_same(
	['filter_by', 'group_id', 'url_pattern'],
	$submitted,
	'toolbar should submit exactly filter_by, group_id and url_pattern.'
);

preg_match_all('/\$_REQUEST\[\'([a-z_]+)\'\]/', $table_src, $matches);
$read_from_request = array_unique($matches[1]);

preg_match_all('/\$request\[\'([a-z_]+)\'\]/', $table_src, $matches);
$consumed = array_unique($matches[1]);

foreach ($submitted as $name) {
	assert_true(
		in_array($name, $read_from_request, true),
		"toolbar submits \"{$name}\" but prepare_items() never reads it from \$_REQUEST."
	);
	assert_true(
		in_array($name, $consumed, true),
		"prepare_items() reads \"{$name}\" but resolve_filters() never consumes it."
	);
}

echo "rules-filter-test passed\n";
