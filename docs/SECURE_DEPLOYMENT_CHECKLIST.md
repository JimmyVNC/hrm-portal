# Secure Deployment Checklist

## Before Deploy

- Rotate any secrets that were ever committed, zipped, or shared outside the server.
- Create production `.env` from `.env.example`.
- Keep `config/hr_config.json`, `.env`, `uploads/`, `runtime/`, and logs out of Git and Docker images.
- Confirm Apache denies direct access to `config/`, `runtime/`, and spreadsheet files under `uploads/`.
- If using Nginx, add equivalent deny rules because `.htaccess` is not read by Nginx.

## Required Environment

- `ADMIN_PASSWORD`
- `MITACO_SQLSERVER_HOST`
- `MITACO_SQLSERVER_DATABASE`
- `MITACO_SQLSERVER_UID`
- `MITACO_SQLSERVER_PASSWORD`
- `MITACO_CORS_ORIGIN` when direct browser access is required

## Verify

```bash
composer install
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/smoke_test.php
composer test
```

## Manual Smoke Test

- Admin login and logout.
- Upload auth XLSX where header is not necessarily the first row.
- Edit auth data in web editor, save, then login with the edited employee password.
- Login with employee codes with and without leading zeroes.
- Lookup payroll from local XLSX/CSV and Google periods.
- Lookup attendance by employee code only.
