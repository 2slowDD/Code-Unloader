<?php
/**
 * Regression tests for the URL filter reaching SQL correctly (1.4.11).
 *
 * Counting placeholders against arguments proves only that the numbers agree —
 * it cannot see an argument landing in the WRONG placeholder, which is the real
 * hazard when a filter is threaded into six existing prepare() calls. These
 * tests substitute values through a faithful stand-in for $wpdb->prepare() and
 * assert the finished SQL, so a mis-ordered argument shows up as the URL value
 * appearing in someone else's comparison.
 */

declare(strict_types=1);

const ABSPATH = __DIR__ . '/';

require_once __DIR__ . '/../src/Core/RuleRepository.php';

use CodeUnloader\Core\RuleRepository;

function assert_true(bool $condition, string $message): void {
	if (! $condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function assert_contains(string $needle, string $haystack, string $message): void {
	if (false === strpos($haystack, $needle)) {
		fwrite(STDERR, "FAIL: {$message}\nExpected to find: {$needle}\nIn SQL:\n{$haystack}\n");
		exit(1);
	}
}

function wp_cache_get(string $key) { return false; }
function wp_cache_set(string $key, $value): bool { return true; }

/**
 * Stand-in for wpdb: substitutes %s / %d positionally the way $wpdb->prepare()
 * does, and records the finished SQL of every query for inspection.
 */
class FakeWpdb {
	public string $prefix = 'wp_';
	public array $queries = array();

	public function prepare(string $query, ...$args): string {
		$i = 0;
		$out = preg_replace_callback(
			'/%[sd]/',
			function (array $m) use (&$i, $args): string {
				if (! array_key_exists($i, $args)) {
					fwrite(STDERR, "FAIL: prepare() ran out of arguments at placeholder #{$i}\n");
					exit(1);
				}
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . $v . "'";
			},
			$query
		);
		if ($i !== count($args)) {
			fwrite(STDERR, "FAIL: prepare() left " . (count($args) - $i) . " unused argument(s)\n");
			exit(1);
		}
		return $out;
	}

	public function esc_like(string $t): string { return $t; }
	public function query(string $sql) { $this->queries[] = $sql; return true; }
	public function get_results(string $sql) { $this->queries[] = $sql; return array(); }
	public function get_col(string $sql) { $this->queries[] = $sql; return array(); }
	public function get_var(string $sql) { $this->queries[] = $sql; return 0; }

	/** The last SELECT that returns rows (skips the SET SESSION housekeeping). */
	public function rows_sql(): string {
		foreach ($this->queries as $q) {
			if (false !== strpos($q, 'LIMIT')) { return $q; }
		}
		return '';
	}

	public function count_sql(): string {
		foreach ($this->queries as $q) {
			if (false !== strpos($q, 'COUNT(')) { return $q; }
		}
		return '';
	}
}

function run_filtered(array $filters): FakeWpdb {
	$GLOBALS['wpdb'] = new FakeWpdb();
	RuleRepository::get_rules_filtered($filters, 10, 1);
	return $GLOBALS['wpdb'];
}

// ---------------------------------------------------------------------------
// All Groups branch (the one the URL filter actually uses).
// ---------------------------------------------------------------------------

$url = 'https://example.test/our-products/code-unloader';
$db  = run_filtered(array('url_pattern' => $url));

foreach (array('rows' => $db->rows_sql(), 'count' => $db->count_sql()) as $which => $sql) {
	assert_true('' !== $sql, "All Groups branch should issue a {$which} query.");
	// The URL must land in the url_pattern comparison, and nowhere else.
	assert_contains("OR r.url_pattern = '{$url}'", $sql, "URL filter should reach the {$which} query's url_pattern comparison.");
	assert_contains("('{$url}' = '' OR r.url_pattern", $sql, "URL filter's bypass sentinel should be the URL itself in the {$which} query.");
	// Inactive filters must still be bypassed — proof no argument shifted into them.
	foreach (array('match_type', 'asset_type', 'device_type') as $col) {
		assert_contains("('' = '' OR r.{$col} = '')", $sql, "inactive {$col} filter should stay bypassed in the {$which} query (argument order intact).");
	}
	assert_true(false === strpos($sql, "r.match_type = '{$url}'"), "URL value must not leak into match_type in the {$which} query.");
	assert_true(false === strpos($sql, "r.device_type = '{$url}'"), "URL value must not leak into device_type in the {$which} query.");
}

// LIMIT/OFFSET are the tail arguments — a shift would corrupt paging silently.
assert_contains('LIMIT 10 OFFSET 0', $db->rows_sql(), 'paging arguments should survive the added filter.');

// ---------------------------------------------------------------------------
// No URL selected ("All URLs") must bypass the new condition entirely.
// ---------------------------------------------------------------------------

$db_all = run_filtered(array());
assert_contains("('' = '' OR r.url_pattern = '')", $db_all->rows_sql(), 'All URLs should bypass the url_pattern condition.');

// ---------------------------------------------------------------------------
// The other two branches accept the filter too — RestController is a second
// caller, so a parameter honoured in only one branch would be a silent trap.
// ---------------------------------------------------------------------------

$ungrouped = run_filtered(array('group_id' => -1, 'url_pattern' => $url));
assert_contains('r.group_id IS NULL', $ungrouped->rows_sql(), 'group_id -1 should select the Ungrouped branch.');
assert_contains("OR r.url_pattern = '{$url}'", $ungrouped->rows_sql(), 'Ungrouped branch should honour the url_pattern filter.');
assert_contains("('' = '' OR r.device_type = '')", $ungrouped->rows_sql(), 'Ungrouped branch argument order should stay intact.');

$in_group = run_filtered(array('group_id' => 7, 'url_pattern' => $url));
assert_contains('r.group_id = 7', $in_group->rows_sql(), 'a positive group_id should select the specific-group branch.');
assert_contains("OR r.url_pattern = '{$url}'", $in_group->rows_sql(), 'specific-group branch should honour the url_pattern filter.');
assert_contains("('' = '' OR r.device_type = '')", $in_group->rows_sql(), 'specific-group branch argument order should stay intact.');

// ---------------------------------------------------------------------------
// Combined with search: both filters must land in their own comparisons.
// ---------------------------------------------------------------------------

$combined = run_filtered(array('url_pattern' => $url, 'search' => 'dashicons'));
assert_contains("r.asset_handle LIKE '%dashicons%'", $combined->rows_sql(), 'search should still reach the handle comparison.');
assert_contains("OR r.url_pattern = '{$url}'", $combined->rows_sql(), 'URL filter should coexist with an active search.');

echo "rules-filter-sql-test passed\n";
