<?php
defined( 'ABSPATH' ) || exit;

/**
 * The notification channels: configuration, validation and sending.
 * Every request goes straight from this site to the service; nothing is relayed.
 */
class Tet_Notify_Channels {

	const TIMEOUT = 10;

	/** Channel id => label. Service names are not translated. */
	public static function labels(): array {
		return [
			'telegram' => 'Telegram',
			'slack'    => 'Slack',
			'discord'  => 'Discord',
			'webhook'  => __( 'Webhook', 'tet-order-notifications' ),
		];
	}

	/** Option suffixes per channel (option name = tet_notify_{channel}_{key}). */
	private static function keys( string $channel ): array {
		$keys = [
			'telegram' => [ 'token', 'chat_ids' ],
			'slack'    => [ 'webhook' ],
			'discord'  => [ 'webhook' ],
			'webhook'  => [ 'url', 'secret' ],
		];
		return $keys[ $channel ] ?? [];
	}

	public static function is_enabled( string $channel ): bool {
		return Tet_Notify_Settings::yes( 'tet_notify_' . $channel . '_enabled' );
	}

	public static function enabled(): array {
		return array_values( array_filter( array_keys( self::labels() ), [ __CLASS__, 'is_enabled' ] ) );
	}

	/**
	 * The channel's settings: saved options, or $override values (the test button
	 * posts what is typed in the form, keyed like the option names).
	 */
	public static function config( string $channel, ?array $override = null ): array {
		$config = [];
		foreach ( self::keys( $channel ) as $key ) {
			$option = 'tet_notify_' . $channel . '_' . $key;
			$value  = null !== $override ? ( $override[ $option ] ?? '' ) : get_option( $option, '' );
			$config[ $key ] = in_array( $key, [ 'url', 'webhook' ], true )
				? esc_url_raw( trim( (string) $value ), [ 'http', 'https' ] )
				: trim( sanitize_text_field( (string) $value ) );
		}
		return $config;
	}

	/** Why this configuration can't work, or '' when it looks fine. */
	public static function config_error( string $channel, array $config ): string {
		switch ( $channel ) {
			case 'telegram':
				if ( ! Tet_Notify_Message::valid_telegram_token( $config['token'] ) ) {
					return __( 'The bot token looks wrong. It has the form 123456789:ABC… (from @BotFather).', 'tet-order-notifications' );
				}
				if ( ! Tet_Notify_Message::parse_chat_ids( $config['chat_ids'] ) ) {
					return __( 'Add at least one chat ID.', 'tet-order-notifications' );
				}
				return '';
			case 'slack':
				return Tet_Notify_Message::valid_slack_webhook( $config['webhook'] ) ? '' : __( 'The webhook URL must start with https://hooks.slack.com/', 'tet-order-notifications' );
			case 'discord':
				return Tet_Notify_Message::valid_discord_webhook( $config['webhook'] ) ? '' : __( 'The webhook URL must look like https://discord.com/api/webhooks/…', 'tet-order-notifications' );
			case 'webhook':
				return Tet_Notify_Message::valid_webhook_url( $config['url'] ) ? '' : __( 'Enter a URL starting with https:// or http://', 'tet-order-notifications' );
		}
		return __( 'Unknown channel.', 'tet-order-notifications' );
	}

	/**
	 * Sends one order to one channel.
	 *
	 * @return true|WP_Error
	 */
	public static function send( string $channel, array $config, array $data, bool $is_test = false ) {
		$error = self::config_error( $channel, $config );
		if ( $error ) {
			return new WP_Error( 'tet_notify_config', $error );
		}

		$tags  = Tet_Notify_Settings::tags();
		$title = Tet_Notify_Message::render( Tet_Notify_Settings::title_template(), $data, $tags );
		$body  = Tet_Notify_Message::render( Tet_Notify_Settings::body_template(), $data, $tags );
		if ( $is_test ) {
			/* translators: %s: notification title. */
			$title = sprintf( __( '[Test] %s', 'tet-order-notifications' ), $title );
		}
		$url   = (string) ( $data['order_url'] ?? '' );
		$label = __( 'Open order', 'tet-order-notifications' );

		/**
		 * Filters the title and body before sending. Return [ 'title' => …, 'body' => … ].
		 */
		$message = apply_filters( 'tet_notify_message', [ 'title' => $title, 'body' => $body ], $channel, $data );
		$title   = (string) $message['title'];
		$body    = (string) $message['body'];

		switch ( $channel ) {
			case 'telegram':
				return self::send_telegram( $config, Tet_Notify_Message::telegram_text( $title, $body, $url, $label ) );
			case 'slack':
				return self::post_json( $config['webhook'], Tet_Notify_Message::slack_payload( $title, $body, $url, $label ) );
			case 'discord':
				return self::post_json( $config['webhook'], Tet_Notify_Message::discord_payload( $title, $body, $url, (string) ( $data['date_iso'] ?? '' ) ) );
			case 'webhook':
				return self::send_webhook( $config, Tet_Notify_Message::webhook_payload( $data, $title, $body ), $is_test );
		}
		return new WP_Error( 'tet_notify_channel', __( 'Unknown channel.', 'tet-order-notifications' ) );
	}

	/** @return true|WP_Error */
	private static function send_telegram( array $config, string $text ) {
		foreach ( Tet_Notify_Message::parse_chat_ids( $config['chat_ids'] ) as $chat_id ) {
			$response = wp_remote_post(
				'https://api.telegram.org/bot' . $config['token'] . '/sendMessage',
				[
					'timeout' => self::TIMEOUT,
					'headers' => [ 'Content-Type' => 'application/json' ],
					'body'    => wp_json_encode( [
						'chat_id'                  => $chat_id,
						'text'                     => $text,
						'parse_mode'               => 'HTML',
						'disable_web_page_preview' => true,
					] ),
				]
			);
			if ( is_wp_error( $response ) ) {
				// The error may contain the request URL, i.e. the bot token: keep only the code.
				return new WP_Error( 'tet_notify_http', __( 'Could not reach Telegram.', 'tet-order-notifications' ) . ' (' . $response->get_error_code() . ')' );
			}
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( empty( $json['ok'] ) ) {
				$desc = isset( $json['description'] ) ? sanitize_text_field( $json['description'] ) : 'HTTP ' . wp_remote_retrieve_response_code( $response );
				/* translators: 1: chat ID, 2: error from Telegram. */
				return new WP_Error( 'tet_notify_telegram', sprintf( __( 'Telegram refused the message for chat %1$s: %2$s', 'tet-order-notifications' ), $chat_id, $desc ) );
			}
		}
		return true;
	}

	/** @return true|WP_Error */
	private static function send_webhook( array $config, array $payload, bool $is_test ) {
		$body    = (string) wp_json_encode( $payload );
		$headers = [
			'Content-Type' => 'application/json',
			'X-Tet-Event'  => $is_test ? 'test' : 'order.new',
		];
		if ( '' !== $config['secret'] ) {
			$headers['X-Tet-Signature'] = Tet_Notify_Message::signature( $body, $config['secret'] );
		}
		/**
		 * By default the webhook can't reach private or local addresses (wp_safe_remote_post).
		 * Return false to allow them, e.g. for n8n on the same server.
		 */
		$safe = apply_filters( 'tet_notify_webhook_safe', true, $config['url'] );
		$args = [ 'timeout' => self::TIMEOUT, 'headers' => $headers, 'body' => $body ];
		$response = $safe ? wp_safe_remote_post( $config['url'], $args ) : wp_remote_post( $config['url'], $args );
		return self::check_response( $response, __( 'the webhook', 'tet-order-notifications' ) );
	}

	/** @return true|WP_Error */
	private static function post_json( string $url, array $payload ) {
		$response = wp_remote_post( $url, [
			'timeout' => self::TIMEOUT,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( $payload ),
		] );
		return self::check_response( $response, wp_parse_url( $url, PHP_URL_HOST ) );
	}

	/** @return true|WP_Error */
	private static function check_response( $response, string $target ) {
		if ( is_wp_error( $response ) ) {
			/* translators: 1: service host, 2: error message. */
			return new WP_Error( 'tet_notify_http', sprintf( __( 'Could not reach %1$s: %2$s', 'tet-order-notifications' ), $target, $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$detail = mb_substr( sanitize_text_field( (string) wp_remote_retrieve_body( $response ) ), 0, 200 );
			/* translators: 1: service host, 2: HTTP status code, 3: response text. */
			return new WP_Error( 'tet_notify_http', trim( sprintf( __( '%1$s answered HTTP %2$d. %3$s', 'tet-order-notifications' ), $target, $code, $detail ) ) );
		}
		return true;
	}
}
