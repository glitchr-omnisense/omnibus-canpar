# Changelog

## [Unreleased]

## [2.0.0] - 2026-11-13

The first stable 2.x release: it installs with Composer's default minimum-stability (stable).

### Release
- Requires `omnisense/contracts` `^1.0` and `omnisense/omnibus` `^2.0`, stable versions, where it
  required `@dev` ones; `extra.branch-alias` `dev-2.x` → `2.x-dev`.
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml: the package installed alone,
  PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml` (that CI on the tag,
  then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the package's own tests.
- README: the install line and a first call on a recorded answer (no account, nothing sent).

### Added
- On omnisense/contracts: the carrier's usage policy as data (`policy.json`, named in
  `composer.json`'s `extra.omnisense.policy`; docs/index.md, Usage policy), and its waits honoured:
  the API client takes the gateway's Backoff, a 429 is a `RateLimitedException`, the next calls are
  held unsent, nothing is retried. Requires omnisense/contracts. `phpstan.neon` at level max,
  `phpstan-baseline.neon` what was there.

### Fixed
- A `CarrierException` no longer carries the password. The message quoted the HTTP client's (the
  whole URL), the previous was the client's own exception, and every trace of the chain kept the
  arguments of the calls under it (the request's options, the `Api` with its credentials) when
  `zend.exception_ignore_args` is off: a log of the whole chain wrote them. The message masks them
  as `***`, the previous is a copy of the client's exception (the same class and code), and no
  exception `Api` throws keeps its trace's arguments (`Omnibus\Http\Redaction`).
- A label that failed to come at booking can be fetched again: `slip()` (GetSlip) finds the
  barcode's shipment id (trackByBarcode, as cancel() does) and asks getLabels for it; a comment
  claimed it before, while GetSlip was not supported.
