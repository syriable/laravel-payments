# Contributing

Thanks for considering a contribution to `syriable/laravel-payments`.

## Guiding principle

This package is deliberately small. Before proposing a feature, ask the
question the architecture is built around: **can this be simpler?** Pull
requests that add abstraction, configuration, or surface area need to justify
their weight. Pull requests that remove complexity are always welcome.

## Reporting bugs

Open an issue with:

- the package version, PHP version, and Laravel version,
- a minimal reproduction (ideally a failing test), and
- what you expected versus what happened.

For anything security-related, **do not open a public issue** — see
[SECURITY.md](SECURITY.md).

## Proposing a new gateway

In most cases a new gateway should **not** be added to this repository. The
package is intentionally a small core (Stripe + PayPal) plus an open ecosystem.
Regional and additional gateways belong in their own Composer packages that
register themselves via `Gateway::extend()`. See the "Adding a custom gateway"
section of the README.

If you believe a gateway belongs in core, open an issue to discuss it first.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Add or update tests — every behavior change needs test coverage.
3. Make sure the full suite passes: `composer test`.
4. Run static analysis: `composer analyse`.
5. Format your code: `composer format` (Laravel Pint).
6. Update `CHANGELOG.md` under the `## [Unreleased]` heading.
7. Keep the PR focused on a single concern.

## Coding standards

- PHP 8.3+, `declare(strict_types=1)` in every file.
- Follow the existing style; Pint enforces it.
- Prefer readonly DTOs and small, focused classes.
- No floating-point math on monetary amounts — amounts are integer minor units.
- Avoid introducing abstract base classes unless duplication genuinely appears.

## Running the test suite

```bash
composer install
composer test
```

Tests run against Laravel via `orchestra/testbench`; no external services are
contacted (gateway HTTP calls are faked).

Thanks again for helping keep this package small and sharp.
