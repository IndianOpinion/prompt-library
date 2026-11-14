# PRD: prompt-library

Prompt templates with named variables, stored as a custom post type,
versioned, with a test runner that shows the rendered prompt and the model's
answer side by side.

Status: draft for build. Version target: 0.1.0. Type: WordPress plugin
(group B base). Written from scratch; not derived from any third-party
plugin.

Words used in this document:

- **Template**: one named prompt, identified by a slug. It has a working
  draft and zero or more published versions.
- **Draft**: the editable copy of a template. Callers never receive it.
- **Version**: an immutable, numbered snapshot of a draft (1, 2, 3, ...).
- **Live version**: the version a template points at for callers that do
  not pin a number. Exactly one per template once version 1 exists.
- **Consumer**: another plugin, theme or site code that fetches and renders
  a template through the public contract in section 5.
- **Run**: one test-runner call to a model, made by a person in wp-admin.

## 1. Problem

Plugins that call language models keep their prompts as string literals in
PHP. Every wording change is then a code change and a deploy. Nobody can
see which wording produced which output last Tuesday. Editors who own the
wording cannot change it, and engineers who can change it cannot easily try
it. When a prompt is assembled by string concatenation, a value that
contains the closing marker of a section, or a stray placeholder, silently
changes the prompt's meaning.

The opposite mistake is also common: prompts kept as ordinary posts. They
then pass through the display filters that WordPress and themes attach to
post content, which turn straight quotes into curly ones, add paragraph
tags, run shortcodes and strip markup for users without the unfiltered HTML
capability. A prompt saved and read back is no longer the prompt that was
written.

Measured result: none yet. This repository is written from its one-line
description, not extracted from running code. The builder records the first
measured result (for example render time for a large template, or a
mangling bug the storage rules prevented) in the README once there is one.

## 2. Goals and non-goals

Goals:

1. Store prompt templates in WordPress as a custom post type, editable in
   wp-admin by people with the right capability.
2. A small, stable public contract that lets any plugin fetch and render a
   template by slug and version, with or without this plugin present.
3. A simple, strict variable syntax with declared, typed variables and
   explicit, per-variable escaping of values.
4. Immutable numbered versions, a movable live pointer, one-click rollback,
   and a full history of who published what and when.
5. A test runner: fill the variables, run the rendered prompt against a
   model, see the rendered prompt and the answer side by side, with tokens,
   cost and timing.
6. Every test-runner call goes through `llm-json-php`, is capped per day,
   has a kill switch, and reports its spend through `spend-meter` when that
   plugin is active.
7. Text in equals text out: nothing in the storage or render path changes a
   single byte of a template except the rules this PRD states.

Non-goals:

1. No logic in templates: no conditionals, loops, includes, filters,
   expressions or function calls. Composition belongs in the consumer.
2. No production model calls. Consumers make their own calls; this plugin
   only renders text for them. The test runner is the only caller here.
3. No automatic evaluation, scoring, grading or A/B testing of outputs.
4. No queueing. Test runs are interactive and synchronous; the plugin does
   not use `gen-queue`.
5. No front-end output. Nothing renders on public pages.
6. No shipped prompt content beyond a neutral example template used in the
   documentation. The plugin carries no editorial rules or house style.
7. No translation of templates, and no per-locale variants in 0.1.0.
8. No streaming, images, audio, tools or function calling in the runner.
9. No synchronisation between sites. Export and import (cases 76 to 79)
   cover moving templates between staging and production.

## 3. Users and setting

- **Site administrator** installs and configures the plugin, sets the
  provider key, the daily caps and who may do what.
- **Prompt editor** (often an editor, not an engineer) changes wording,
  tries it in the test runner, and publishes a version.
- **Plugin developer** writes a consumer: calls the public contract with a
  slug, a version and an array of values, and sends the rendered text to a
  model through their own code.
- Runs on a single WordPress site or on each site of a multisite network.
  Admin only. Works on shared hosting, a VPS or a container. No front-end
  cost. Activated as a normal plugin; it does not support running as a
  must-use plugin in 0.1.0 because it creates tables and capabilities on
  activation (see open question 6).

## 4. Behaviour

### 4.1 Templates and the editor

1. A template is a post of the plugin's own non-public post type. It is not
   publicly queryable, has no archive, no front-end permalink, is excluded
   from search and sitemaps, and is not exposed through the core REST posts
   endpoints. It appears under its own admin menu.
2. A template has: slug, title, description, a system part, a user part,
   a list of declared variables, an output declaration, optional model
   settings (temperature, output token cap), saved test inputs (fixtures),
   and an editor's note for the next version.
3. The slug is the public identifier. It matches
   `^[a-z0-9]+(?:[-_][a-z0-9]+)*$`, at most 100 characters. Uppercase input
   is lowercased in the editor before validation. Anything else is refused
   with a message; the plugin never silently changes a slug.
4. Slugs are unique per site, including templates in the trash. Saving a
   template whose slug is taken is refused with a message naming the other
   template. WordPress's usual behaviour of appending `-2` to a duplicate
   post name must not happen.
5. The slug is locked once version 1 is published. To rename, the editor
   creates a new template (the editor offers "duplicate as new slug"). The
   old one keeps working for consumers until it is trashed.
6. The editor is the plugin's own screen with plain textareas (monospace,
   no rich text, no block editor). The block editor is never used for this
   post type.
7. The draft is stored as one structured value in post meta, not in post
   content. Post content stays empty. This keeps every content display
   filter, shortcode, embed, autop, texturize and kses pass away from the
   prompt (see section 14).
8. Saving a draft validates it (cases 21 to 28). Errors block "publish
   version" but never block saving the draft: an editor can save broken
   work and come back to it. The editor shows each error with the part
   (system or user), line and column.
9. Line endings in both parts are normalised to LF on save. A trailing
   newline at the end of a part is kept exactly as typed. Leading and
   trailing whitespace elsewhere is kept.
10. Both parts must be valid UTF-8. Invalid UTF-8 is refused on save with
    the byte offset.
11. The system part may be empty. The user part may not be empty when
    publishing a version.
12. Each part has a maximum size of 200,000 bytes (filterable, case 53).
13. Concurrent editing uses WordPress's post lock: a second editor sees the
    usual "someone else is editing" notice. On save, if the draft changed
    since the editor's page loaded (a draft hash sent with the form does not
    match), the save is refused and the editor is shown both versions; no
    silent last-write-wins.

### 4.2 Variables: declaration

14. Each variable has: `name`, `type`, `required` (default true),
    `default` (optional), `max_length` (characters, optional),
    `escape` (default `plain`), `enum` (for type `enum`), and a short
    `description` shown in the test runner and the consumer docs screen.
15. Names match `^[a-z][a-z0-9_]{0,63}$`. Names are case-sensitive and
    lower case only, so `Name` is a syntax error, not a different variable.
16. Types:
    - `string`: one line of text. Default `max_length` 2,000.
    - `text`: multi-line text. Default `max_length` 50,000.
    - `integer`: a PHP int, or a string matching `^-?[0-9]+$` within the
      platform int range. Rendered in plain decimal.
    - `number`: int, float or numeric string. Rendered with `.` as the
      decimal point and no thousands separator, never in exponent form for
      values between 1e-6 and 1e15, regardless of locale. NaN and infinity
      are refused.
    - `boolean`: PHP bool only, or the strings `true` and `false`.
      Rendered as `true` or `false`.
    - `enum`: a string that is exactly one of the declared values.
    - `list`: a PHP list of strings (or of scalars converted as `string`).
      Rendered one item per line, each prefixed with `- `. An empty list
      renders as an empty string.
    - `json`: any PHP array or scalar that `json_encode` accepts. Rendered
      as pretty-printed JSON with unescaped Unicode and slashes.
17. A `default` must itself pass the variable's type and length checks;
    otherwise the draft does not validate.
18. A variable with `required: false` and no `default` renders as an
    empty string when absent.
19. A value of `null` counts as absent.
20. A required variable may not have a default (it would never be used);
    the draft does not validate if it does.

### 4.3 Variables: syntax in the template

21. A placeholder is `{{` then optional spaces or tabs, a name, optional
    spaces or tabs, then `}}`. Example: `{{ article_title }}` or
    `{{article_title}}`. Nothing else is allowed between the braces: no
    filters, defaults, dots or expressions.
22. A literal `{{` in the text is written `\{{`. The backslash is removed
    on render. A backslash anywhere else is literal and kept. `\\{{name}}`
    renders one literal backslash followed by the value of `name`.
23. A run of three or more opening braces before a placeholder renders the
    extra braces literally: `{{{name}}}` renders `{`, the value, `}`. Single
    braces and `}}` on their own are always literal, so JSON examples in a
    prompt need no escaping unless they contain `{{`.
24. Any other `{{` (an unknown shape such as `{{ Name }}`, `{{ a b }}`,
    `{{}}`, or an unclosed `{{name`) is a syntax error on save, with line
    and column. The plugin never passes a malformed placeholder through as
    text, because a typo would then reach the model unnoticed.
25. A placeholder whose name is not declared is a syntax error.
26. A declared variable that is used in neither part is a warning, not an
    error. It is still validated when supplied.
27. A variable may be used more than once and in both parts.
28. Placeholders are found by a single left-to-right scan. Rendering is one
    pass: a value that contains `{{ other }}` is inserted as text and is
    never scanned again. There is no way for a value to inject a
    placeholder.

### 4.4 Rendering and escaping of values

29. Render takes a slug, a version selector (case 37), and an array of
    values keyed by variable name. It returns a rendered prompt object
    (section 5.2) or throws a typed exception. It never returns a partly
    rendered prompt.
30. Order of checks: resolve template and version; check every supplied
    value's type; apply defaults; check required values; check lengths;
    escape; substitute; check the total size. The first failure throws, and
    the exception lists every problem found in its stage (for example all
    missing variables at once, not only the first).
31. Values must be valid UTF-8. Invalid UTF-8 throws an invalid-variable
    exception naming the variable. CRLF and lone CR in values become LF.
32. `max_length` counts Unicode code points after escaping rules that
    remove characters, before fencing or JSON encoding. Over the limit
    throws; the plugin never truncates a value silently. A consumer that
    wants truncation does it before calling.
33. Unknown keys in the values array are ignored and reported in the
    rendered object's warnings. With the `strict` render option they throw.
34. Escape modes, declared per variable:
    - `plain` (default): remove C0 control characters except tab and LF,
      remove DEL and C1 controls, remove the bidirectional override and
      isolate characters (U+202A to U+202E, U+2066 to U+2069) and U+FEFF.
      Zero-width joiner and non-joiner are kept, because Indic and other
      scripts need them.
    - `raw`: no change beyond UTF-8 validation and line-ending
      normalisation. Allowed only for variables whose description says why;
      the editor warns when `raw` is chosen.
    - `line`: `plain`, then every run of whitespace (including newlines)
      becomes one space, then trim. For values that must stay on one line,
      such as a title inside a sentence.
    - `json`: the value is rendered as a JSON string literal (for `json`
      type, as compact JSON) with unescaped Unicode and slashes. For values
      placed inside a JSON example in the prompt.
    - `fenced`: `plain`, then the value is wrapped in an opening line
      `<name>` and a closing line `</name>` using the variable's own name.
      Any text inside the value that looks like the closing tag (matching
      `<\s*/\s*name\s*>` case-insensitively) is replaced with `[/name]`, so
      the value cannot close its own fence. Fencing lowers the risk of
      prompt injection; it does not remove it, and the docs say so.
35. Escaping is a property of the variable, not of each use, so the same
    variable cannot be fenced in one place and raw in another.
36. The rendered output has a total size limit, by default 400,000 bytes
    for both parts together (filterable). Over the limit throws.

### 4.5 Versions, the live pointer and revisions

37. Version selectors accepted by the public contract:
    - a positive integer: that exact version;
    - `live` (the default): the version the live pointer names;
    - `latest`: the highest published version number, live or not.
    The draft is never a valid selector for the public contract. Only the
    test runner and the editor's preview can render a draft.
38. Publishing a version takes the current draft, validates it, and if it
    has no errors stores an immutable snapshot with the next number for
    that template, the publishing user, the time (UTC), the editor's note
    and a content hash. The note is required (one line, up to 500
    characters), so the history explains itself.
39. If the draft is byte-identical to the latest version (same content
    hash), publishing is refused with "no changes since version N".
40. Numbers start at 1, increase by one, are never reused and never have
    gaps. Two editors publishing at the same moment both succeed, with
    consecutive numbers, or one is refused and told to reload; a duplicate
    number is impossible because the store enforces uniqueness of template
    and number, and the publish path retries once on a conflict.
41. By default the new version becomes live. The editor can untick "make
    live" to publish without moving the pointer (for testing a pinned
    number first).
42. "Make live" on any older version is the rollback. It does not create a
    version; it moves the pointer and records who moved it, when, from
    which number to which, and an optional reason, in the template's
    history.
43. "Restore to draft" copies any version back into the draft. It does not
    publish.
44. A version's stored content, variables, output declaration and settings
    never change after publish. There is no edit path, in the UI, the REST
    API, WP-CLI or the PHP API.
45. Versions are not stored as WordPress revisions. WordPress revisions are
    used only for draft editing history: every draft save creates a
    revision that includes the draft meta, and restoring a revision
    restores the draft. Site settings that limit or disable revisions affect
    only this draft history and never touch versions.
46. Autosave of the draft uses the normal autosave revision. An autosave is
    never published and never seen by consumers.
47. The history screen lists every version (number, author, time, note,
    content hash, whether live) and every pointer move, newest first, with
    a diff between any two versions or between a version and the draft.
    The diff is line-based, per part, plus a table of variable changes.

### 4.6 Consumers and compatibility checks

48. A consumer may declare, on `init`, which variables it supplies for a
    slug, under a consumer id of its own (for example its plugin slug).
    This is optional and informational.
49. When an editor publishes a version, or makes a version live, and that
    version declares a required variable that a declared consumer does not
    supply, the screen lists the consumers that will fail and requires a
    second, explicit confirmation. The check runs again on the server side,
    not only in the browser.
50. The same check is available to code (section 5.1) so a consumer can
    verify its pinned version at activation or in a Site Health test.
51. A consumer may ship a default template with its plugin by registering
    a definition (section 5.4) for a slug:
    - slug absent: the plugin creates the template, publishes the
      definition as version 1 with the note "Installed by <consumer id>",
      and makes it live;
    - slug present: nothing is overwritten. If the shipped definition's
      hash differs from the hash last seen from that consumer, the template
      shows "an update is offered by <consumer id>"; the editor can load it
      into the draft, review it and publish it like any edit. Nothing is
      ever published automatically after version 1.
52. Registering a default for a slug owned by another consumer is allowed
    only as an offer; the second consumer never becomes the installer. A
    warning is logged once per day.
53. Rendering a missing, trashed or draft-only template (no versions)
    throws a not-found exception with a reason (`missing`, `trashed`,
    `unpublished`). A consumer may pass a fallback definition in the render
    options; then render uses that definition instead of throwing for these
    three reasons only. Variable errors are never covered by the fallback.
    The rendered object says it came from the fallback.
54. Requesting a version number that does not exist throws a
    version-not-found exception with the highest available number.
55. When this plugin is inactive, the soft-dependency filter (section 5.3)
    returns its input unchanged (null), so a consumer that uses the filter
    falls back to its own built-in text without a fatal error.

### 4.7 The test runner

56. The runner is an admin screen reachable from the template list and the
    editor. It shows: a version picker (draft, or any version, default the
    draft), a model picker, a form generated from the variables (with type
    hints, required markers, descriptions and defaults), a fixture picker,
    and a Run button.
57. "Preview" renders without calling a model and costs nothing. It needs
    only the edit capability. Render errors are shown next to the fields.
58. "Run" renders, then calls the model through `llm-json-php`, then shows
    two columns: left, the rendered system and user parts exactly as sent
    (monospace, whitespace visible on request, character and estimated
    token counts); right, the answer. Under both: model, version or
    "draft", content hash, prompt tokens, completion tokens, cost and its
    source (provider-reported, price table, or unknown), duration, attempts,
    any guard action the library reported, and the run id.
59. Output declaration of a template: `text` (default) or `json` with a
    JSON schema. For `text`, the runner asks `llm-json-php` for an object
    with a single string field holding the answer and displays that string
    as the answer; the screen states this wrapper exists, because in modes
    without strict structured output the library places the schema in the
    system message. For `json`, the template's schema (object root) is used
    as given and the answer is shown as pretty-printed JSON, with the
    validation result.
60. Compare mode runs the same values against two selections side by side
    (two versions, or the draft and a version, or one version on two
    models): two calls, both counted against the caps, both reserved before
    the first call starts. If the second reservation fails, neither call is
    made.
61. Fixtures: an editor can save the current form values as a named
    fixture on the template (up to 50 per template, each within the
    variables' limits). Fixtures are draft data: they are not part of a
    version and do not change version hashes.
62. One run in flight per user. A second Run while one is in flight is
    refused with "a run is already in progress"; the lock expires after the
    request deadline plus 30 seconds, so a crashed request cannot block a
    user for long. A per-user rate of 10 runs per minute applies on top
    (filterable).

### 4.8 Spend path of the test runner

The order is fixed. Each step either passes or stops the run with a
message, and every stop after step 4 releases what step 4 reserved.

63. Step 1, kill switch. If the constant that disables the runner is
    defined and true, or the runner is switched off in settings, Run and
    Compare are refused and the buttons are disabled with the reason. The
    switch is read at the moment of the call, not cached from page load.
    Preview still works.
64. Step 2, permission. The user must hold the run capability; the request
    must carry a valid nonce (REST cookie auth or an admin form nonce).
65. Step 3, render. Render errors stop the run before any spend.
66. Step 4, own daily cap. The plugin keeps two per-site daily counters in
    UTC: runs started and known cost. Before the call it atomically reserves
    one run (a single conditional increment that fails at the cap, so two
    concurrent requests at cap minus one cannot both pass). Default caps:
    50 runs per day; cost cap off (0) until the administrator sets one.
    When a cost cap is set and the counter has reached it, the run is
    refused. These caps apply even when `spend-meter` is active.
67. Step 5, `spend-meter` pre-call check, only when that plugin is active.
    The runner asks it, through the pre-call filter its PRD defines, whether
    a call for this purpose and model may proceed, passing an estimate of
    input tokens (rendered characters divided by four, rounded up) and the
    output token cap. If it refuses, the run stops with its reason. If the
    filter throws or returns something unrecognised, the run stops (fail
    closed: a broken meter must not mean unmetered spend), and the error is
    logged.
68. Step 6, the call. One `llm-json-php` request with: purpose label
    `prompt-test` (filterable), the rendered system part as the system
    instruction, the rendered user part as the input, the schema from case
    59, the template's temperature and token cap (or the site defaults), a
    deadline of 60 seconds (filterable, never above the PHP time limit
    minus 10 seconds when that limit is set), WordPress HTTP transport and
    the transient store.
69. Step 7, report. Every attempt the library reports, successful or not,
    is passed to `spend-meter`'s record action when that plugin is active,
    with purpose, model, provider, token counts, cost and cost source,
    because failed attempts are billed too. A listener that throws does not
    break the run; the error is logged.
70. Step 8, settle. The known cost of all attempts is added to the own
    cost counter. Unknown cost adds nothing and the run row says
    "cost unknown". The reservation from step 4 is kept (the run happened).
71. Step 9, record. One run row is written (section 6) and the result is
    returned to the browser.
72. If the call throws (chain exhausted, credit exhausted, configuration,
    deadline), steps 7 to 9 still run with what the exception carries, the
    run row has status `failed` and the error class, and the screen shows a
    plain-language message: out of credit, no route answered, bad key,
    output did not match the schema, output rejected by the guard, timed
    out.
73. If no provider key or model is configured, Run is disabled with a link
    to settings. Preview still works.
74. If `llm-json-php` cannot be loaded (missing library in a broken
    install), the plugin shows an admin notice, the runner is disabled, and
    rendering for consumers keeps working.
75. A site may replace the whole client by a filter (section 5.3) that
    returns a configured `llm-json-php` client, for example one shared with
    the site's production chain. The caps, kill switch and `spend-meter`
    steps still apply to it.

### 4.9 Export, import and WP-CLI

76. Export writes one JSON document per template: format id and format
    version, slug, title, description, every version (number, definition,
    note, author login, time, hash), the live number, and the draft
    (optional). It never includes run rows, provider keys or settings.
77. Import of a template whose slug is absent creates it with the same
    version numbers, notes and hashes, and the same live number. Hashes are
    recomputed and an import whose hashes do not match is refused.
78. Import of a template whose slug exists never touches its versions. It
    loads the imported live version into the draft and tells the editor to
    review and publish. Nothing is published by an import onto an existing
    slug.
79. Import validates every definition with the same rules as save and
    publish. One invalid version refuses the whole file.
80. WP-CLI commands (section 5.6) cover list, show, render, export, import,
    publish from a file, and set-live. `render` prints the rendered parts
    and never calls a model. There is no CLI command that runs a model.

### 4.10 Lifecycle

81. Activation creates the tables, records the schema version, adds the
    capabilities to roles (section 8), and schedules the daily prune. It
    then checks every expected column exists, because silent skips of
    column additions have been seen in production; a missing column puts
    the plugin in a degraded state with an admin notice and a Site Health
    failure, and the runner is disabled.
82. Upgrade runs the same schema step when the stored schema version is
    older, on `admin_init` and on WP-CLI, never on a front-end request.
83. Deactivation unschedules the prune and changes nothing else. Consumers
    then get null from the soft-dependency filter.
84. Trashing a template: if any version was rendered in the last 7 days
    (case 99), the trash link asks for confirmation and lists the consumers
    seen. Trashed templates throw not-found with reason `trashed`. Restoring
    from trash restores everything.
85. Permanently deleting a template removes its versions, history, fixtures
    and run rows. It requires the manage capability.
86. Uninstall removes everything the plugin created (section 6), on every
    site of a network, unless the keep-data constant is defined and true.

### 4.11 Failure modes summary

87. Storage read error during render: throws a storage exception. Fail
    closed: a consumer must never get a half-loaded or empty prompt.
88. Object cache returns a stale live pointer after a change on another
    server: the pointer has a short cache life (60 seconds) and is
    invalidated on every move; a consumer that needs certainty pins a
    number.
89. Version row exists but its JSON does not decode, or its recomputed hash
    differs from the stored one: throws an integrity exception naming the
    slug and number, logs once per hour, and Site Health reports it. The
    plugin does not try to repair it.
90. Clock skew or a timezone change does not affect caps: counters are
    keyed by UTC date.
91. Settings missing or corrupt: safe defaults apply (runner on, 50 runs a
    day, cost cap off) and an admin notice says settings were reset in
    memory; nothing is written until the administrator saves.

### 4.12 Multisite

92. Each site has its own templates, versions, runs, counters and
    settings. Network activation runs activation per site, and new sites
    get it when they are created.
93. A network-wide provider key may be set as a constant; a site-level key
    in settings overrides it only if the network allows (a constant).
94. Super admins hold every capability on every site.

### 4.13 Observability cases

95. Every publish, pointer move, import, trash, restore and permanent
    delete writes a history entry and fires an action (section 5.3).
96. Every run, including refused ones, writes a log line through the
    plugin's log action with: user id, slug, version or draft, model,
    outcome, tokens, cost, duration. Prompt text and values are not logged.
97. Refusals by caps, kill switch or `spend-meter` are counted per day and
    shown on the runner screen ("3 runs refused today: daily cap").
98. Site Health shows: library present, provider configured, tables and
    columns present, runner on or off, today's runs against the cap,
    integrity errors.
99. The plugin records, per template version, the last time it was
    rendered for a consumer and which consumer id was passed, at most once
    an hour per version (throttled through a transient), so editors can see
    what is in use without a write on every render.

## 5. Public interface

Names in this section are this project's own. The namespace is
`IndianOpinion\PromptLibrary`. Global functions and hooks use the prefix
`promptlib_`. The contract in 5.1 to 5.4 follows semantic versioning from
0.1.0: no breaking change without a major version.

### 5.1 PHP functions (the consumer contract)

| Function | Returns | Notes |
|---|---|---|
| `promptlib_render(string $slug, array $values, int\|string $version = 'live', array $options = [])` | `RenderedPrompt` | Throws on any failure (5.5). |
| `promptlib_get(string $slug, int\|string $version = 'live')` | `TemplateVersion` | Throws not-found. Read-only view of a version. |
| `promptlib_exists(string $slug)` | `bool` | True when a template with at least one version exists and is not trashed. |
| `promptlib_check(string $slug, array $supplied_names, int\|string $version = 'live')` | `CheckResult` | Lists missing required, unknown and unused names without rendering. |
| `promptlib_register_consumer(string $slug, string $consumer_id, array $supplied_names)` | `void` | Case 48. Call on `init`. |
| `promptlib_register_default(string $consumer_id, array $definition)` | `void` | Case 51. Call on `init`. Definition format in 5.4. |
| `promptlib_api_version()` | `string` | The contract version, `1.0` for 0.1.0. |

Render options:

| Key | Type | Default | Meaning |
|---|---|---|---|
| `strict` | bool | false | Unknown keys throw instead of warning. |
| `fallback` | array | none | A definition (5.4) used only for missing, trashed or unpublished templates. |
| `consumer` | string | `''` | Consumer id, recorded by case 99. |

### 5.2 Value objects

All immutable, in `IndianOpinion\PromptLibrary`.

- `RenderedPrompt`: `slug()`, `version()` (int, or 0 when from a
  fallback), `fromFallback()`, `system()` (string), `user()` (string),
  `contentHash()` (hash of the version), `renderHash()` (SHA-256 of
  system, a NUL byte, and user), `warnings()` (list of strings),
  `output()` (`OutputSpec`), `settings()` (temperature and token cap or
  null), `toMessages()` (a chat message list with system first, the system
  message omitted when empty), `toArray()`.
- `TemplateVersion`: `slug()`, `version()`, `title()`, `variables()`
  (list of `VariableSpec`), `output()`, `settings()`, `contentHash()`,
  `publishedAt()` (UTC `DateTimeImmutable`), `isLive()`. The raw parts are
  available as `systemTemplate()` and `userTemplate()`.
- `VariableSpec`: `name()`, `type()`, `required()`, `default()`,
  `maxLength()`, `escape()`, `enum()`, `description()`.
- `OutputSpec`: `mode()` (`text` or `json`), `schema()` (array or null).
- `CheckResult`: `ok()`, `missingRequired()`, `unknown()`, `unused()`.

The renderer is also usable without WordPress: `Renderer` and `Parser`
take a definition array and values, with no database. Consumers' unit tests
can use them.

### 5.3 Hooks

Soft-dependency filter (the one hook a consumer needs if it does not want to
check `function_exists`):

- Filter `promptlib_render` with arguments `(null, string $slug, array
  $values, int|string $version, array $options)`. Returns an array with keys
  `system`, `user`, `slug`, `version`, `content_hash`, `render_hash`,
  `warnings`, `output`, `settings`, or a `WP_Error` with the exception's
  code and message. When the plugin is inactive the filter returns `null`.

Actions:

- `promptlib_version_published (int $template_id, string $slug, int $version, int $user_id)`
- `promptlib_live_changed (int $template_id, string $slug, ?int $from, int $to, int $user_id)`
- `promptlib_template_trashed`, `promptlib_template_restored`, `promptlib_template_deleted (int $template_id, string $slug)`
- `promptlib_imported (string $slug, string $mode)` with mode `created` or `draft`
- `promptlib_run_finished (array $run)` with the run row minus prompt text and answer
- `promptlib_log (string $level, string $message, array $context)`

Filters:

- `promptlib_runner_client (null|Client $client, array $route)`: supply an
  `llm-json-php` client.
- `promptlib_runner_purpose (string $purpose, string $slug)`
- `promptlib_runner_deadline (int $seconds)`
- `promptlib_daily_caps (array $caps)` with keys `runs` and `cost`
- `promptlib_rate_limit (int $per_minute, int $user_id)`
- `promptlib_max_part_bytes (int $bytes)`, `promptlib_max_render_bytes (int $bytes)`
- `promptlib_fixture_limit (int $count)`
- `promptlib_run_retention_days (int $days)`
- `promptlib_store_runs (bool $store, string $slug)`: false keeps only
  metadata, not prompt text or answers.
- `promptlib_role_caps (array $map)`: default role to capability map,
  read on activation and when the administrator resets roles.

### 5.4 Definition format

One JSON-compatible array, used by `promptlib_register_default`, the
fallback option, export, import and publish-from-file:

- `format`: `prompt-library/1`
- `slug`, `title`, `description`
- `system`, `user`: strings
- `variables`: list of objects with the keys of case 14
- `output`: `{ "mode": "text" }` or `{ "mode": "json", "schema": {...} }`
- `settings`: `{ "temperature": number|null, "token_cap": int|null }`
- `note`: string (used as the version note when published)

The content hash is SHA-256 of the canonical JSON of `system`, `user`,
`variables`, `output` and `settings` (keys sorted, no insignificant
whitespace, unescaped Unicode and slashes). Title, description and note do
not change the hash.

### 5.5 Exceptions

All extend `IndianOpinion\PromptLibrary\Exception\PromptLibraryException`
and carry a stable string `code()` used by the filter's `WP_Error`:

| Class | Code | When |
|---|---|---|
| `TemplateNotFoundException` | `promptlib_not_found` | Missing, trashed, unpublished (reason on the exception). |
| `VersionNotFoundException` | `promptlib_version_not_found` | Number does not exist. |
| `MissingVariableException` | `promptlib_missing_variable` | Lists every missing name. |
| `InvalidVariableException` | `promptlib_invalid_variable` | Type, enum, UTF-8, length; lists every problem. |
| `UnknownVariableException` | `promptlib_unknown_variable` | Strict mode only. |
| `TemplateSyntaxException` | `promptlib_syntax` | Part, line, column, message. Thrown by the parser and by fallback definitions. |
| `RenderTooLargeException` | `promptlib_too_large` | Case 36. |
| `IntegrityException` | `promptlib_integrity` | Case 89. |
| `StorageException` | `promptlib_storage` | Case 87. |

The runner's own refusals (`promptlib_runner_disabled`,
`promptlib_cap_reached`, `promptlib_meter_refused`, `promptlib_rate_limited`,
`promptlib_busy`, `promptlib_not_configured`) are REST error codes, not
consumer exceptions.

### 5.6 REST API and WP-CLI

REST namespace `prompt-library/v1`, cookie or application-password auth,
every route has a permission callback:

| Route | Method | Capability | Purpose |
|---|---|---|---|
| `/templates` | GET | edit | List slugs, titles, live numbers. |
| `/templates/{slug}` | GET | edit | Draft and version list. |
| `/templates/{slug}/versions/{n}` | GET | edit | One version. |
| `/templates/{slug}/versions` | POST | publish | Publish the draft (note, make-live flag, confirmation flag). |
| `/templates/{slug}/live` | PUT | publish | Move the pointer. |
| `/templates/{slug}/preview` | POST | edit | Render draft or version with values. No model call. |
| `/runs` | POST | run | Run or compare. |
| `/runs` | GET | run | The current user's recent runs; all runs with manage. |

WP-CLI: `wp prompt-library list`, `show <slug> [--version=<v>]`,
`render <slug> --values=<file.json> [--version=<v>]`, `export <slug>...
[--dir=<dir>] [--with-draft]`, `import <file>...`, `publish <slug>
--from=<file.json> --note=<text> [--no-live]`, `set-live <slug> <n>
[--reason=<text>]`. Exit code 0 on success, 1 on any error, with the error
code printed.

### 5.7 Constants

| Constant | Effect |
|---|---|
| `PROMPTLIB_DISABLE_RUNNER` | true disables Run and Compare (case 63). |
| `PROMPTLIB_PROVIDER_KEY` | Provider key; preferred over the settings field. |
| `PROMPTLIB_ALLOW_SITE_KEY` | Multisite: allow a site key to override the constant. |
| `PROMPTLIB_KEEP_DATA_ON_UNINSTALL` | true keeps all data on uninstall. |

## 6. Data

- **Post type** (the plugin's own name, at most 20 characters): one post
  per template. `post_name` holds the slug, `post_title` the title,
  `post_excerpt` the description, `post_content` stays empty.
- **Post meta on the template**: the draft (one structured value, included
  in revisions), fixtures, the live pointer, the consumer-offer state per
  consumer id (last seen hash, pending flag), the installer consumer id.
  All registered with `register_post_meta`, not shown in REST, with the
  draft marked as revisioned.
- **Versions table**: id, template id, slug, version number, definition
  (JSON), content hash, note, author user id, published time (UTC). Unique
  key on template id and number; index on slug and number.
- **History table** (or rows in the versions table with a type column, the
  builder's choice, recorded in ARCHITECTURE.md): pointer moves, imports,
  trash and restore events with user, time and reason.
- **Runs table**: id, template id, version (0 for draft), user id, model,
  provider, status, error code, prompt tokens, completion tokens, cost, cost
  source, duration in ms, attempts, rendered parts and answer (nullable,
  see `promptlib_store_runs`), compare group id, created time (UTC).
- **Counters**: per site, per UTC day: runs started, known cost, refusals
  by reason. Rows older than 7 days are pruned.
- **Options**: settings (not autoloaded), schema version (autoloaded,
  small). The provider key, if stored in settings, is in its own
  non-autoloaded option.
- **Transients**: per-user run lock, rate counter, last-render throttle.
- **Retention**: versions and history are kept for the life of the
  template. Run rows are kept 30 days (filterable) and at most 5,000 per
  site, oldest first; pruned by a daily cron event.
- **Uninstall** removes: all template posts with their meta and revisions,
  the tables, the options, the transients, the cron event and the
  capabilities from every role. It never touches posts, meta or options it
  did not create.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| Runner enabled | on | Kill switch. The constant wins. |
| Provider kind | OpenRouter | OpenRouter, OpenAI, or a generic chat completions endpoint (base URL). |
| Provider key | none | Constant preferred. Field is write-only in the UI. |
| Models offered | none | List of model ids the runner may use. Run is disabled while empty. |
| Default model | first in the list | |
| Default temperature | provider default | Template settings override. |
| Default output token cap | 2,048 | Template settings override; never above the site maximum. |
| Site maximum output token cap | 8,192 | |
| Daily runs cap | 50 | Per site, UTC day. |
| Daily cost cap | 0 (off) | In the provider's currency as reported; uses the price table when set. |
| Price table | empty | Optional per-model input and output price per million tokens, with the date the prices were taken. Never guessed. |
| Store prompt text and answers in run rows | on | Off keeps metadata only. |
| Run retention | 30 days | |
| Per-user rate | 10 runs a minute | |

## 8. Security and privacy

- Capabilities, granted by role on activation (filterable, editable by
  administrators on the settings screen):
  - edit templates and use Preview: administrator, editor;
  - publish versions and move the live pointer: administrator, editor;
  - run the test runner (spends money): administrator only;
  - manage settings, delete permanently, see every user's runs:
    administrator only.
  Meta capabilities are mapped so core checks on the post type use these
  and nothing falls back to `edit_posts`.
- Rendering through the PHP contract needs no capability. It is server code
  calling server code. The REST routes always check.
- Every admin form and REST write checks a nonce and the capability.
- Every value shown in wp-admin is escaped for its context. The rendered
  prompt and the answer are shown as text, never as HTML.
- Template text is never executed, evaluated, or run as a shortcode, and
  never passes through content display filters.
- The provider key never appears in REST responses, exports, logs, page
  source or run rows. The settings field shows only whether a key is set.
- The generic provider URL must be HTTPS unless the host is localhost
  (enforced by the library); the plugin refuses to save other values.
- Run rows may hold personal data supplied as variable values. They are
  visible only to their author and to managers, kept 30 days, and covered
  by a personal data exporter and eraser keyed on the user who ran them.
  Settings state this, and the setting to store metadata only exists for
  sites that should not keep text at all.
- Values are untrusted. Escape modes (case 34) are the defence; the docs
  explain that no escape mode makes prompt injection impossible.
- No outbound request is made except the runner's model call.

## 9. Performance budget

- Render of a version already in the object cache: under 1 ms for 20 KB of
  template text with 50 placeholders on a typical host.
- Uncached render: at most two database queries (pointer and version row).
  Parsed versions are cached in the object cache by template and number for
  12 hours (immutable, so safe); the live pointer for 60 seconds.
- No database write on a consumer render, except case 99 at most once an
  hour per version.
- No autoloaded option larger than 1 KB. No queries or assets on the front
  end. Admin assets load only on the plugin's own screens.
- The runner adds no work beyond the model call itself and four small
  writes (reservation, settle, run row, log).

## 10. Compatibility

- PHP 8.1, 8.2, 8.3. WordPress 6.4 or later (needed for revisioned post
  meta).
- Depends on `indianopinion/llm-json-php` (bundled for non-Composer
  installs). Soft dependency on `spend-meter`. No other runtime dependency.
- Single site and multisite. Works with and without a persistent object
  cache. Page caches are irrelevant (admin only).
- Classic editor and block editor sites alike: the plugin's screens never
  use either for templates.
- Works where `DISALLOW_UNFILTERED_HTML` is set, because nothing goes
  through kses.
- Hosts with a short PHP time limit: the runner's deadline adapts (case 68).

## 11. Observability

- History screen per template (case 47).
- Runner screen footer: today's runs against the cap, known cost against
  the cap, refusals today by reason, and whether `spend-meter` is active.
- Runs list: recent runs with outcome, model, tokens, cost, duration.
- Site Health tests (case 98).
- Log action `promptlib_log` for every event in cases 95 and 96, plus
  integrity and storage errors. When `WP_DEBUG_LOG` is on, the plugin also
  writes these lines to the PHP error log with the prefix
  `[prompt-library]`. No prompt text or values in logs.
- "Last rendered" column in the template list (case 99).

## 12. Acceptance tests

Each test names the cases it covers. All run under PHPUnit with the stub
layer, except where marked "integration", which may use a real WordPress
test install in CI if the builder adds one.

1. (1) The post type is registered not public, not queryable, not in REST,
   excluded from search.
2. (2, 76) A draft round-trips every field through save and load
   unchanged.
3. (3) Slugs: valid ones accepted; `A-b` lowercased; `a--b`, `-a`, `a b`,
   101 characters refused with a message.
4. (4) Saving a duplicate slug, including against a trashed template, is
   refused; no `-2` slug is ever created.
5. (5) After version 1, a slug change is refused; duplicate-as-new works.
6. (6) The post type has no block editor support.
7. (7) A part containing straight quotes, `<tag>`, `[shortcode]`, `--`,
   `...` and blank lines reads back byte-identical, with the kses filters
   active for a user without unfiltered HTML.
8. (7, 22) A part containing backslashes and `\{{` reads back
   byte-identical after save (no slash stripping).
9. (8) A draft with syntax errors saves; publish is refused with part,
   line and column.
10. (9) CRLF and lone CR become LF; trailing newline kept.
11. (10, 31) Invalid UTF-8 in a part is refused on save; in a value it
    throws invalid-variable.
12. (11) Empty system part publishes; empty user part does not.
13. (12, 36) Oversize part refused; oversize render throws too-large.
14. (13) A stale draft hash refuses the save and returns both versions.
15. (14, 15) Variable names validated; `Name` and `1a` refused.
16. (16) One test per type: accepted values, refused values, exact
    rendering (including number locale independence under a comma-decimal
    locale, and NaN refused).
17. (17, 20) A default that fails its own type, and a required variable
    with a default, both fail validation.
18. (18, 19) Optional absent and null values render empty.
19. (21) Spacing variants of a placeholder render the same.
20. (22, 23) `\{{x}}`, `\\{{x}}`, `{{{x}}}` render as specified.
21. (24) Each malformed shape is a syntax error with correct line and
    column.
22. (25, 26) Undeclared placeholder is an error; unused declaration is a
    warning.
23. (27) A variable used in both parts renders in both.
24. (28) A value containing `{{ other }}` renders literally and `other` is
    not substituted.
25. (29, 30) A render with three missing and two invalid values throws
    missing-variable listing all three; with the missing ones supplied it
    throws invalid-variable listing both. Nothing partial is returned.
26. (32) Over-length value throws; the value is never truncated.
27. (33) Unknown keys warn; with `strict` they throw.
28. (34) One test per escape mode with hostile input: control characters,
    bidi overrides, ZWJ in a Devanagari word (kept), newlines for `line`,
    quotes and backslashes for `json`, and a value containing `</name>`,
    `< / NAME >` for `fenced`.
29. (35) The definition schema has no per-use escape option.
30. (37) `live`, `latest` and a number select the right version; the
    draft is never returned by the contract.
31. (38) Publish stores author, UTC time, note, hash; empty note refused.
32. (39) Publishing an unchanged draft is refused.
33. (40) Two concurrent publishes yield consecutive numbers, never a
    duplicate (simulated conflict on the unique key, one retry).
34. (41, 42) Publish without live keeps the pointer; make-live on an older
    version moves it and writes a history entry with from, to and reason.
35. (43) Restore to draft copies a version into the draft only.
36. (44) No code path updates a version row (the repository class has no
    update method; REST and CLI have no such route or command).
37. (45, 46) Draft saves create revisions carrying the draft meta; with
    revisions disabled, versions are unaffected; autosave never reaches
    consumers.
38. (47) The diff between two versions shows part line changes and
    variable changes.
39. (48, 49, 50) A consumer supplying `a` blocks publishing a live version
    that requires `b` until confirmed, server side; `promptlib_check`
    reports the same.
40. (51) Registering a default installs version 1 live when absent; a
    changed shipped definition produces an offer and publishes nothing.
41. (52) A second consumer's default for the same slug is an offer only.
42. (53) Missing, trashed and unpublished each throw with their reason;
    with a fallback they render from it and say so; a variable error is
    not covered by the fallback.
43. (54) A missing number throws with the highest available number.
44. (55) With the plugin inactive, the filter returns null.
45. (56, 57) Preview renders the draft without any HTTP call.
46. (58) Run shows both columns and all listed metadata (HTTP faked).
47. (59) Text mode uses the single-field wrapper; JSON mode passes the
    template's schema unchanged and shows the validation result.
48. (60) Compare reserves two runs before the first call; with one slot
    left, neither call is made.
49. (61) Fixtures save, load, respect the limit, and do not change hashes.
50. (62) A second concurrent run is refused; the lock expires; the rate
    limit applies.
51. (63) Constant and setting each stop Run with no HTTP call; flipping the
    setting between page load and Run takes effect.
52. (64) Missing nonce or capability is refused.
53. (65) A render error stops the run before reservation.
54. (66) The cap stops the 51st run; two concurrent runs at 49 both
    succeed and a third fails; the cost cap stops a run once reached; caps
    apply with a fake `spend-meter` present.
55. (67) A fake meter that refuses stops the run and releases the
    reservation; one that throws stops the run (fail closed).
56. (68) The request carries the purpose, parts, schema, cap, temperature
    and a deadline clamped to the PHP time limit.
57. (69) Each attempt, including failed ones, reaches the fake meter's
    record action; a throwing listener does not break the run.
58. (70) Known cost is added; unknown cost adds nothing and is labelled.
59. (71, 72) Success and each failure class write one run row with the
    right status and show the right message.
60. (73, 74) No key, no model, or no library disables Run; Preview and
    consumer renders still work.
61. (75) A client supplied by the filter is used, and caps still apply.
62. (76, 77) Export then import on an empty site reproduces versions,
    numbers, hashes and the live number; a tampered hash is refused.
63. (78) Import onto an existing slug only loads the draft.
64. (79) One invalid version refuses the whole file.
65. (80) Each CLI command's success and error exit codes; `render` makes no
    HTTP call.
66. (81, 82) Activation creates tables and checks columns; a simulated
    missing column puts the plugin in degraded state with the runner off.
67. (83) Deactivation leaves data and unschedules cron.
68. (84) Trashing a recently rendered template asks for confirmation;
    trashed renders throw `trashed`; restore works.
69. (85) Permanent delete removes versions, history, fixtures and runs and
    needs the manage capability.
70. (86) Uninstall removes everything listed in section 6 and nothing else;
    with the keep-data constant it removes nothing.
71. (87, 89) A storage error and a corrupted version row each throw the
    right exception; nothing partial is returned.
72. (88) The pointer cache is invalidated on a move.
73. (90) Counters key on the UTC date with a non-UTC site timezone.
74. (91) Corrupt settings yield safe defaults without a write.
75. (92, 93, 94) Per-site isolation; network key constant and override
    rule; super admin capabilities. (Integration.)
76. (95, 96, 97) Each event fires its action and log line; log context
    holds no prompt text or values; refusals are counted.
77. (98) Site Health tests report each state.
78. (99) Last-rendered is written at most once an hour per version.
79. (Section 8) Every REST route rejects users without the capability; the
    provider key never appears in any REST response or export.
80. (Section 9) A benchmark test renders 20 KB with 50 placeholders under
    the budget on CI hardware (reported, with a generous threshold).

## 13. Open questions

For the builder to answer in `docs/ARCHITECTURE.md`:

1. `spend-meter` interface binding. Its PRD is written in the same batch.
   Bind the pre-call check (case 67) and the per-attempt record (case 69)
   to the names its merged PRD defines, inside one adapter class, so a
   later rename touches one file. If its PRD has no pre-call veto, record
   that and keep the own caps as the only gate.
2. Day boundary: UTC (this PRD) or the site timezone. Align with
   `spend-meter` if it chose otherwise, and record the choice.
3. History storage: a separate table or a type column on the versions
   table (section 6).
4. Bundling `llm-json-php` for non-Composer installs: vendored copy in the
   release zip or a documented separate install. Record the choice and how
   version conflicts with another plugin's copy are avoided.
5. Whether `latest` is worth keeping as a selector, or whether it invites
   consumers to bypass the live pointer. Keep it unless there is a reason.
6. Must-use plugin support: what activation work would need to move to a
   lazy first-run path for it to work.
7. Whether token estimation for the pre-call check (characters divided by
   four) should be replaced by anything the library exposes.

## 14. Lessons from production

Written as rules. They come from running prompt-driven features on a
WordPress news site.

1. Never store a prompt in post content, and never pass prompt text
   through `the_content` or any display filter. Those filters run more than
   once per request on some themes, add markup, turn quotes curly and run
   shortcodes. Store prompt text in meta or a table and read it raw.
2. Never let kses touch prompt text. It strips angle-bracket markup for
   users without the unfiltered HTML capability, and prompts often contain
   tag-like delimiters.
3. Post meta writes unslash their input. Slash prompt text before writing
   it through the meta API, and test that backslashes survive a round trip.
4. Never rely on WordPress revisions for anything a consumer depends on.
   Site settings prune or disable them. Immutable versions live in their
   own storage.
5. WordPress appends a number to duplicate post names without telling
   anyone. A slug that is an API contract must be checked and refused, not
   adjusted.
6. Schema helpers can silently skip a column addition. After creating or
   upgrading tables, check every expected column exists and refuse to run
   the parts that need a missing one.
7. Read a kill switch at the moment of the call. A switch read at page load
   or cached for a request lets one more call through after it is flipped.
8. Count failed attempts as spend. A rejected or truncated answer was
   still billed.
9. Reserve against a daily cap before the call, atomically. Check-then-act
   lets concurrent requests overrun it.
10. Never truncate a value silently to fit. A cut value changes the prompt's
    meaning without anyone noticing; throw and let the caller decide.
11. Keep zero-width joiners and non-joiners when cleaning text. Indic
    scripts need them; removing them corrupts words.
12. Keep the model's output exactly as returned until it is shown. Any
    tool that rewrites output after the call can corrupt it.
13. A template change that adds a required input breaks every caller that
    does not supply it, at the moment it goes live. Check declared callers
    before moving the live pointer.
14. Retire old endpoints with an explicit, loud refusal, not by deleting
    the code path, so callers that still use them fail visibly.
