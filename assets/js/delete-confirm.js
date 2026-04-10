/**
 * Code Unloader — Plugin delete confirmation modal
 * Enqueued on the Plugins admin screen only.
 */
(function () {
	'use strict';

	var pluginFile = ( window.CDUNLOADER_DELETE_DATA && window.CDUNLOADER_DELETE_DATA.plugin_file ) ? window.CDUNLOADER_DELETE_DATA.plugin_file : '';
	var modal      = document.getElementById( 'cu-delete-modal' );
	var confirmBtn = document.getElementById( 'cu-delete-confirm' );
	var cancelBtn  = document.getElementById( 'cu-delete-cancel' );

	if ( ! modal || ! pluginFile ) {
		return;
	}

	var links = document.querySelectorAll( 'tr[data-plugin="' + pluginFile + '"] .delete a, tr[data-slug="code-unloader"] .delete a' );
	links.forEach( function ( link ) {
		var realHref = link.href;
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			modal.style.display = 'flex';
			confirmBtn.href     = realHref;
		} );
	} );

	cancelBtn.addEventListener( 'click', function () {
		modal.style.display = 'none';
	} );
	modal.addEventListener( 'click', function ( e ) {
		if ( e.target === modal ) {
			modal.style.display = 'none';
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			modal.style.display = 'none';
		}
	} );
})();
