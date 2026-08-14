=== Site Agent ===
Contributors: maisy
Tags: ai, administration, rollback, diagnostics, knowledge base
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask your site anything. Tell it what to do.

== Description ==

Site Agent is a private WordPress administration agent for authenticated wp-admin users.

It builds a local, secret-filtered knowledge index of the site; answers administrative questions; runs bounded diagnostics and plugin-dependency analysis; proposes controlled changes; requires exact-plan approval; records supported before-and-after states; and offers conflict-aware rollback where a verified snapshot exists.

This is an early developer build intended for controlled testing on backed-up sites.

= Core boundaries =

* No public chatbot.
* No generic prompt proxy.
* OpenAI API keys saved in WordPress use authenticated encryption and are never rendered back to the browser.
* Browser-cookie, REST nonce, origin/referrer, WordPress capability, and per-target permission checks.
* Model output is planning input only. It cannot execute a write directly.
* Every write is canonicalized, validated, risk-rated, reviewed, approved, and audited.
* Secret-like fields and values are excluded before indexing and redacted again before provider requests and logs.
* Plugin source and raw builder payloads are not sent to AI.
* Rollback is shown only for supported captured changes.

= Current inspection tools =

* Local knowledge search
* Recent change history
* WordPress/database/runtime diagnostics
* Plugin removal-impact analysis
* Post/page reading
* Installed plugin inventory
* Scheduled event inventory
* SEO metadata gap detection

= Current controlled actions =

* Create posts and pages
* Update approved post/page fields
* Trash posts/pages
* Update supported builder metadata
* Update Yoast and Rank Math metadata
* Update a small allowlist of non-secret core settings
* Activate/deactivate installed plugins on the current site
* Delete expired transients
* Roll back supported ledger entries

== Installation ==

1. Back up the site.
2. Upload and activate Site Agent.
3. Open Site Agent > Settings and save an API key using encrypted storage, or add it outside the database (preferably in wp-config.php):
   `define( 'SITE_AGENT_OPENAI_API_KEY', 'your-key-here' );`
4. Open Site Agent > Knowledge and rebuild the local index.
5. Review Site Agent > Roles before granting non-administrators execution rights.

The key may also be supplied through the `SITE_AGENT_OPENAI_API_KEY` environment variable or `site_agent_openai_api_key` filter.

== Privacy ==

Site Agent stores its local index, redacted chat history (when enabled), audit events, approvals, and change ledger in the WordPress database.

When OpenAI is configured, Site Agent sends the current redacted question plus a bounded set of relevant local evidence. It sets provider-side response storage to false and does not fine-tune a model. OpenAI account retention and Zero Data Retention eligibility remain governed by the customer's OpenAI agreement and account configuration; the plugin cannot grant ZDR by itself.

== Limitations ==

* A WordPress plugin cannot reliably see all hosting telemetry, SaaS state, DNS state, vendor accounts, or server logs.
* The impact scanner is bounded and evidence-based; it cannot prove that removal is consequence-free.
* Rollback is targeted, not a full-site backup.
* Network-wide plugin rollback is intentionally unsupported.
* Some plugin and builder integrations need dedicated adapters for complete semantic editing.
* The model configured in Settings must exist in the connected OpenAI account.

== Changelog ==

= 0.3.5 =
* Kept working, source, proposal, failure, and recovery states inside the Jira SA-3 conversation flow.
* Added draft-preserving Try again and Edit request controls without duplicate user turns.
* Added bounded current-user recent conversation history when local storage is enabled.
* Added active, success, failure, retry, history, accessibility, and reduced-motion regressions.

= 0.3.4 =
* Decoded one stored WordPress entity layer for readable Knowledge result titles in Jira SA-17.
* Kept stored titles in Technical details and retained safe text-only primary rendering.
* Added entity, apostrophe, already-decoded, and double-decoding regressions.

= 0.3.3 =
* Preserved readable boundaries while indexing page content for Jira SA-17.
* Removed navigation and footer boilerplate from indexed content.
* Centered Knowledge excerpts on the search phrase with a calm metadata-match fallback.

= 0.3.2 =
* Incremented the public asset cache identity for the completed Jira SA-17 Site Knowledge interface.
* Kept contextual help inside the Site Agent panel at narrow mobile widths.

= 0.3.1 =
* Reworked Site Knowledge in plain language for Jira SA-17.
* Added an obvious read-only scan action when site knowledge is empty or stale.
* Replaced raw search records with readable summaries and optional technical details.
* Added accessible contextual help and calm plugin-safety guidance.

= 0.3.0 =
* Added focused homepage-change guidance for Jira SA-16.
* Uses bounded indexed discovery to identify the configured homepage and editing system before asking the user.
* Asks one plain-language content-goal question with a suggested default instead of a technical checklist.
* Defaults the next step to a reversible reviewable proposal that requires explicit approval.

= 0.2.7 =
* Release blocker fixes: Jira SA-11 and SA-12.
* Answered current Site Agent version questions from authoritative live plugin state without a slow two-stage provider round trip.
* Moved `chat_completed` to a separate browser-render receipt so disconnected responses remain `chat_response_ready`, not falsely visible completions.
* Reduced the shared provider budget below the observed gateway boundary and added clear Retry/Edit recovery without duplicating the user turn.

= 0.2.6 =
* Bounded the complete chat provider workflow below common hosting gateway limits.
* Prevented network timeouts from triggering duplicate structured-output fallback requests.
* Added specific, recoverable timeout messages in both the REST and browser layers.
* Added regression coverage for provider deadlines and retry eligibility.

= 0.2.5 =
* Added deterministic API-key lifecycle tests covering missing, invalid, valid, replaced, rejected-replacement, and removed states.
* Added persistent dismiss and reopen controls for first-run setup guidance.
* Added explicit Enter and Space activation for starter prompts with automated keyboard coverage.
* Required explicit supported change requests to return a validated proposal or clarification instead of prose-only plans.
* Added a signed-in status readback that verifies the installed release file manifest and exposes its SHA-256 identity.

= 0.2.4 =
* Kept the chat composer in document order so it cannot obscure starter prompts or messages at supported viewports.

= 0.2.3 =
* Normalized bounded OpenAI tool-call encodings before allowlist and canonical argument validation.

= 0.2.2 =
* Allowed intermediate read/write tool plans without an answer while preventing empty final chat completions.

= 0.2.1 =
* Hardened OpenAI planning-response parsing, validation, retry, refusal/incomplete handling, and safe diagnostics.

= 0.2.0 =
* Added guided encrypted OpenAI API-key setup, validation, replacement, and removal.
* Added Application Password support while preserving cookie nonce and same-origin checks.
* Rebuilt the admin experience around chat, history, settings, progressive disclosure, responsive design, and accessible interaction states.

= 0.1.0 =
* Initial full developer build.
