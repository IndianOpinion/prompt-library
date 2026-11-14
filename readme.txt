=== Prompt Library ===
Contributors: indianopinion
Tags: prompt, templates, llm, versioning, ai
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Prompt templates with typed variables, immutable versions, a live pointer and a capped test runner.

== Description ==

Written from scratch; not derived from any third-party plugin.

Prompt Library keeps the prompts your plugins send to language models in WordPress, where editors can change the wording without a deploy. Each template has a draft and immutable, numbered versions; one version is live. Other plugins render a template by slug and version with typed, escaped variables. A test runner shows the rendered prompt and the model's answer side by side, with a daily cap and a kill switch.

* Templates are stored outside post content, so no display filter, shortcode or kses pass ever changes them.
* Strict placeholders: `{{ name }}`. Malformed placeholders are errors with line and column.
* Typed variables with per-variable escaping: plain, raw, line, json and fenced.
* Versions with notes, content hashes, a live pointer, rollback, history and diff.
* Declared consumers are checked before a version that needs more variables goes live.
* Test runner: Preview for free, Run and Compare under a daily runs cap, an optional cost cap, a kill switch and optional spend-meter integration.
* Export and import, REST API and WP-CLI.

Nothing publishes on its own: shipped defaults are installed once as version 1, and later changes are only offered to an editor.

== Installation ==

1. Copy the plugin into `wp-content/plugins/prompt-library`.
2. For the test runner, run `composer install --no-dev` in the plugin folder. It fetches the llm-json-php library from GitHub. Without it, everything except the runner works.
3. Activate the plugin. It is not supported as a must-use plugin in 0.1.0.
4. In Prompts, Settings, add a provider key (or define `PROMPTLIB_PROVIDER_KEY`) and the models offered.

== Frequently Asked Questions ==

= Does rendering a template call a model? =

No. Rendering only builds text. Your plugin makes its own call. The test runner is the only part of this plugin that calls a model.

= What if this plugin is deactivated? =

The `promptlib_render` filter returns null, so a consumer that uses it falls back to its own text.

= Does it store personal data? =

Run rows may hold values typed into the runner, for 30 days by default. They are covered by the personal data exporter and eraser, and can be switched to metadata only.

== Changelog ==

= 0.1.0 =
* First release.
