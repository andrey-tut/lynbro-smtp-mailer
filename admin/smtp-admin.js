/* Lynbro SMTP — admin screen behaviour (enqueued; no inline script). */
( function () {
	'use strict';
	var d = window.lynbroSmtp || { i18n: {}, presets: {} };

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', d.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( d.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.json();
		} );
	}

	function el( tag, cls, text ) {
		var n = document.createElement( tag );
		if ( cls ) {
			n.className = cls;
		}
		if ( text ) {
			n.textContent = text;
		}
		return n;
	}

	// Provider presets fill host, port and encryption, and show the login hint.
	var provider = document.getElementById( 'lynbro-smtp-provider' );
	if ( provider ) {
		provider.addEventListener( 'change', function () {
			var p = d.presets[ provider.value ];
			var hint = document.getElementById( 'lynbro-smtp-provider-hint' );
			// The server's own mail needs no host or login: hide those rows.
			var rows = document.querySelectorAll( '.lynbro-smtp-conn' );
			for ( var r = 0; r < rows.length; r++ ) {
				rows[ r ].hidden = ( 'php' === provider.value );
			}
			if ( ! p ) {
				return;
			}
			if ( hint ) {
				hint.textContent = p.hint || '';
			}
			if ( ! p.host ) {
				return;
			}
			var host = document.getElementById( 'lynbro-smtp-host' );
			var port = document.getElementById( 'lynbro-smtp-port' );
			var enc = document.getElementById( 'lynbro-smtp-enc' );
			if ( host && ! host.readOnly ) {
				host.value = p.host;
			}
			if ( port && ! port.readOnly ) {
				port.value = p.port;
			}
			if ( enc ) {
				enc.value = p.enc;
			}
		} );
	}

	// Typical port for the chosen encryption, when the port is still a default.
	var encSel = document.getElementById( 'lynbro-smtp-enc' );
	if ( encSel ) {
		encSel.addEventListener( 'change', function () {
			var port = document.getElementById( 'lynbro-smtp-port' );
			if ( ! port || port.readOnly ) {
				return;
			}
			if ( 'ssl' === encSel.value && '587' === port.value ) {
				port.value = '465';
			} else if ( 'tls' === encSel.value && '465' === port.value ) {
				port.value = '587';
			}
		} );
	}

	// Show the backup SMTP fields only when "Another SMTP server" is chosen.
	var backupRadios = document.querySelectorAll( 'input[name$="[backup_type]"]' );
	function syncBackup() {
		var smtp = document.querySelector( 'input[name$="[backup_type]"][value="smtp"]' );
		Array.prototype.forEach.call( document.querySelectorAll( '.lynbro-smtp-backup-smtp' ), function ( row ) {
			row.hidden = ! ( smtp && smtp.checked );
		} );
	}
	Array.prototype.forEach.call( backupRadios, function ( r ) {
		r.addEventListener( 'change', syncBackup );
	} );
	if ( backupRadios.length ) {
		syncBackup();
	}

	// Test send.
	var result = document.getElementById( 'lynbro-smtp-test-result' );
	var wrap = document.getElementById( 'lynbro-smtp-transcript-wrap' );
	var pre = document.getElementById( 'lynbro-smtp-transcript' );
	Array.prototype.forEach.call( document.querySelectorAll( '.lynbro-smtp-test' ), function ( btn ) {
		btn.addEventListener( 'click', function () {
			var to = document.getElementById( 'lynbro-smtp-test-to' );
			btn.disabled = true;
			result.className = 'lynbro-smtp-result';
			result.textContent = d.i18n.sending;
			wrap.hidden = true;
			post( 'lynbro_smtp_test', { to: to ? to.value : '', connection: btn.getAttribute( 'data-connection' ) } )
				.then( function ( json ) {
					result.textContent = '';
					if ( ! json.success ) {
						result.className = 'lynbro-smtp-result bad';
						result.textContent = json.data && json.data.message ? json.data.message : d.i18n.failed;
						return;
					}
					var r = json.data;
					result.className = 'lynbro-smtp-result ' + ( r.sent ? 'ok' : 'bad' );
					result.appendChild( el( 'p', '', r.sent ? d.i18n.sent : d.i18n.failed + ( r.error ? ' ' + r.error : '' ) ) );
					if ( r.sent && r.via ) {
						result.appendChild( el( 'p', 'lynbro-muted', d.i18n.via + ' ' + r.via ) );
					}
					if ( r.hints && r.hints.length ) {
						var ul = el( 'ul', 'lynbro-smtp-list' );
						r.hints.forEach( function ( h ) {
							ul.appendChild( el( 'li', '', h ) );
						} );
						result.appendChild( ul );
					}
					if ( r.transcript && r.transcript.length ) {
						pre.textContent = r.transcript.join( '\n' );
						wrap.hidden = false;
						wrap.open = ! r.sent;
					}
				} )
				.catch( function ( e ) {
					result.className = 'lynbro-smtp-result bad';
					result.textContent = d.i18n.network + ' ' + e.message;
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );

	// Port check.
	var portsBtn = document.getElementById( 'lynbro-smtp-ports' );
	if ( portsBtn ) {
		portsBtn.addEventListener( 'click', function () {
			var out = document.getElementById( 'lynbro-smtp-ports-out' );
			portsBtn.disabled = true;
			out.textContent = '';
			out.appendChild( el( 'li', 'info', d.i18n.checking ) );
			post( 'lynbro_smtp_ports', {} )
				.then( function ( json ) {
					out.textContent = '';
					if ( ! json.success ) {
						out.appendChild( el( 'li', 'bad', json.data && json.data.message ? json.data.message : d.i18n.failed ) );
						return;
					}
					json.data.ports.forEach( function ( p ) {
						var txt = json.data.host + ':' + p.port + ' — ' + ( p.open ? d.i18n.open + ' (' + p.ms + ' ms)' : d.i18n.closed + ( p.error ? ' (' + p.error + ')' : '' ) );
						out.appendChild( el( 'li', p.open ? 'ok' : 'warn', txt ) );
					} );
				} )
				.catch( function ( e ) {
					out.textContent = '';
					out.appendChild( el( 'li', 'bad', d.i18n.network + ' ' + e.message ) );
				} )
				.finally( function () {
					portsBtn.disabled = false;
				} );
		} );
	}

	// Confirm destructive buttons and links.
	Array.prototype.forEach.call( document.querySelectorAll( '[data-confirm]' ), function ( n ) {
		n.addEventListener( 'click', function ( ev ) {
			if ( ! window.confirm( n.getAttribute( 'data-confirm' ) ) ) {
				ev.preventDefault();
			}
		} );
	} );

	// Select all rows of the log.
	var all = document.querySelector( '.lynbro-smtp-checkall' );
	if ( all ) {
		all.addEventListener( 'change', function () {
			Array.prototype.forEach.call( document.querySelectorAll( '.lynbro-smtp-log input[name="ids[]"]' ), function ( c ) {
				c.checked = all.checked;
			} );
		} );
	}
} )();
