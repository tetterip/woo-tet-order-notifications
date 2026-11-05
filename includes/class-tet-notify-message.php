<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pure logic, no WordPress calls: template tags, per-channel formatting,
 * request payloads and setting validation. Covered by tests/run.php.
 *
 * Order data ($data) is the flat array built by Tet_Notify_Order_Data::from_order():
 * plain-text values, no HTML.
 */
class Tet_Notify_Message {

	/** Template tags and the $data key each one reads. */
	const TAGS = [
		'order_number',
		'order_id',
		'status',
		'total',
		'payment_method',
		'shipping_method',
		'customer_name',
		'email',
		'phone',
		'city',
		'country',
		'items',
		'item_count',
		'customer_note',
		'date',
		'order_url',
		'site_name',
	];

	const TELEGRAM_MAX = 4096;
	const DISCORD_TITLE_MAX = 256;
	const DISCORD_DESC_MAX  = 4096;
	const SLACK_MAX         = 3000;

	/**
	 * Replaces {tag} with the value from $data. Unknown tags are left as they are,
	 * so a typo stays visible instead of silently disappearing.
	 */
	public static function render( string $template, array $data, array $tags = self::TAGS ): string {
		$text = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/',
			static function ( $m ) use ( $data, $tags ) {
				if ( ! in_array( $m[1], $tags, true ) || is_array( $data[ $m[1] ] ?? '' ) ) {
					return $m[0];
				}
				return isset( $data[ $m[1] ] ) ? (string) $data[ $m[1] ] : '';
			},
			$template
		);
		// Tidy what empty tags leave behind: a dangling "·" / "," / "|" separator at the
		// start or end of a line, and lines left with nothing but separators.
		$out = [];
		foreach ( explode( "\n", str_replace( "\r", '', (string) $text ) ) as $line ) {
			$line = preg_replace( '/([·|,])(?:\s*[·|,])+/u', '$1', $line );
			$line = preg_replace( '/^[\s·|,]+|[\s·|,]+$/u', '', (string) $line );
			if ( null !== $line && '' !== $line ) {
				$out[] = $line;
			}
		}
		return implode( "\n", $out );
	}

	/** "2 × Product name" per line. $items: [ ['name' => …, 'qty' => …], … ]. */
	public static function items_text( array $items, int $max = 10 ): string {
		$lines = [];
		foreach ( array_slice( $items, 0, $max ) as $item ) {
			$lines[] = (int) $item['qty'] . ' × ' . $item['name'];
		}
		if ( count( $items ) > $max ) {
			$lines[] = '+' . ( count( $items ) - $max ) . ' …';
		}
		return implode( "\n", $lines );
	}

	/** Multibyte-safe cut with an ellipsis. */
	public static function truncate( string $text, int $max ): string {
		if ( mb_strlen( $text, 'UTF-8' ) <= $max ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, $max - 1, 'UTF-8' ) ) . '…';
	}

	/* ── Formatting per channel ─────────────────────────────── */

	public static function telegram_text( string $title, string $body, string $url, string $link_label ): string {
		$e    = static function ( $s ) {
			return htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );
		};
		$link = $url ? "\n\n" . '<a href="' . htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ) . '">' . $e( $link_label ) . '</a>' : '';
		// Cut the body, never the markup around it.
		$room = self::TELEGRAM_MAX - mb_strlen( $title . $link, 'UTF-8' ) - 20;
		return '<b>' . $e( $title ) . '</b>' . ( '' !== $body ? "\n" . $e( self::truncate( $body, max( 100, $room ) ) ) : '' ) . $link;
	}

	public static function slack_escape( string $s ): string {
		return str_replace( [ '&', '<', '>' ], [ '&amp;', '&lt;', '&gt;' ], $s );
	}

	public static function slack_payload( string $title, string $body, string $url, string $link_label ): array {
		$text = '*' . self::slack_escape( $title ) . '*';
		if ( '' !== $body ) {
			$text .= "\n" . self::slack_escape( self::truncate( $body, self::SLACK_MAX ) );
		}
		if ( $url ) {
			$text .= "\n<" . $url . '|' . self::slack_escape( $link_label ) . '>';
		}
		return [ 'text' => $text ];
	}

	public static function discord_escape( string $s ): string {
		return preg_replace( '/([\\\\*_~`|>#\[\]()-])/', '\\\\$1', $s );
	}

	public static function discord_payload( string $title, string $body, string $url, string $timestamp ): array {
		$embed = [
			'title'       => self::truncate( $title, self::DISCORD_TITLE_MAX ),
			'description' => self::truncate( self::discord_escape( $body ), self::DISCORD_DESC_MAX ),
			'color'       => 0x7F54B3,
		];
		if ( $url ) {
			$embed['url'] = $url;
		}
		if ( $timestamp ) {
			$embed['timestamp'] = $timestamp;
		}
		return [
			'embeds'           => [ $embed ],
			// Never ping @everyone / roles because of text in an order.
			'allowed_mentions' => [ 'parse' => [] ],
		];
	}

	public static function email_body( string $body, string $url, string $link_label ): string {
		return $body . ( $url ? "\n\n" . $link_label . ': ' . $url : '' ) . "\n";
	}

	/** Structured JSON for the generic webhook. */
	public static function webhook_payload( array $data, string $title, string $body ): array {
		return [
			'event'   => 'order.new',
			'site'    => [
				'name' => (string) ( $data['site_name'] ?? '' ),
				'url'  => (string) ( $data['site_url'] ?? '' ),
			],
			'order'   => [
				'id'                   => (int) ( $data['order_id'] ?? 0 ),
				'number'               => (string) ( $data['order_number'] ?? '' ),
				'status'               => (string) ( $data['status_slug'] ?? '' ),
				'total'                => (string) ( $data['total_raw'] ?? '' ),
				'currency'             => (string) ( $data['currency'] ?? '' ),
				'payment_method'       => (string) ( $data['payment_method_id'] ?? '' ),
				'payment_method_title' => (string) ( $data['payment_method'] ?? '' ),
				'shipping_method'      => (string) ( $data['shipping_method'] ?? '' ),
				'created'              => (string) ( $data['date_iso'] ?? '' ),
				'customer'             => [
					'name'    => (string) ( $data['customer_name'] ?? '' ),
					'email'   => (string) ( $data['email'] ?? '' ),
					'phone'   => (string) ( $data['phone'] ?? '' ),
					'city'    => (string) ( $data['city'] ?? '' ),
					'country' => (string) ( $data['country_code'] ?? '' ),
				],
				'note'                 => (string) ( $data['customer_note'] ?? '' ),
				'items'                => array_values( $data['items_list'] ?? [] ),
				'admin_url'            => (string) ( $data['order_url'] ?? '' ),
			],
			'message' => [
				'title' => $title,
				'body'  => $body,
			],
		];
	}

	/** Value of the X-Tet-Signature header: "sha256=" + HMAC of the exact body sent. */
	public static function signature( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	/* ── Validation ─────────────────────────────────────────── */

	public static function valid_telegram_token( string $token ): bool {
		return (bool) preg_match( '/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token );
	}

	/** Comma / newline separated chat ids: numeric (-100… for groups) or @channel. */
	public static function parse_chat_ids( string $value ): array {
		$ids = [];
		foreach ( preg_split( '/[\s,;]+/', $value ) as $id ) {
			if ( preg_match( '/^(-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/', $id ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function parse_emails( string $value ): array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $value ) as $email ) {
			if ( '' !== $email && false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$out[] = $email;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function valid_slack_webhook( string $url ): bool {
		return (bool) preg_match( '#^https://hooks\.slack\.com/(services|workflows|triggers)/[A-Za-z0-9/_-]+$#', $url );
	}

	public static function valid_discord_webhook( string $url ): bool {
		return (bool) preg_match( '#^https://(?:(?:canary|ptb)\.)?discord(?:app)?\.com/api/webhooks/\d+/[A-Za-z0-9_-]+$#', $url );
	}

	public static function valid_webhook_url( string $url ): bool {
		return (bool) preg_match( '#^https?://[^\s/$.?\#][^\s]*$#i', $url );
	}

	/**
	 * Should this status change send a notification? Once per order, only into a
	 * selected status, only for orders created after the plugin was activated and
	 * not older than $max_age seconds (so a late manual status change on an old
	 * order doesn't notify).
	 */
	public static function should_notify( string $status, array $statuses, bool $already_sent, int $created, int $since, int $now, int $max_age, bool $admin_created, bool $include_admin ): bool {
		if ( $already_sent || ! in_array( $status, $statuses, true ) ) {
			return false;
		}
		if ( $admin_created && ! $include_admin ) {
			return false;
		}
		if ( $created < $since || $created < $now - $max_age ) {
			return false;
		}
		return true;
	}
}
