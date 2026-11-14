# Contributing

Thank you for helping. Small, focused pull requests with a test are the
easiest to review.

## Setup

PHP 8.1 or later with `mbstring` and `json`, and Composer 2.

```sh
git clone https://github.com/IndianOpinion/prompt-library.git
cd prompt-library
composer install
```

No WordPress install is needed for the tests: they run on a small stub layer
in `tests/stubs/`, with a fake `wpdb` that understands the plugin's own SQL
statements by the marker comment each one carries.

## Checks

```sh
composer test   # PHPUnit
composer stan   # PHPStan, level 6, with WordPress stubs
composer cs     # PHP_CodeSniffer, PSR-12
composer check  # all three
```

All three must pass before a pull request is merged. CI runs them on PHP 8.1,
8.2 and 8.3. Only PHP 8.1 syntax is allowed in `src/`.

## Style

- `declare(strict_types=1);` in every PHP file.
- PSR-12. Long, escaped HTML lines in the admin screens are allowed.
- Typed exceptions with a stable `promptlib_` code; never the `@` operator,
  never `eval`, never a shell.
- Escape everything printed in wp-admin; check a nonce and a capability on
  every write.
- Never pass prompt text through a content filter, and never log prompt text
  or variable values.
- A new SQL statement carries a `/*promptlib:<name>*/` marker and gets a case
  in `tests/stubs/wpdb.php`.
- Prose: short sentences, plain words, British spelling.

## Pull requests

- One change per pull request, with a test for each behaviour it adds or fixes.
- Update `CHANGELOG.md` under Unreleased, and the README when behaviour or
  configuration changes.
- Record any design choice in `docs/ARCHITECTURE.md`.
- A DCO-style sign-off is not required.

## Reporting a security problem

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
