/**
 * The report screens: rows dragged into order when the screen reorders, the delete links' confirmation,
 * the note shown while an export was written, and the pagination header's page field, which goes back
 * to page 1 when the form is submitted for anything but a page change.
 */
/* global jQuery */
jQuery( function ( $ ) {
	// The page has loaded, so the export it was writing is done (or failed, and the screen says so).
	$( '.exports-reports-export-progress' ).remove();

	const sortable = $( '.admin_ui_reorder_form tbody' );

	if ( sortable.length && $.fn.sortable ) {
		sortable.sortable( { axis: 'y', handle: '.dragme' } );
	}

	$( document ).on(
		'click',
		'a[data-exports-reports-confirm]',
		function ( event ) {
			if (
				// eslint-disable-next-line no-alert -- the delete link asks before it deletes.
				! window.confirm(
					$( this ).attr( 'data-exports-reports-confirm' )
				)
			) {
				event.preventDefault();
			}
		}
	);

	const pageInput = $( 'input.current-page' );

	if ( pageInput.length ) {
		const currentPage = pageInput.val();

		pageInput.closest( 'form' ).on( 'submit', function () {
			const action = $( 'select[name="action"]' );
			const actionBulk = $( 'select[name="action_bulk"]' );
			const noAction =
				( 1 > action.length || -1 === parseInt( action.val(), 10 ) ) &&
				( 1 > actionBulk.length ||
					-1 === parseInt( actionBulk.val(), 10 ) );

			if ( noAction && pageInput.val() === currentPage ) {
				pageInput.val( '1' );
			}
		} );
	}
} );
