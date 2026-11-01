/**
 * The field editor of the report form: add a row from the template, remove one, drag to reorder.
 * The input names carry the row index; a new row takes the next free one.
 */
/* global jQuery */
jQuery( function ( $ ) {
	const wrap = $( '.er-fields' );

	if ( ! wrap.length ) {
		return;
	}

	const list = wrap.find( '.er-fields__list' );
	const template = wrap.find( '.er-fields__template' ).html();
	let next = parseInt( wrap.data( 'next-index' ), 10 ) || 0;

	wrap.on( 'click', '.er-fields__add', function () {
		list.append( template.split( '__i__' ).join( String( next ) ) );
		next += 1;
		list.children()
			.last()
			.find( 'input[type="text"]' )
			.first()
			.trigger( 'focus' );
	} );

	wrap.on( 'click', '.er-field__remove', function () {
		// eslint-disable-next-line no-alert -- the editor asks before it removes a field.
		if ( window.confirm( 'Remove this field from the report?' ) ) {
			$( this ).closest( '.er-field' ).remove();
		}
	} );

	list.sortable( {
		axis: 'y',
		handle: '.er-field__handle',
		placeholder: 'er-field er-field--placeholder',
		forcePlaceholderSize: true,
	} );
} );
