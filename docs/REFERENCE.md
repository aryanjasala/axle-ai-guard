# Reference

## How a call is checked

The WordPress AI Client gives a plugin two places to act on a call, and neither is complete. Axle AI Guard uses both, plus the HTTP transport.

1. **`wp_supports_ai`** (priority 1000). Off while the global switch, the network switch, the `AXLE_AI_GUARD_KILL` constant or the switch for the current context is on. Every `is_supported_*()` call then says no, so features hide themselves. A real generation in that state is logged as blocked.
2. **`wp_ai_client_prevent_prompt`** (priority 1000). The caller, context and user are known, the prompt and model are not. Checks, in order: kill switches, caller approval, allowed contexts, allowed roles, budgets already used up, rate limits. A block here gives the caller `WP_Error( 'prompt_prevented', 503 )`. Support checks (`is_supported_*`) only see the kill switches and caller approval, and are not logged or counted.
3. **`wp_ai_client_before_generate_result`** (priority 1000). Provider, model and messages are known. Checks: the provider kill switch, allowed providers and models, the estimated size against the budgets, file attachments, and data rules in block mode. A pending row is written with the data profile. A block here throws `AryanX\Axle\AiGuard\BlockedException`; core turns it into `WP_Error( 'prompt_builder_error', 500 )` with a message that names the rule.
4. **The transport decorator.** Installed on `init` with `AiClient::defaultRegistry()->setHttpTransporter()`, around whatever transport is there, so it chains with other decorators. It redacts data rules in redact mode from the JSON body, and records the request and response size, the HTTP status and failures.
5. **`wp_ai_client_after_generate_result`**. Tokens from the result, cost from the price table, the row to `ok`, the counters updated, budget alerts.
6. **`shutdown`**. A row still pending becomes `error` (`incomplete`).

Direct SDK use (`WordPress\AiClient\AiClient::prompt()`) skips step 2; step 3 then runs its checks too. WordPress 7.2 embeddings go through the same steps through `wp_ai_client_prevent_embedding` and the embedding events, logged with path `embedding`.

**Direct calls** (off by default): `pre_http_request` and `http_api_debug` watch requests to known provider hosts that did not come through the transport. They are logged with path `direct`, tokens read from the `usage` (OpenAI, Anthropic) or `usageMetadata` (Google) block of the response, or estimated from the size, and stopped by kill switches and caller approval with `WP_Error( 'axle_ai_guard_blocked' )`.

**Failing open.** If the guard's tables are missing or a check throws, the call goes through and a notice says the guard is degraded. `axle_ai_guard_fail_closed` blocks instead.

## Callers

Core passes no caller, so the plugin walks the backtrace and takes the first frame outside WordPress core and outside this plugin. The caller key is the plugin folder (`chatbot`), `mu:<name>` for a must-use plugin, `theme:<slug>`, `core` or `unknown`. A shared library inside one plugin, used by another, shows up as the first one. The file and line it used are stored and shown in the call details. `axle_ai_guard_caller` overrides it.

A caller seen for the first time is handled by the **new caller** setting: allow, alert (allow and send an alert, the default) or deny (block until approved on Budgets and limits, or with `wp axle-ai-guard approve <caller>`).

## Data rules

| ID | Finds | Default |
| --- | --- | --- |
| `user_email` | Email addresses that belong to a user of the site (each looked up with `email_exists()`, no user list is loaded) | redact |
| `email` | Any email address | log |
| `phone` | 8 to 15 digits with a + or separators | log |
| `ip` | IPv4 and IPv6 addresses | log |
| `card` | 13 to 19 digits that pass the Luhn check | log |
| `password_hash` | phpass (`$P$`), WordPress bcrypt (`$wp$2y$`), bcrypt and Argon2 hashes | block |
| `salts` | The values of the eight salts and keys in wp-config.php | block |
| `secrets` | The values of connector API key options and `axle_ai_guard_secret_option_names` | block |
| `custom-N` | Your strings (any case) and regular expressions | your choice |

Modes: **off**, **log** (counted in the profile), **redact** (replaced with `[redacted:<id>]` in the request body; the original is not kept anywhere), **block** (the call stops before any request). Matching runs on the user, model and tool messages and the system instruction, up to 200 KB per call (`axle_ai_guard_max_scan_bytes`); above it, a call is blocked when any rule is in block mode. Files are never scanned; block them per plugin.

Redaction rewrites the JSON body for three formats: OpenAI (`messages[].content`, `input`, `instructions`), Anthropic (`system`, `messages[].content`) and Google (`contents[].parts[].text`, `systemInstruction`). Other bodies get every string under `content`, `text`, `system`, `input`, `prompt` and `instructions` redacted. Add a format with `axle_ai_guard_provider_body_rewriters`.

## Budgets, rate limits and costs

- Budgets per subject (`global`, `plugin:<caller>`): tokens per day and month, money per day and month. Money is stored as integer micro-units of the site currency (1 USD = 1,000,000).
- Before a call: blocked when a budget is already used up (prevent filter), or when usage plus the estimate goes past it (before event). The estimate is characters / 4 plus the max output tokens when the caller set them (`axle_ai_guard_token_estimator`).
- After a call: counters go up with `INSERT ... ON DUPLICATE KEY UPDATE x = x + n`, so parallel requests never lose a count. Crossing the soft share (80%) and the limit each fire an action and an alert. Calls already running can go past a limit by their own size.
- Rate limits count calls per minute: global 120, per caller 60, per logged-in user 20, per anonymous IP 5. 0 is off. Buckets live in the object cache when it is persistent, else in the usage table (rows older than an hour are pruned).
- Cost = prompt tokens x input price + completion tokens x output price + thought tokens x thought price, per million. The price table ships with list prices; keys ending in `*` match every model id that starts with the rest, and the longest match wins.

## Hooks

### Filters

| Hook | Arguments | Use |
| --- | --- | --- |
| `axle_ai_guard_capability` | `( string $cap )` | Capability for the screens, REST, abilities. Default `axle_ai_guard_manage` |
| `axle_ai_guard_caller` | `( array $guess, array $frames )` | Override who made a call: `type`, `slug`, `file`, `line` |
| `axle_ai_guard_context` | `( string $context )` | admin, front, cron, rest, ajax or cli |
| `axle_ai_guard_policy` | `( array $decision, array $call, string $phase )` | Final allow or block. `$decision` has `allow`, `rule`, `message`; `$phase` is pre, model, estimate or data |
| `axle_ai_guard_budget` | `( array $budget, string $subject )` | `daily_tokens`, `monthly_tokens`, `daily_cost`, `monthly_cost` (micro-units); 0 is none |
| `axle_ai_guard_rate_limit` | `( int $limit, string $subject )` | Calls per minute for `global`, `plugin:<caller>`, `user:<id>`, `ip:<address>` |
| `axle_ai_guard_soft_limit_ratio` | `( float $ratio, string $subject )` | Share of a budget that sends the soft alert. Default 0.8 |
| `axle_ai_guard_token_estimator` | `( int $estimate, int $chars, int $max_tokens, array $call )` | Estimated tokens before a call |
| `axle_ai_guard_prices` | `( array $table )` | Prices per million tokens keyed by `provider/model` |
| `axle_ai_guard_cost` | `( int $cost, string $provider, string $model, array $tokens )` | Cost of a call in micro-units |
| `axle_ai_guard_currency` | `( string $code )` | ISO 4217 code. Default from the settings, USD |
| `axle_ai_guard_detectors` | `( array $detectors )` | Add or change detectors: `label`, `default` mode, `match` callback returning the matched strings |
| `axle_ai_guard_rule_mode` | `( string $mode, string $detector, string $caller )` | Mode per detector and caller |
| `axle_ai_guard_secret_option_names` | `( string[] $names )` | Options whose values must never be sent |
| `axle_ai_guard_redact_placeholder` | `( string $placeholder, string $detector )` | Default `[redacted:<detector>]` |
| `axle_ai_guard_provider_body_rewriters` | `( array $rewriters )` | Callables `( array $body, callable $redact ): ?array`, one per body format |
| `axle_ai_guard_provider_hosts` | `( array $hosts )` | Provider id keyed by host, or host and path prefix, for direct-call monitoring |
| `axle_ai_guard_block_in_before_event` | `( bool $block, array $call )` | `false` lets calls that would be blocked in the before event go ahead, with block-mode data redacted |
| `axle_ai_guard_block_message` | `( string $message, string $rule, array $call )` | What a blocked caller is told |
| `axle_ai_guard_new_caller_default` | `( string $mode, array $caller )` | allow, alert or deny for a caller seen for the first time |
| `axle_ai_guard_store_preview` | `( bool $store, array $call )` | Store the first characters of the prompt, redacted |
| `axle_ai_guard_preview_length` | `( int $length, array $call )` | Default 300 |
| `axle_ai_guard_retention_days` | `( int $days, string $table )` | calls 90, usage 400, prompts 7 |
| `axle_ai_guard_alert_recipients` | `( string[] $emails, string $type )` | Default the setting, else the admin email |
| `axle_ai_guard_alert_throttle` | `( int $seconds, string $type, string $subject )` | Default one hour |
| `axle_ai_guard_webhook_payload` | `( array $payload, string $type )` | The JSON sent to the webhook; empty skips it |
| `axle_ai_guard_autotrip_error_threshold` | `( int $percent )` | Error rate over 5 minutes that switches AI off. Default from the settings, 0 (off) |
| `axle_ai_guard_count_support_checks` | `( bool $count, array $call )` | `true` makes `is_supported_*` calls face every rule |
| `axle_ai_guard_max_scan_bytes` | `( int $bytes, array $call )` | Most prompt text the data rules scan. Default 200 KB |
| `axle_ai_guard_fail_closed` | `( bool $closed, Throwable $error )` | Block calls while the guard itself is failing |

### Actions

| Hook | Arguments | When |
| --- | --- | --- |
| `axle_ai_guard_call_started` | `( int $id, array $call )` | A call passed every rule and its row is open |
| `axle_ai_guard_call_finished` | `( int $id, array $row, array $call )` | A call finished and was counted |
| `axle_ai_guard_call_failed` | `( int $id, string $code, array $call )` | The provider answered with an error, the request failed, or the call never finished |
| `axle_ai_guard_call_blocked` | `( string $rule, array $caller, array $row )` | A call was blocked |
| `axle_ai_guard_data_redacted` | `( string $detector, int $count, int $id )` | Values were replaced in a request |
| `axle_ai_guard_budget_soft_reached` | `( string $subject, string $limit, int $used, int $budget )` | Usage passed the soft share |
| `axle_ai_guard_budget_hard_reached` | `( string $subject, string $limit, int $used, int $budget )` | Usage reached a limit |
| `axle_ai_guard_rate_limited` | `( string $subject, int $limit, array $call )` | A call went over a rate limit |
| `axle_ai_guard_kill_switch_on` | `( string $scope, string $subject, array $info )` | A switch was turned on (scope `network` for the network switch) |
| `axle_ai_guard_kill_switch_off` | `( string $scope, string $subject, int $user_id )` | A switch was turned off |
| `axle_ai_guard_new_caller_seen` | `( array $caller, string $mode )` | A caller made its first call |
| `axle_ai_guard_alert_sent` | `( string $type, string $subject, string $message, string[] $channels )` | An alert went out |
| `axle_ai_guard_logs_pruned` | `( array $counts )` | Old rows were deleted |

`$call` has `path` (client, embedding, direct), `caller`, `context`, `user_id`, `roles`, `ip`, `provider`, `model` and `capability`.

Examples:

```php
// Stop the chatbot on weekends.
add_filter( 'axle_ai_guard_policy', function ( $decision, $call, $phase ) {
	if ( 'pre' === $phase && 'chatbot' === $call['caller']['slug'] && in_array( gmdate( 'N' ), array( '6', '7' ), true ) ) {
		return array( 'allow' => false, 'rule' => 'weekend', 'message' => 'The assistant is off at weekends.' );
	}
	return $decision;
}, 10, 3 );

// Redact German IBANs.
add_filter( 'axle_ai_guard_detectors', function ( $detectors ) {
	$detectors['iban'] = array(
		'label'   => 'IBAN',
		'default' => 'redact',
		'match'   => function ( $text ) {
			return preg_match_all( '/\bDE\d{20}\b/', $text, $m ) ? $m[0] : array();
		},
	);
	return $detectors;
} );

// Post blocked calls to a log service.
add_action( 'axle_ai_guard_call_blocked', function ( $rule, $caller, $row ) {
	error_log( "AI call from {$caller['slug']} blocked by {$rule}" );
}, 10, 3 );
```

### JavaScript (`wp.hooks`, on Tools > AI Guard)

| Hook | Type | Use |
| --- | --- | --- |
| `axleAiGuard.overviewCards` | filter | `( cards )`. The Overview cards, each `{ id, label, value, kind }` with kind `number` or `cost` |
| `axleAiGuard.callColumns` | filter | `( columns )`. Columns of the Calls table, each `{ key, label, render( call ) }` |
| `axleAiGuard.formatCost` | filter | `( text, micros, currency )`. How a cost is shown |
| `axleAiGuard.killSwitchToggled` | action | `( result )`. A switch changed: `{ scope, subject, on, changed }` |

Filters are read when the screen draws, and a filter added later redraws it:

```js
wp.hooks.addFilter( 'axleAiGuard.callColumns', 'my-plugin', ( columns ) => [
	...columns,
	{ key: 'bytes', label: 'Bytes sent', render: ( call ) => String( call.request_bytes ) },
] );
```

## Abilities

Category `axle-ai-guard`, all behind `axle_ai_guard_manage`, all listed for the MCP adapter.

| Ability | Annotations | Input | Output |
| --- | --- | --- | --- |
| `axle-ai-guard/get-usage` | readonly | `period` (day, month), `by` (plugin, provider, model, user) | `total` and `groups` with calls, tokens, cost (micro-units), blocked |
| `axle-ai-guard/list-calls` | readonly | `status`, `plugin`, `provider`, `model`, `context`, `since`, `limit`, `include_preview` | Calls with their data profile; previews only when asked and stored |
| `axle-ai-guard/get-limits` | readonly | none | Budgets with usage, rate limits, data rule modes, kill switches that are on |
| `axle-ai-guard/engage-kill-switch` | idempotent | `scope` (global, plugin, provider, context), `subject`, `reason` | `{ scope, subject, changed, on }` |
| `axle-ai-guard/test-data-rules` | readonly | `text`, `plugin` | Findings, the redacted text, whether it would be blocked |

There is no ability to turn a switch off, raise a budget or loosen a rule. Axle Read-Only Mode still allows the readonly ones during a freeze.

## REST API

Routes under `axle-ai-guard/v1`, for the screens, behind the same capability: `GET /calls` (`status`, `plugin`, `provider`, `page`), `GET /calls/<id>` (with the preview and stored prompt), `POST /kill` (`scope`, `subject`, `on`, `reason`), `POST /rules/test` (`text`).

## WP-CLI

```sh
wp axle-ai-guard usage [--period=day|month] [--by=plugin|provider|model|user] [--format=<format>]
wp axle-ai-guard calls [--status=<status>] [--plugin=<caller>] [--provider=<id>] [--since=<date>] [--limit=<n>] [--format=<format>]
wp axle-ai-guard call <id>
wp axle-ai-guard kill [--plugin=<caller>] [--provider=<id>] [--context=<context>] [--reason=<reason>]
wp axle-ai-guard resume [--plugin=<caller>] [--provider=<id>] [--context=<context>] [--all]
wp axle-ai-guard switches [--format=<format>]
wp axle-ai-guard budget list [--format=<format>]
wp axle-ai-guard budget set <global|plugin:caller> [--daily-tokens=<n>] [--monthly-tokens=<n>] [--daily-cost=<amount>] [--monthly-cost=<amount>]
wp axle-ai-guard limit set <global|plugin:caller|plugins|users|ips> --per-minute=<n>
wp axle-ai-guard rules list [--format=<format>]
wp axle-ai-guard rules set <detector> <off|log|redact|block>
wp axle-ai-guard rules test <text|->
wp axle-ai-guard prices list [--format=<format>]
wp axle-ai-guard prices set <provider/model> --input=<price> --output=<price> [--thought=<price>]
wp axle-ai-guard prices reset
wp axle-ai-guard prices recalc [--days=30]
wp axle-ai-guard callers [--format=<format>]
wp axle-ai-guard approve <caller>
wp axle-ai-guard deny <caller>
wp axle-ai-guard settings [list|set <key> <value>]
wp axle-ai-guard prune [--older-than=<days>]
```

With no `--plugin`, `--provider` or `--context`, `kill` and `resume` work the switch for the whole site.

## Data

| Stored | What |
| --- | --- |
| `{prefix}axle_ai_guard_calls` | One row per call: time, duration, status, block rule, path, caller and file, user, context, anonymised visitor IP, provider, model, capability, tokens, estimated flag, cost and currency, request and response bytes, HTTP status, error code, data profile (JSON), preview |
| `{prefix}axle_ai_guard_usage` | Counters per period (`d:2026-10-30`, `m:2026-10`, `n:<minute>` for rate limits) and subject (`global`, `plugin:<caller>`, `provider:<id>`, `user:<id>`, `ip:<address>`): calls, tokens, cost, blocked |
| `{prefix}axle_ai_guard_prompts` | Redacted prompts, only when the prompt store is on, with an expiry |
| `axle_ai_guard_settings` | Settings |
| `axle_ai_guard_policies` | Budgets, rate limits, contexts, providers, models, roles and file blocking per subject |
| `axle_ai_guard_rules` | Data rule modes and custom rules |
| `axle_ai_guard_prices` | The edited price table and its date |
| `axle_ai_guard_kill` | Switches that are on, with who, when and why |
| `axle_ai_guard_seen_callers` | Callers and their approval status |
| `axle_ai_guard_db_version` | Schema version |
| `axle_ai_guard_network_kill` (network option) | The network switch |
| `axle_ai_guard_manage` capability | Given to Administrator on activation |
| `axle_ai_guard_cleanup` cron event | Daily clean-up, in batches of 1,000 rows |

Uninstalling removes the tables, options, transients, cron event and capability on every site of a network.

## Privacy

User IDs and anonymised visitor IP addresses are personal data, as are previews and stored prompts when they are on (redacted). **Tools > Export Personal Data** lists a user's calls; **Tools > Erase Personal Data** removes the user, IP address, preview and stored prompt and keeps the record that a call happened, so totals stay right. Settings > Privacy gets suggested text. The only outgoing request the plugin makes is the optional alert webhook.

## Limits

- Caller attribution is a best guess from the call stack.
- Blocking once the model is known uses an exception, which core turns into a generic `prompt_builder_error`. The prevent filter cannot pass a reason, so `prompt_prevented` blocks carry core's generic message; the log has the rule.
- Redaction only reaches providers that send through the AI Client transport.
- Images, audio and PDFs are not scanned.
- Streaming is not covered (the AI Client does not stream in 7.1).
- On multisite, budgets are per site; the network has a kill switch and a summary, not a shared budget.
- Prices are a local table, not a live feed.
