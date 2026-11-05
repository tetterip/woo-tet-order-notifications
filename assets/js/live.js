/**
 * Order Notifications: real-time alerts in wp-admin.
 * Polls the REST endpoint, shows pop-ups, plays the chime / voice / custom sound,
 * and drives the bell in the admin bar. Plain JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.tetNotifyLive;
	if ( ! cfg || ! window.fetch ) {
		return;
	}
	var t = cfg.i18n;
	var last = parseInt( cfg.last, 10 ) || 0;
	var muted = !! cfg.muted;
	var expired = false;
	var audioCtx = null;
	var unlocked = false;
	var openToasts = 0;
	var repeatTimer = null;
	var baseTitle = document.title;
	var HEARD_KEY = 'tetNotifyHeard';

	/* ── Storage (a private window may block it) ─────────── */

	function storeGet( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}
	function storeSet( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {}
	}

	/* ── Audio ──────────────────────────────────────────── */

	function getCtx() {
		var Ctx = window.AudioContext || window.webkitAudioContext;
		if ( ! audioCtx && Ctx ) {
			audioCtx = new Ctx();
		}
		return audioCtx;
	}

	function unlock() {
		var ctx = getCtx();
		if ( ctx && ctx.state === 'suspended' ) {
			ctx.resume().then( function () {
				unlocked = true;
				render();
			} );
		} else {
			unlocked = true;
			render();
		}
	}

	function chime() {
		var ctx = getCtx();
		if ( ! ctx ) {
			return;
		}
		var now = ctx.currentTime;
		[ [ 880, 0 ], [ 1318.5, 0.18 ] ].forEach( function ( note ) {
			var osc = ctx.createOscillator();
			var gain = ctx.createGain();
			osc.type = 'sine';
			osc.frequency.value = note[ 0 ];
			gain.gain.setValueAtTime( 0.0001, now + note[ 1 ] );
			gain.gain.exponentialRampToValueAtTime( 0.35, now + note[ 1 ] + 0.02 );
			gain.gain.exponentialRampToValueAtTime( 0.0001, now + note[ 1 ] + 0.9 );
			osc.connect( gain ).connect( ctx.destination );
			osc.start( now + note[ 1 ] );
			osc.stop( now + note[ 1 ] + 1 );
		} );
	}

	function findVoice() {
		if ( ! window.speechSynthesis ) {
			return null;
		}
		var want = String( cfg.voiceLang || '' ).toLowerCase();
		var base = want.split( '-' )[ 0 ];
		var voices = window.speechSynthesis.getVoices();
		var exact = null;
		var partial = null;
		for ( var i = 0; i < voices.length; i++ ) {
			var lang = String( voices[ i ].lang || '' ).toLowerCase().replace( '_', '-' );
			if ( lang === want && ! exact ) {
				exact = voices[ i ];
			} else if ( lang.split( '-' )[ 0 ] === base && ! partial ) {
				partial = voices[ i ];
			}
		}
		return exact || partial;
	}
	// Voices load asynchronously in Chrome.
	if ( window.speechSynthesis ) {
		window.speechSynthesis.getVoices();
	}

	function speak() {
		var voice = findVoice();
		if ( ! voice || ! cfg.voiceText ) {
			return;
		}
		var u = new window.SpeechSynthesisUtterance( cfg.voiceText );
		u.voice = voice;
		u.lang = voice.lang;
		window.speechSynthesis.cancel();
		window.speechSynthesis.speak( u );
	}

	function playSound( force ) {
		if ( ( muted && ! force ) || cfg.sound === 'none' ) {
			return;
		}
		if ( cfg.sound === 'custom' && cfg.soundUrl ) {
			var a = new window.Audio( cfg.soundUrl );
			a.play().catch( function () {
				chime();
			} );
			return;
		}
		chime();
		if ( cfg.sound === 'voice' ) {
			window.setTimeout( speak, 900 );
		}
	}

	/* ── Admin bar bell ─────────────────────────────────── */

	function node( id ) {
		return document.getElementById( 'wp-admin-bar-' + id );
	}

	function render() {
		var bell = node( 'tet-notify' );
		if ( ! bell ) {
			return;
		}
		var label = bell.querySelector( '.tet-notify-ab-label' );
		var text;
		bell.classList.toggle( 'tet-notify-muted', muted );
		bell.classList.toggle( 'tet-notify-locked', ! unlocked && ! muted && cfg.sound !== 'none' );
		bell.classList.toggle( 'tet-notify-expired', expired );
		if ( expired ) {
			text = t.expired;
		} else if ( muted ) {
			text = t.soundOff;
		} else if ( ! unlocked && cfg.sound !== 'none' ) {
			text = t.enableSound;
		} else {
			text = t.soundOn;
		}
		if ( label ) {
			label.textContent = text;
		}
		bell.querySelector( 'a' ).setAttribute( 'title', text );

		var mute = node( 'tet-notify-mute' );
		if ( mute ) {
			mute.querySelector( 'a' ).textContent = muted ? t.unmute : t.mute;
		}
		var desk = node( 'tet-notify-desktop' );
		if ( desk ) {
			desk.style.display = cfg.desktop && 'Notification' in window && window.Notification.permission === 'default' ? '' : 'none';
		}
	}

	function setMuted( value ) {
		muted = value;
		render();
		var body = new window.FormData();
		body.append( 'action', 'tet_notify_mute' );
		body.append( 'nonce', cfg.muteNonce );
		body.append( 'muted', value ? '1' : '0' );
		window.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).catch( function () {} );
		if ( muted ) {
			stopRepeat();
		}
	}

	function bindBar() {
		var bell = node( 'tet-notify' );
		if ( ! bell ) {
			return;
		}
		bell.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
			e.preventDefault();
			// The first click only unlocks the sound.
			if ( ! unlocked && ! muted ) {
				return;
			}
			setMuted( ! muted );
		} );
		var mute = node( 'tet-notify-mute' );
		if ( mute ) {
			mute.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				setMuted( ! muted );
			} );
		}
		var play = node( 'tet-notify-play' );
		if ( play ) {
			play.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				unlock();
				playSound( true );
			} );
		}
		var desk = node( 'tet-notify-desktop' );
		if ( desk ) {
			desk.querySelector( 'a' ).addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( 'Notification' in window ) {
					window.Notification.requestPermission().then( render );
				}
			} );
		}
	}

	/* ── Pop-ups ────────────────────────────────────────── */

	function container() {
		var box = document.getElementById( 'tet-notify-toasts' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.id = 'tet-notify-toasts';
			box.setAttribute( 'role', 'region' );
			box.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( box );
		}
		return box;
	}

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text ) {
			e.textContent = text;
		}
		return e;
	}

	function join( parts ) {
		return parts.filter( function ( p ) {
			return p;
		} ).join( ' · ' );
	}

	function titleFor( ev ) {
		return t.title.replace( '%s', ev.number );
	}

	function updateCount() {
		document.title = openToasts > 0 ? '(' + openToasts + ') ' + baseTitle : baseTitle;
		var all = document.getElementById( 'tet-notify-close-all' );
		if ( openToasts > 1 && ! all ) {
			all = el( 'button', 'tet-notify-close-all', t.closeAll );
			all.id = 'tet-notify-close-all';
			all.type = 'button';
			all.addEventListener( 'click', function () {
				var toasts = container().querySelectorAll( '.tet-notify-toast' );
				for ( var i = 0; i < toasts.length; i++ ) {
					closeToast( toasts[ i ] );
				}
			} );
			container().insertBefore( all, container().firstChild );
		} else if ( openToasts <= 1 && all ) {
			all.parentNode.removeChild( all );
		}
		if ( openToasts === 0 ) {
			stopRepeat();
		}
	}

	function closeToast( toast ) {
		if ( ! toast.parentNode ) {
			return;
		}
		toast.parentNode.removeChild( toast );
		openToasts--;
		updateCount();
	}

	function showToast( ev ) {
		var toast = el( 'div', 'tet-notify-toast' );
		var head = el( 'div', 'tet-notify-head' );
		head.appendChild( el( 'span', 'dashicons dashicons-cart' ) );
		head.appendChild( el( 'strong', '', titleFor( ev ) ) );
		var close = el( 'button', 'tet-notify-close' );
		close.type = 'button';
		close.setAttribute( 'aria-label', t.close );
		close.textContent = '×';
		close.addEventListener( 'click', function () {
			closeToast( toast );
		} );
		head.appendChild( close );
		toast.appendChild( head );

		toast.appendChild( el( 'div', 'tet-notify-line tet-notify-total', join( [ ev.total, ev.payment ] ) ) );
		var who = join( [ ev.customer, ev.city ] );
		if ( who ) {
			toast.appendChild( el( 'div', 'tet-notify-line', who ) );
		}
		if ( ev.items ) {
			var lines = ev.items.split( '\n' );
			toast.appendChild( el( 'div', 'tet-notify-items', lines.slice( 0, 3 ).join( '\n' ) + ( lines.length > 3 ? '\n…' : '' ) ) );
		}
		var open = el( 'a', 'button button-primary tet-notify-open', t.open );
		open.href = ev.url;
		toast.appendChild( open );

		container().appendChild( toast );
		openToasts++;
		updateCount();
	}

	function desktopNotify( ev ) {
		if ( ! cfg.desktop || ! ( 'Notification' in window ) || window.Notification.permission !== 'granted' || ! document.hidden ) {
			return;
		}
		try {
			var n = new window.Notification( titleFor( ev ), {
				body: join( [ ev.total, ev.payment ] ) + ( ev.customer ? '\n' + ev.customer : '' ),
				tag: 'tet-notify-' + ev.id,
				icon: cfg.icon,
			} );
			n.onclick = function () {
				window.focus();
				window.location.href = ev.url;
				n.close();
			};
		} catch ( e ) {}
	}

	function startRepeat() {
		if ( ! cfg.repeat || repeatTimer || muted ) {
			return;
		}
		repeatTimer = window.setInterval( function () {
			if ( openToasts > 0 && ! muted ) {
				playSound( false );
			}
		}, 30000 );
	}

	function stopRepeat() {
		if ( repeatTimer ) {
			window.clearInterval( repeatTimer );
			repeatTimer = null;
		}
	}

	function handle( events ) {
		if ( ! events.length ) {
			return;
		}
		var newest = 0;
		events.forEach( function ( ev ) {
			showToast( ev );
			desktopNotify( ev );
			newest = Math.max( newest, ev.id );
		} );
		// Several admin tabs open: only the first one to see an order plays the sound.
		var heard = parseInt( storeGet( HEARD_KEY ), 10 ) || 0;
		if ( newest > heard ) {
			storeSet( HEARD_KEY, String( newest ) );
			playSound( false );
		}
		startRepeat();
	}

	/* ── Polling ────────────────────────────────────────── */

	function refreshNonce() {
		return window.fetch( cfg.ajaxUrl + '?action=tet_notify_nonce', { credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( j && j.success && j.data.nonce ) {
					cfg.restNonce = j.data.nonce;
					return true;
				}
				return false;
			} )
			.catch( function () {
				return false;
			} );
	}

	function schedule( seconds ) {
		window.setTimeout( poll, seconds * 1000 );
	}

	function poll() {
		var url = cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'after=' + last;
		window.fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.restNonce }, cache: 'no-store' } )
			.then( function ( r ) {
				if ( r.status === 401 || r.status === 403 ) {
					// Usually an expired nonce in a tab left open for a day; else logged out.
					return refreshNonce().then( function ( ok ) {
						if ( ok ) {
							schedule( 1 );
						} else {
							expired = true;
							render();
						}
						return null;
					} );
				}
				return r.json().then( function ( data ) {
					if ( data && typeof data.last === 'number' ) {
						last = Math.max( last, data.last );
						handle( data.events || [] );
					}
					schedule( cfg.interval );
				} );
			} )
			.catch( function () {
				// Offline or server error: try again later.
				schedule( Math.max( cfg.interval, 30 ) );
			} );
	}

	/* ── Start ──────────────────────────────────────────── */

	function start() {
		bindBar();
		// Autoplay allowed already (e.g. the browser trusts this site)?
		var ctx = getCtx();
		unlocked = !! ctx && ctx.state === 'running';
		render();
		if ( ! unlocked ) {
			var once = function () {
				unlock();
				document.removeEventListener( 'pointerdown', once, true );
				document.removeEventListener( 'keydown', once, true );
			};
			document.addEventListener( 'pointerdown', once, true );
			document.addEventListener( 'keydown', once, true );
		}
		schedule( cfg.interval );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
