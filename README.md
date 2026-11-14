# prompt-library

Prompt templates for WordPress with named, typed variables, stored as a private post type, published as immutable numbered versions, and tried in a capped test runner that shows the rendered prompt and the model's answer side by side. Other plugins fetch and render a template by slug and version, so wording changes no longer need a code change and a deploy.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured result: a 20 KB template with 50 placeholders renders in about 0.2 to 0.4 ms per call once parsed (PHP 8.3, two-core virtual machine, 500 renders averaged); the budget is 1 ms.

## Contents

- [Install](#install)
- [Quick start](#quick-start)
- [Writing templates](#writing-templates)
- [Configuration](#configuration)
- [How it works](#how-it-works)
- [The test runner and spending](#the-test-runner-and-spending)
- [Hooks, REST and WP-CLI](#hooks-rest-and-wp-cli)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Install

WordPress 6.4 or later, PHP 8.1 or later, `ext-mbstring` and `ext-json`.

With Composer (needed for the test runner):

```sh
cd wp-content/plugins
git clone https://github.com/IndianOpinion/prompt-library.git
cd prompt-library
composer install --no-dev
```

`composer install` fetches [llm-json-php](https://github.com/IndianOpinion/llm-json-php), the only runtime dependency, from GitHub (a `vcs` repository entry in `composer.json`). The `vendor/` folder is never committed. Then activate **Prompt Library** in wp-admin.

Without Composer: copy the plugin folder into `wp-content/plugins/` and activate it. The plugin loads its own classes with `bootstrap.php`. Templates, versions, the editor, preview, import, export, WP-CLI and rendering for other plugins all work. The test runner stays off, with a notice, until `composer install` has been run, because the built-in model gateway goes through llm-json-php. A site that would rather use its own caller can return an object implementing `IndianOpinion\PromptLibrary\Runner\ModelGateway` from the `promptlib_model_gateway` filter; the runner then works without llm-json-php, and the kill switch, caps, lock and spend-meter checks still apply.

Must-use plugin: not supported in 0.1.0. Activation creates tables, capabilities and a cron event, and a must-use plugin never runs activation. Install it as a normal plugin. See decision D6 in [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## Quick start

1. Go to **Prompts, Add template**. Give it a slug such as `article-summary`, a user part, and declare the variables as JSON:

   ```json
   [
     {"name": "title", "type": "string", "escape": "line"},
     {"name": "text", "type": "text", "escape": "fenced", "max_length": 20000}
   ]
   ```

   with a user part such as:

   ```text
   Summarise the text below in three short points.
   Title: {{ title }}

   {{ text }}
   ```

2. Save the draft, check the list of errors and warnings, then **Publish version** with a one-line note. Version 1 becomes live.

3. Render it from your own plugin:

   ```php
   $prompt = promptlib_render('article-summary', [
       'title' => $post->post_title,
       'text'  => wp_strip_all_tags($post->post_content),
   ], 'live', ['consumer' => 'my-plugin']);

   $messages = $prompt->toMessages(); // system first, then user
   ```

   Or, without a hard dependency on this plugin:

   ```php
   $prompt = apply_filters('promptlib_render', null, 'article-summary', $values, 'live', [
       'consumer' => 'my-plugin',
       'fallback' => $my_builtin_definition,
   ]);
   if ($prompt === null) {
       // prompt-library is not active: use your built-in text.
   } elseif (is_wp_error($prompt)) {
       // A variable problem: fix the caller.
   } else {
       $system = $prompt['system'];
       $user = $prompt['user'];
   }
   ```

4. Try new wording in **Prompts, Test runner**: fill the variables, **Preview** for free, **Run** to call a model. Publish when it is better; roll back with **Make live** on an older version in **History**.

Runnable samples are in [`examples/`](examples): `render-without-wordpress.php` renders a definition with no WordPress at all (`php examples/render-without-wordpress.php`), `consumer-plugin.php` is a small consumer plugin, and `definitions/summary.json` with `values.json` work with `wp prompt-library publish` and `render`.

## Writing templates

A template has a system part (may be empty) and a user part. Placeholders are `{{ name }}`, with optional spaces or tabs inside the braces. Nothing else is allowed between the braces: no filters, defaults, dots or expressions.

| Write | Renders |
|---|---|
| `{{ title }}` or `{{title}}` | the value of `title` |
| `\{{title}}` | `{{title}}` |
| `\\{{title}}` | one backslash, then the value |
| `{{{title}}}` | `{`, the value, `}` |
| `{"a": 1}` or `}}` | itself: single braces need no escaping |

Any other `{{` (an unknown name, `{{ Name }}`, `{{ a b }}`, `{{}}`, an unclosed `{{name`) is an error with part, line and column. A placeholder for an undeclared variable is an error; a declared variable that is never used is a warning. Values are inserted in one pass, so a value that contains `{{ other }}` stays literal text.

Variable keys:

| Key | Default | Notes |
|---|---|---|
| `name` | required | `^[a-z][a-z0-9_]{0,63}$` |
| `type` | `string` | `string`, `text`, `integer`, `number`, `boolean`, `enum`, `list`, `json` |
| `required` | `true` | A required variable may not have a default. |
| `default` | none | Must pass the variable's own checks. |
| `max_length` | 2,000 for `string`, 50,000 for `text` | Code points, after characters are removed, before fencing. Values over it throw; they are never truncated. |
| `escape` | `plain` | `plain`, `raw`, `line`, `json`, `fenced` |
| `enum` | none | The allowed values of an `enum` variable. |
| `description` | empty | Shown in the test runner. Required for `raw`. |

Escape modes:

- `plain` removes control characters (tab and line feed are kept), DEL, C1 controls, bidirectional overrides and isolates, and the byte order mark. Zero-width joiner and non-joiner are kept, because Indic and other scripts need them.
- `raw` changes nothing beyond UTF-8 checks and line endings. The editor warns.
- `line` is `plain`, then every whitespace run becomes one space, then trim.
- `json` renders a JSON string literal (compact JSON for a `json` variable), for values inside a JSON example.
- `fenced` is `plain`, then the value goes between a line `<name>` and a line `</name>`. Text inside the value that looks like the closing tag becomes `[/name]`, so a value cannot close its own fence.

No escape mode makes prompt injection impossible. Fencing lowers the risk; it does not remove it. Treat values as untrusted, and treat model output as untrusted too.

## Configuration

**Prompts, Settings** (administrators only):

| Setting | Default | Notes |
|---|---|---|
| Test runner | on | The kill switch. `PROMPTLIB_DISABLE_RUNNER` wins. |
| Provider | OpenRouter | OpenRouter, OpenAI, or a generic chat completions endpoint with a base URL (HTTPS unless localhost). |
| Provider key | none | Write-only. `PROMPTLIB_PROVIDER_KEY` is preferred. |
| Models offered | none | One model id per line. Run is disabled while empty. |
| Default model | first in the list | |
| Fallback models | none | Tried in order when the chosen model fails in a way another model might avoid. Each attempt is checked against the caps and the spend meter first. |
| Default temperature | provider default | Template settings override. |
| Default output token cap | 2,048 | Template settings override; never above the site maximum. |
| Site maximum output token cap | 8,192 | |
| Daily runs cap | 50 | Per site, per day in the site timezone. 0 allows no runs. |
| Daily cost cap | 0 (off) | Known cost, in the provider currency as reported, or from the price table. |
| Price table | empty | `model, input per million, output per million, YYYY-MM-DD` per line. Never guessed. |
| Store prompt text and answers | on | Off keeps run metadata only. |
| Run retention | 30 days | Also at most 5,000 rows per site. |
| Per-user rate | 10 runs a minute | |

Constants, in `wp-config.php`:

| Constant | Effect |
|---|---|
| `PROMPTLIB_DISABLE_RUNNER` | `true` disables Run and Compare. Preview still works. |
| `PROMPTLIB_PROVIDER_KEY` | The provider key; preferred over the settings field. |
| `PROMPTLIB_ALLOW_SITE_KEY` | Multisite: `true` lets a site key override the network constant. |
| `PROMPTLIB_KEEP_DATA_ON_UNINSTALL` | `true` keeps all data on uninstall. |

Capabilities, granted on activation (filter `promptlib_role_caps`; **Reset roles** on the settings screen re-applies the map):

| Capability | Default roles | Allows |
|---|---|---|
| `promptlib_edit` | administrator, editor | Edit drafts, Preview, trash. |
| `promptlib_publish` | administrator, editor | Publish versions, move the live pointer, import, export. |
| `promptlib_run` | administrator | Run the test runner (spends money). |
| `promptlib_manage` | administrator | Settings, permanent delete, every user's runs. |

## How it works

- **Storage.** Each template is a post of a private post type with empty post content. The draft lives in one post meta value, so no content filter, shortcode, autop, texturize or kses pass ever touches prompt text. Meta writes are slashed first, because the meta API unslashes its input. Published versions are rows in their own table, never WordPress revisions; revisions only keep the draft's editing history.
- **Versions.** Publishing validates the draft and stores an immutable snapshot with the next number, the author, the UTC time, a required note and a SHA-256 content hash. A unique key on template and number makes a duplicate number impossible; a conflict is retried once. Publishing an unchanged draft is refused.
- **Live pointer.** Callers that do not pin a number get the live version. **Make live** on an older version is the rollback and is recorded in the history with who, when, from, to and why. `latest` is an alias resolved at render time.
- **Rendering.** Checks run in a fixed order: types, defaults, required values, lengths, escaping, substitution, total size (400,000 bytes by default). Each stage lists every problem it finds; nothing partial is ever returned.
- **Speed.** An uncached render costs two queries (template with its live pointer, then the version row). Versions are cached for 12 hours (they never change), the pointer for 60 seconds. The only write on a render is the "last rendered" note, at most once an hour per version and consumer.
- **Consumers.** A plugin may declare which variables it supplies (`promptlib_register_consumer`). Publishing or making live a version that needs a variable a declared consumer does not supply asks for a second confirmation, checked again on the server. A plugin may ship a default template (`promptlib_register_default`): it is installed as version 1 if the slug is free; later changes are only offered to the editor.

## The test runner and spending

The order is fixed: kill switch, permission and nonce, render, the plugin's own daily caps (reserved with one conditional increment, so two requests at the cap cannot both pass), the spend-meter check, the call, the report, settling the cost, and the run row. A stop after the reservation gives it back.

When the `spend-meter` plugin is active (it defines `SPEND_METER_API`), every attempt is checked first through its `spend_meter_pre_spend` filter, with its own reservation id, and every attempt, failed ones included, is reported through `spend_meter_record`. A `null` decision means no meter and the run goes on under the plugin's own caps. A meter that throws, returns a malformed decision or speaks an unknown contract version stops the run: a broken meter must not mean unmetered spend. The own caps and kill switch apply whether or not the meter is present.

Compare mode runs two selections (two versions, the draft and a version, or one version on two models). Both runs are reserved before the first call; if the second reservation fails, neither call is made.

## Hooks, REST and WP-CLI

PHP functions: `promptlib_render()`, `promptlib_get()`, `promptlib_exists()`, `promptlib_check()`, `promptlib_register_consumer()`, `promptlib_register_default()`, `promptlib_api_version()` (returns `1.0`). Exceptions extend `IndianOpinion\PromptLibrary\Exception\PromptLibraryException` and carry a stable `code()`, for example `promptlib_not_found` or `promptlib_missing_variable`.

Actions: `promptlib_version_published`, `promptlib_live_changed`, `promptlib_template_trashed`, `promptlib_template_restored`, `promptlib_template_deleted`, `promptlib_imported`, `promptlib_run_finished`, `promptlib_log`.

Filters: `promptlib_render`, `promptlib_model_gateway`, `promptlib_runner_client`, `promptlib_runner_purpose`, `promptlib_runner_deadline`, `promptlib_daily_caps`, `promptlib_rate_limit`, `promptlib_max_part_bytes`, `promptlib_max_render_bytes`, `promptlib_fixture_limit`, `promptlib_run_retention_days`, `promptlib_store_runs`, `promptlib_role_caps`, `promptlib_estimate_tokens`.

REST namespace `prompt-library/v1`: `GET /templates`, `GET /templates/{slug}`, `GET /templates/{slug}/versions/{n}`, `POST /templates/{slug}/versions`, `PUT /templates/{slug}/live`, `POST /templates/{slug}/preview`, `POST /runs`, `GET /runs`. Every route checks a capability.

WP-CLI (exit 0 on success, 1 on any error with the code printed; no command runs a model):

```sh
wp prompt-library list
wp prompt-library show <slug> [--version=<v>]
wp prompt-library render <slug> --values=<file.json> [--version=<v>]
wp prompt-library export <slug>... [--dir=<dir>] [--with-draft]
wp prompt-library import <file>...
wp prompt-library publish <slug> --from=<file.json> --note=<text> [--no-live]
wp prompt-library set-live <slug> <n> [--reason=<text>]
```

## Limitations

- No logic in templates: no conditionals, loops, includes or filters. Compose in your own code.
- The test runner is the only thing that calls a model. Consumers make their own calls.
- No streaming, images, audio, tools or function calling in the runner. No automatic scoring.
- No per-locale variants of a template in 0.1.0.
- Not a must-use plugin in 0.1.0.
- Token counts before the call are an estimate: characters divided by four, rounded up (filter `promptlib_estimate_tokens`).
- The daily window follows the site timezone; changing the timezone moves the boundary once.
- Escape modes reduce prompt injection; they do not prevent it.

## FAQ

**Why not keep prompts as ordinary posts?** Display filters change them: quotes turn curly, paragraphs get tags, shortcodes run, and kses strips tag-like delimiters for users without the unfiltered HTML capability. A prompt saved and read back would not be the prompt that was written.

**Why are versions not WordPress revisions?** Sites limit or disable revisions. A version a consumer depends on must never disappear.

**Can a consumer pin a version?** Yes: pass a number as the version. Use `live` to follow the editors, and pin when you need certainty.

**What happens if this plugin is deactivated?** The `promptlib_render` filter returns `null`, so a consumer that uses it falls back to its own text. Calling `promptlib_render()` directly would be a fatal error, so check `function_exists()` first.

**Does Preview cost anything?** No. Preview never calls a model and needs only the edit capability.

**Where do I see what is in use?** The template list has a "Last rendered" column with the consumer ids seen; trashing a template rendered in the last 7 days asks for confirmation.

**Is personal data stored?** Run rows may hold values typed into the runner. They are visible to their author and to administrators, kept 30 days, and covered by the personal data exporter and eraser. Switch off "Store prompt text and answers" to keep metadata only.

## Licence

MIT. See [LICENSE](LICENSE). Copyright (c) 2026 Indian Opinion.
