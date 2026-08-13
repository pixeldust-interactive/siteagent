# Ten development iterations

## 1. Product boundary

Locked the product to one private administration agent and one God Phrase. Rejected a public chatbot, manual Runbook dependency, provider-hosted site ingestion, and a generic prompt proxy.

**Result:** admin-only product with automatic discovery.

## 2. Closed-loop security

Added custom capabilities, browser-cookie enforcement, REST nonce checks, same-origin/referrer checks, no database API-key field, recursive redaction, and a local audit table.

**Failure tested:** valid capability over non-browser authentication is rejected.

## 3. Knowledge discovery

Built generation-based local indexing for content, plugins, themes, content types, builders, form definitions, cron, safe settings, database inventory, and roles.

**Failure tested:** interrupted rebuild leaves the active completed generation intact; large sites continue by ID rather than stopping at 10,000 posts.

## 4. Site diagnostics

Integrated the useful Site Signal measurements as callable evidence: autoload, transients, cron, Action Scheduler, revisions, spam/trash, PHP memory, OPcache, debug log, database connections, active queries, and table size.

**Failure tested:** unavailable host evidence is gray, not red.

## 5. Plugin removal intelligence

Integrated a bounded source inventory and site evidence scan for blocks, shortcodes, content types, options, tables, cron, REST routes, metadata, content use, and theme/MU-code references.

**Failure tested:** source code, option values, stored content, and absolute paths are never returned.

## 6. Change Ledger and rollback

Added external WordPress change capture, Site Agent action capture, integrity-coded snapshots, before/after fingerprints, per-target permissions, and conflict-aware rollback.

**Failure tested:** unsupported, oversized, corrupt, network-wide, or newer-conflicting states do not receive a false rollback promise.

## 7. Controlled action library

Added bounded actions for content, supported metadata, SEO, safe settings, plugin state, expired transients, and rollback.

**Failure tested:** uppercase or padded statuses are canonicalized before risk; publishing, Trash, builders, plugins, and forced rollback cannot be downgraded.

## 8. Conversational planner

Connected local retrieval and the OpenAI Responses API. The model can request tools but has no execution endpoint.

**Failure tested:** unknown tools, malformed arguments, secret-like payloads, and unsupported actions are rejected server-side.

## 9. Roles and administration UI

Added AI focus roles, WordPress capability matrix, local chat, review cards, diagnostics, knowledge search, impact scans, changes, rollback proposals, retention, and provider status.

**Failure tested:** tabs and routes remain unavailable when the user lacks the underlying capability.

## 10. Release hardening

Added one-time approvals, duplicate locks, bounded payloads, provider-error re-redaction, sensitive-content exclusions, honest privacy/rollback language, uninstall behavior, static checks, runtime smoke tests, reproducible ZIP packaging, and checksum.

**Release rule:** no “done” status until lint, unit checks, static security checks, package inspection, and checksum succeed.
