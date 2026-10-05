# Tests

PHPUnit 11 suites live under `tests/`, mirroring `src/`:

- `tests/Unit` — fast, isolated tests of domain and application code (no I/O).

Run locally with PHP 8.4+:

```bash
composer install
composer verify        # phpunit + phpstan (level max)
```

Every change must include tests for the happy path, failure paths and security-negative cases (for example cross-tenant access).
