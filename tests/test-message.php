<?php
/**
 * Tests for Tet_Notify_Message (pure logic).
 */

$data = [
	'order_number'  => '1234',
	'total'         => '42,50 €',
	'payment_method'=> 'Αντικαταβολή',
	'customer_name' => 'Μαρία Παπαδοπούλου',
	'city'          => 'Αθήνα',
	'items'         => "2 × Μπλούζα\n1 × Παντελόνι",
	'customer_note' => '',
	'email'         => 'm@example.com',
];

test( 'render replaces known tags', function () use ( $data ) {
	eq( 'New order #1234', Tet_Notify_Message::render( 'New order #{order_number}', $data ) );
} );

test( 'render keeps unknown tags visible', function () use ( $data ) {
	eq( 'Hi {nope}', Tet_Notify_Message::render( 'Hi {nope}', $data ) );
} );

test( 'render drops lines left empty by a tag', function () use ( $data ) {
	eq( "42,50 €\nΑθήνα", Tet_Notify_Message::render( "{total}\n{customer_note}\n{city}", $data ) );
} );

test( 'render tidies dangling separators', function () use ( $data ) {
	$d = array_merge( $data, [ 'payment_method' => '' ] );
	eq( '42,50 €', Tet_Notify_Message::render( '{total} · {payment_method}', $d ) );
	eq( '42,50 € · Αθήνα', Tet_Notify_Message::render( '{total} · {customer_note} · {city}', $d ) );
} );

test( 'render does not corrupt Greek letters next to separators', function () use ( $data ) {
	// "η" is 0xCE 0xB7; "·" is 0xC2 0xB7: a byte-wise trim would break it.
	eq( 'Αθήνα η', Tet_Notify_Message::render( '{city} η ·', $data ) );
} );

test( 'render ignores array values', function () {
	eq( '{items_list}', Tet_Notify_Message::render( '{items_list}', [ 'items_list' => [ 1 ] ], [ 'items_list' ] ) );
} );

test( 'render accepts extra tags', function () {
	eq( 'X: 5', Tet_Notify_Message::render( 'X: {my_tag}', [ 'my_tag' => 5 ], [ 'my_tag' ] ) );
} );

test( 'items_text lists quantity × name and caps the list', function () {
	$items = [];
	for ( $i = 1; $i <= 12; $i++ ) {
		$items[] = [ 'name' => "P$i", 'qty' => 1 ];
	}
	$text = Tet_Notify_Message::items_text( $items );
	eq( 11, count( explode( "\n", $text ) ) );
	eq( '+2 …', substr( $text, strrpos( $text, "\n" ) + 1 ) );
} );

test( 'truncate is multibyte safe', function () {
	eq( 'Αθή…', Tet_Notify_Message::truncate( 'Αθήνα', 4 ) );
	eq( 'Αθήνα', Tet_Notify_Message::truncate( 'Αθήνα', 5 ) );
} );

test( 'telegram text escapes HTML and bolds the title', function () {
	$t = Tet_Notify_Message::telegram_text( 'Order <1>', 'A & B', 'https://x.gr/?a=1&b=2', 'Open' );
	eq( "<b>Order &lt;1&gt;</b>\nA &amp; B\n\n<a href=\"https://x.gr/?a=1&amp;b=2\">Open</a>", $t );
} );

test( 'telegram text stays under the limit', function () {
	$t = Tet_Notify_Message::telegram_text( 'T', str_repeat( 'α', 6000 ), '', 'Open' );
	if ( mb_strlen( $t, 'UTF-8' ) > Tet_Notify_Message::TELEGRAM_MAX ) {
		throw new Exception( 'too long: ' . mb_strlen( $t, 'UTF-8' ) );
	}
} );

test( 'slack payload escapes control characters and links the order', function () {
	$p = Tet_Notify_Message::slack_payload( 'New <b>', 'x & y', 'https://shop.gr/o', 'Open' );
	eq( "*New &lt;b&gt;*\nx &amp; y\n<https://shop.gr/o|Open>", $p['text'] );
} );

test( 'discord payload escapes markdown and blocks mentions', function () {
	$p = Tet_Notify_Message::discord_payload( 'New', '**bold** @everyone', 'https://shop.gr/o', '2026-11-05T10:00:00+02:00' );
	eq( '\\*\\*bold\\*\\* @everyone', $p['embeds'][0]['description'] );
	eq( [], $p['allowed_mentions']['parse'] );
	eq( 'https://shop.gr/o', $p['embeds'][0]['url'] );
} );

test( 'webhook payload has the order structure', function () use ( $data ) {
	$p = Tet_Notify_Message::webhook_payload( array_merge( $data, [ 'order_id' => 9, 'items_list' => [ [ 'name' => 'A', 'qty' => 1 ] ] ] ), 'T', 'B' );
	eq( 'order.new', $p['event'] );
	eq( 9, $p['order']['id'] );
	eq( 'm@example.com', $p['order']['customer']['email'] );
	eq( 'A', $p['order']['items'][0]['name'] );
} );

test( 'signature is the HMAC of the body', function () {
	eq( 'sha256=' . hash_hmac( 'sha256', '{"a":1}', 's3cret' ), Tet_Notify_Message::signature( '{"a":1}', 's3cret' ) );
} );

test( 'telegram token validation', function () {
	eq( true, Tet_Notify_Message::valid_telegram_token( '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw' ) );
	eq( false, Tet_Notify_Message::valid_telegram_token( 'abc:123' ) );
	eq( false, Tet_Notify_Message::valid_telegram_token( '' ) );
} );

test( 'chat ids: numbers, groups, @channels; junk dropped', function () {
	eq( [ '12345', '-1001234567890', '@my_shop' ], Tet_Notify_Message::parse_chat_ids( "12345, -1001234567890\n@my_shop, bad id!, 12345" ) );
} );

test( 'email list parsing', function () {
	eq( [ 'a@b.gr', 'c@d.com' ], Tet_Notify_Message::parse_emails( 'a@b.gr; nope, c@d.com a@b.gr' ) );
} );

test( 'slack and discord webhook validation', function () {
	eq( true, Tet_Notify_Message::valid_slack_webhook( 'https://hooks.slack.com/services/T000/B000/XXXX' ) );
	eq( false, Tet_Notify_Message::valid_slack_webhook( 'https://evil.example/services/T0' ) );
	eq( true, Tet_Notify_Message::valid_discord_webhook( 'https://discord.com/api/webhooks/123/abc-DEF_1' ) );
	eq( true, Tet_Notify_Message::valid_discord_webhook( 'https://discordapp.com/api/webhooks/123/abc' ) );
	eq( false, Tet_Notify_Message::valid_discord_webhook( 'https://discord.com.evil.gr/api/webhooks/1/a' ) );
} );

test( 'generic webhook URL validation', function () {
	eq( true, Tet_Notify_Message::valid_webhook_url( 'https://n8n.example.com/webhook/abc' ) );
	eq( true, Tet_Notify_Message::valid_webhook_url( 'http://localhost:5678/webhook' ) );
	eq( false, Tet_Notify_Message::valid_webhook_url( 'ftp://x' ) );
	eq( false, Tet_Notify_Message::valid_webhook_url( '' ) );
} );

$now   = 1_800_000_000;
$since = $now - 30 * 86400;
$week  = 7 * 86400;

test( 'should_notify: selected status, new order → yes', function () use ( $now, $since, $week ) {
	eq( true, Tet_Notify_Message::should_notify( 'processing', [ 'processing', 'on-hold' ], false, $now - 60, $since, $now, $week, false, false ) );
} );

test( 'should_notify: once per order', function () use ( $now, $since, $week ) {
	eq( false, Tet_Notify_Message::should_notify( 'processing', [ 'processing' ], true, $now - 60, $since, $now, $week, false, false ) );
} );

test( 'should_notify: pending is not announced', function () use ( $now, $since, $week ) {
	eq( false, Tet_Notify_Message::should_notify( 'pending', [ 'processing', 'on-hold' ], false, $now - 60, $since, $now, $week, false, false ) );
} );

test( 'should_notify: orders from before activation are skipped', function () use ( $now, $since, $week ) {
	eq( false, Tet_Notify_Message::should_notify( 'processing', [ 'processing' ], false, $since - 1, $since, $now, $week, false, false ) );
} );

test( 'should_notify: old orders changing status are skipped', function () use ( $now, $week ) {
	eq( false, Tet_Notify_Message::should_notify( 'processing', [ 'processing' ], false, $now - $week - 1, 0, $now, $week, false, false ) );
} );

test( 'should_notify: admin-created orders only when enabled', function () use ( $now, $since, $week ) {
	eq( false, Tet_Notify_Message::should_notify( 'processing', [ 'processing' ], false, $now - 60, $since, $now, $week, true, false ) );
	eq( true, Tet_Notify_Message::should_notify( 'processing', [ 'processing' ], false, $now - 60, $since, $now, $week, true, true ) );
} );
