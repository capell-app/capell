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

Automatic changes require an operator's active approval for the specific weakness ID. Approval is standing consent for future findings of that kind and binds to the registered remediation class; approve it again if that class changes. Findings whose check failed in this scan, or that this scan did not re-detect, are skipped. Revoke it to stop subsequent changes; revocation does not undo prior changes. Each attempted change is recorded, including its outcome and before/after values. Environment changes use a shared cache lock, a file lock, a backup and an atomic replacement. A failed configuration cache refresh is reported as a failed fix (exit 2) and retried on the next run. Symbolic links are refused, and CRLF files, duplicate target keys and target keys inside multiline quoted values need manual review. Target definitions using export, quotes or indentation are refused, as are spaces before the equals sign. Allowing security releases may permit major versions: review the update policy and the package's update infrastructure first.

```bash
php artisan capell:security:scan
php artisan capell:security:scan --fix-approved --dry-run
php artisan capell:security:scan --fix-approved
php artisan capell:security:scan --fix-approved --only=app.debug_enabled
php artisan capell:security:scan --no-analyser --json
```

The command exits 0 on success, 1 when a critical finding remains open, and 2 when the scan is incomplete or a requested fix fails. `--dry-run` still records scan findings; it simulates remediation only. `--only` filters remediation only; the scan and displayed findings are unaffected. Manual runs record `system:console` and scheduled runs record `system:scheduler`. A remediated finding detected again reopens. The schedule applies approved fixes by default. `CAPELL_SECURITY_SCAN_SCHEDULED`, `CAPELL_SECURITY_SCAN_AT`, `CAPELL_SECURITY_FIX_APPROVED` and `CAPELL_SECURITY_ANALYSER_ENABLED` control the schedule and analyser.

## Multi-node deployments

With `CAPELL_MULTI_NODE=true`, an environment change applied on one node does not reach the other nodes' configuration cache, or their `.env` when storage is not shared. Capell therefore waits for every node:

- Each node registers itself in the shared cache store as `capell:security:nodes:<node id>` whenever it applies a remediation and every five minutes through `php artisan capell:security:refresh-node`, which the scheduler runs on every node (not `onOneServer`). Run the scheduler on every node. Node-local cache stores (`array`, `file`, `null`) are refused before any setting changes.
- When an approved environment change succeeds on one node, the run snapshots the nodes seen within `CAPELL_SECURITY_NODE_TTL_MINUTES` as its expected nodes and records the outcome `applied_pending_nodes`. The finding stays open and is neither resolved by a scan nor changed again while it waits; Doctor's security posture check lists the nodes still to refresh.
- `capell:security:refresh-node` repeats the approved change against the node's own `.env` (idempotent: on shared storage it only finds the values already set), clears the configuration cache and then acknowledges. A node that cannot apply the change or clear its cache does not acknowledge. Running the command again is harmless.
- Once every expected node has acknowledged, the run becomes `applied` and the finding `remediated`. A node missing from the registry for longer than the TTL is dropped from the expected nodes as unreachable. When `CAPELL_SECURITY_NODE_REFRESH_GRACE_MINUTES` pass with nodes still waiting, or every live node acknowledged but some became unreachable, the run fails with a message naming those nodes and the finding stays open: scans do not resolve it, and the next approved run sends the change to the current fleet again.

A single-node installation behaves exactly as described above and writes nothing to the node registry. Node identity comes from `CAPELL_NODE_ID`, falling back to the host name; give each node a stable, unique value.

## Companion packages

A package can tag implementations of `SecurityWeaknessCheck` and `SecurityWeaknessRemediation` with their respective `TAG` constants. Checks use the supplied inventory, return stable fingerprints, and must not mutate the installation. Remediations must be idempotent and record enough information to audit and reverse changes. A package may tag a `SecurityAdvisorySource` to supply advisories already matched to installed packages, or bind `SecurityWeaknessAnalyser` to provide additional advice. Core ships no advisory source and a null analyser. Do not put secrets in inventory, finding evidence or remediation outcomes.

Analyser findings are advice only and are never automatically remediated.
