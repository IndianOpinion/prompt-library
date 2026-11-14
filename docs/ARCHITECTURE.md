# Architecture

This document describes how prompt-library 0.1.0 is built and records every
decision the PRD left open, with the reason. The requirements are in
[PRD.md](PRD.md).

## Modules

```
prompt-library.php          plugin header, autoloaders, boot
bootstrap.php               own PSR-4 autoloader (no Composer needed for the plugin's own code)
uninstall.php               calls WordPress\Uninstaller
src/
  functions.php             the global consumer contract (promptlib_*)
  Plugin.php                service container and hook wiring
  Parser.php Token.php ParsedPart.php
  Renderer.php RenderedPrompt.php ValueFormatter.php Text.php
  Definition.php VariableSpec.php OutputSpec.php Slug.php Issue.php
  CanonicalJson.php Diff.php TemplateVersion.php CheckResult.php
  Exception/                typed exceptions with stable codes
  WordPress/
    PostType.php Capabilities.php Schema.php Settings.php Clock.php Logger.php
    TemplateRepository.php  posts and meta; one-query locate
    VersionRepository.php   versions and history rows (no update method)
    RunRepository.php CounterRepository.php
    Library.php             render, get, exists, check; caches; integrity check
    Consumers.php Defaults.php Drafts.php Publisher.php ImportExport.php
    Lifecycle.php Uninstaller.php SiteHealth.php Privacy.php
  Runner/
    Runner.php              the spend path
    SpendMeter.php          the only file that knows the spend-meter contract
    ModelGateway.php LlmJsonGateway.php GatewayCall.php GatewayResponse.php AttemptReport.php
    RunGuard.php RunRefused.php
  Rest/Controller.php       prompt-library/v1
  Cli/Command.php           wp prompt-library (subcommands only)
  Cli/Commands.php          the logic behind them, testable without WP-CLI
  Admin/                    menus, screens, form parsing
assets/                     admin CSS and a small autosave script
```

Everything above `WordPress/` (the parser, renderer, definition and value
objects) has no WordPress dependency, so a consumer's unit tests can render a
definition array with `Renderer::renderArray()` after one `require` of
`bootstrap.php`.

## Data flow

**Consumer render.** `promptlib_render()` (or the `promptlib_render` filter)
goes to `Library::render()`. `TemplateRepository::locate()` finds the template,
its status and its live pointer in one query (cached 60 seconds, deleted on
every change). `Library::load()` fetches the version row (cached 12 hours,
since versions never change), decodes it, rebuilds a `Definition`, recomputes
the content hash and compares it with the stored one. `Renderer::render()`
runs the stages in the PRD order and returns a `RenderedPrompt`. A transient
throttles the "last rendered" note to once an hour per version and consumer.

**Editing.** The editor posts a form; `Admin\Forms` turns it into a draft
array; `Drafts::save()` checks the draft hash (stale page), validates, refuses
only save-blocking problems (invalid UTF-8, oversize part, bad slug, wrong
field types), slashes and stores the draft meta, updates the post title and
excerpt, and asks WordPress for a revision, which copies the revisioned draft
meta. `Publisher::publish()` validates fully, refuses an unchanged draft,
checks declared consumers, inserts the version with `INSERT IGNORE` on the
unique key, retries once on a conflict, and moves the pointer.

**Test run.** `Runner::run()`: kill switch and configuration, capability,
lock and rate, render, own caps (cost check, then one conditional increment
for all selections), spend-meter checks for every first attempt, then per
selection the attempt loop through the `ModelGateway`, reporting every
attempt, settling known cost in nanos, writing one run row and firing
`promptlib_run_finished`.

## Tables and stored data

- `{prefix}promptlib_versions`: versions and history in one table with a
  `type` column (D3). Unique key `(template_id, version_no)`; history rows
  have `version_no` NULL, which a unique key allows many times.
- `{prefix}promptlib_runs`: one row per run; prompt text and answer nullable.
- `{prefix}promptlib_counters`: `(day, metric) -> value`, metrics `runs`,
  `cost_nanos` and `refused:<code>`. Pruned after 7 days.
- Post meta: `_promptlib_slug`, `_promptlib_draft` (revisioned),
  `_promptlib_fixtures`, `_promptlib_live`, `_promptlib_offers`,
  `_promptlib_installer`, `_promptlib_last_rendered`.
- Options: `promptlib_settings` and `promptlib_provider_key` (not autoloaded),
  `promptlib_schema_version` and `promptlib_schema_state` (autoloaded, small),
  `promptlib_integrity_errors` (not autoloaded, last 20), and per-user run
  locks `promptlib_lock_<user>` (not autoloaded, D10).
- Transients: `promptlib_rate_*`, `promptlib_seen_*`, `promptlib_logged_*`,
  and the model library's cooldown keys under the prefix `promptlib_llmjson`
  (D21).

## Rulings on the open questions

### D1. Spend-meter binding, and one check per attempt (ruling 1)

The names and shapes are exactly section 5.8: filter `spend_meter_pre_spend`
with `null` and the intent, action `spend_meter_record` with the call array,
action `spend_meter_release` with the reservation id, contract constant
`SPEND_METER_API`. All of it lives in `Runner/SpendMeter.php`.

Ruling: each fallback attempt gets its own pre-spend check and its own
reservation id. This replaces the PRD sentence "every attempt of one run uses
the same reservation_id". To make every attempt visible before it happens,
the runner drives the attempts itself: the gateway sends one request to one
model with the library's reliability layer off (one attempt per call), and the
runner walks the chosen model and then the "fallback models" setting, running
the pre-spend check before each one. Attempts the library still reports inside
one call (a guard regeneration, or a site-supplied client with its own chain,
case 75) are recorded under that call's reservation id. A reservation is
released when an attempt stops after an allowed check and before its request:
the deadline guard, a configuration error with no request made, or a gateway
that throws. In compare mode, if the first selection throws, the second
selection's meter reservation and its run reservation are given back before
the error reaches the caller. A fallback attempt is also checked against the
own daily cost cap, counting what the run has already spent. A run row that
cannot be stored is logged and the paid result is still shown (run id 0).

Binding rules: no `SPEND_METER_API` constant means no meter and no meter
traffic at all; a constant naming a version other than `1` fails closed
(reason `unsupported_api`); a `null` decision passes; a decision that is not
an array with a boolean `allowed`, names another `api`, or allows the call
with a reason other than empty, `ok` or `allowed`, fails closed. Deny reasons
the screen knows map to plain language; the six cap codes are recognised by
their `cap_` prefix; any other code shows "Refused by the spend meter" and is
logged. The decision `message` is logged, never shown.

Difference to record: no `spend-meter` release existed on 14 November 2026, so
the binding could not be confirmed against a merged release. The allowed
reason set and the `cap_` prefix rule are the two places to adjust if the
release differs.

### D2. Day boundary (ruling 2)

The daily cap window follows the site timezone (`wp_timezone()`); every stored
timestamp is UTC. This replaces the PRD's "UTC day" in cases 66 and 90.
Consequence: changing the site timezone moves the window boundary once, which
can make one day slightly shorter or longer. Counters are keyed by the
site-timezone date, so clock skew between web servers within a day has no
effect.

### D3. History storage (ruling 3)

One table with a `type` column: `version` rows and event rows (`live`,
`import`, `trash`, `restore`, `install`). Event rows keep `version_no` NULL so
the unique key on `(template_id, version_no)` still guards version numbers. A live
event row is written before the pointer moves, so if the history write fails
the live version stays where it was.

### D4. llm-json-php (ruling 4)

A Composer dependency through a `vcs` repository entry
(github.com/IndianOpinion/llm-json-php), constraint `^0.1`, not bundled. The
README documents `composer install`; `vendor/` is in `.gitignore` and never
committed. Without it the plugin's own autoloader still loads every class of
this plugin, so templates, rendering, the editor, import and export work, and
the runner is disabled with an admin notice and a Site Health note (case 74).
Version conflicts: on a Composer-managed site, Composer resolves one copy. On
a site where another plugin loads its own copy first, PHP keeps the first
classes it loaded; the runner only uses the library's public 0.1 interface,
and the gateway checks the client class before use.

Caller filter: `promptlib_model_gateway` lets a site return its own
`Runner\ModelGateway` (one model, one request, never throws for a model
failure). It is read at call time, so a filter added by a plugin that loads
later still counts. With it, the runner works without llm-json-php; the kill
switch, caps, lock, rate and spend-meter steps are unchanged. A gateway that
throws anyway is caught by the runner, logged, recorded as a failed run, and
its reservation released. `promptlib_runner_client` stays for sites that
only want to hand over a configured llm-json-php client.

### D5. `latest` (ruling 5)

Kept as an alias resolved at render time to the highest version number. It is
never stored on a draft or a version, and the runner's picker offers numbers
only.

### D6. Must-use plugin (ruling 6)

Not supported in 0.1.0; standard plugin only. Activation creates three
tables, adds capabilities, records the schema version and schedules the daily
prune, and a must-use plugin never runs activation. For that reason this
release ships no must-use loader stub, although the general builder checklist
asks WordPress plugins for one: the ruling takes precedence. A later release
could support it by moving that work to a lazy first-run path on `admin_init`
(the schema upgrade already runs there) plus a capability grant guarded by an
option.

### D7. Token estimate (ruling 7)

Characters (Unicode code points of both rendered parts) divided by four,
rounded up, passed through the filter `promptlib_estimate_tokens` with the
character count. It is documented as an estimate in the README and only feeds
`est_input_tokens` in the spend-meter intent and the runner screen.

## Decisions made by the builder

- **D8. Slug identity in meta.** WordPress renames a trashed post's
  `post_name` with a `__trashed` suffix, so the slug of record is the
  `_promptlib_slug` meta. `post_name` mirrors it; the `wp_unique_post_slug`
  filter stops WordPress from appending `-2`, and `wp_untrash_post_status`
  restores templates as published rather than as drafts.
- **D9. One-query locate.** The template id, status and live pointer come from
  one join on posts and post meta, so an uncached render is two queries.
- **D10. Run lock.** The per-user lock is an option added with `add_option()`,
  which is atomic on the unique option name; the value is its expiry time, and
  an expired lock is taken over. Transients have no atomic add.
- **D11. Fallback models.** A new setting, empty by default, lists the models
  the runner may try after the chosen one. Only failures another model might
  avoid fall back (transport, server, rate limit, truncated, malformed, schema,
  guard, refusal); bad keys, empty balances, rejected requests and the
  deadline stop at once.
- **D12. A runs cap of 0 allows no runs.** The cost cap of 0 is "off", as the
  PRD says; for runs, 0 meaning "unlimited" would remove the cap the PRD
  requires.
- **D13. The `string` type accepts line breaks.** It is meant for one line
  (shorter default length, one-line input on the runner screen); use
  `escape: line` to force one line. Refusing a stray trailing newline in a
  title turned out to be more surprising than useful.
- **D14. Costs in nanos.** Counters and run rows store known cost as integers
  in billionths of the provider currency unit, so the cap check and the
  increment stay integer SQL.
- **D15. Registered defaults.** They are installed on `admin_init` and in
  WP-CLI, never on a front-end request. Until then, a render of a missing
  slug with a registered default renders that default as a fallback (marked
  `from_fallback`). Trashed or unpublished templates are not covered by this
  implicit fallback.
- **D16. Editor format.** Variables, the output schema and model settings are
  edited as JSON in plain monospace textareas, which keeps the editor exact and
  free of rich text. Invalid JSON refuses the save and keeps the typed text on
  the form.
- **D17. Last rendered.** Recorded per version with the consumer ids seen,
  throttled once an hour per version and consumer id.
- **D18. Integrity errors** are kept in an option (last 20) so Site Health can
  report them, and logged once an hour per version.
- **D19. The filter result** also carries `from_fallback`, in addition to the
  keys section 5.3 lists.
- **D20. Text-mode wrapper.** The runner asks for an object with one string
  field `answer`; the screen says so, because in modes without strict
  structured output the library puts that schema in the system message.
- **D21. Model library store.** The runner's llm-json-php client uses the
  WordPress transient store with the key prefix `promptlib_llmjson`, so
  uninstall removes its keys with the plugin's own prefix.
- **D22. Test layer.** PHPUnit runs without WordPress on a stub layer in
  `tests/stubs/`. Every SQL statement the plugin sends carries a marker
  comment (for example `/*promptlib:counter.reserve*/`), and the fake `wpdb`
  implements each marked statement in memory, so repository code runs for
  real. The fake also simulates dbDelta skipping a column and a unique-key
  race. Multisite isolation (acceptance test 75) is covered at stub level for
  the key rule and super admins only; per-site table isolation needs a real
  WordPress test install.

## Security notes

- Every admin form and REST write checks a nonce (admin) or the REST cookie
  nonce, plus a capability; meta capabilities on the post type map to the
  plugin's own capabilities, and permanent delete needs `promptlib_manage`.
- Everything printed in wp-admin is escaped for its context; rendered prompts
  and answers are printed as text inside `<pre>`.
- The provider key never appears in REST responses, exports, logs, page
  source or run rows; the settings field is write-only.
- No `eval`, no shell, no outbound request except the runner's model call.
