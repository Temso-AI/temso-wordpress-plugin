/**
 * Settings → Temso AI: Connect publishing button.
 *
 * Takes the one-time setup link/code the admin pasted from Temso, hands it to
 * the admin-ajax claim handler, and reports the result. The handler does the
 * Temso round-trip and never returns the shared secret, so this stays small and
 * never sees a credential.
 */
( function () {
	var cfg = window.temsoPublishing;
	if ( ! cfg ) {
		return;
	}

	var btn    = document.getElementById( 'temso-connect-publishing-btn' );
	var input  = document.getElementById( 'temso-setup-link' );
	var result = document.getElementById( 'temso-publishing-result' );

	if ( ! btn || ! input || ! result ) {
		return;
	}

	function show( prefix, message ) {
		result.textContent = prefix ? prefix + ' ' + message : message;
	}

	// Map the structured `code` from the PHP handler to a translated message.
	// Anything unrecognized falls back to the generic "unknown" string.
	var messages = {
		missing_token: cfg.i18n.missing,
		invalid_token: cfg.i18n.invalid,
		server_error:  cfg.i18n.server,
		forbidden:     cfg.i18n.forbidden,
		network:       cfg.i18n.network
	};

	function connect() {
		var setup = input.value.trim();

		if ( ! setup ) {
			show( '✗', cfg.i18n.missing );
			return;
		}

		btn.disabled = true;
		show( '', cfg.i18n.connecting );

		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'setup', setup );

		fetch( cfg.ajaxUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:        body.toString()
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( payload ) {
				if ( payload && payload.success ) {
					show( '✓', cfg.i18n.success );
					input.value = '';
					return;
				}
				var code = payload && payload.data && payload.data.code ? payload.data.code : 'unknown';
				show( '✗', messages[ code ] || cfg.i18n.unknown );
			} )
			.catch( function () {
				show( '✗', cfg.i18n.network );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	}

	btn.addEventListener( 'click', connect );

	// The setup field lives inside the settings <form>, so Enter would
	// otherwise submit the page (saving settings) instead of connecting.
	input.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			connect();
		}
	} );
}() );
