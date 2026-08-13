# Site Agent

**God Phrase:** “Ask your site anything. Tell it what to do.”

Site Agent is a private, admin-only WordPress knowledge and action layer. It inventories one site, retrieves relevant local evidence, lets an AI planner request tightly controlled tools, converts writes into exact review plans, and records supported changes for conflict-aware rollback.

Version 0.1.0 is a developer build for backed-up test sites. It is intentionally broad enough to use, but not marketed as omniscient.

## Security boundary

- Authenticated wp-admin browser sessions only.
- No public chatbot and no generic prompt endpoint.
- REST nonce, logged-in cookie, same-origin/referrer, Site Agent capability, and native target permission checks.
- OpenAI API key is read from a constant, environment variable, or filter. It is never stored by the plugin.
- Secrets are excluded before indexing and recursively redacted before provider requests, audit records, and local chat storage.
- Provider output is a plan, never authority.
- Read tools are allowlisted and bounded.
- Write tools are canonicalized before risk classification.
- Every write becomes an exact plan tied to a single-use, user-bound, expiring token.
- Duplicate plan locks prevent accidental double execution.
- Supported writes capture before/after fingerprints.
- Rollback refuses when the current target no longer matches the recorded after-state unless a user with high-risk permission explicitly forces it.
- Network-wide plugin rollback is not claimed.

## Provider setup

```php
define( 'SITE_AGENT_OPENAI_API_KEY', 'your-key-here' );
define( 'SITE_AGENT_OPENAI_MODEL', 'gpt-5-mini' ); // optional
```

The `site_agent_openai_api_key` and `site_agent_openai_model` filters are also available.

## What stays local

The index, audit log, approvals, action ledger, role permissions, retention settings, and optional redacted conversation history stay in the site's database. The plugin does not use embeddings or upload the full site.

## Development

```bash
./bin/validate.sh
./bin/build.sh
```

See `docs/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/ITERATIONS.md`, and `docs/TESTING.md`.
