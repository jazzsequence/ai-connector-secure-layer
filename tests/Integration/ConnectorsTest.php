<?php
/**
 * Integration tests for AICSL\Connectors hook callbacks.
 *
 * Requires WordPress bootstrapped via wpunit-helpers.
 * Run: composer test:integration
 */

namespace AICSL\Tests\Integration;

use AICSL\Tests\Stubs\StubAnthropicProvider;
use WP_UnitTestCase;
use WordPress\AiClient\AiClient;

class ConnectorsTest extends WP_UnitTestCase {

	/**
	 * Value of $pagenow before a test changed it.
	 *
	 * @var string|null
	 */
	private $original_pagenow;

	protected function setUp(): void {
		parent::setUp();
		$this->original_pagenow = $GLOBALS['pagenow'] ?? null;
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
		putenv( 'PANTHEON_SITE_NAME' );
		unset( $GLOBALS['_test_pantheon_secrets'] );

		// Register a stub anthropic provider so inject_lazy_auth() can set
		// authentication on it without requiring the real provider plugin.
		try {
			AiClient::defaultRegistry()->registerProvider( StubAnthropicProvider::class );
		} catch ( \Exception $e ) {
			// Already registered from a previous test — safe to ignore.
		}
	}

	protected function tearDown(): void {
		putenv( 'ANTHROPIC_API_KEY' );
		putenv( 'GOOGLE_API_KEY' );
		putenv( 'PANTHEON_SITE_NAME' );
		unset( $GLOBALS['_test_pantheon_secrets'] );
		remove_all_filters( 'aicsl_is_pantheon_site' );

		if ( null === $this->original_pagenow ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->original_pagenow;
		}
		// Clean up any options we may have set.
		delete_option( 'connectors_ai_anthropic_api_key' );
		delete_option( 'connectors_ai_google_api_key' );
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// DB write blocking
	// -------------------------------------------------------------------------

	public function test_pre_update_option_filter_is_registered_for_anthropic(): void {
		$this->assertGreaterThan(
			0,
			has_filter( 'pre_update_option_connectors_ai_anthropic_api_key' ),
			'pre_update_option hook should be registered for the anthropic connector option'
		);
	}

	public function test_pre_update_option_filter_is_registered_for_google(): void {
		$this->assertGreaterThan(
			0,
			has_filter( 'pre_update_option_connectors_ai_google_api_key' ),
			'pre_update_option hook should be registered for the google connector option'
		);
	}

	public function test_connector_api_key_cannot_be_saved_to_database(): void {
		// Attempt to save a key through the normal WordPress options API.
		update_option( 'connectors_ai_anthropic_api_key', 'sk-ant-should-not-save' );

		$stored = get_option( 'connectors_ai_anthropic_api_key', '' );
		$this->assertSame( '', $stored, 'API key must not be stored in wp_options' );
	}

	// -------------------------------------------------------------------------
	// Lazy auth injection
	// -------------------------------------------------------------------------

	public function test_lazy_auth_is_injected_when_secret_configured(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-test' );

		// Re-run inject_lazy_auth to simulate post-init state.
		\AICSL\Connectors\inject_lazy_auth();

		$ai_registry = \WordPress\AiClient\AiClient::defaultRegistry();
		$auth        = $ai_registry->getProviderRequestAuthentication( 'anthropic' );

		$this->assertInstanceOf(
			\AICSL\Lazy_Auth::class,
			$auth,
			'Lazy_Auth should be set on the AI registry for anthropic when secret is configured'
		);
	}

	public function test_lazy_auth_not_injected_when_no_secret(): void {
		// No env var, no Pantheon secret — provider should not get our auth.
		\AICSL\Connectors\inject_lazy_auth();

		$ai_registry = \WordPress\AiClient\AiClient::defaultRegistry();

		try {
			$auth = $ai_registry->getProviderRequestAuthentication( 'anthropic' );
			// If we got here without throwing, auth was set — but it shouldn't be ours.
			$this->assertNotInstanceOf(
				\AICSL\Lazy_Auth::class,
				$auth,
				'Lazy_Auth should not be set when no secret is configured'
			);
		} catch ( \Exception $e ) {
			// No auth set at all — also acceptable.
			$this->addToAssertionCount( 1 );
		}
	}

	// -------------------------------------------------------------------------
	// wpai_has_ai_credentials filter
	// -------------------------------------------------------------------------

	public function test_filter_has_ai_credentials_returns_true_when_secret_configured(): void {
		putenv( 'GOOGLE_API_KEY=google-test-key' );

		$connectors = [
			'google' => [ 'type' => 'ai_provider' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertTrue( $result );
	}

	public function test_filter_has_ai_credentials_returns_false_when_no_secret(): void {
		$connectors = [
			'google' => [ 'type' => 'ai_provider' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertFalse( $result );
	}

	public function test_filter_has_ai_credentials_passes_through_existing_true(): void {
		$result = \AICSL\Connectors\filter_has_ai_credentials( true, [] );

		$this->assertTrue( $result );
	}

	public function test_filter_has_ai_credentials_ignores_non_ai_connectors(): void {
		// Akismet has type spam_filtering — should not satisfy the credential check.
		$connectors = [
			'akismet' => [ 'type' => 'spam_filtering' ],
		];

		$result = \AICSL\Connectors\filter_has_ai_credentials( false, $connectors );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// script_module_data filter
	// -------------------------------------------------------------------------

	public function test_script_module_data_sets_key_source_to_env_when_env_var_configured(): void {
		putenv( 'ANTHROPIC_API_KEY=sk-ant-test' );

		$input = [
			'connectors' => [
				'anthropic' => [
					'type'           => 'ai_provider',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		// Core already resolves env vars itself as of 7.1; reporting 'constant'
		// here would mislabel a key that really does come from the environment.
		$this->assertSame( 'env', $output['connectors']['anthropic']['authentication']['keySource'] );
		$this->assertTrue( $output['connectors']['anthropic']['authentication']['isConnected'] );
	}

	public function test_script_module_data_sets_key_source_to_constant_for_pantheon_secret(): void {
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'sk-ant-from-pantheon';

		$input = [
			'connectors' => [
				'anthropic' => [
					'type'           => 'ai_provider',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		// Core has no 'secret' key source; 'constant' is the closest match for
		// "set outside the database and not editable from this form".
		$this->assertSame( 'constant', $output['connectors']['anthropic']['authentication']['keySource'] );
		$this->assertTrue( $output['connectors']['anthropic']['authentication']['isConnected'] );
	}

	public function test_script_module_data_leaves_unconfigured_providers_unchanged(): void {
		$input = [
			'connectors' => [
				'anthropic' => [
					'type'           => 'ai_provider',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		$this->assertSame( 'none', $output['connectors']['anthropic']['authentication']['keySource'] );
		$this->assertFalse( $output['connectors']['anthropic']['authentication']['isConnected'] );
	}

	public function test_script_module_data_does_not_modify_non_ai_connectors(): void {
		putenv( 'AKISMET_API_KEY=akismet-key' );

		$input = [
			'connectors' => [
				'akismet' => [
					'type'           => 'spam_filtering',
					'authentication' => [
						'keySource'   => 'none',
						'isConnected' => false,
					],
				],
			],
		];

		$output = \AICSL\Connectors\filter_script_module_data( $input );

		$this->assertSame( 'none', $output['connectors']['akismet']['authentication']['keySource'] );
		putenv( 'AKISMET_API_KEY' );
	}

	// -------------------------------------------------------------------------
	// admin notices
	// -------------------------------------------------------------------------

	/**
	 * Captures show_admin_notices() output on the Connectors screen.
	 */
	private function render_notices(): string {
		$GLOBALS['pagenow'] = 'options-connectors.php';

		ob_start();
		\AICSL\Connectors\show_admin_notices();
		return (string) ob_get_clean();
	}

	public function test_admin_notice_is_not_rendered_outside_the_connectors_screen(): void {
		$GLOBALS['pagenow'] = 'options-general.php';

		ob_start();
		\AICSL\Connectors\show_admin_notices();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_admin_notice_shows_terminus_command_on_pantheon(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_true' );

		$output = $this->render_notices();

		$this->assertStringContainsString( 'Pantheon Secrets', $output );
		$this->assertStringContainsString( 'terminus secret:site:set', $output );
		$this->assertStringContainsString( 'anthropic_api_key', $output );
		$this->assertStringNotContainsString( 'ANTHROPIC_API_KEY=YOUR_KEY', $output );
	}

	public function test_admin_notice_shows_env_var_off_pantheon(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_false' );

		$output = $this->render_notices();

		$this->assertStringContainsString( 'ANTHROPIC_API_KEY=YOUR_KEY', $output );
		$this->assertStringNotContainsString( 'terminus', $output );
		$this->assertStringNotContainsString( 'Pantheon Secrets', $output );
	}

	public function test_admin_notice_omits_providers_that_already_have_a_secret(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_false' );
		putenv( 'ANTHROPIC_API_KEY=sk-ant-test' );

		$output = $this->render_notices();

		// The positive assertion matters: without it this test would also pass if
		// the notice rendered nothing at all.
		$this->assertStringContainsString( 'GOOGLE_API_KEY=YOUR_KEY', $output );
		$this->assertStringNotContainsString( 'ANTHROPIC_API_KEY=YOUR_KEY', $output );
	}

	public function test_admin_notice_omits_providers_configured_via_pantheon_secret(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_true' );
		$GLOBALS['_test_pantheon_secrets']['anthropic_api_key'] = 'sk-ant-from-pantheon';

		$output = $this->render_notices();

		$this->assertStringContainsString( 'google_api_key', $output );
		$this->assertStringNotContainsString( 'anthropic_api_key', $output );
	}

	public function test_admin_notice_is_skipped_when_every_provider_is_configured(): void {
		// Cover every registered AI provider, not just the two built-ins we know by name.
		$provider_ids = [];
		foreach ( wp_get_connectors() as $id => $data ) {
			if ( 'ai_provider' === ( $data['type'] ?? '' ) ) {
				$provider_ids[] = $id;
			}
		}

		/*
		 * Without this the test is vacuous: no providers means the setup loop is a
		 * no-op, the notice returns early, and the assertion below passes having
		 * proved nothing.
		 */
		$this->assertNotEmpty( $provider_ids, 'expected at least one registered AI provider' );

		// Snapshot rather than blindly unset — an ambient key must survive the test.
		$restore = [];
		foreach ( $provider_ids as $id ) {
			$env_var_name             = \AICSL\Secrets\get_env_var_name( $id );
			$restore[ $env_var_name ] = getenv( $env_var_name );
			putenv( $env_var_name . '=configured' );
		}

		try {
			$output = $this->render_notices();
		} finally {
			// finally, so a throw in render_notices() cannot leak env state into the
			// rest of the run — setUp()/tearDown() only know the two built-ins by name.
			foreach ( $restore as $env_var_name => $previous ) {
				putenv( false === $previous ? $env_var_name : $env_var_name . '=' . $previous );
			}
		}

		$this->assertSame( '', $output );
	}

	public function test_terminus_site_name_prefers_the_pantheon_env_var(): void {
		putenv( 'PANTHEON_SITE_NAME=my-real-site' );

		$this->assertSame( 'my-real-site', \AICSL\Connectors\get_terminus_site_name() );
	}

	public function test_terminus_site_name_falls_back_to_a_slug_of_the_site_title(): void {
		update_option( 'blogname', 'My Example Site' );

		$this->assertSame( 'my-example-site', \AICSL\Connectors\get_terminus_site_name() );
	}

	public function test_terminus_command_uses_the_pantheon_site_name(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_true' );
		putenv( 'PANTHEON_SITE_NAME=my-real-site' );

		$output = $this->render_notices();

		$this->assertStringContainsString( 'terminus secret:site:set my-real-site', $output );
	}

	public function test_is_pantheon_site_is_filterable(): void {
		add_filter( 'aicsl_is_pantheon_site', '__return_false' );

		$this->assertFalse( \AICSL\Connectors\is_pantheon_site() );
	}
}
