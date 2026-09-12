<?php
/**
 * Secret resolution for AI Connector Secure Layer.
 *
 * Priority: Pantheon Secrets → environment variable → null.
 *
 * Secret naming convention:
 *   provider ID 'anthropic' → Pantheon secret 'anthropic_api_key' / env var 'ANTHROPIC_API_KEY'
 */

namespace AICSL\Secrets;

/**
 * Returns the Pantheon Secrets key name for a provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function get_secret_name( string $provider_id ): string {
	return $provider_id . '_api_key';
}

/**
 * Returns the environment variable name for a provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function get_env_var_name( string $provider_id ): string {
	return strtoupper( $provider_id ) . '_API_KEY';
}

/**
 * Resolves a provider's API key and the source it came from, in priority order.
 *
 * Single point of truth for "where does this provider's key live and what is it".
 * get_secret_source() and get_secret_for_provider() are both thin wrappers, so the
 * two can never disagree about which source wins — a divergence there would show a
 * provider as Connected in the admin while getApiKey() throws at request time.
 *
 * Priority: Pantheon Secrets → environment variable → nothing.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 * @return array{0: string|null, 1: string|null} Source ('pantheon', 'env' or null) and key value.
 */
function resolve_secret( string $provider_id ): array {
	// Pantheon Secrets — only available on Pantheon or local Lando (via secrets.json wrapper).
	if ( function_exists( 'pantheon_get_secret' ) ) {
		$secret = pantheon_get_secret( get_secret_name( $provider_id ) );
		if ( ! empty( $secret ) ) {
			return [ 'pantheon', $secret ];
		}
	}

	// Fall back to environment variable.
	$env_val = getenv( get_env_var_name( $provider_id ) );
	if ( false !== $env_val && '' !== $env_val ) {
		return [ 'env', $env_val ];
	}

	return [ null, null ];
}

/**
 * Returns which source has a key configured for a provider, without returning the key.
 *
 * Use this wherever only the presence — or the origin — of a key matters, so the key
 * value is never pulled into the caller's scope.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 * @return string|null 'pantheon', 'env', or null when nothing is configured.
 */
function get_secret_source( string $provider_id ): ?string {
	return resolve_secret( $provider_id )[0];
}

/**
 * Fetches the API key for a provider from the most secure available source.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 * @return string|null Key value, or null if not configured anywhere.
 */
function get_secret_for_provider( string $provider_id ): ?string {
	return resolve_secret( $provider_id )[1];
}

/**
 * Returns true if any configured secret source has a key for this provider.
 *
 * @param string $provider_id WP AI Client provider ID (e.g. 'anthropic').
 */
function has_secret_for_provider( string $provider_id ): bool {
	return null !== get_secret_source( $provider_id );
}
