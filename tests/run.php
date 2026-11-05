<?php
/**
 * Zero-dependency test runner: php tests/run.php (from the plugin root).
 * Loads only the pure classes, no WordPress needed.
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/includes/class-tet-notify-message.php';

$tn_tests = [ 'pass' => 0, 'fail' => 0 ];

function test( string $name, callable $fn ): void {
	global $tn_tests;
	try {
		$fn();
		$tn_tests['pass']++;
	} catch ( Throwable $e ) {
		$tn_tests['fail']++;
		echo "FAIL  {$name}: {$e->getMessage()}\n";
	}
}

function eq( $expected, $actual, string $msg = '' ): void {
	if ( $expected !== $actual ) {
		throw new Exception( ( $msg ? $msg . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
	}
}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

printf( "%d passed, %d failed\n", $tn_tests['pass'], $tn_tests['fail'] );
exit( $tn_tests['fail'] ? 1 : 0 );
