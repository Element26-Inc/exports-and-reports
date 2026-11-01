/* global jQuery */
jQuery( function ( $ ) {
	// The report selector goes to the chosen report.
	$( '#report' ).on( 'change', function () {
		window.location = this.value;
	} );

	// Store the original query params to test for changes later
	const originalQueryParams = {};
	window.location.search
		.substr( 1 )
		.split( '&' )
		.forEach( function ( item ) {
			originalQueryParams[ item.split( '=' )[ 0 ] ] =
				item.split( '=' )[ 1 ];
		} );

	// Reset the pg param on form submit if any filter value change
	$( '#posts-filter' ).on( 'submit', function () {
		// Loop through the form values
		const formParams = $( this ).serializeArray();

		for ( let i = 0; i < formParams.length; i++ ) {
			const name = formParams[ i ].name;
			const value = encodeURIComponent( formParams[ i ].value );

			// Was there an original value for this key?
			if ( originalQueryParams.hasOwnProperty( name ) ) {
				// Original value has changed?
				if ( originalQueryParams[ name ] !== value ) {
					$( '#posts-filter [name=pg]' ).remove();
				}
			}
			// The param wasn't originally on the URL, so any non-empty value now means a change
			else if ( value !== '' ) {
				$( '#posts-filter [name=pg]' ).remove();
			}
		}
	} );
} );
