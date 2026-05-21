/**
 * Settings → Temso AI: Test connection button.
 *
 * Reads the URL and key currently typed in the form (not the saved values)
 * so the user can verify a freshly pasted pair before saving. The server
 * round-trip lives in PHP (admin-ajax) so this stays small and unprivileged.
 */
( function () {
	var cfg = window.temsoVerify;
	if ( ! cfg ) {
		return;
	}

	var btn       = document.getElementById( 'temso-verify-btn' );
	var result    = document.getElementById( 'temso-verify-result' );
	var urlInput  = document.getElementById( 'temso-ingest-url' );
	var keyInput  = document.getElementById( 'temso-api-key' );

	if ( ! btn || ! result || ! urlInput || ! keyInput ) {
		return;
	}

	function show( prefix, message ) {
		result.textContent = prefix ? prefix + ' ' + message : message;
	}

	// Map the structured `code` returned by the PHP handler / Temso backend
	// to a translated message. Anything we don't recognize falls back to
	// the generic "unknown" string.
	var messages = {
		missing:       { ok: false, text: cfg.i18n.missing },
		UNAUTHORIZED:  { ok: false, text: cfg.i18n.unauthorized },
		FORBIDDEN:     { ok: false, text: cfg.i18n.forbidden },
		REVOKED:       { ok: false, text: cfg.i18n.revoked },
		NOT_FOUND:     { ok: false, text: cfg.i18n.not_found },
		network:       { ok: false, text: cfg.i18n.network }
	};

	btn.addEventListener( 'click', function () {
		var url = urlInput.value.trim();
		var key = keyInput.value.trim();

		if ( ! url || ! key ) {
			show( '✗', cfg.i18n.missing );
			return;
		}

		btn.disabled = true;
		show( '', cfg.i18n.testing );

		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		body.set( 'ingest_url', url );
		body.set( 'api_key', key );

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
					return;
				}
				var code  = payload && payload.data && payload.data.code ? payload.data.code : 'unknown';
				var entry = messages[ code ];
				show( '✗', entry ? entry.text : cfg.i18n.unknown );
			} )
			.catch( function () {
				show( '✗', cfg.i18n.network );
			} )
			.then( function () {
				btn.disabled = false;
			} );
	} );
}() );
