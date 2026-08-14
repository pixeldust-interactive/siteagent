# Site Agent provider UAT fixture

This fixture deterministically exercises Site Agent's real WordPress REST, browser UI, parser, timeout, retry, and audit paths without a provider credential or outbound OpenAI request.

It is test tooling, not Site Agent product code. The source lives at `tests/uat/site-agent-uat-provider-fixture.php`, outside the plugin release allowlist. It must never be copied into `site-agent/`, included in a release ZIP, or merged into PR #10.

## Safety boundary

- The fixture only boots when `home_url()` has the exact host `maisy.wpenginepowered.com`.
- Only administrators with `manage_options` can read, enable, select, or disable fixture state.
- State is disabled by default and expires automatically after one hour.
- Enabling requires selecting exactly one known scenario.
- A selected scenario is consumed after its terminal response.
- The fixture supplies a dummy `sk-test-...` key only while enabled.
- Every request to `api.openai.com` is intercepted locally while enabled. The Responses endpoint gets a deterministic fixture response, Models gets an empty local list, and every other provider endpoint fails closed.
- Stored fixture events contain only scenario, step, outcome, timestamp, and a SHA-256 of the redacted request body. They contain no key, prompt, raw response, or site content.

WordPress currently reports its environment label as `production` on the authorized Maisy test hostname, so the runtime label is not used as a gate. The exact hostname and administrator-controlled state are the enforcement boundary approved for this UAT target.

## Install and verify

Copy only the fixture source to:

`wp-content/mu-plugins/site-agent-uat-provider-fixture.php`

For a wp-admin upload, package that one committed PHP file inside a temporary `site-agent-uat-provider-fixture/` ZIP directory. Activating the temporary regular plugin copies the identical file to the must-use directory on the exact authorized host and immediately deactivates the bootstrap copy. Delete the temporary regular plugin after verifying the MU-plugin checksum. Do not commit the generated ZIP.

Verify its SHA-256 against the committed source. Then make an authenticated administrator request:

`GET /wp-json/site-agent-uat/v1/state`

The initial response must show `authorized_host: true`, `enabled: false`, fixture version `0.2.1`, the committed source SHA-256, and install path `wp-content/mu-plugins/site-agent-uat-provider-fixture.php`.

Before each test, select one scenario:

`POST /wp-json/site-agent-uat/v1/state`

```json
{"enabled":true,"scenario":"valid"}
```

Supported scenarios are returned by the GET response. After one Site Agent chat request, read the state again and verify `consumed: true`, the expected request count, and local fixture outcomes.

### SA-16 homepage conversation

`sa16_homepage_flow` is a three-provider-call scenario spanning two Site Agent chat turns. It remains selected until the full path completes:

1. The fixture asks Site Agent to run `site.search` for the configured homepage/editor evidence and `content.get` for the authorized test homepage.
2. It refuses to advance unless validated tool data contains the live `page_on_front`, front-page, `WP Maisy`, active Twenty Twenty-Five, and content-read evidence. It then asks exactly one plain-language content-goal question.
3. Submit the exact test answer `Help visitors understand the eight focused WordPress tools and choose the right next step.` The fixture returns a draft-only review proposal and consumes the scenario.

The proposal creates only a draft preview page if an administrator separately approves and executes it. UAT must stop at review, confirm zero executed writes, and never approve the plan.

## Required UAT scenarios

- `valid`
- `fenced_json`
- `surrounded_json`
- `malformed_then_valid`
- `empty_exhausted`
- `null_exhausted`
- `missing_exhausted`
- `clarification`
- `proposal` (review only; never approve or execute)
- `read_tool_roundtrip`
- `timeout`

For every scenario, record exact Site Agent 0.2.7 build/manifest identity, the visible result, Site Agent audit correlation, fixture state/events, idle composer state, and zero executed writes. For failure cases, verify a specific safe message and Retry/Edit controls. Retry must keep one user turn and create at most one new attempt.

## Teardown

1. Send authenticated `DELETE /wp-json/site-agent-uat/v1/state` and verify `enabled: false`.
2. Remove the single file `wp-content/mu-plugins/site-agent-uat-provider-fixture.php`.
3. Verify the fixture state route returns 404.
4. Read Site Agent status and verify version/release/manifest are unchanged and provider state is `local`, key source `none`, and key stored `false`.

Do not install or run this fixture on AskMaisy or any other host.
