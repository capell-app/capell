# Security weakness scans

Capell scans the installation daily at 04:10 in the scheduler's timezone. Run Laravel's scheduler and use a shared scheduler lock store on multiple nodes. Scans record findings and refresh their status; a finding is resolved once a successful scan no longer detects it. Dismissed findings remain dismissed while their fingerprint stays the same. A failed check is reported and does not cause findings to be resolved.

| Weakness ID | Severity | Automatic fix available |
| --- | --- | --- |
| `app.debug_enabled` | Critical in production, high otherwise | Turn off debug mode |
| `app.url_not_https` | High in production | No |
| `app.key_missing` | Critical | No |
| `session.insecure_cookie` | High | Enable secure and HTTP-only cookies when the URL uses HTTPS |
| `installer.still_enabled` | High | No |
| `extension.security_advisory` | Advisory severity | No |
| `extension.auto_updates_off_for_advisory` | Medium | Allow security releases automatically |

Automatic changes require an operator's active approval for the specific weakness ID. Approval is standing consent for future findings of that kind and binds to the registered remediation class; approve it again if that class changes. Only findings confirmed by the current scan are eligible, and a failed check prevents fixes for its weakness. Revoke it to stop subsequent changes; revocation does not undo prior changes. Each attempted change is recorded, including its outcome and before/after values. Environment changes use a shared cache lock, a backup and an atomic replacement; check that the configuration cache was refreshed. Symbolic links are refused, and CRLF files, duplicate target keys and target keys inside multiline quoted values need manual review. Allowing security releases may permit major versions: review the update policy and the package's update infrastructure first.

```bash
php artisan capell:security:scan
php artisan capell:security:scan --fix-approved --dry-run
php artisan capell:security:scan --fix-approved
php artisan capell:security:scan --fix-approved --only=app.debug_enabled
php artisan capell:security:scan --no-analyser --json
```

The command exits 0 on success, 1 when a critical finding remains open, and 2 when the scan is incomplete or a requested fix fails. `--dry-run` still records scan findings; it simulates remediation only. Manual runs record `system:console` and scheduled runs record `system:scheduler`. A remediated finding detected again reopens. The schedule applies approved fixes by default. `CAPELL_SECURITY_SCAN_SCHEDULED`, `CAPELL_SECURITY_SCAN_AT`, `CAPELL_SECURITY_FIX_APPROVED` and `CAPELL_SECURITY_ANALYSER_ENABLED` control the schedule and analyser.

## Companion packages

A package can tag implementations of `SecurityWeaknessCheck` and `SecurityWeaknessRemediation` with their respective `TAG` constants. Checks use the supplied inventory, return stable fingerprints, and must not mutate the installation. Remediations must be idempotent and record enough information to audit and reverse changes. A package may tag a `SecurityAdvisorySource` to supply advisories already matched to installed packages, or bind `SecurityWeaknessAnalyser` to provide additional advice. Core ships no advisory source and a null analyser. Do not put secrets in inventory, finding evidence or remediation outcomes.

Analyser findings are advice only and are never automatically remediated.
