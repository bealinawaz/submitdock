( function () {
	const { __ } = wp.i18n;

	document.querySelectorAll( '.submitdock-copy' ).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			const code = button.closest( '.submitdock-code' ).querySelector( 'code' );
			try {
				await navigator.clipboard.writeText( code.textContent );
				button.textContent = __( 'Copied', 'submitdock' );
			} catch ( error ) {
				window.getSelection().selectAllChildren( code );
				button.textContent = __( 'Press Ctrl+C to copy', 'submitdock' );
			}
			window.setTimeout( () => {
				button.textContent = __( 'Copy', 'submitdock' );
			}, 2500 );
		} );
	} );
} )();
