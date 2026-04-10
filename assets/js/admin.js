/**
 * Code Unloader — Admin Screen JS
 */
(function () {
	'use strict';

	const cfg   = window.CDUNLOADER_ADMIN || {};
	const API   = cfg.api_base    || '';
	const NONCE = cfg.nonce       || '';

	async function api(method, path, body) {
		const r = await fetch(API + path, {
			method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: body ? JSON.stringify(body) : undefined,
		});
		const d = await r.json();
		if (!r.ok) throw new Error(d.message || r.statusText);
		return d;
	}

	function notice(msg, type = 'success') {
		const existing = document.querySelector('.cu-js-notice');
		if (existing) existing.remove();
		const div = document.createElement('div');
		div.className = `notice notice-${type} is-dismissible cu-js-notice`;
		div.innerHTML = `<p>${msg}</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>`;
		div.querySelector('.notice-dismiss').addEventListener('click', () => div.remove());
		const h1 = document.querySelector('.cu-admin-wrap h1');
		if (h1 && h1.parentNode) h1.parentNode.insertBefore(div, h1.nextSibling);
		setTimeout(() => div.remove(), 5000);
	}

	/**
	 * Adjust the "N total rules" counter by delta (+n or -n).
	 * Parses the current number from the span text, adjusts it, rewrites it.
	 */
	function updateRulesCount(delta) {
		const el = document.getElementById('cu-total-rules-count');
		if (!el) return;
		const match = el.textContent.match(/\d+/);
		if (!match) return;
		const newCount = Math.max(0, parseInt(match[0], 10) + delta);
		el.textContent = el.textContent.replace(/\d+/, newCount);
	}

	// Delete stale rules
	const staleBtn = document.getElementById('cu-delete-stale-btn');
	if (staleBtn) {
		staleBtn.addEventListener('click', async function () {
			const staleNotice = this.closest('.cu-stale-notice');
			const ids = JSON.parse(staleNotice ? (staleNotice.dataset.staleIds || '[]') : '[]');
			if (!ids.length) return;
			if (!confirm(`Delete ${ids.length} stale rule(s)? This cannot be undone.`)) return;
			this.disabled = true; this.textContent = 'Deleting…';
			try {
				const r = await api('POST', '/rules/bulk-delete', { ids });
				if (staleNotice) staleNotice.remove();
				updateRulesCount(-r.deleted);
				notice(`Deleted ${r.deleted} stale rule(s).`);
			} catch (err) {
				notice('Error: ' + err.message, 'error');
				this.disabled = false; this.textContent = 'Delete stale rules';
			}
		});
	}

	// Delete single rule
	document.addEventListener('click', async function (e) {
		const btn = e.target.closest('.cu-delete-rule-btn');
		if (!btn) return;
		if (!confirm('Delete this rule?')) return;
		try {
			await api('DELETE', `/rules/${btn.dataset.id}`);
			const row = btn.closest('tr');
			if (row) row.remove();
			updateRulesCount(-1);
			notice('Rule deleted.');
		} catch (err) { notice('Error: ' + err.message, 'error'); }
	});

	// Bulk delete
	document.addEventListener('submit', async function (e) {
		const form = e.target;
		if (!form.querySelector('input[name="rule_ids[]"]')) return;
		const sel1 = form.querySelector('select[name="action"]');
		const sel2 = form.querySelector('select[name="action2"]');
		const action = (sel1 && sel1.value !== '-1' ? sel1.value : null) || (sel2 && sel2.value !== '-1' ? sel2.value : null);

		if (action === 'bulk-assign-group') {
			e.preventDefault();
			const ids = [...form.querySelectorAll('input[name="rule_ids[]"]:checked')].map(i => parseInt(i.value, 10));
			if (!ids.length) { notice('Select at least one rule.', 'warning'); return; }
			openAssignGroupModal(ids);
			return;
		}

		if (action !== 'bulk-delete') return;
		e.preventDefault();
		const ids = [...form.querySelectorAll('input[name="rule_ids[]"]:checked')].map(i => i.value);
		if (!ids.length) { notice('Select at least one rule.', 'warning'); return; }
		if (!confirm(`Delete ${ids.length} rule(s)?`)) return;
		try {
			const r = await api('POST', '/rules/bulk-delete', { ids: ids.map(Number) });
			notice(`Deleted ${r.deleted} rule(s).`);
			ids.forEach(id => { const cb = form.querySelector(`input[value="${id}"]`); if (cb) { const row = cb.closest('tr'); if (row) row.remove(); } });
			updateRulesCount(-r.deleted);
		} catch (err) { notice('Error: ' + err.message, 'error'); }
	});

	// ---- Bulk Assign Group modal ----
	function buildAssignGroupModal() {
		if (document.getElementById('cu-assign-group-modal')) return;
		const modal = document.createElement('div');
		modal.id = 'cu-assign-group-modal';
		modal.style.cssText = 'display:none;position:fixed;inset:0;z-index:100100;background:rgba(0,0,0,.55);align-items:center;justify-content:center;';
		modal.innerHTML = `
		<div style="background:#fff;border-radius:6px;padding:24px 28px;max-width:420px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.22);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<h2 style="margin:0 0 16px;font-size:16px;font-weight:600;">Add Rules to Group</h2>
			<div id="cu-agm-body"></div>
			<div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;">
				<button id="cu-agm-cancel" class="button">Cancel</button>
				<button id="cu-agm-confirm" class="button button-primary">Assign</button>
			</div>
		</div>`;
		document.body.appendChild(modal);

		modal.addEventListener('click', function(e) { if (e.target === modal) closeAssignGroupModal(); });
		document.getElementById('cu-agm-cancel').addEventListener('click', closeAssignGroupModal);
	}

	function closeAssignGroupModal() {
		const modal = document.getElementById('cu-assign-group-modal');
		if (modal) modal.style.display = 'none';
	}

	function openAssignGroupModal(ids) {
		buildAssignGroupModal();
		const modal  = document.getElementById('cu-assign-group-modal');
		const body   = document.getElementById('cu-agm-body');
		const groups = (cfg.groups || []);

		let html = '<p style="margin:0 0 14px;color:#555;font-size:13px;">Assign <strong>' + ids.length + '</strong> selected rule(s) to a group.</p>';

		// Existing groups
		if (groups.length) {
			html += '<fieldset style="border:1px solid #ddd;border-radius:4px;padding:10px 14px;margin:0 0 14px;">';
			html += '<legend style="font-size:12px;font-weight:600;padding:0 6px;color:#444;">Existing groups</legend>';
			groups.forEach(function(g) {
				html += `<label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;">
					<input type="radio" name="cu_agm_group" value="${g.id}" style="margin:0;">
					<span>${escHtml(g.name)}</span>
					<span style="color:#999;font-size:11px;">(${g.rule_count || 0} rules)</span>
				</label>`;
			});
			html += '</fieldset>';
		}

		// Create new
		html += `<label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;">
			<input type="radio" name="cu_agm_group" value="__new__" style="margin:0;" ${groups.length ? '' : 'checked'}>
			<span style="font-weight:600;">Create new group</span>
		</label>
		<div id="cu-agm-new-wrap" style="${groups.length ? 'display:none;' : ''}padding-left:24px;">
			<input type="text" id="cu-agm-new-name" placeholder="Group name" style="width:100%;padding:6px 8px;border:1px solid #ccc;border-radius:3px;font-size:13px;">
		</div>`;

		body.innerHTML = html;

		// Show/hide new group input based on radio selection
		body.querySelectorAll('input[name="cu_agm_group"]').forEach(function(radio) {
			radio.addEventListener('change', function() {
				const wrap = document.getElementById('cu-agm-new-wrap');
				if (wrap) wrap.style.display = (this.value === '__new__') ? '' : 'none';
			});
		});

		// Wire confirm button — always clone so prior event listeners/disabled state are cleared.
		const confirmBtn = document.getElementById('cu-agm-confirm');
		const newConfirm = confirmBtn.cloneNode(true);
		newConfirm.disabled  = false;          // Bug 1: ensure clone starts enabled
		newConfirm.textContent = 'Assign';
		confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);
		newConfirm.addEventListener('click', async function() {
			const selected = body.querySelector('input[name="cu_agm_group"]:checked');
			if (!selected) { alert('Please select a group or enter a new group name.'); return; }

			newConfirm.disabled = true; newConfirm.textContent = 'Saving…';
			try {
				let groupId = null;

				if (selected.value === '__new__') {
					const nameEl = document.getElementById('cu-agm-new-name');
					const name   = nameEl ? nameEl.value.trim() : '';
					if (!name) { alert('Please enter a group name.'); newConfirm.disabled = false; newConfirm.textContent = 'Assign'; return; }
					const created = await api('POST', '/groups', { name, description: '' });
					// Bug 2: always store id as integer for consistent find() matching
					groupId = parseInt(created.id, 10);
					cfg.groups = cfg.groups || [];
					cfg.groups.push({ id: groupId, name, rule_count: ids.length });
				} else {
					groupId = parseInt(selected.value, 10);
				}

				const r = await api('POST', '/rules/bulk-assign-group', { ids, group_id: groupId });
				closeAssignGroupModal();

				// Bug 2: find() with strict int comparison — both sides are now parseInt
				const groupObj   = (cfg.groups || []).find(g => parseInt(g.id, 10) === groupId);
				const groupLabel = groupObj ? groupObj.name : String(groupId);
				const pillHtml   = '<span class="cu-pill cu-pill-teal">' + escHtml(groupLabel) + '</span>';

				// Refresh Group column cells in affected rows without a page reload.
				ids.forEach(function(id) {
					const cb = document.querySelector('input[name="rule_ids[]"][value="' + id + '"]');
					if (!cb) return;
					const row  = cb.closest('tr');
					if (!row) return;
					const cell = row.querySelector('td.column-group_name');
					if (cell) cell.innerHTML = pillHtml;
					// Item 3: deselect the checkbox after assign
					cb.checked = false;
				});
				// Uncheck "select all" checkboxes too
				document.querySelectorAll('#cb-select-all-1, #cb-select-all-2').forEach(function(cb) { cb.checked = false; });

				notice('Assigned ' + r.updated + ' rule(s) to group "' + escHtml(groupLabel) + '".');
			} catch(err) {
				notice('Error: ' + err.message, 'error');
			} finally {
				// Bug 1: always re-enable button so the modal is usable again after any outcome
				newConfirm.disabled  = false;
				newConfirm.textContent = 'Assign';
			}
		});

		modal.style.display = 'flex';
		// Focus first radio or name input
		const firstRadio = body.querySelector('input[name="cu_agm_group"]');
		if (firstRadio) firstRadio.focus();
	}

	function escHtml(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	// Delete ALL rules
	const deleteAllBtn = document.getElementById('cu-delete-all-rules-btn');
	if (deleteAllBtn) {
		deleteAllBtn.addEventListener('click', async function () {
			if (!confirm('Delete ALL rules? This cannot be undone.\n\nTip: export a backup first in the Settings tab.')) return;
			this.disabled = true; this.textContent = 'Deleting…';
			try {
				const r = await api('DELETE', '/rules/delete-all');
				notice(`Deleted all ${r.deleted} rule(s).`);
				// Remove all rows from the table and reset counter
				document.querySelectorAll('#the-list tr').forEach(row => row.remove());
				updateRulesCount(-Infinity); // set to 0
				const countEl = document.getElementById('cu-total-rules-count');
				if (countEl) countEl.textContent = '0 total rules';
				this.remove(); // button no longer needed
			} catch (err) {
				notice('Error: ' + err.message, 'error');
				this.disabled = false; this.textContent = 'Delete All Rules';
			}
		});
	}

	// Kill switch
	const ksBtn = document.getElementById('cu-killswitch-btn');
	if (ksBtn) {
		ksBtn.addEventListener('click', async function () {
			const active = this.dataset.active === '1';
			const msg = active ? 'Deactivate the kill switch? Rules will resume.' : 'Activate the kill switch? ALL assets load normally sitewide until deactivated.';
			if (!confirm(msg)) return;
			this.disabled = true; this.textContent = 'Working…';
			try {
				const r = await api('POST', '/killswitch', { confirmed: true });
				notice('Kill switch ' + (r.active ? 'activated.' : 'deactivated.'));
				setTimeout(() => location.reload(), 800);
			} catch (err) {
				notice('Error: ' + err.message, 'error');
				this.disabled = false; this.textContent = active ? 'Deactivate Kill Switch' : 'Activate Kill Switch';
			}
		});
	}

	// Group toggle
	document.addEventListener('click', async function (e) {
		const btn = e.target.closest('.cu-group-toggle-btn');
		if (!btn) return;
		const id = btn.dataset.id, enabled = btn.dataset.enabled === '1';
		btn.disabled = true; btn.textContent = 'Working…';
		try {
			await api('PATCH', `/groups/${id}`, { enabled: enabled ? 0 : 1 });
			notice('Group ' + (enabled ? 'disabled.' : 'enabled.'));
			setTimeout(() => location.reload(), 500);
		} catch (err) { notice('Error: ' + err.message, 'error'); btn.disabled = false; btn.textContent = enabled ? 'Disable Group' : 'Enable Group'; }
	});

	// Group delete
	document.addEventListener('click', async function (e) {
		const btn = e.target.closest('.cu-group-delete-btn');
		if (!btn) return;
		if (!confirm('Delete this group? Rules will become ungrouped.')) return;
		btn.disabled = true;
		try {
			await api('DELETE', `/groups/${btn.dataset.id}`);
			const card = btn.closest('.cu-group-card');
			if (card) card.remove();
			notice('Group deleted.');
		} catch (err) { notice('Error: ' + err.message, 'error'); btn.disabled = false; }
	});

	// Create group
	const createGroupBtn = document.getElementById('cu-create-group-btn');
	if (createGroupBtn) {
		createGroupBtn.addEventListener('click', async function () {
			const nameEl = document.getElementById('cu-new-group-name');
			const descEl = document.getElementById('cu-new-group-desc');
			const name = nameEl ? nameEl.value.trim() : '';
			if (!name) { notice('Group name is required.', 'warning'); return; }
			this.disabled = true; this.textContent = 'Creating…';
			try {
				await api('POST', '/groups', { name, description: descEl ? descEl.value.trim() : '' });
				notice('Group created.');
				setTimeout(() => location.reload(), 500);
			} catch (err) { notice('Error: ' + err.message, 'error'); this.disabled = false; this.textContent = 'Create Group'; }
		});
	}

	// Clear log
	const clearLogBtn = document.getElementById('cu-clear-log-btn');
	if (clearLogBtn) {
		clearLogBtn.addEventListener('click', async function () {
			if (!confirm('Clear the entire audit log? This cannot be undone.')) return;
			this.disabled = true; this.textContent = 'Clearing…';
			try {
				await api('DELETE', '/log', { confirmed: true });
				notice('Audit log cleared.');
				setTimeout(() => location.reload(), 800);
			} catch (err) { notice('Error: ' + err.message, 'error'); this.disabled = false; this.textContent = 'Clear Log'; }
		});
	}

	// ---- View Rules modal ----
	function buildViewRulesModal() {
		if (document.getElementById('cu-view-rules-modal')) return;
		const modal = document.createElement('div');
		modal.id = 'cu-view-rules-modal';
		modal.style.cssText = 'display:none;position:fixed;inset:0;z-index:100100;background:rgba(0,0,0,.55);align-items:center;justify-content:center;';
		modal.innerHTML = `
		<div style="background:#fff;border-radius:6px;padding:24px 28px;max-width:680px;width:95%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.22);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
			<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
				<h2 id="cu-vrm-title" style="margin:0;font-size:16px;font-weight:600;"></h2>
				<button id="cu-vrm-close" class="button" style="min-width:0;padding:4px 10px;">&times;</button>
			</div>
			<div id="cu-vrm-body" style="overflow-y:auto;flex:1;"></div>
			<div id="cu-vrm-pagination" style="display:none;margin-top:14px;display:flex;align-items:center;gap:10px;justify-content:space-between;border-top:1px solid #eee;padding-top:12px;">
				<button id="cu-vrm-prev" class="button">&larr; Previous</button>
				<span id="cu-vrm-page-info" style="font-size:12px;color:#555;"></span>
				<button id="cu-vrm-next" class="button">Next &rarr;</button>
			</div>
		</div>`;
		document.body.appendChild(modal);
		modal.addEventListener('click', function(e) { if (e.target === modal) closeViewRulesModal(); });
		document.getElementById('cu-vrm-close').addEventListener('click', closeViewRulesModal);
	}

	function closeViewRulesModal() {
		const modal = document.getElementById('cu-view-rules-modal');
		if (modal) modal.style.display = 'none';
	}

	// State for current modal pagination
	var vrmState = { groupId: null, groupName: '', page: 1, totalPages: 1, perPage: 50 };

	async function loadViewRulesPage(page) {
		const body = document.getElementById('cu-vrm-body');
		const pagination = document.getElementById('cu-vrm-pagination');
		const pageInfo = document.getElementById('cu-vrm-page-info');
		const prevBtn = document.getElementById('cu-vrm-prev');
		const nextBtn = document.getElementById('cu-vrm-next');

		body.innerHTML = '<p style="color:#555;font-size:13px;">Loading…</p>';

		try {
			const data = await api('GET', '/rules?group_id=' + encodeURIComponent(vrmState.groupId) +
				'&per_page=' + vrmState.perPage + '&page=' + page);
			const rules = data.rows || [];
			const total = data.total || rules.length;
			vrmState.page = page;
			vrmState.totalPages = Math.max(1, Math.ceil(total / vrmState.perPage));

			if (!rules.length) {
				body.innerHTML = '<p style="color:#555;font-size:13px;">No rules in this group.</p>';
				pagination.style.display = 'none';
				return;
			}

			let html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
			html += '<thead><tr style="background:#f6f7f7;">';
			html += '<th style="text-align:left;padding:7px 10px;border-bottom:2px solid #ddd;font-weight:600;">Handle</th>';
			html += '<th style="text-align:left;padding:7px 10px;border-bottom:2px solid #ddd;font-weight:600;">Type</th>';
			html += '<th style="text-align:left;padding:7px 10px;border-bottom:2px solid #ddd;font-weight:600;">URL Pattern</th>';
			html += '<th style="text-align:left;padding:7px 10px;border-bottom:2px solid #ddd;font-weight:600;">Match</th>';
			html += '</tr></thead><tbody>';

			rules.forEach(function(rule, i) {
				const bg = (i % 2 === 0) ? '#fff' : '#f9f9f9';
				html += '<tr style="background:' + bg + ';">';
				html += '<td style="padding:7px 10px;border-bottom:1px solid #eee;font-family:monospace;">' + escHtml(rule.asset_handle) + '</td>';
				html += '<td style="padding:7px 10px;border-bottom:1px solid #eee;">' + escHtml(rule.asset_type) + '</td>';
				html += '<td style="padding:7px 10px;border-bottom:1px solid #eee;font-family:monospace;word-break:break-all;">' + escHtml(rule.url_pattern) + '</td>';
				html += '<td style="padding:7px 10px;border-bottom:1px solid #eee;">' + escHtml(rule.match_type) + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			body.innerHTML = html;

			// Pagination controls
			if (vrmState.totalPages > 1) {
				pagination.style.display = 'flex';
				pageInfo.textContent = 'Page ' + vrmState.page + ' of ' + vrmState.totalPages + ' (' + total + ' rules)';
				prevBtn.disabled = (vrmState.page <= 1);
				nextBtn.disabled = (vrmState.page >= vrmState.totalPages);
			} else {
				pagination.style.display = 'none';
			}
		} catch (err) {
			body.innerHTML = '<p style="color:#b00020;font-size:13px;">Error: ' + escHtml(err.message) + '</p>';
			pagination.style.display = 'none';
		}
	}

	async function openViewRulesModal(groupId, groupName) {
		buildViewRulesModal();
		const modal = document.getElementById('cu-view-rules-modal');
		const title = document.getElementById('cu-vrm-title');

		vrmState.groupId = groupId;
		vrmState.groupName = groupName;
		title.textContent = groupName;
		modal.style.display = 'flex';

		// Wire pagination buttons (clone to clear old listeners)
		const prevBtn = document.getElementById('cu-vrm-prev');
		const nextBtn = document.getElementById('cu-vrm-next');
		const newPrev = prevBtn.cloneNode(true);
		const newNext = nextBtn.cloneNode(true);
		prevBtn.parentNode.replaceChild(newPrev, prevBtn);
		nextBtn.parentNode.replaceChild(newNext, nextBtn);
		newPrev.addEventListener('click', function() { if (vrmState.page > 1) loadViewRulesPage(vrmState.page - 1); });
		newNext.addEventListener('click', function() { if (vrmState.page < vrmState.totalPages) loadViewRulesPage(vrmState.page + 1); });

		await loadViewRulesPage(1);
	}

	// View Rules button click
	document.addEventListener('click', function(e) {
		const btn = e.target.closest('.cu-group-view-rules-btn');
		if (!btn) return;
		openViewRulesModal(btn.dataset.id, btn.dataset.name);
	});

})();
