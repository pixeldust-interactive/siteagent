# Testing

## OpenAI planning responses

Run `php tests/openai-response-parser-test.php` to cover bare structured JSON, fenced JSON, JSON surrounded by prose, malformed JSON, missing fields, empty answers, refusals, and incomplete provider responses. These tests use deterministic provider fixtures and never require or read an API key.

Run `php tests/openai-key-lifecycle-test.php` to cover missing, invalid, valid, rejected replacement, successful replacement, and removed key states without using a real credential or network request.

Run `node tests/admin-keyboard-test.js` to verify that starter prompts respond exactly once to Enter, Space, and pointer activation and move focus to the populated composer.

Run:

```bash
./bin/validate.sh
./bin/build.sh
```

## Automated validation

- PHP syntax for every plugin and test file.
- JavaScript syntax when Node.js is available.
- Pure unit checks for recursive redaction, secret-name detection, codec integrity, canonicalization, and risk classification.
- Static checks reject:
  - `permission_callback => __return_true`;
  - `innerHTML`;
  - `eval`;
  - API-key settings fields;
  - direct option storage of an OpenAI key;
  - missing `store=false`;
  - missing nonce/cookie/origin checks;
  - missing approval-token hash and one-time update;
  - unbounded approval plan count.

## Required WordPress runtime tests

1. Activate on a clean single site and multisite subsite.
2. Rebuild index on:
   - a small site;
   - a site with more than 10,000 posts;
   - a site with a known form-submission CPT;
   - Elementor and Divi sites.
3. Confirm orders, submissions, users, credentials, and builder payloads are absent from the index.
4. Test chat with and without the API constant.
5. Test role matrix with Administrator and Editor.
6. Propose low, medium, and high-risk actions.
7. Replay an approval token; the second execution must fail.
8. Double-click Execute; only one plan runs.
9. Change a target after a ledger entry, then request rollback; conflict must block.
10. Force rollback as a user without high-risk permission; it must fail.
11. Deactivate a plugin locally and roll it back.
12. Confirm network-wide changes are never advertised as reversible.
13. Submit provider errors containing fake secrets; audit output must show redaction.
14. Test wp-admin with narrow mobile width.
15. Uninstall with purge disabled and enabled.

## Explicit limitations to verify in UI

- Targeted snapshots are not full backups.
- Host worker queues, TTFB, traffic, DNS, and SaaS state may be unavailable.
- Plugin removal analysis is evidence, not proof.
- Provider retention is controlled by the customer's OpenAI arrangement; the plugin cannot grant ZDR.
