# Contribution Guide

Thank you for considering contributing to Laravel Mailbox! Please review these guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Add tests for any behaviour you add or change — the suite runs the real `local` transport end to end, never `Mail::fake()`.
- Keep the public API surface intact: everything outside the documented API is `@internal` and enforced by an architecture test.
- Send a coherent commit history; you may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- We follow [Semantic Versioning](https://semver.org/). The package is pre-1.0, so breaking changes land in minor releases (`0.x`) and are documented in [UPGRADE.md](../UPGRADE.md).

## Setup

The test suite needs **PHP 8.3+** (Pest 4/5), although the package itself supports PHP 8.2 at runtime.

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Running checks

```bash
composer lint        # Pint (fix)
composer lint:check  # Pint (verify only)
composer analyse     # Larastan, level 8
composer test:types  # 100% type coverage
composer test:unit   # Pest (parallel)
composer test        # everything above
```

The browser suite (sandbox containment, keyboard, responsive, accessibility) needs Node and Playwright:

```bash
cd tests/Browser
npm install
npx playwright install chromium webkit
npm test
```

## Trying your changes in a real app

Use the bundled workbench — it seeds demo mail (including a hostile message that exercises the sandbox):

```bash
composer serve
# then open /demo in the browser it announces
```

## Security vulnerabilities

Please review [our security policy](SECURITY.md) — do not report vulnerabilities through public issues.
