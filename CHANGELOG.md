# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). The consumer
contract (the `promptlib_*` functions, the value objects, the
`promptlib_render` filter and the exception codes) is version `1.0`.

## [Unreleased]

## [0.1.0] - 2026-11-14

### Added

- Prompt templates as a private post type with a plain-textarea editor, draft
  validation with part, line and column, a stale-draft check, autosave and
  revisions of the draft.
- A strict placeholder syntax (`{{ name }}`, `\{{` for a literal), typed
  variables (`string`, `text`, `integer`, `number`, `boolean`, `enum`, `list`,
  `json`) and per-variable escape modes (`plain`, `raw`, `line`, `json`,
  `fenced`).
- Immutable numbered versions with notes and content hashes, a live pointer,
  rollback, restore to draft, history and a line diff.
- The consumer contract: `promptlib_render()`, `promptlib_get()`,
  `promptlib_exists()`, `promptlib_check()`, `promptlib_register_consumer()`,
  `promptlib_register_default()`, `promptlib_api_version()`, and the
  soft-dependency filter `promptlib_render`.
- A test runner with Preview, Run and Compare, fixtures, a daily runs cap, a
  daily cost cap, a kill switch, a per-user lock and rate, and optional
  spend-meter integration with one pre-spend check per attempt.
- Export and import, a REST API (`prompt-library/v1`) and WP-CLI commands.
- Site Health tests, a personal data exporter and eraser, and uninstall that
  removes everything the plugin created.
