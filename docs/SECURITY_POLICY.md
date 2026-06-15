# Security Policy

## Secret Management
- Never commit `.env` or `config/hr_config.json`.
- `ADMIN_PASSWORD` must be a secure hash when stored in file-based config.

## File Retention
- Uploaded payroll files should be retained only as long as needed for payroll verification.
- Recommended default retention: 90 days.
- Purge orphaned and unused files monthly.

## Dependency Hygiene
- Run `composer audit` weekly.
- Patch critical vulnerabilities within 48 hours.

## Access Control
- Admin session timeout: 30 minutes.
- CSRF required on all admin POST actions.
- Rate limiting enabled on admin login.
