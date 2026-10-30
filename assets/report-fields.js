/**
 * The field editor of the report form: add a row from the template, remove one, drag to reorder.
 * The input names carry the row index; a new row takes the next free one.
 */
jQuery( function ( $ ) {
	var wrap = $( '.er-fields' );

	if ( ! wrap.length ) {
		return;
	}

	var list     = wrap.find( '.er-fields__list' );
	var template = wrap.find( '.er-fields__template' ).html();
	var next     = parseInt( wrap.data( 'next-index' ), 10 ) || 0;

	wrap.on( 'click', '.er-fields__add', function () {
		list.append( template.split( '__i__' ).join( String( next ) ) );
		next += 1;
		list.children().last().find( 'input[type="text"]' ).first().trigger( 'focus' );
	} );

	wrap.on( 'click', '.er-field__remove', function () {
		if ( window.confirm( 'Remove this field from the report?' ) ) {
			$( this ).closest( '.er-field' ).remove();
		}
	} );

	list.sortable( {
		axis: 'y',
		handle: '.er-field__handle',
		placeholder: 'er-field er-field--placeholder',
		forcePlaceholderSize: true
	} );
} );
