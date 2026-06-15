# HRM Portal

HRM Portal is a lightweight PHP application for employee payroll lookup and admin configuration.

## Project Structure

```text
.
├── index.php / admin.php / scripts/diagnostics.php
├── src
│   ├── Application   (AuthActions, DataActions, AdminActions)
│   ├── Infrastructure (bootstrap.php, Autoloader)
│   ├── Domain
│   ├── Services
│   └── Config.php, Security.php, …
├── tests
├── docs
├── runtime/cache
├── uploads
└── .github/workflows
```

## Requirements

- PHP 7.4+ (recommended PHP 8+)
- PHP `zip` extension for writing XLSX files
- Write permission for `uploads/`

## Setup

1. Copy `config/hr_config.json.example` to `config/hr_config.json` (first-time bootstrap only).
2. Copy `.env.example` to `.env` and set sensitive values (especially `ADMIN_PASSWORD`).
3. Optional: run `composer install` so `vendor/autoload.php` loads `App\` and `Shuchkin\`. If you skip Composer, entry points still work via `src/Infrastructure/bootstrap.php` (bundled PSR-4 loader).
4. Start server:
   - PHP built-in: `php -S localhost:8000`
   - Or deploy with Apache/Nginx.
5. Open:
   - User portal: `index.php`
   - Admin panel: `admin.php`

## Optional Docker Run

```bash
docker compose up --build
```

Then open `http://localhost:8080`.

## Security Notes

- `config/hr_config.json`, `.env`, and uploaded files are ignored by Git via `.gitignore`.
- `ADMIN_PASSWORD` in `.env` has highest priority over `config/hr_config.json`.
- `APP_FILE_ENCRYPTION_KEY` in `.env` enables encrypted-at-rest storage for shared payroll result files under `runtime/share/`.
- The same `APP_FILE_ENCRYPTION_KEY` also protects local spreadsheet files stored under `uploads/`; the app decrypts them transparently for admin preview, download, and payroll lookup.
- If a plaintext admin password is still stored in `config/hr_config.json`, the first successful admin login will auto-migrate it to a secure hash.
- `uploads/.htaccess` blocks PHP execution and directory listing for Apache deployments.
- Upload logic validates extension, size, and path format.
- Local spreadsheet uploads support CSV/XLSX. Legacy XLS is rejected.
- See `docs/SECURE_DEPLOYMENT_CHECKLIST.md` before deploying.

## Configuration Priority

Configuration is loaded in this order:

1. Default values in `src/Config.php`
2. `config/hr_config.json`
3. Environment variables from `.env` and process environment

## Smoke Test

Run:

```bash
php scripts/smoke_test.php
```

The script checks:

- config loading
- environment override visibility
- password hash flow
- upload directory permissions
- csrf/session bootstrap
- runtime upload limit sanity checks

## Encrypted Storage Rollout

1. Generate a key:

```bash
php scripts/generate_file_encryption_key.php
```

2. Add the output line to `.env`.

3. Dry-run migration:

```bash
php scripts/migrate_encrypted_storage.php --dry-run
```

4. Run migration:

```bash
php scripts/migrate_encrypted_storage.php
```

5. Verify in admin diagnostics that `file_encryption.enabled` is `true`.

## Local Runbook

1. Create `.env` from `.env.example`.
2. Ensure `uploads/` is writable and contains `.htaccess` on Apache.
3. Run `php scripts/smoke_test.php`.
4. Open `admin.php`, login, and verify:
   - config save works
   - upload accepts valid CSV/XLSX
   - oversize sheet returns friendly validation error
5. Open `index.php` and verify employee login and period data lookup.

## Operational Targets (SLO Baseline)

- Admin login success: >= 99.5%
- Data lookup success: >= 99.0%
- p95 data lookup response: < 3 seconds
- 5xx error rate: < 0.5%

## CI/CD and Quality Gates

- CI workflow: `.github/workflows/ci.yml`
- Pipeline includes lint, smoke test, PHPUnit, cache maintenance script, and dependency audit.
- Recommended promotion flow: dev -> staging -> production with smoke test at each stage.

## Diagnostics and Operations

- Admin diagnostics page: `scripts/diagnostics.php` (admin only).
- Operational runbook: `docs/OPERATIONS.md`
- Security policy and retention guidance: `docs/SECURITY_POLICY.md`
- Contributor / extension guide: `docs/DEVELOPMENT.md`
- Cache housekeeping (optional cron): `php scripts/maintenance.php` or `composer maintenance`
