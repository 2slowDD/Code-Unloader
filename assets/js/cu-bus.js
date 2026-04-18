/**
 * Code Unloader — cross-tab event bus.
 *
 * Publishes/subscribes to small messages across tabs in the same browser,
 * same origin. Used to keep the admin Rules tab in sync with edits made
 * from the frontend CU panel (and vice versa).
 *
 * Primary transport: BroadcastChannel (all modern browsers).
 * Fallback: `storage` event (for older browsers without BroadcastChannel).
 *
 * Exposes `window.CuBus = { emit, on }`.
 * Callers should feature-detect: `window.CuBus && CuBus.emit(...)`.
 */
(function () {
	'use strict';

	var CHANNEL_NAME = 'code-unloader';
	var STORAGE_KEY  = 'cu-bus:' + CHANNEL_NAME;

	var bc        = null;
	var listeners = [];

	try {
		if (typeof BroadcastChannel !== 'undefined') {
			bc = new BroadcastChannel(CHANNEL_NAME);
			bc.addEventListener('message', function (ev) {
				dispatch(ev.data);
			});
		}
	} catch (e) { /* fall through to storage shim */ }

	if (!bc) {
		// Storage-event fallback. Write-then-remove so every emit triggers a
		// 'storage' event in other tabs even when the payload is identical.
		window.addEventListener('storage', function (ev) {
			if (ev.key !== STORAGE_KEY || !ev.newValue) return;
			try {
				var parsed = JSON.parse(ev.newValue);
				dispatch(parsed.msg);
			} catch (e) { /* noop */ }
		});
	}

	function dispatch(msg) {
		for (var i = 0; i < listeners.length; i++) {
			try { listeners[i](msg); } catch (e) { /* swallow listener errors */ }
		}
	}

	function emit(msg) {
		if (bc) {
			try { bc.postMessage(msg); } catch (e) { /* noop */ }
			return;
		}
		try {
			localStorage.setItem(STORAGE_KEY, JSON.stringify({ t: Date.now(), msg: msg }));
			localStorage.removeItem(STORAGE_KEY);
		} catch (e) { /* noop — storage quota, private mode, etc. */ }
	}

	function on(cb) {
		if (typeof cb === 'function') listeners.push(cb);
	}

	window.CuBus = { emit: emit, on: on };
})();
