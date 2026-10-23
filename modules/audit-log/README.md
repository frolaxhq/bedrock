# Audit history

Backend-only Bedrock module. Package with `module:pack`, install the ZIP using
`module:install audit-log --zip=/path/to/audit-log-1.0.0.zip`, and enable it explicitly.
For development against `dev-main`, configure `bedrock.core.version` to `1.0.0`.

Capture starts on the next application boot. Successful Bedrock lifecycle, setting-key,
and Bedrock access-adapter events are appended after commit. Direct Spatie API calls are
not observed. No application model observers, raw request
payloads, or secret values are captured. Records survive disable/uninstall; no retention
job runs automatically. Storage failures are reported to Laravel's logger.

Resolve `Modules\AuditLog\AuditRecorder` while the module is available to record explicit
application events. Custom metadata keys require an allowlist in `audit-log.metadata_keys`.
Actor and subject identifiers can be numeric or UUID strings. Ordinary model updates and
deletes are rejected, but database administrators still control the underlying table.

With `frolaxhq/bedrock-admin` installed and enabled, administrators explicitly granted
`audit-log.view` can open `/bedrock/admin/audit-log`. The module also runs on core alone.
