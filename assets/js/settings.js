/**
 * Order Notifications settings: "Send test message" per channel and the sound file picker.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.tetNotifySettings;

	$( document ).on( 'click', '.tet-notify-test', function () {
		var $btn = $( this );
		var channel = $btn.data( 'channel' );
		var $result = $btn.siblings( '.tet-notify-test-result' );
		var config = {};
		$( '[name^="tet_notify_' + channel + '_"]' ).each( function () {
			config[ this.name ] = this.value;
		} );

		$btn.prop( 'disabled', true );
		$result.removeClass( 'is-ok is-error' ).text( cfg.i18n.sending );

		$.post( cfg.ajaxUrl, { action: 'tet_notify_test', nonce: cfg.nonce, channel: channel, config: config } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$result.addClass( 'is-ok' ).text( cfg.i18n.sent );
				} else {
					$result.addClass( 'is-error' ).text( cfg.i18n.failed + ' ' + ( res && res.data && res.data.message ? res.data.message : '' ) );
				}
			} )
			.fail( function ( xhr ) {
				$result.addClass( 'is-error' ).text( cfg.i18n.failed + ' HTTP ' + xhr.status );
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
			} );
	} );

	var frame;
	$( document ).on( 'click', '.tet-notify-media', function ( e ) {
		e.preventDefault();
		var $input = $( '#' + $( this ).data( 'target' ) );
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}
		frame = window.wp.media( {
			title: cfg.i18n.chooseSound,
			button: { text: cfg.i18n.useSound },
			library: { type: 'audio' },
			multiple: false,
		} );
		frame.on( 'select', function () {
			$input.val( frame.state().get( 'selection' ).first().get( 'url' ) );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.tet-notify-preview', function () {
		var url = $( '#' + $( this ).data( 'target' ) ).val();
		if ( url ) {
			new window.Audio( url ).play();
		}
	} );
} )( jQuery );

/* Channels tab: the card's badge follows its Enable checkbox before saving. */
( function ( $ ) {
	'use strict';
	var cfg = window.tetNotifySettings;
	$( document ).on( 'change', '.tet-notify-channel input[type="checkbox"][id$="_enabled"]', function () {
		var $badge = $( this ).closest( '.tet-notify-channel' ).find( '.tet-notify-channel-badge' );
		$badge
			.removeClass( 'ttrp-badge--success ttrp-badge--warning ttrp-badge--neutral' )
			.addClass( this.checked ? 'ttrp-badge--success' : 'ttrp-badge--neutral' )
			.text( this.checked ? cfg.i18n.on : cfg.i18n.off );
	} );
} )( jQuery );

/* Telegram: "Find my chat ID" opens getUpdates for the token typed in the form. */
( function ( $ ) {
	'use strict';
	function update() {
		var token = $.trim( $( '#tet_notify_telegram_token' ).val() || '' );
		var ok = /^\d{5,}:[A-Za-z0-9_-]{30,}$/.test( token );
		$( '.tet-notify-getupdates' )
			.attr( 'href', ok ? 'https://api.telegram.org/bot' + token + '/getUpdates' : '#' )
			.toggleClass( 'disabled', ! ok )
			.attr( 'aria-disabled', ok ? 'false' : 'true' );
		$( '.tet-notify-getupdates-hint' ).toggle( ! ok );
	}
	$( document ).on( 'input change', '#tet_notify_telegram_token', update );
	$( document ).on( 'click', '.tet-notify-getupdates.disabled', function ( e ) {
		e.preventDefault();
		$( '#tet_notify_telegram_token' ).trigger( 'focus' );
	} );
	$( update );
} )( jQuery );
