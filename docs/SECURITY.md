# Security model

## Closed loop

Site Agent exposes no public chatbot. Every REST route requires:

- an authenticated WordPress user;
- a valid logged-in browser cookie;
- a WordPress REST nonce;
- an Origin or Referer host matching wp-admin;
- the relevant Site Agent capability;
- native WordPress permission for the target object when applicable.

This deliberately rejects Application Password and generic API-proxy use.

## Credential boundary

The plugin never stores an OpenAI API key in an option, post, user record, custom table, JavaScript variable, HTML field, or audit record. The key can come only from:

- `SITE_AGENT_OPENAI_API_KEY`;
- an environment variable with the same name;
- `site_agent_openai_api_key`.

Credentials, secrets, API keys, salts, cookies, authorization headers, private keys, JWT-like strings, and high-entropy token-like values are recursively redacted. Sensitive names are excluded at collection time and data is redacted again:

1. before local indexing;
2. before provider requests;
3. before local chat storage;
4. before audit storage;
5. before provider and parser failures enter logs.

The action layer also blocks secret-like values from entering an approved write plan.

## Provider boundary

The OpenAI request contains:

- system safety instructions;
- recent redacted conversation turns when local history is enabled;
- the current redacted question;
- a bounded set of relevant local evidence;
- bounded, validated read-tool results.

The plugin does not send the whole database, source tree, form submissions, orders, user records, credentials, raw builder payloads, or change snapshots. It sets `store=false` and does not fine-tune a model.

The plugin cannot independently grant OpenAI Zero Data Retention. ZDR and provider retention depend on the customer’s OpenAI account and agreement.

## Model authority

The model has none.

It can output:

- an answer;
- up to three requested read calls;
- up to ten proposed write actions;
- a clarification request.

The server ignores unknown tools, canonicalizes arguments, validates exact targets, calculates risk, verifies capabilities, and requires approval before any write.

## Approval integrity

- 256-bit random token.
- Only the SHA-256 token hash is stored.
- Bound to the proposing user.
- Exact plan hash is returned and rechecked.
- Ten-minute expiration.
- Atomic one-time consumption.
- Browser JavaScript keeps the raw token only in a closure; it is not put in the DOM, URL, local storage, or model context.
- Duplicate plan execution is blocked with an expiring database lock.

## Output rendering

Model text and tool data are inserted with `textContent`. No model-controlled HTML is rendered. The admin script contains no `innerHTML`, `eval`, dynamic script loading, or generic fetch relay.

## Known limits

A compromised WordPress administrator, plugin, theme, server, browser, or database can still defeat controls inside WordPress. Site Agent reduces its own attack surface; it is not a substitute for endpoint security, least privilege, backups, patching, or a secure host.
