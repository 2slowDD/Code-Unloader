# Group Rule Decoupling — Regression QA Checklist
Version: 1.5.0 | Date: 2026-04-17

Run each check on a WordPress test install with at least 2 groups and 5 grouped rules.

## Schema

- [ ] After activating 1.5.0 on a 1.4.x install: `SHOW COLUMNS FROM wp_cu_rules` includes `group_item_id`
- [ ] `SHOW TABLES LIKE 'wp_cu_group_items'` returns the table
- [ ] `SELECT COUNT(*) FROM wp_cu_group_items` equals the number of previously grouped rules (pre-upgrade count)
- [ ] `SELECT id FROM wp_cu_rules WHERE group_id IS NOT NULL AND group_item_id IS NULL` returns 0 rows

## Rules Tab — Delete

- [ ] Delete a single active rule that belongs to a group → rule disappears from Rules tab; `SELECT * FROM wp_cu_group_items WHERE group_id = <id>` still shows the snapshot
- [ ] Bulk-delete selected grouped rules → same: snapshots remain
- [ ] "Delete All Rules" → all `wp_cu_rules` rows gone; `wp_cu_group_items` rows intact

## Groups Tab — Counts and View Rules

- [ ] Group card shows rule count matching `SELECT COUNT(*) FROM wp_cu_group_items WHERE group_id = <id>`
- [ ] After deleting all active rules for a group, group card count is unchanged
- [ ] Click "View Rules" → modal shows saved snapshots (not empty, even with no active rules)

## Group Enable / Disable

- [ ] Disable a group → active rules for that group removed from `wp_cu_rules`; `wp_cu_group_items` rows intact
- [ ] Re-enable the group → active rules recreated from snapshots; no duplicates
- [ ] Disable and re-enable twice → rule count matches snapshot count each time

## CU Panel — Scoped Re-enable

- [ ] Disable an asset via the CU panel → `wp_cu_rules` has a new row
- [ ] Click the toggle to re-enable → "On this page" and "Globally" buttons appear
- [ ] Click "On this page" → only the rule matching the current page URL is removed; rules for other URLs remain
- [ ] Repeat and click "Globally" → all active rules for that asset+type removed
- [ ] After either re-enable: if the rule originated from a group, `wp_cu_group_items` row is still present

## Export / Import Round-trip

- [ ] Export → JSON file includes `group_items` key with snapshot rows
- [ ] Delete all rules and groups → site is clean
- [ ] Import the exported file → groups recreated, group items recreated, active rules recreated
- [ ] Group card counts match post-import
- [ ] View Rules modal shows imported snapshots

## Dequeue (Frontend)

- [ ] A rule from an enabled group correctly prevents the asset from loading on the matched URL
- [ ] After disabling the group (via Groups tab toggle) → asset loads again
- [ ] No PHP errors or warnings in debug log after each action above
