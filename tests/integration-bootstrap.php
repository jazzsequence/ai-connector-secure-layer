<?php
/**
 * Bootstrap for integration tests.
 *
 * Requires WP_TESTS_DIR to point to a WordPress test library install.
 * wpunit-helpers sets this up via `composer run install-wp-tests`.
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test suite at '{$_tests_dir}'. " .
		"Set WP_TESTS_DIR or run: composer run install-wp-tests\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

function aicsl_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/ai-connector-secure-layer.php';
}
tests_add_filter( 'muplugins_loaded', 'aicsl_manually_load_plugin' );

/*
 * Mock pantheon_get_secret() so the Pantheon Secrets path is exercisable in
 * integration tests. On a real Pantheon environment the function is already
 * defined by the platform's config/application.php, and this guard leaves it
 * alone.
 */
if ( ! function_exists( 'pantheon_get_secret' ) ) {
	function pantheon_get_secret( string $key ): ?string {
		return $GLOBALS['_test_pantheon_secrets'][ $key ] ?? null;
	}
}

require $_tests_dir . '/includes/bootstrap.php';

// Loaded after WP bootstraps so WP AI Client classes are available.
require_once dirname( __DIR__ ) . '/tests/stubs/wp-ai-provider-stubs.php';
