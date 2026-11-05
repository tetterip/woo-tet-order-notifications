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
