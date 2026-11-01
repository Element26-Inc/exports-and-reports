/**
 * The report screens: rows dragged into order when the screen reorders, the delete links' confirmation,
 * and the pagination header's page field, which goes back to page 1 when the form is submitted for
 * anything but a page change.
 */
jQuery( function ( $ ) {
	var sortable = $( 'tbody.sortable' );

	if ( sortable.length && $.fn.sortable ) {
		sortable.sortable( { axis: 'y', handle: '.dragme' } );
	}

	$( document ).on( 'click', 'a[data-exports-reports-confirm]', function ( event ) {
		if ( ! window.confirm( $( this ).attr( 'data-exports-reports-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var pageInput = $( 'input.current-page' );

	if ( pageInput.length ) {
		var currentPage = pageInput.val();

		pageInput.closest( 'form' ).on( 'submit', function () {
			var action     = $( 'select[name="action"]' );
			var actionBulk = $( 'select[name="action_bulk"]' );
			var noAction   = ( 1 > action.length || -1 === parseInt( action.val(), 10 ) ) && ( 1 > actionBulk.length || -1 === parseInt( actionBulk.val(), 10 ) );

			if ( noAction && pageInput.val() === currentPage ) {
				pageInput.val( '1' );
			}
		} );
	}
} );
