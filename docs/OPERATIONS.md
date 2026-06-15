# Operations Runbook

## Logging
- Application and audit events are emitted via `error_log` as JSON payloads with `request_id`, `app`, `app_version`, and sanitized `context`.
- Actions logged include admin login outcomes, config updates, and sensitive admin operations.
- Optional: schedule `php scripts/maintenance.php` (weekly) to prune `runtime/cache` per `RUNTIME_CACHE_*` env vars (see `.env.example`).

## Encrypted Storage
- Generate an application file key with `php scripts/generate_file_encryption_key.php`.
- Store the resulting `APP_FILE_ENCRYPTION_KEY=...` line in `.env`.
- Migrate existing plaintext local files with `php scripts/migrate_encrypted_storage.php --dry-run` and then `php scripts/migrate_encrypted_storage.php`.
- Confirm `scripts/diagnostics.php` reports `file_encryption.enabled = true`.

## Alerts (Suggested)
- Alert if admin login failure spikes above 20 attempts / 10 min.
- Alert if 5xx response ratio exceeds 1% for 5 minutes.
- Alert if upload directory is not writable.

## Rollback
1. Restore previous release package.
2. Restore `config/hr_config.json` backup.
3. Clear runtime cache directory `runtime/cache`.
4. Re-run smoke test.

## Structure Migration Notes
- Entry points load `src/Infrastructure/bootstrap.php` (Composer autoload when present, else bundled loader) and use `App\Application\*` for admin/auth/data flows.
- Rolling back: restore the previous deployment tree and `config/hr_config.json`; older packages that expected shim files under `src/` may need those files restored from Git history.

## Incident Response
1. Identify scope (auth, upload, data lookup).
2. Correlate events by `request_id` in logs.
3. Contain: disable admin access if compromise suspected.
4. Recover: restore known-good config and redeploy.
5. Postmortem: root cause and action items within 48h.
