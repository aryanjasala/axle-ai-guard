=== Axle AI Guard ===
Contributors: aryanjasala
Tags: ai, ai client, budget, privacy, kill switch
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See and control every AI call your plugins make: what it cost, what site data it carried, budgets, rate limits, data rules and a kill switch.

== Description ==

Since WordPress 7.0, any plugin can call an AI provider through the WordPress AI Client, using the API key you saved under Settings > Connectors. That is handy. It also means a chatbot on the front end, an SEO tool in a cron job and a helper you forgot about all spend from the same key, and you cannot see which one sent what.

Axle AI Guard sits between those plugins and the provider. It logs every call, adds up what it costs, stops personal data from leaving the site, and gives you budgets, rate limits and a kill switch.

Part of Axle by AryanX, a family of plugins for running WordPress sites safely.

**Data rules:**

* Detectors for emails of registered users, any email address, phone numbers, IP addresses, payment card numbers (with the Luhn check), password hashes, WordPress salts and keys, connector API keys and other secret options, plus your own strings and regular expressions.
* Each detector is off, log, redact or block. By default user emails are redacted, and password hashes, salts and API keys are blocked. The rest are logged.
* Redact replaces the value with a placeholder in the request body before it leaves the site, for OpenAI, Anthropic and Google request formats. No map back to the original value is kept.
* A test box runs the rules on pasted text, with no AI call.

**Budgets and limits:**

* Daily and monthly budgets in tokens and in money, for the whole site and per plugin. An alert at 80% (you pick the share), a block at 100%.
* Rate limits per plugin (60 calls a minute), per logged-in user (20), per visitor IP address (5, so a chatbot or a form cannot become a free AI proxy) and for the site (120).
* Allowed contexts (front end, wp-admin, REST, Ajax, cron, WP-CLI), providers, models and roles, per plugin or for the site.

**Kill switches:**

* For the whole site, one plugin, one provider or one context, from the screen, WP-CLI, an ability or the `AXLE_AI_GUARD_KILL` constant in wp-config.php. On multisite, a network switch.
* Optional auto-trip when the monthly money budget is reached, or when too many calls fail in 5 minutes.
* While a switch is on, administrators see a notice and "AI is off" in the toolbar.

**What was sent, and what it cost:**

* Every call: which plugin made it (and the file it was traced to), context, user, provider, model, tokens, estimated cost, duration, HTTP status and bytes.
* A data profile instead of the prompt: message count, characters per role, attached file types and sizes, what the detectors found and what was done. Previews and a full redacted prompt store are there for debugging, off by default.
* Costs come from a price table per provider and model that ships with list prices and that you can edit.

**Callers:**

* A plugin making its first AI call is allowed with an alert by default. You can allow silently, or block new plugins until you approve them.

Alerts go by email and, if you set one, to a webhook (JSON, for Slack or anything else), at most once an hour per event and subject.

Free and open source under the GPL. No pro version, no upsells, no ads in wp-admin, no tracking. And it stays that way.

= Why WordPress 7.0 =

The WordPress AI Client, its `wp_ai_client_prevent_prompt` filter and its before and after events arrived in WordPress 7.0. On an older WordPress there is nothing to guard, so the plugin deactivates itself and says why.

= How it works, and its limits =

The checks that need no model (kill switches, approvals, contexts, roles, used-up budgets, rate limits) run in the `wp_ai_client_prevent_prompt` filter, and the caller gets the usual `prompt_prevented` error. Checks that need the model or the messages (provider and model lists, estimated size, data rules in block mode) run in the `wp_ai_client_before_generate_result` event. Core has no clean way to stop a call there, so the plugin throws an exception that core turns into a `prompt_builder_error`. The `axle_ai_guard_block_in_before_event` filter turns that off.

* Core does not say which plugin made a call. The plugin walks the call stack and takes the first plugin, theme or must-use plugin file. A shared library shows up as the plugin it lives in. The Calls screen shows the file it used.
* Redaction works for providers that send through the AI Client's HTTP transport. A provider with its own transport can be blocked but not redacted; Site Health tells you when it sees one.
* Files sent with a prompt (images, PDFs, audio) are not scanned. You can block calls with files per plugin.
* Budgets are checked before a call. A call that is already running can go past a hard limit by its own size.
* Calls that skip the AI Client and use the WordPress HTTP API can be watched with direct-call monitoring (off by default). Calls made with cURL or Guzzle directly cannot be seen.
* If the guard itself fails, calls go through and an admin notice says so. The `axle_ai_guard_fail_closed` filter blocks instead.

= WP-CLI =

* `wp axle-ai-guard usage [--period=day|month] [--by=plugin|provider|model|user]` and `calls`, `call <id>` read the log.
* `wp axle-ai-guard kill [--plugin=<caller>] [--provider=<id>] [--context=<context>]` and `resume [--all]` work the switches.
* `wp axle-ai-guard budget set global --monthly-cost=50`, `limit set plugin:<caller> --per-minute=10`, `rules list|set|test`, `prices list|set|reset|recalc`, `callers`, `approve`, `deny`, `settings` and `prune` cover the rest.

= For developers =

Every default, limit, list and message has a filter, and every call, block, redaction, alert and switch fires an action. The final decision for each call goes through `axle_ai_guard_policy`. The full list is in [the reference](https://github.com/aryanjasala/axle-ai-guard/blob/main/docs/REFERENCE.md).

= Privacy =

The log stores user IDs, and for visitors who are not logged in an anonymised IP address. Prompt text is not stored unless you turn on previews or the prompt store, and stored text has every detector finding redacted. Calls are kept for 90 days, usage totals for 400 days and stored prompts for 7 days. The plugin takes part in Tools > Export Personal Data and Tools > Erase Personal Data, and adds suggested text to Settings > Privacy.

== External services ==

The plugin itself sends nothing anywhere, with one exception: if you enter an alert webhook URL, each alert is sent to that URL as JSON (event, subject, message, site address, time and details such as the budget numbers). Nothing is sent when the field is empty. The service behind the URL is yours to choose; its terms and privacy policy apply.

== Frequently Asked Questions ==

= Does it work with every AI plugin? =

With every plugin that uses the WordPress AI Client, which is what WordPress 7.0 provides. Plugins with their own HTTP code to a provider can be logged and switched off with direct-call monitoring, but not budgeted per token as precisely, and not redacted.

= How exact are the costs? =

They are estimates: tokens times the price table. The table ships with list prices dated on the Prices screen. Check them against your provider and edit them; "Recalculate the last 30 days" applies new prices to the log.

= What does a blocked plugin see? =

A `WP_Error`, as it would for any failed AI call. `prompt_prevented` (503) for kill switches, approvals, contexts, roles, used-up budgets and rate limits, and `prompt_builder_error` (500) with a message that names the rule for data rules, model rules and estimated budgets.

= Can an AI agent turn the guard off? =

No. Agents get abilities to read usage, calls and limits, test the data rules and switch AI off. No ability turns a switch back on, raises a budget or loosens a rule. Only a person in wp-admin or on WP-CLI can.

= Does it work with the official AI plugin? =

Yes. Its request logging and AI Guard both work, side by side; Site Health notes the double logging. If its Connector Approval experiment is on, approve connectors there and set AI Guard's new-plugin default to allow or alert.

= Does it work on multisite? =

Yes, per site: each site has its own log, budgets and rules. Network Admin has a network kill switch and a usage summary per site.

== Screenshots ==

1. Overview: usage today and this month, each plugin with its budget and kill switch, providers, models and recent blocks.
2. Calls: every AI call with caller, status, model, tokens and cost.
3. Call details: the file the call was traced to, the data profile, what was found and redacted, tokens, cost and timing.
4. Budgets and limits per plugin: tokens and money, rate limits, contexts, providers, models and roles.
5. Data rules with the test box: what each detector found and what the provider would get.
6. Prices per provider and model, editable, with the date they are from.

== Changelog ==

= 1.0.0 =
* First release.
