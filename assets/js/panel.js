/**
 * Code Unloader — Frontend Panel
 * panel.js v10
 *
 * v10 changes:
 * - Live sync: rule_map and groups re-fetched from REST on every panel open
 *   so changes made in the admin screen are reflected immediately
 */

var cuTogglePanel = function () {
	var panel = document.getElementById('cu-panel');
	if (!panel) return false;
	if (panel.hasAttribute('inert')) { cuOpenPanel(); } else { cuClosePanel(); }
	return false;
};

var cuOpenPanel = function () {
	var panel = document.getElementById('cu-panel');
	if (!panel) return;
	panel.removeAttribute('inert');
	panel.classList.add('cu-panel--open');
	if (!window._cuRendered) {
		// First open: render immediately with baked-in PHP data, then refresh in background.
		window._cuRendered = true;
		if (window._cu) {
			window._cu.renderAssets();
			window._cu.renderInlineBlocks();
			window._cu.syncData(); // background refresh to catch any admin changes
		}
	} else {
		// Subsequent opens: always sync so admin changes are reflected immediately.
		if (window._cu) window._cu.syncData();
	}
};

var cuClosePanel = function () {
	var panel = document.getElementById('cu-panel');
	if (!panel) return;
	panel.classList.remove('cu-panel--open');
	setTimeout(function () {
		if (!panel.classList.contains('cu-panel--open')) panel.setAttribute('inert', '');
	}, 280);
	// Remove ?wpcu from URL so refresh won't reopen
	try {
		var u = new URL(window.location.href);
		if (u.searchParams.has('wpcu')) {
			u.searchParams.delete('wpcu');
			history.replaceState(null, '', u.toString());
		}
	} catch (ignore) {}
};

(function () {
	'use strict';
	/* eslint-disable no-console */
	console.log('[Code Unloader] panel.js v10 loaded');

	var D       = window.CDUNLOADER_DATA || {};
	var API     = D.api_base     || '';
	var NONCE   = D.nonce        || '';
	var assets  = D.assets       || [];
	var ruleMap = D.rule_map     || {};
	var groups  = D.groups       || [];
	var pageUrl = D.page_url     || '';

	/* -----------------------------------------------------------------------
	   Theme
	   ----------------------------------------------------------------------- */
	var THEME_KEY    = 'cdunloader_theme';
	var currentTheme = localStorage.getItem(THEME_KEY) || 'light';

	function applyTheme(theme) {
		currentTheme = theme;
		var panel  = document.getElementById('cu-panel');
		var dialog = document.getElementById('cu-dialog');
		if (panel)  panel.setAttribute('data-theme', theme);
		if (dialog) dialog.setAttribute('data-theme', theme);
		var btn = document.getElementById('cu-theme-toggle');
		if (btn) btn.textContent = theme === 'dark' ? '☀️' : '🌙';
		localStorage.setItem(THEME_KEY, theme);
	}

	/* -----------------------------------------------------------------------
	   Dock side (left / right)
	   ----------------------------------------------------------------------- */
	var DOCK_KEY  = 'cdunloader_dock';
	var dockSide  = localStorage.getItem(DOCK_KEY) || 'right';

	function applyDock(side) {
		dockSide = side;
		var panel = document.getElementById('cu-panel');
		if (!panel) return;
		panel.classList.toggle('cu-panel--left', side === 'left');
		var btn = document.getElementById('cu-dock-toggle');
		if (btn) {
			btn.textContent  = side === 'left' ? '▶' : '◀';
			btn.title        = side === 'left' ? 'Dock to right side' : 'Dock to left side';
			btn.setAttribute('aria-label', btn.title);
		}
		localStorage.setItem(DOCK_KEY, side);
	}

	/* -----------------------------------------------------------------------
	   First-use warning
	   ----------------------------------------------------------------------- */
	var WARN_KEY = 'cdunloader_no_warn';

	function showWarningIfNeeded() {
		if (localStorage.getItem(WARN_KEY) === '1') return;
		var banner = document.getElementById('cu-first-use-warning');
		if (banner) banner.hidden = false;
	}

	/* -----------------------------------------------------------------------
	   API
	   ----------------------------------------------------------------------- */
	function api(method, path, body) {
		return fetch(API + path, {
			method: method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: body ? JSON.stringify(body) : undefined,
		}).then(function (r) {
			return r.json().then(function (d) {
				if (!r.ok) throw new Error(d.message || 'HTTP ' + r.status);
				return d;
			});
		});
	}

	function esc(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function formatSize(bytes) {
		bytes = parseInt(bytes, 10) || 0;
		if (bytes === 0) return '';
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
	}

	function groupTotalSize(groupAssets) {
		var total = 0;
		groupAssets.forEach(function(a) { total += parseInt(a.size, 10) || 0; });
		return total;
	}

	function notify(msg, type) {
		var el = document.createElement('div');
		el.className = 'cu-notification cu-notification--' + (type || 'success');
		el.setAttribute('data-theme', currentTheme);
		el.textContent = msg;
		document.body.appendChild(el);
		setTimeout(function () { if (el.parentNode) el.remove(); }, 3200);
	}

	/* -----------------------------------------------------------------------
	   _cu — render + logic
	   ----------------------------------------------------------------------- */
	var _cu = window._cu = {

		renderAssets: function () {
			var container = document.getElementById('cu-assets-tab');
			if (!container) return;

			var q = '';
			var searchEl = document.getElementById('cu-search');
			if (searchEl) q = searchEl.value.toLowerCase();

			var grouped = {};
			assets.forEach(function (a) {
				if (q) {
					if ((a.handle||'').toLowerCase().indexOf(q) === -1 &&
					    (a.src   ||'').toLowerCase().indexOf(q) === -1) return;
				}
				var label = a.source_label || 'Unknown / External';
				if (!grouped[label]) grouped[label] = [];
				grouped[label].push(a);
			});

			var labels = Object.keys(grouped).sort();
			if (!labels.length) {
				container.innerHTML = '<p class="cu-empty">No assets match your filter.</p>';
				return;
			}

			var html = '';
			labels.forEach(function (label) {
				var key       = 'cu-g-' + encodeURIComponent(label);
				var collapsed = sessionStorage.getItem(key) === '1';

				// Count active (not disabled) in this group
				var activeCount = grouped[label].filter(function(a){ return !ruleMap[a.handle + '|' + a.type]; }).length;
				var totalCount  = grouped[label].length;

				html += '<div class="cu-source-group' + (collapsed ? ' cu-source-group--collapsed' : '') + '" data-group-key="' + esc(key) + '" data-group-label="' + esc(label) + '">';
				html += '<div class="cu-source-header-row">';
				html += '<button class="cu-source-header" type="button">';
				html += '<span class="cu-source-label">' + esc(label) + '</span>';
				html += '<span class="cu-source-count">' + totalCount + '</span>';
				var groupSize = groupTotalSize(grouped[label]);
				if (groupSize > 0) {
					html += '<span class="cu-source-size">' + formatSize(groupSize) + '</span>';
				}
				html += '<span class="cu-chevron" aria-hidden="true">▾</span>';
				html += '</button>';

				// Group action buttons
				html += '<div class="cu-group-actions">';
				if (activeCount > 0) {
					html += '<button class="cu-group-disable-all cu-group-action-btn" data-group-label="' + esc(label) + '" title="Disable all active assets in this group">Disable all</button>';
				}
				if (activeCount < totalCount) {
					html += '<button class="cu-group-enable-all cu-group-action-btn cu-group-action-btn--enable" data-group-label="' + esc(label) + '" title="Re-enable all disabled assets in this group">Enable all</button>';
				}
				html += '</div>';

				html += '</div>'; // .cu-source-header-row
				html += '<div class="cu-source-assets">';
				grouped[label].forEach(function (a) { html += _cu.assetRow(a); });
				html += '</div></div>';
			});

			container.innerHTML = html;
			_cu.bindEvents(container);
			showWarningIfNeeded();
			_cu.updateStatsBar();
		},

		assetRow: function (a) {
			var rule     = ruleMap[a.handle + '|' + a.type];
			var disabled = !!rule;

			var badge = '';
			if (rule) {
				if (rule.group_id) {
					var gname = '';
					groups.forEach(function (g) { if (String(g.id) === String(rule.group_id)) gname = g.name; });
					badge = '<span class="cu-badge cu-badge--blue">Group' + (gname ? ': ' + esc(gname) : '') + '</span>'
					      + ' <span class="cu-badge cu-badge--red">Disabled (' + esc(rule.match_type) + ')</span>';
				} else if (rule.match_type === 'exact') {
					badge = '<span class="cu-badge cu-badge--red">Disabled (exact)</span>';
				} else {
					badge = '<span class="cu-badge cu-badge--orange" title="' + esc(rule.url_pattern) + '">Disabled (' + esc(rule.match_type) + ')</span>';
				}
				if (rule.condition_type) {
					badge += ' <span class="cu-badge cu-badge--purple">' + esc(rule.condition_type.split(':')[0]) + (rule.condition_invert ? ' ¬' : '') + '</span>';
				}
			} else {
				badge = '<span class="cu-badge cu-badge--green">Active</span>';
			}

			var filename = '';
			if (a.src) filename = a.src.split('/').pop().split('?')[0];

			var noteIcon = (rule && rule.label)
				? ' <span class="cu-note" title="' + esc(rule.label) + '">📝</span>' : '';

			var isActive = !disabled;
			var rowClass = 'cu-asset-row' + (disabled ? ' cu-asset-row--disabled' : '');

			return '<div class="' + rowClass + '" data-handle="' + esc(a.handle) + '">' +
				'<span class="cu-type-pill cu-type-' + esc(a.type) + '">' + esc(a.type.toUpperCase()) + '</span>' +
				'<div class="cu-asset-info">' +
					'<div class="cu-asset-handle">' + esc(a.handle) + noteIcon + '</div>' +
					(filename ? '<div class="cu-asset-src">' + esc(filename) + '</div>' : '') +
					'<div class="cu-asset-badges">' + badge + '</div>' +
				'</div>' +
				(a.size ? '<div class="cu-asset-size">' + formatSize(a.size) + '</div>' : '') +
				'<label class="cu-toggle" title="' + (isActive ? 'Click to disable' : 'Click to re-enable') + '">' +
					'<input type="checkbox" class="cu-asset-cb"' +
						(isActive ? ' checked' : '') +
						' data-handle="' + esc(a.handle) + '"' +
						' data-type="' + esc(a.type) + '"' +
						' data-source="' + esc(a.source_label || '') + '"' +
						' data-rule-id="' + (rule ? esc(String(rule.id)) : '') + '">' +
					'<span class="cu-toggle-track"><span class="cu-toggle-thumb"></span></span>' +
				'</label>' +
			'</div>';
		},

		bindEvents: function (container) {
			// Group collapse
			container.querySelectorAll('.cu-source-header').forEach(function (h) {
				h.addEventListener('click', function () {
					var g = this.closest('.cu-source-group');
					g.classList.toggle('cu-source-group--collapsed');
					var k = g.dataset.groupKey;
					if (k) sessionStorage.setItem(k, g.classList.contains('cu-source-group--collapsed') ? '1' : '0');
				});
			});

			// "Disable all" button
			container.querySelectorAll('.cu-group-disable-all').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					var label = this.dataset.groupLabel;
					_cu.disableAllInGroup(label);
				});
			});

			// "Enable all" button
			container.querySelectorAll('.cu-group-enable-all').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					var label = this.dataset.groupLabel;
					_cu.enableAllInGroup(label);
				});
			});

			// Individual asset toggles
			container.querySelectorAll('.cu-asset-cb').forEach(function (cb) {
				cb.addEventListener('change', function () {
					var handle = this.dataset.handle;
					var type   = this.dataset.type;
					var source = this.dataset.source;
					var ruleId = this.dataset.ruleId;

					if (!this.checked) {
						this.checked = true;
						_cu.openDialog(handle, type, source, this);
					} else {
						if (ruleId) {
							_cu.enableAsset(ruleId, handle, type, this);
						} else {
							this.checked = true;
						}
					}
				});
			});
		},

		/* -------------------------------------------------------------------
		   Group-level bulk disable / enable
		   ------------------------------------------------------------------- */
		disableAllInGroup: function (label) {
			var groupAssets = assets.filter(function (a) {
				return (a.source_label || 'Unknown / External') === label && !ruleMap[a.handle + '|' + a.type];
			});
			if (!groupAssets.length) return;

			var btn = document.querySelector('.cu-group-disable-all[data-group-label="' + CSS.escape(label) + '"]');
			if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }

			var promises = groupAssets.map(function (a) {
				var body = {
					url_pattern:      pageUrl,
					match_type:       'exact',
					asset_handle:     a.handle,
					asset_type:       a.type,
					source_label:     a.source_label || '',
					device_type:      'all',
					condition_type:   null,
					condition_value:  null,
					condition_invert: 0,
					group_id:         null,
					label:            'Bulk disable: ' + label,
				};
				return api('POST', '/rules', body).then(function (result) {
					ruleMap[a.handle + '|' + a.type] = Object.assign({ id: result.id }, body);
				}).catch(function () { /* skip individual failures */ });
			});

			Promise.all(promises).then(function () {
				_cu.renderAssets();
				notify('Disabled ' + groupAssets.length + ' assets in ' + label, 'success');
			});
		},

		enableAllInGroup: function (label) {
			var groupAssets = assets.filter(function (a) {
				return (a.source_label || 'Unknown / External') === label && ruleMap[a.handle + '|' + a.type];
			});
			if (!groupAssets.length) return;

			var btn = document.querySelector('.cu-group-enable-all[data-group-label="' + CSS.escape(label) + '"]');
			if (btn) { btn.disabled = true; btn.textContent = 'Working…'; }

			var promises = groupAssets.map(function (a) {
				var rule = ruleMap[a.handle + '|' + a.type];
				if (!rule || !rule.id) return Promise.resolve();
				return api('DELETE', '/rules/' + rule.id).then(function () {
					delete ruleMap[a.handle + '|' + a.type];
				}).catch(function () {});
			});

			Promise.all(promises).then(function () {
				_cu.renderAssets();
				notify('Re-enabled ' + groupAssets.length + ' assets in ' + label, 'success');
			});
		},

		enableAsset: function (ruleId, handle, type, cb) {
			var toggle = cb.closest('.cu-toggle');
			if (toggle) toggle.classList.add('cu-toggle--loading');
			cb.disabled = true;

			api('DELETE', '/rules/' + ruleId)
				.then(function () {
					delete ruleMap[handle + '|' + type];
					_cu.replaceRow(handle, type);
					notify('Re-enabled: ' + handle, 'success');
				})
				.catch(function (e) {
					cb.checked = false;
					cb.disabled = false;
					if (toggle) toggle.classList.remove('cu-toggle--loading');
					notify('Error: ' + e.message, 'error');
				});
		},

		replaceRow: function (handle, type) {
			var row = null;
			var sh = handle; try { sh = CSS.escape(handle); } catch(e) {}
			document.querySelectorAll('.cu-asset-row[data-handle="' + sh + '"]').forEach(function(el) {
				var cb = el.querySelector('.cu-asset-cb');
				if (cb && cb.dataset.type === type) row = el;
			});
			if (!row) { _cu.renderAssets(); return; }

			var a = null;
			assets.forEach(function (x) { if (x.handle === handle && x.type === type) a = x; });
			if (!a) return;

			var tmp = document.createElement('div');
			tmp.innerHTML = _cu.assetRow(a);
			var newRow = tmp.firstChild;
			row.parentNode.replaceChild(newRow, row);
			var parent = newRow.closest('.cu-source-assets') || document.getElementById('cu-assets-tab');
			if (parent) _cu.bindEvents(parent);

			// Re-render header row counts
			var groupEl = newRow.closest('.cu-source-group');
			if (groupEl) _cu.updateGroupHeader(groupEl);
			// Item 1: keep stats bar in sync after single-row toggle
			_cu.updateStatsBar();
		},

		// Update the disabled-files summary bar (items 1, 2, 5, 6)
		updateStatsBar: function () {
			var container = document.getElementById('cu-assets-tab');
			if (!container) return;

			var disabledAssets = assets.filter(function (a) { return !!ruleMap[a.handle + '|' + a.type]; });
			var disabledCount  = disabledAssets.length;
			var disabledSize   = disabledAssets.reduce(function (sum, a) { return sum + (a.size || 0); }, 0);

			var statsBar = document.getElementById('cu-disabled-stats-bar');
			if (!statsBar) {
				statsBar = document.createElement('div');
				statsBar.id = 'cu-disabled-stats-bar';
				statsBar.className = 'cu-disabled-stats-bar';
				container.parentNode.insertBefore(statsBar, container);
			}

			if (disabledCount > 0) {
				// Item 5: "X files" is a link that scrolls to the first disabled row
				var filesLink = '<a id="cu-stats-files-link" href="#" class="cu-stats-files-link">'
					+ disabledCount + ' file' + (disabledCount !== 1 ? 's' : '') + '</a>';
				// Item 6: "Unloaded from this URL:" instead of "Reduced by:"
				statsBar.innerHTML = 'Disabled on this URL: ' + filesLink
					+ ' &nbsp;·&nbsp; Unloaded from this URL: ' + esc(formatSize(disabledSize))
					+ ' &nbsp;<button class="cu-stats-reenable-all" title="Re-enable all disabled assets on this URL">Re-enable all</button>';
				statsBar.style.display = '';

				// Bind the files link — scroll to first disabled row
				var link = document.getElementById('cu-stats-files-link');
				if (link) {
					link.addEventListener('click', function (e) {
						e.preventDefault();
						var firstDisabled = container.querySelector('.cu-asset-row--disabled');
						if (firstDisabled) {
							// Expand the parent group if collapsed
							var grp = firstDisabled.closest('.cu-source-group');
							if (grp && grp.classList.contains('cu-source-group--collapsed')) {
								grp.classList.remove('cu-source-group--collapsed');
								var key = grp.dataset.groupKey;
								if (key) sessionStorage.removeItem(key);
							}
							firstDisabled.scrollIntoView({ behavior: 'smooth', block: 'center' });
						}
					});
				}

				// Bind re-enable all button
				var reBtn = statsBar.querySelector('.cu-stats-reenable-all');
				if (reBtn) {
					reBtn.addEventListener('click', function () {
						this.disabled = true;
						this.textContent = 'Working…';
						var all = assets.filter(function (a) { return !!ruleMap[a.handle + '|' + a.type]; });
						var promises = all.map(function (a) {
							var rule = ruleMap[a.handle + '|' + a.type];
							if (!rule || !rule.id) return Promise.resolve();
							return api('DELETE', '/rules/' + rule.id).then(function () {
								delete ruleMap[a.handle + '|' + a.type];
							}).catch(function () {});
						});
						Promise.all(promises).then(function () {
							_cu.renderAssets();
							notify('Re-enabled ' + all.length + ' assets', 'success');
						});
					});
				}
			} else {
				statsBar.style.display = 'none';
			}
		},

		// Re-render the action buttons count in a group's header row
		updateGroupHeader: function (groupEl) {
			var label      = groupEl.dataset.groupLabel;
			if (!label) return;
			var groupAssets = assets.filter(function(a){ return (a.source_label||'Unknown / External') === label; });
			var activeCount = groupAssets.filter(function(a){ return !ruleMap[a.handle + '|' + a.type]; }).length;
			var actionsEl   = groupEl.querySelector('.cu-group-actions');
			if (!actionsEl) return;

			var html = '';
			if (activeCount > 0) {
				html += '<button class="cu-group-disable-all cu-group-action-btn" data-group-label="' + esc(label) + '" title="Disable all active assets in this group">Disable all</button>';
			}
			if (activeCount < groupAssets.length) {
				html += '<button class="cu-group-enable-all cu-group-action-btn cu-group-action-btn--enable" data-group-label="' + esc(label) + '" title="Re-enable all disabled assets in this group">Enable all</button>';
			}
			actionsEl.innerHTML = html;

			// Re-bind the new buttons
			if (actionsEl.querySelector('.cu-group-disable-all')) {
				actionsEl.querySelector('.cu-group-disable-all').addEventListener('click', function(e){
					e.stopPropagation(); _cu.disableAllInGroup(this.dataset.groupLabel);
				});
			}
			if (actionsEl.querySelector('.cu-group-enable-all')) {
				actionsEl.querySelector('.cu-group-enable-all').addEventListener('click', function(e){
					e.stopPropagation(); _cu.enableAllInGroup(this.dataset.groupLabel);
				});
			}
		},

		/* -------------------------------------------------------------------
		   Dialog
		   ------------------------------------------------------------------- */
		_dHandle: null, _dType: null, _dSource: null, _dCb: null,

		openDialog: function (handle, type, source, cb) {
			_cu._dHandle = handle;
			_cu._dType   = type;
			_cu._dSource = source;
			_cu._dCb     = cb;

			var dialog = document.getElementById('cu-dialog');
			if (!dialog) return;

			var info = document.getElementById('cu-dialog-asset-info');
			if (info) info.textContent = handle + ' · ' + type.toUpperCase();

			var err = document.getElementById('cu-dialog-error');
			if (err) { err.hidden = true; err.textContent = ''; }

			document.querySelectorAll('input[name="cu-scope"]').forEach(function (r) { r.checked = r.value === 'exact'; });
			document.querySelectorAll('.cu-scope-btn').forEach(function (b) {
				b.classList.toggle('cu-scope-active', b.dataset.scope === 'exact');
			});
			_cu.setScope('exact');

			var urlInput = document.getElementById('cu-url-pattern');
			if (urlInput) urlInput.value = pageUrl;
			document.querySelectorAll('input[name="cu-match-type"]').forEach(function (r) { r.checked = r.value === 'wildcard'; });
			var rw = document.getElementById('cu-regex-warning');
			if (rw) rw.hidden = true;
			['cu-device-type','cu-condition-type'].forEach(function (id) {
				var el = document.getElementById(id);
				if (el) el.selectedIndex = 0;
			});
			var cw = document.getElementById('cu-condition-value-wrap');
			if (cw) cw.hidden = true;
			var cv = document.getElementById('cu-condition-value');
			if (cv) cv.value = '';
			var ci = document.getElementById('cu-condition-invert');
			if (ci) ci.checked = false;
			var lb = document.getElementById('cu-label');
			if (lb) lb.value = '';

			_cu.populateGroups();
			_cu.showDepWarning(handle, dialog);

			// Wire "create new group" sentinel — show/hide inline input
			var groupSel   = document.getElementById('cu-group-id');
			var newGrpWrap = document.getElementById('cu-new-group-wrap');
			if (groupSel && newGrpWrap) {
				newGrpWrap.style.display = 'none';
				groupSel.addEventListener('change', function () {
					newGrpWrap.style.display = (this.value === '__new__') ? '' : 'none';
					if (this.value === '__new__') {
						var ni = document.getElementById('cu-new-group-name');
						if (ni) ni.focus();
					}
				});
			}

			dialog.removeAttribute('hidden');
			dialog.setAttribute('data-theme', currentTheme);
		},

		closeDialog: function (revert) {
			var dialog = document.getElementById('cu-dialog');
			if (dialog) dialog.hidden = true;
			if (revert && _cu._dCb) _cu._dCb.checked = true;
			_cu._dHandle = _cu._dType = _cu._dSource = _cu._dCb = null;
		},

		setScope: function (scope) {
			var wrap = document.getElementById('cu-custom-pattern-wrap');
			if (wrap) wrap.hidden = scope !== 'custom';
			// For except_here, condition fields are auto-managed — grey them out
			var condWrap = document.getElementById('cu-condition-wrap');
			if (condWrap) condWrap.style.opacity = (scope === 'except_here') ? '0.4' : '';
			if (condWrap) condWrap.style.pointerEvents = (scope === 'except_here') ? 'none' : '';
		},

		populateGroups: function () {
			var sel = document.getElementById('cu-group-id');
			if (!sel) return;
			while (sel.options.length > 1) sel.remove(1);
			groups.forEach(function (g) {
				var o = document.createElement('option');
				o.value = g.id;
				o.textContent = g.name;
				sel.appendChild(o);
			});
			// "＋ Create new group" sentinel option
			var newOpt = document.createElement('option');
			newOpt.value = '__new__';
			newOpt.textContent = '＋ Create new group';
			sel.appendChild(newOpt);
		},

		showDepWarning: function (handle, dialog) {
			var old = dialog.querySelector('.cu-dep-warning');
			if (old) old.remove();
			var deps = assets.filter(function (a) { return a.deps && a.deps.indexOf(handle) !== -1; });
			if (!deps.length) return;
			var w = document.createElement('div');
			w.className = 'cu-dep-warning';
			w.innerHTML = '⚠ <strong>' + deps.length + '</strong> asset(s) depend on this: <em>' +
				esc(deps.map(function(a){ return a.handle; }).join(', ')) + '</em>';
			var actions = dialog.querySelector('.cu-dialog-actions');
			if (actions) actions.parentNode.insertBefore(w, actions);
		},

		saveRule: function () {
			if (!_cu._dHandle) return;
			var err     = document.getElementById('cu-dialog-error');
			var saveBtn = document.getElementById('cu-dialog-save');

			var scope = (document.querySelector('input[name="cu-scope"]:checked') || {}).value || 'exact';
			var matchType, urlPattern;
			if      (scope === 'exact')       { matchType = 'exact';    urlPattern = pageUrl; }
			else if (scope === 'except_here') { matchType = 'wildcard'; urlPattern = '/*'; }
			else if (scope === 'sitewide')    { matchType = 'wildcard'; urlPattern = '/*'; }
			else if (scope === 'all_pages')   { matchType = 'wildcard'; urlPattern = '/*'; }
			else if (scope === 'all_posts')   { matchType = 'wildcard'; urlPattern = '/*'; }
			else {
				matchType  = (document.querySelector('input[name="cu-match-type"]:checked') || {}).value || 'wildcard';
				urlPattern = ((document.getElementById('cu-url-pattern') || {}).value || '').trim() || pageUrl;
			}

			var condType   = ((document.getElementById('cu-condition-type')  || {}).value || '');
			var condVal    = (((document.getElementById('cu-condition-value') || {}).value) || '').trim();
			var condInvert = ((document.getElementById('cu-condition-invert') || {}).checked) ? 1 : 0;

			// "Everywhere except here" = sitewide wildcard rule with current URL excluded via exact_url condition (inverted)
			if (scope === 'except_here') {
				condType   = 'exact_url:' + pageUrl;
				condInvert = 1;
			}
			if (scope === 'all_pages' && !condType) condType = 'is_post_type:page';
			if (scope === 'all_posts' && !condType) condType = 'is_post_type:post';
			if (condType && condVal && condType.indexOf(':') === -1) condType = condType + ':' + condVal;

			var groupEl  = document.getElementById('cu-group-id');
			var labelEl  = document.getElementById('cu-label');
			var deviceEl = document.getElementById('cu-device-type');

			saveBtn.disabled = true;
			saveBtn.textContent = 'Saving…';
			if (err) err.hidden = true;

			// Resolve group_id — if user chose "create new group", create it first
			var resolveGroupId = Promise.resolve(null);
			if (groupEl && groupEl.value === '__new__') {
				var newNameEl = document.getElementById('cu-new-group-name');
				var newName   = newNameEl ? newNameEl.value.trim() : '';
				if (newName) {
					resolveGroupId = api('POST', '/groups', { name: newName, description: '' })
						.then(function (created) {
							var gid = parseInt(created.id, 10);
							groups.push({ id: gid, name: newName, rule_count: 0 });
							return gid;
						});
				}
			} else if (groupEl && groupEl.value && groupEl.value !== '') {
				resolveGroupId = Promise.resolve(parseInt(groupEl.value, 10));
			}

			resolveGroupId.then(function (groupId) {
				var body = {
					url_pattern:      urlPattern,
					match_type:       matchType,
					asset_handle:     _cu._dHandle,
					asset_type:       _cu._dType,
					source_label:     _cu._dSource || '',
					device_type:      deviceEl ? deviceEl.value : 'all',
					condition_type:   condType  || null,
					condition_value:  null,
					condition_invert: condInvert,
					group_id:         groupId,
					label:            (labelEl && labelEl.value.trim()) ? labelEl.value.trim() : null,
				};

			return api('POST', '/rules', body)
				.then(function (result) {
					ruleMap[_cu._dHandle + '|' + _cu._dType] = Object.assign({ id: result.id }, body);
					var handle = _cu._dHandle;
					var type   = _cu._dType;
					_cu.closeDialog(false);
					_cu.replaceRow(handle, type);
					notify('Rule saved: ' + handle, 'success');
				});
			})
			.catch(function (e) {
					if (err) { err.textContent = e.message; err.hidden = false; }
				})
				.finally(function () {
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save Rule';
				});
		},
	};

	/* -----------------------------------------------------------------------
	   Live sync — fetch fresh rule_map + groups from REST API on panel open
	   so changes made in the admin screen are reflected without a page reload.
	   ----------------------------------------------------------------------- */
	_cu.syncData = function () {
		if (!API || !NONCE || !pageUrl) return;

		// Fetch fresh rule_map for this page
		var rulesPromise = fetch(API + '/assets?page_url=' + encodeURIComponent(pageUrl), {
			method: 'GET',
			headers: { 'X-WP-Nonce': NONCE },
		}).then(function (r) { return r.ok ? r.json() : null; });

		// Fetch fresh groups list
		var groupsPromise = fetch(API + '/groups', {
			method: 'GET',
			headers: { 'X-WP-Nonce': NONCE },
		}).then(function (r) { return r.ok ? r.json() : null; });

		Promise.all([rulesPromise, groupsPromise]).then(function (results) {
			var rulesData  = results[0];
			var groupsData = results[1];

			var changed = false;

			// Update ruleMap if fresh data arrived
			if (rulesData && Array.isArray(rulesData.rules)) {
				// Rebuild ruleMap from the fresh rule list
				var freshMap = {};
				rulesData.rules.forEach(function (rule) {
					freshMap[rule.asset_handle + '|' + rule.asset_type] = rule;
				});
				// Only re-render if something actually changed
				var oldKeys = Object.keys(ruleMap).sort().join(',');
				var newKeys = Object.keys(freshMap).sort().join(',');
				if (oldKeys !== newKeys) {
					Object.keys(ruleMap).forEach(function (k) { delete ruleMap[k]; });
					Object.keys(freshMap).forEach(function (k) { ruleMap[k] = freshMap[k]; });
					changed = true;
				}
			}

			// Update groups if fresh data arrived
			if (groupsData && Array.isArray(groupsData)) {
				var oldGroupStr = JSON.stringify(groups);
				var newGroupStr = JSON.stringify(groupsData);
				if (oldGroupStr !== newGroupStr) {
					groups.length = 0;
					groupsData.forEach(function (g) { groups.push(g); });
					changed = true;
				}
			}

			if (changed) {
				_cu.renderAssets();
			}
		}).catch(function () {
			// Sync failure is silent — panel still works with cached data
		});
	};

	/* -----------------------------------------------------------------------
	   Render Inline Blocks tab
	   ----------------------------------------------------------------------- */
	_cu.renderInlineBlocks = function () {
		var container = document.getElementById('cu-inline-tab');
		if (!container) return;

		var blocks = D.inline_blocks || [];
		// Note: console.log for inline_blocks count intentionally removed (task 9)

		if (!blocks.length) {
			container.innerHTML = '<p class="cu-empty">No inline scripts or styles detected on this page.</p>';
			return;
		}

		// --- Info notice (task 6) ---
		var infoBar = '<div class="cu-inline-info-bar">'
			+ '<span class="cu-inline-info-icon">ℹ</span>'
			+ '<span>Inline blocks are informational only — they cannot be unloaded from this panel.</span>'
			+ '</div>';

		// --- Group-by-type toggle (task 8) ---
		var INLINE_GROUP_KEY = 'cdunloader_inline_group';
		var grouped = localStorage.getItem(INLINE_GROUP_KEY) === '1';

		var toolbar = '<div class="cu-inline-toolbar">'
			+ '<button id="cu-inline-group-btn" class="cu-inline-group-btn' + (grouped ? ' cu-inline-group-btn--active' : '') + '" title="Group by type (JS / CSS)">'
			+ '<span>Group by type</span>'
			+ '</button>'
			+ '<span class="cu-inline-count">' + blocks.length + ' block' + (blocks.length !== 1 ? 's' : '') + '</span>'
			+ '</div>';

		function renderBlocks(groupByType) {
			var html = '';

			function blockHtml(b) {
				// task 7: CSS → blue pill, JS → amber pill (matches .cu-type-css / .cu-type-js)
				var typeLabel = b.type === 'inline_js' ? 'JS' : 'CSS';
				var typeClass = b.type === 'inline_js' ? 'cu-type-pill cu-type-js' : 'cu-type-pill cu-type-css';
				var sizeStr   = b.size > 1024 ? (b.size / 1024).toFixed(1) + ' KB' : b.size + ' B';
				var idLabel   = b.id
					? '<code class="cu-inline-id">' + esc(b.id) + '</code>'
					: '<span class="cu-inline-noid">no id</span>';

				return '<div class="cu-inline-block">'
					+ '<div class="cu-inline-block-header">'
					+   '<span class="' + typeClass + '">' + typeLabel + '</span> '
					+   idLabel
					+   '<span class="cu-inline-size">' + sizeStr + '</span>'
					+ '</div>'
					+ '<pre class="cu-inline-preview">' + esc(b.preview) + '</pre>'
					+ '</div>';
			}

			if (!groupByType) {
				blocks.forEach(function (b) { html += blockHtml(b); });
			} else {
				var jsBlocks  = blocks.filter(function(b){ return b.type === 'inline_js'; });
				var cssBlocks = blocks.filter(function(b){ return b.type === 'inline_css'; });

				if (jsBlocks.length) {
					html += '<div class="cu-inline-group-header cu-inline-group-header--js">'
						+ '<span class="cu-type-pill cu-type-js">JS</span>'
						+ '<span class="cu-inline-group-label">Inline Scripts</span>'
						+ '<span class="cu-inline-count">' + jsBlocks.length + '</span>'
						+ '</div>';
					jsBlocks.forEach(function(b){ html += blockHtml(b); });
				}
				if (cssBlocks.length) {
					html += '<div class="cu-inline-group-header cu-inline-group-header--css">'
						+ '<span class="cu-type-pill cu-type-css">CSS</span>'
						+ '<span class="cu-inline-group-label">Inline Styles</span>'
						+ '<span class="cu-inline-count">' + cssBlocks.length + '</span>'
						+ '</div>';
					cssBlocks.forEach(function(b){ html += blockHtml(b); });
				}
			}
			return html;
		}

		container.innerHTML = infoBar + toolbar + '<div id="cu-inline-blocks-list">' + renderBlocks(grouped) + '</div>';

		// Bind group-by-type toggle
		var groupBtn = document.getElementById('cu-inline-group-btn');
		if (groupBtn) {
			groupBtn.addEventListener('click', function () {
				grouped = !grouped;
				localStorage.setItem(INLINE_GROUP_KEY, grouped ? '1' : '0');
				this.classList.toggle('cu-inline-group-btn--active', grouped);
				var list = document.getElementById('cu-inline-blocks-list');
				if (list) list.innerHTML = renderBlocks(grouped);
			});
		}
	};

	/* -----------------------------------------------------------------------
	   Boot
	   ----------------------------------------------------------------------- */
	document.addEventListener('DOMContentLoaded', function () {

		applyTheme(currentTheme);
		applyDock(dockSide);

		// Guarantee panel starts closed
		var panel = document.getElementById('cu-panel');
		if (panel) {
			panel.setAttribute('inert', '');
			panel.classList.remove('cu-panel--open');
		}

		// Theme toggle
		var themeBtn = document.getElementById('cu-theme-toggle');
		if (themeBtn) themeBtn.addEventListener('click', function () {
			applyTheme(currentTheme === 'dark' ? 'light' : 'dark');
		});

		// Dock toggle
		var dockBtn = document.getElementById('cu-dock-toggle');
		if (dockBtn) dockBtn.addEventListener('click', function () {
			applyDock(dockSide === 'right' ? 'left' : 'right');
		});

		// Close button
		var closeBtn = document.getElementById('cu-close-btn');
		if (closeBtn) closeBtn.addEventListener('click', cuClosePanel);

		// Admin bar link — remove inline onclick to prevent double-fire
		var abLink = document.querySelector('#wp-admin-bar-cu-panel-toggle > a');
		if (abLink) {
			abLink.removeAttribute('onclick');
			abLink.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				cuTogglePanel();
			});
		}

		// Warning banner
		var warnBanner  = document.getElementById('cu-first-use-warning');
		var warnDismiss = document.getElementById('cu-warning-dismiss');
		var warnNever   = document.getElementById('cu-warning-never');
		if (warnDismiss) warnDismiss.addEventListener('click', function () {
			if (warnBanner) warnBanner.hidden = true;
		});
		if (warnNever) warnNever.addEventListener('click', function () {
			localStorage.setItem(WARN_KEY, '1');
			if (warnBanner) warnBanner.hidden = true;
		});

		// Tabs
		document.querySelectorAll('.cu-tab').forEach(function (tab) {
			tab.addEventListener('click', function () {
				document.querySelectorAll('.cu-tab').forEach(function (t) { t.classList.remove('cu-tab--active'); });
				this.classList.add('cu-tab--active');
				var t = this.dataset.tab;
				var ae = document.getElementById('cu-assets-tab');
				var ie = document.getElementById('cu-inline-tab');
				if (ae) ae.hidden = t !== 'assets';
				if (ie) ie.hidden = t !== 'inline';
			});
		});

		// Search
		var searchBox = document.getElementById('cu-search');
		if (searchBox) searchBox.addEventListener('input', function () { _cu.renderAssets(); });

		// Tooltips
		document.addEventListener('mouseover', function (e) {
			var tipEl = e.target.closest('[data-tip]');
			if (!tipEl) return;
			var old = document.getElementById('cu-tooltip');
			if (old) old.remove();
			var tt = document.createElement('div');
			tt.id = 'cu-tooltip';
			tt.className = 'cu-tooltip';
			tt.textContent = tipEl.getAttribute('data-tip');
			document.body.appendChild(tt);
			var r = tipEl.getBoundingClientRect();
			requestAnimationFrame(function () {
				var left = r.left + window.scrollX;
				if (left + tt.offsetWidth > window.innerWidth - 12) left = window.innerWidth - tt.offsetWidth - 12;
				tt.style.left = left + 'px';
				tt.style.top  = (r.bottom + window.scrollY + 8) + 'px';
			});
		});
		document.addEventListener('mouseout', function (e) {
			if (e.target.closest('[data-tip]')) { var tt = document.getElementById('cu-tooltip'); if (tt) tt.remove(); }
		});

		// Dialog
		var dialog = document.getElementById('cu-dialog');
		if (dialog) {
			var overlay   = dialog.querySelector('.cu-dialog-overlay');
			if (overlay)  overlay.addEventListener('click', function () { _cu.closeDialog(true); });
			var cancelBtn = document.getElementById('cu-dialog-cancel');
			if (cancelBtn) cancelBtn.addEventListener('click', function () { _cu.closeDialog(true); });
			var saveBtn   = document.getElementById('cu-dialog-save');
			if (saveBtn)  saveBtn.addEventListener('click', _cu.saveRule);

			dialog.querySelectorAll('input[name="cu-scope"]').forEach(function (r) {
				r.addEventListener('change', function () {
					_cu.setScope(this.value);
					dialog.querySelectorAll('.cu-scope-btn').forEach(function (b) {
						b.classList.toggle('cu-scope-active', b.dataset.scope === r.value);
					});
				});
			});
			dialog.querySelectorAll('.cu-scope-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var radio = this.querySelector('input[type="radio"]');
					if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
				});
			});
			dialog.querySelectorAll('input[name="cu-match-type"]').forEach(function (r) {
				r.addEventListener('change', function () {
					var rw = document.getElementById('cu-regex-warning');
					if (rw) rw.hidden = this.value !== 'regex';
				});
			});
			var condSel = document.getElementById('cu-condition-type');
			if (condSel) condSel.addEventListener('change', function () {
				var w = document.getElementById('cu-condition-value-wrap');
				var needs = ['has_shortcode','is_post_type','is_page_template'].indexOf(this.value) !== -1;
				if (w) w.hidden = !needs;
			});
			dialog.addEventListener('keydown', function (e) {
				if (e.key === 'Escape') _cu.closeDialog(true);
			});
		}

		// Auto-open via ?wpcu
		if (D.auto_open) {
			cuOpenPanel();
		}

		// Item 4: sync rule/group data when user returns to this browser tab,
		// so changes made in the admin dashboard are reflected without a manual refresh.
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'visible' && window._cu) {
				window._cu.syncData();
			}
		});

	});

})();
