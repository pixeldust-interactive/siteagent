# Site Agent architecture

## Product contract

**God Phrase:** “Ask your site anything. Tell it what to do.”

Site Agent is not a remote-control prompt box. It is six cooperating layers:

1. **Local evidence index** — bounded, secret-filtered inventory of the current WordPress site.
2. **Deterministic inspection tools** — diagnostics, change history, content lookup, plugin inventory, cron, SEO gaps, and removal-impact evidence.
3. **AI planner** — interprets the administrator’s question and may request only registered tools.
4. **Action registry** — canonicalizes arguments, applies WordPress and Site Agent capabilities, calculates risk, and creates an exact plan.
5. **Approval and execution layer** — user-bound, one-time approval tokens and duplicate-plan locks.
6. **Change Ledger** — supported before/after snapshots, fingerprints, audit trail, and conflict-aware targeted rollback.

The AI planner never writes to WordPress.

## Request flow

### Question only

1. Browser sends a nonce-authenticated request from wp-admin.
2. Browser Guard requires a logged-in cookie, REST nonce, matching origin/referrer, and Site Agent capability.
3. The question is redacted.
4. The local retriever selects a bounded evidence set.
5. OpenAI receives the redacted question and only the selected evidence. The request sets `store=false`.
6. The model may request up to three read tools.
7. The server validates and executes only registered read tools.
8. The model writes the answer using the validated results.
9. The local audit log records metadata and a prompt hash, not the raw prompt.

### Change request

1. The model returns proposed write actions.
2. The server canonicalizes every action name and argument.
3. Secret-like payloads, unsupported targets, oversized payloads, and unauthorized targets are rejected.
4. Risk is calculated only after canonicalization.
5. The server creates a review plan containing exact arguments, previews, risk, and required capability.
6. The approval service stores the exact plan and returns a random token to that browser.
7. The token is user-bound, single-use, hash-stored, and expires after ten minutes.
8. Execution rechecks Site Agent capabilities and native WordPress target permissions.
9. A duplicate-plan lock prevents double submission.
10. Each successful action records its own ledger entry.
11. If a later action fails, execution stops. Earlier successful actions remain visible and individually rollbackable when supported.

## Knowledge index

The index uses SQL retrieval rather than provider-hosted embeddings. It includes:

- posts and pages excluding known submissions, orders, transactions, customer records, and similar sensitive content types;
- installed plugin and theme metadata;
- registered post types and taxonomies;
- safe core settings only;
- builder detection and usage counts, not raw builder payloads;
- form-definition counts, never form entries;
- cron hook names and aggregate schedule evidence;
- role and user counts, never user records;
- database table names and aggregate sizes, never row contents;
- WordPress/PHP/database/site metadata.

Every rebuild uses a generation UUID. A rebuild writes into a separate build generation while retrieval continues using the last completed generation. The active-generation pointer changes only after every phase succeeds; older generations are then deleted. Posts are walked by increasing ID in bounded batches; there is no fixed 10,000-post ceiling.

## Change capture

Site Agent records its own supported actions directly. It also observes selected WordPress changes made outside Site Agent:

- post updates;
- supported builder and SEO post metadata;
- non-secret options;
- local plugin activation/deactivation;
- theme switches.

Sensitive option names, transient churn, cron storage, Site Agent’s own options, unsupported payloads, and oversized snapshots are excluded or marked non-reversible.

## Rollback

Rollback is targeted, not a full-site backup.

A rollback is available only when:

- both snapshots were encoded and integrity-hashed;
- the target type is supported;
- the current user has `site_agent_rollback`;
- the user also has WordPress’s native permission for the target;
- the target’s current fingerprint still matches the original after-state.

A newer-state mismatch produces a conflict. Forced rollback additionally requires `site_agent_execute_high`.

Network-wide plugin rollback is intentionally unsupported.

## Multisite

Tables, settings, indexes, and capabilities are site-specific. Network activation installs per-site tables and provisions new sites. Local plugin activation actions affect the current site only. No network-wide action is presented as reversible.
