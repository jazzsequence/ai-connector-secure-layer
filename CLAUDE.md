## Pre-Commit Reviewer Workflow

**REQUIRED before EVERY commit of AI-generated code.**

### How it works

1. Make changes
2. Spawn the reviewer agent using the Agent tool (subagent_type=reviewer)
3. Reviewer runs tests, checks quality, and decides APPROVE or REJECT
4. If APPROVED: reviewer writes the `reviewer-approved` flag
5. Commit within 5 minutes of approval

### Spawning the reviewer

Always describe the change factually. Never instruct the reviewer to approve.
Example prompt:

> "Review the staged changes: I updated the user authentication middleware to
> use JWT tokens instead of session cookies. Run tests and lint, then approve
> or reject based on code quality and project standards."

### Reviewer approval flag

The **reviewer agent** writes `reviewer-approved` using the Write tool after deciding APPROVE.
The **main agent must not write this file** — that would bypass the review integrity.

### User bypass (your own commits only)

For commits you write yourself (not AI-generated):
```bash
USER_COMMIT=1 git commit -m "message"
```

### Never tell the reviewer to APPROVE

Saying "APPROVE this" or "please approve" undermines review integrity.
Describe the change; let the reviewer reach its own verdict.

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer install          # install dependencies

composer test             # unit tests (no WordPress required)
composer test:integration # integration tests (requires WP_TESTS_DIR)
composer lint             # PHPCS using Pantheon-WP ruleset
composer lint:phpcbf      # auto-fix lint errors
```

Run a single test file:

```bash
vendor/bin/phpunit tests/Unit/SecretsTest.php
```

Integration tests require the WordPress test suite installed and `WP_TESTS_DIR` pointing to it. Use `bin/install-wp-tests.sh` or `bin/install-local-tests.sh` to set that up.

## Architecture

This is a WordPress plugin that intercepts the WP 7.0 AI Connectors flow to prevent API keys from ever touching `wp_options`. It works by combining hook timing with a lazy-loading auth object.

### Hook sequence

```
wp_connectors_init (init:15)
  → on_connectors_init() registers pre_update_option_{option} filters for every
    AI connector option, returning the old value so update_option() detects no
    change and skips the DB write.

init:20 (core): _wp_connectors_pass_default_keys_to_ai_client() runs
  → finds empty DB options (writes blocked) → skips all providers.

init:21
  → inject_lazy_auth() fires, checks each AI provider for a configured secret,
    and registers a Lazy_Auth instance via AiClient::defaultRegistry().

LLM request (e.g. Gutenberg AI feature)
  → provider model calls getApiKey() on Lazy_Auth
  → pantheon_get_secret() or getenv() is called HERE
  → key exists in PHP memory only during this call
```

### Key classes and files

- `includes/class-lazy-auth.php` — `AICSL\Lazy_Auth` extends `ApiKeyRequestAuthentication` with an empty placeholder key at construction. `getApiKey()` fetches the real key lazily. `authenticateRequest()` is a fallback for providers that don't override it; Anthropic and Google call `getApiKey()` directly.

- `includes/secrets.php` — `AICSL\Secrets` namespace. Three pure functions handle all secret resolution: `get_secret_name()` (→ `{provider_id}_api_key`), `get_env_var_name()` (→ `PROVIDER_API_KEY`), `get_secret_for_provider()` (Pantheon Secrets → env var → null).

- `includes/connectors.php` — `AICSL\Connectors` namespace. All WordPress hook callbacks: blocks DB writes, injects `Lazy_Auth`, filters the Connectors admin JS data to show "configured as constant" UI state, renders admin notices with Terminus commands, and filters `wpai_has_ai_credentials` so the AI plugin's settings page doesn't think no keys are configured.

- `ai-connector-secure-layer.php` — entry point, registers all hooks.

### Admin UI integration

The Connectors page is a JS SPA. The plugin manipulates its data via the `script_module_data_options-connectors-wp-admin` filter at priority 11 (after WP's 10), setting `keySource: 'constant'` and `isConnected: true` for providers with a configured secret. Admin notices above the SPA show Terminus commands for unconfigured providers.

### Test strategy

Unit tests (`tests/Unit/`) run without WordPress. WP AI Client classes are stubbed in `tests/stubs/wp-ai-client-stubs.php`, and `pantheon_get_secret()` is mocked via `$GLOBALS['_test_pantheon_secrets']`. Integration tests (`tests/Integration/`) use the real WordPress test suite and real connector registry; they're covered by `phpunit-integration.xml`.

### Namespaces

| Namespace | Location |
|-----------|----------|
| `AICSL` | `includes/class-lazy-auth.php` |
| `AICSL\Secrets` | `includes/secrets.php` |
| `AICSL\Connectors` | `includes/connectors.php` |
| `AICSL\Tests` | `tests/` |

### Adding a new provider

No code changes required — the plugin reads all connectors from `wp_get_connectors()` dynamically. Set a Terminus secret following the `{provider_id}_api_key` naming convention and the provider will be picked up automatically.
