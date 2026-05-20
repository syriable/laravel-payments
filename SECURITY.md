# Security Policy

## Supported versions

During the `0.x` series, security fixes are applied to the latest released
minor version only. Once `1.0.0` is released, this policy will be updated to
cover the current major version.

## Reporting a vulnerability

If you discover a security vulnerability, please email
**security@syriable.com**. Do not open a public issue or pull request for
security problems.

Please include:

- a description of the vulnerability and its impact,
- steps to reproduce, and
- the package, PHP, and Laravel versions affected.

You will receive an acknowledgement of your report, and we will keep you
informed as the issue is investigated and resolved.

## Scope

This package handles payment gateway communication and webhook verification.
Reports of particular interest include:

- webhook signature verification bypasses,
- leakage of gateway secrets or credentials, and
- any path that could cause a payment or refund to be processed without
  proper verification.

Webhook handlers in this package verify request signatures before parsing —
Stripe via HMAC-SHA256, PayPal via PayPal's verification API. A failed
verification returns `403` and dispatches no events. If you can defeat that,
we want to hear from you.
