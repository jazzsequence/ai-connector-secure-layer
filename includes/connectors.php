<?php
/**
 * WordPress Connectors API integration for AI Connector Secure Layer.
 *
 * Hooks:
 *   wp_connectors_init  — blocks DB writes for all AI connector options
 *   init:21             — injects Lazy_Auth into the AI client registry
 *   script_module_data_options-connectors-wp-admin:11 — updates UI state for configured providers
 *   admin_notices       — Terminus instructions for unconfigured providers on the Connectors page
 *
 * @package AICSL
 */

namespace AICSL\Connectors;

use AICSL\Lazy_Auth;
use WordPress\AiClient\AiClient;

/**
 * Fired on wp_connectors_init (during init:15).
 *
 * Registers pre_update_option filters for every AI connector option so that
 * keys cannot be saved to wp_options through the Connectors UI or REST API.
 *
 * @param \WP_Connector_Registry $registry The connector registry (unused; wp_get_connectors() is the public API).
 */
function on_connectors_init( \WP_Connector_Registry $registry ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
	foreach ( wp_get_connectors() as $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}

		$option = $data['authentication']['setting_name'] ?? '';
		if ( ! $option ) {
			continue;
		}

		// Return old value so update_option() detects no change and skips the write.
		add_filter(
			"pre_update_option_{$option}",
			static fn( $new_value, $old ) => $old,
			10,
			2
		);
	}
}

/**
 * Fired at init:21 — after _wp_connectors_pass_default_keys_to_ai_client() at init:20.
 *
 * For each AI provider that has a secret configured, injects a Lazy_Auth instance
 * into the AI client registry. The key is fetched from Pantheon Secrets or an
 * environment variable only when an LLM request is actually made.
 */
function inject_lazy_auth(): void {
	try {
		$ai_registry = AiClient::defaultRegistry();
	} catch ( \Exception $e ) {
		return;
	}

	foreach ( wp_get_connectors() as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}

		if ( ! \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			continue;
		}

		try {
			$ai_registry->setProviderRequestAuthentication( $id, new Lazy_Auth( $id ) );
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Provider plugin may not be active; intentionally silent.
			unset( $e );
		}
	}
}

/**
 * Filters the data passed to the Connectors admin JS module.
 *
 * For providers with a configured secret, sets keySource to the source the key
 * actually came from and isConnected to true (green Connected badge). Both 'env'
 * and 'constant' put the field into the read-only "configured outside WordPress"
 * UI state; core has no source for Pantheon Secrets, so those report as
 * 'constant' — the closest match for "set outside the database, not editable here".
 * Keys in environment variables report as 'env', which is what core already
 * detects at priority 10; overriding those with 'constant' would mislabel them.
 *
 * The equivalence of 'env' and 'constant' is core's, not an assumption: the
 * connectors SPA branches on
 * `isExternallyConfigured = keySource === "env" || keySource === "constant"`
 * (wp-includes/build/routes/connectors-home/content.js as of 7.1) and uses that
 * single flag for the read-only field and the masked value.
 *
 * @param array<string, mixed> $data Script module data passed by WordPress to the Connectors admin JS.
 * @return array<string, mixed>
 */
function filter_script_module_data( array $data ): array {
	if ( ! isset( $data['connectors'] ) || ! is_array( $data['connectors'] ) ) {
		return $data;
	}

	foreach ( $data['connectors'] as $id => $connector ) {
		if ( 'ai_provider' !== ( $connector['type'] ?? '' ) ) {
			continue;
		}

		$source = \AICSL\Secrets\get_secret_source( $id );
		if ( null === $source ) {
			continue;
		}

		$data['connectors'][ $id ]['authentication']['keySource']   = 'env' === $source ? 'env' : 'constant';
		$data['connectors'][ $id ]['authentication']['isConnected'] = true;
	}

	return $data;
}

/**
 * Tells the WordPress AI plugin (wordpress.org/plugins/ai) that credentials are
 * available when a Pantheon Secret or environment variable is configured for any
 * AI provider.
 *
 * The AI plugin's has_ai_credentials() checks get_option() directly. Because this
 * plugin blocks DB writes for connector options, that check always returns empty.
 * This filter corrects that so the AI settings page and editor features stay enabled.
 *
 * @param bool  $has_credentials Whether the AI plugin found credentials via wp_options.
 * @param array $connectors      All registered connectors.
 */
function filter_has_ai_credentials( bool $has_credentials, array $connectors ): bool {
	if ( $has_credentials ) {
		return true;
	}

	foreach ( $connectors as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}
		if ( \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns true when the site is running on Pantheon with the Secrets API available.
 *
 * Determines which set of instructions the admin notice shows: Terminus commands on
 * Pantheon, environment variables everywhere else. Telling a non-Pantheon admin to
 * run `terminus secret:site:set` is instructions they cannot follow.
 */
function is_pantheon_site(): bool {
	/**
	 * Filters whether this site should be treated as a Pantheon site.
	 *
	 * Controls which setup instructions the Connectors admin notice renders. Useful on
	 * a Pantheon site that deliberately configures keys through environment variables
	 * instead of Secrets, and it gives the detection a seam for tests — the Secrets API
	 * is a bare function that cannot otherwise be undefined once loaded.
	 *
	 * @param bool $is_pantheon Whether the Pantheon Secrets API is available.
	 */
	return (bool) apply_filters( 'aicsl_is_pantheon_site', function_exists( 'pantheon_get_secret' ) );
}

/**
 * Returns the Pantheon site name to use in Terminus command examples.
 *
 * PANTHEON_SITE_NAME is set on every Pantheon environment and is the machine name
 * Terminus expects. Falls back to a slug of the site title, which is only a guess.
 */
function get_terminus_site_name(): string {
	$site_name = getenv( 'PANTHEON_SITE_NAME' );

	if ( is_string( $site_name ) && '' !== $site_name ) {
		return $site_name;
	}

	return sanitize_title( get_bloginfo( 'name' ) );
}

/**
 * Shows admin notices on the Connectors page for unconfigured providers.
 *
 * The Connectors page is a JS SPA but renders inside the standard wp-admin
 * header which does output admin_notices above the app div.
 */
function show_admin_notices(): void {
	global $pagenow;

	if ( 'options-connectors.php' !== ( $pagenow ?? '' ) ) {
		return;
	}

	$unconfigured = [];
	foreach ( wp_get_connectors() as $id => $data ) {
		if ( 'ai_provider' !== ( $data['type'] ?? '' ) ) {
			continue;
		}
		if ( ! \AICSL\Secrets\has_secret_for_provider( $id ) ) {
			$unconfigured[ $id ] = $data;
		}
	}

	if ( empty( $unconfigured ) ) {
		return;
	}

	$on_pantheon = is_pantheon_site();

	echo '<div class="notice notice-info"><p>';

	if ( $on_pantheon ) {
		echo '<strong>' . esc_html__( 'AI keys managed via Pantheon Secrets', 'ai-connector-secure-layer' ) . '</strong><br>';
		esc_html_e(
			'This site manages AI provider API keys through Pantheon Secrets — not through this form. Keys entered here cannot be saved. To connect a provider, run the Terminus command for it:',
			'ai-connector-secure-layer'
		);
	} else {
		echo '<strong>' . esc_html__( 'AI keys managed outside the database', 'ai-connector-secure-layer' ) . '</strong><br>';
		esc_html_e(
			'This site reads AI provider API keys from environment variables — not from this form. Keys entered here cannot be saved. To connect a provider, set its environment variable at the server level:',
			'ai-connector-secure-layer'
		);
	}

	echo '</p><ul>';

	foreach ( $unconfigured as $id => $data ) {
		$provider_name = $data['name'];

		echo '<li>' . esc_html( $provider_name ) . ': ';

		if ( $on_pantheon ) {
			$secret_name = \AICSL\Secrets\get_secret_name( $id );
			$site_name   = get_terminus_site_name();
			echo '<code>' . esc_html( "terminus secret:site:set {$site_name} {$secret_name} YOUR_KEY --type=runtime --scope=web,user" ) . '</code>';
		} else {
			$env_var_name = \AICSL\Secrets\get_env_var_name( $id );
			echo '<code>' . esc_html( "{$env_var_name}=YOUR_KEY" ) . '</code>';
		}

		echo '</li>';
	}

	echo '</ul></div>';
}
