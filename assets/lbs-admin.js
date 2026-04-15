/* Load Balanced Sync — Admin JS */
/* global document */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Copy-to-clipboard buttons.
		document.querySelectorAll( '[data-lbs-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-lbs-copy' );
				var input    = document.getElementById( targetId );
				if ( ! input ) return;

				input.select();
				input.setSelectionRange( 0, 99999 );

				try {
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( input.value ).then( function () {
							btn.textContent = 'Copied!';
							setTimeout( function () {
								btn.textContent = 'Copy';
							}, 2000 );
						} );
					} else {
						document.execCommand( 'copy' );
						btn.textContent = 'Copied!';
						setTimeout( function () {
							btn.textContent = 'Copy';
						}, 2000 );
					}
				} catch ( err ) {
					/* silently fail — text is still selected */
				}
			} );
		} );
	} );
}() );
