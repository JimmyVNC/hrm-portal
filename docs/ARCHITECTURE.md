# Architecture Notes

## Current Direction
- Entry points: `index.php`, `admin.php`, `scripts/diagnostics.php`.
- Layered folders under `src/`:
  - `src/Application` — `App\Application\{AuthActions,DataActions,AdminActions}` (HTTP-facing orchestration).
  - `src/Infrastructure` — `bootstrap.php` + `Autoloader.php` (Composer preferred; fallback PSR-4 for `App\` and `Shuchkin\`).
  - `src/Services` — spreadsheet IO, cache, config schema.
  - `src/Domain` — reusable domain rules (expand as needed).

## Layering (as implemented)
- Interface: HTTP entry points.
- Application: action orchestration (`AuthActions`, `DataActions`, `AdminActions`).
- Services / shared: `Config`, `Security`, `SpreadsheetReader`, etc. under `App\` in `src/`.

## Next hardening
- Prefer Composer `vendor/autoload.php` in environments where Composer is available; keep `Autoloader.php` for minimal shared-hosting deploys.
- Expand `Domain` with pure rules and thin Application callers.
- Use `App\AppMetadata` for version strings; use `scripts/maintenance.php` for `runtime/cache` retention.
- Application logs: `Security::appLog` + `App\Services\LogFormatter` for consistent JSON-safe context.
