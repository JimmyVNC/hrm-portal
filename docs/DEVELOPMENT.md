# HRM Portal — Developer Guide

This document is for engineers extending the portal (new data sources, UI, or integrations).

## Repository layout

| Area | Path | Role |
|------|------|------|
| HTTP entry | `index.php`, `admin.php` | Sessions, CSRF, dispatch to Application layer |
| Application | `src/Application/*.php` | `AuthActions`, `DataActions`, `AdminActions` — orchestration |
| Services | `src/Services/*.php` | Spreadsheet IO, cache, config schema, log formatting |
| Shared config/security | `src/Config.php`, `src/Security.php` | Env + JSON config, headers, structured logs |
| Bootstrap | `src/Infrastructure/bootstrap.php` | Composer autoload if `vendor/` exists, else bundled PSR-4 loader |

Release identity for logs and diagnostics lives in `App\AppMetadata` (`VERSION`, `NAME`).

## Running checks locally

```bash
composer install
composer run check    # smoke + PHPUnit
composer run smoke-test
composer run test
php scripts/maintenance.php
php scripts/benchmark_local_read.php --file=uploads/example.xlsx --sheet=0
```

## Tests

- PHPUnit config: `phpunit.xml`, bootstrap: `tests/bootstrap.php`.
- Prefer adding tests when changing `SpreadsheetReader`, cache behavior, path validation, or auth/data edge cases.
- Isolated cache tests set `RUNTIME_CACHE_DIR` to a temp directory (see `tests/CacheStoreTest.php`).

## Configuration and limits

- Operational tuning: `.env` (see `.env.example`): `AUTH_*`, `PERIOD_*`, `GOOGLE_CACHE_TTL`, `LOG_CONTEXT_MAX_STRING`, `RUNTIME_CACHE_*`.
- Local spreadsheet limits/validation: `LOCAL_FILE_MAX_BYTES`, `LOCAL_META_CACHE_TTL`, `SCHEMA_VALIDATE_MAX_ROWS`, `PERIOD_NUMERIC_COLS`, `PERIOD_DATE_COLS`.
- JSON schema validation warns on load; strict rules live in `src/Services/ConfigSchema.php`.

## Spreadsheet pipeline

- `SpreadsheetReader` enforces row/column caps for CSV (streaming) and Excel (dimension + capped `rows()`), used by auth and payroll flows.
- `SpreadsheetReader` also caches local sheet metadata (`sheet names`, `rows`, `cols`) and supports resolving by `sheet_name` to avoid breakage when sheet order changes.
- Upload path validates the same limits in `AdminActions::validateUploadedSpreadsheetLimits`.
- `SpreadsheetSchemaValidator` validates period datasets before save, with row/column-level type errors.

## Logging

- `Security::appLog` / `Security::auditLog` emit JSON-like lines prefixed with `[HRM]`.
- Context is passed through `LogFormatter::sanitizeContext` (depth and string length caps) to keep syslog/Splunk-safe payloads.

## Operations hooks

- `scripts/maintenance.php` prunes `runtime/cache` using `RUNTIME_CACHE_MAX_FILE_AGE_SECONDS` and `RUNTIME_CACHE_MAX_TOTAL_BYTES`.
- Schedule weekly on the app host if Google CSV caching grows large.

## Adding features safely

1. Put new business rules in small, testable methods (Application or Services).
2. Never bypass upload path checks; keep files under `uploads/` with `uploads/filename` references only.
3. Preserve CSRF on all state-changing `POST`s.
4. Bump `App\AppMetadata::VERSION` when shipping user-visible or ops-visible changes.
