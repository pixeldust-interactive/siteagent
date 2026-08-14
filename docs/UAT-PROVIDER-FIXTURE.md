# Site Agent provider UAT fixture

This fixture deterministically exercises Site Agent's real WordPress REST, browser UI, parser, timeout, retry, and audit paths without a provider credential or outbound OpenAI request.

It is test tooling, not Site Agent product code. The source lives at `tests/uat/site-agent-uat-provider-fixture.php`, outside the plugin release allowlist. It must never be copied into `site-agent/`, included in a release ZIP, or merged into a Site Agent product PR, including PR #17.

## Safety boundary

- The fixture only boots when `home_url()` has the exact host `maisy.wpenginepowered.com`.
- Only administrators with `manage_options` can read, enable, select, or disable fixture state.
- State is disabled by default and expires automatically after one hour.
- Enabling requires selecting exactly one known scenario.
- A selected scenario is consumed after its terminal response.
- The fixture supplies a dummy `sk-test-...` key only while enabled.
- Every request to `api.openai.com` is intercepted locally while enabled. The Responses endpoint gets a deterministic fixture response, Models gets an empty local list, and every other provider endpoint fails closed.
- While an SA-6 simulator scenario is enabled, every Site Agent execution request either matches the exact selected sentinel plan and receives a deterministic simulated result, or fails closed. The fixture consumes the real one-time approval token and checks plan ownership, plan hash, risk capability, action count, and exact action arguments before simulating.
- The SA-6 simulator contains no WordPress content, option, transient, plugin-activation, or deletion API calls. It can write only its expiring fixture state plus Site Agent's normal approval and audit evidence.
- Stored fixture events contain only scenario, step, outcome, timestamp, and a SHA-256 of the redacted request body. They contain no key, prompt, raw response, or site content.

WordPress currently reports its environment label as `production` on the authorized Maisy test hostname, so the runtime label is not used as a gate. The exact hostname and administrator-controlled state are the enforcement boundary approved for this UAT target.

## Install and verify

Copy only the fixture source to:

`wp-content/mu-plugins/site-agent-uat-provider-fixture.php`

For a wp-admin upload, package that one committed PHP file inside a temporary `site-agent-uat-provider-fixture/` ZIP directory. Activating the temporary regular plugin copies the identical file to the must-use directory on the exact authorized host and immediately deactivates the bootstrap copy. Delete the temporary regular plugin after verifying the MU-plugin checksum. Do not commit the generated ZIP.

Verify its SHA-256 against the committed source. Then make an authenticated administrator request:

`GET /wp-json/site-agent-uat/v1/state`

The initial response must show `authorized_host: true`, `enabled: false`, fixture version `0.3.0`, the committed source SHA-256, phase `disabled`, zero provider/execution requests, empty simulated results/events, and install path `wp-content/mu-plugins/site-agent-uat-provider-fixture.php`.

Before each test, select one scenario:

`POST /wp-json/site-agent-uat/v1/state`

```json
{"enabled":true,"scenario":"valid"}
```

Supported scenarios are returned by the GET response. After one Site Agent chat request, read the state again and verify `consumed: true`, the expected request count, and local fixture outcomes.

### SA-3 proposal card

`proposal` is a deterministic two-provider-call scenario within one Site Agent chat turn:

1. The fixture requests a bounded `site.search` read and remains unconsumed.
2. It refuses to advance unless the post-read provider payload contains validated `site.search` result data. It then returns the terminal review-only `option.update` proposal and consumes the scenario.

This sequence matches Site Agent's accepted read-before-write planning flow. It prevents the fixture from being consumed before the required post-tool provider turn. UAT must render and inspect the proposal card, correlate its audit evidence, and stop without approving or executing the action.

### SA-16 homepage conversation

`sa16_homepage_flow` is a three-provider-call scenario spanning two Site Agent chat turns. It remains selected until the full path completes:

1. The fixture asks Site Agent to run `site.search` for the configured homepage/editor evidence and `content.get` for the authorized test homepage.
2. It refuses to advance unless validated tool data contains the live `page_on_front`, front-page, `WP Maisy`, active Twenty Twenty-Five, and content-read evidence. It then asks exactly one plain-language content-goal question.
3. Submit the exact test answer `Help visitors understand the eight focused WordPress tools and choose the right next step.` The fixture returns a draft-only review proposal and consumes the scenario.

The proposal creates only a draft preview page if an administrator separately approves and executes it. UAT must stop at review, confirm zero executed writes, and never approve the plan.

### SA-6 plain-language action and result matrix

These scenarios use Site Agent's real chat, proposal, approval-token, renderer, REST, and audit paths. The fixture intercepts only final execution and deterministic Change Ledger replay. It never calls a WordPress mutation API.

- `sa6_routine_success`: one medium-risk `option.update/blogdescription` proposal. Use it first for **Cancel** and confirm `execution_count:0`, the real tagline is unchanged, and there is no execution audit. Re-arm it for approval; the simulator consumes the real approval and returns a reversible success with simulated ledger ID `960601`.
- `sa6_partial_failure`: two medium-risk option actions. Simulated step one returns bounded reversible evidence; simulated step two returns `action_failed` with recovery text and both step results. Neither option changes.
- `sa6_nonreversible_success`: one `transients.delete_expired` proposal. Approval returns a successful non-reversible result with `ledger_id:0`; no transient is deleted and rollback proposal requests fail closed.
- `sa6_high_risk`: one published-post proposal using the exact sentinel title `SA-6 UAT high-risk confirmation - simulated only`. Use it to inspect the stronger browser confirmation. If approval reaches the server, the fixture consumes the token and returns a review-only block; no post is created.

After reversible success, the normal **View Change Ledger** link calls `/site-agent/v1/changes`; while the fixture remains enabled it returns only the deterministic simulated record. **Review rollback** calls the normal rollback-proposal route, where the fixture issues a real one-time approval for a simulated rollback plan. The rollback remains review-only and execution fails closed, so UAT can verify offer/link correctness without changing WordPress.

For every scenario, record the exact pre-state, proposal/card text, collapsed/open Technical details state, confirmation behavior, responsive geometry, console, Site Agent audit IDs, fixture provider/execution events, and the unchanged real WordPress target. Disable and clear between scenarios.

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
- `sa16_homepage_flow`
- `sa6_routine_success`
- `sa6_partial_failure`
- `sa6_nonreversible_success`
- `sa6_high_risk`
- `timeout`

For every scenario, record the exact installed Site Agent build/manifest identity, the visible result, Site Agent audit correlation, fixture state/events, idle composer state, and zero executed writes. For failure cases, verify a specific safe message and Retry/Edit controls. Retry must keep one user turn and create at most one new attempt.

## Teardown

1. Send authenticated `DELETE /wp-json/site-agent-uat/v1/state` and verify in the returned one-step read-back: `enabled:false`, phase `disabled`, empty scenario/last scenario, `consumed:false`, provider/execution counts zero, last execution and simulated changes empty, and events empty.
2. Remove the single file `wp-content/mu-plugins/site-agent-uat-provider-fixture.php`.
3. Verify the fixture state route returns 404.
4. Read Site Agent status and verify version/release/manifest are unchanged and provider state is `local`, key source `none`, and key stored `false`.

Do not install or run this fixture on AskMaisy or any other host.
