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

Automatic changes require an operator's active approval for the specific weakness ID. Approval is standing consent for future findings of that kind, not just the current finding. Revoke it to stop subsequent changes; revocation does not undo prior changes. Each attempted change is recorded, including its outcome and before/after values. Environment changes have a backup; check that the configuration cache was refreshed. Allowing security releases may permit major versions: review the update policy and the package's update infrastructure first.

```bash
php artisan capell:security:scan
php artisan capell:security:scan --fix-approved --dry-run
php artisan capell:security:scan --fix-approved
php artisan capell:security:scan --no-analyser --json
```

A critical finding still open makes the command exit with status 1. The schedule applies approved fixes by default. `CAPELL_SECURITY_SCAN_SCHEDULED`, `CAPELL_SECURITY_SCAN_AT`, `CAPELL_SECURITY_FIX_APPROVED` and `CAPELL_SECURITY_ANALYSER_ENABLED` control the schedule and analyser.

## Companion packages

A package can tag implementations of `SecurityWeaknessCheck` and `SecurityWeaknessRemediation` with their respective `TAG` constants. Checks use the supplied inventory, return stable fingerprints, and must not mutate the installation. Remediations must be idempotent and record enough information to audit and reverse changes. A package may tag a `SecurityAdvisorySource` to supply advisories already matched to installed packages, or bind `SecurityWeaknessAnalyser` to provide additional advice. Core ships no advisory source and a null analyser. Do not put secrets in inventory, finding evidence or remediation outcomes.

Analyser findings are advice; they can only be automatically fixed when they identify a registered remediation, are marked remediable, and an operator has approved that weakness. No arbitrary analyser-proposed change is executed.
