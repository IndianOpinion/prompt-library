# Security policy

## Reporting a vulnerability

Email **hello@indianopinion.org** with a description, the version affected and
steps to reproduce. Please do not open a public issue for a security problem.

We acknowledge reports within 3 working days and aim to send a fix or a plan
within 14 days. We credit reporters in the release notes unless asked not to.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Scope notes

- The escape modes reduce the risk of prompt injection through variable
  values; they do not remove it. A report that a value can steer a model is
  expected behaviour unless it breaks a stated rule (for example, a value that
  closes its own fence, or a value that is scanned for placeholders).
- The provider key must never appear in a REST response, an export, a log
  line, page source or a run row. Any way to read it is in scope.
