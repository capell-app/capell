# Security weakness scans

Capell scans the installation daily at 04:10 in the scheduler's timezone. Run Laravel's scheduler and use a shared scheduler lock store on multiple nodes. Each scan records a ULID, source, start and completion; the command and scan inventory expose the ID. Scans record findings and refresh their status; a finding is resolved once a successful scan no longer detects it. Dismissed findings remain dismissed while their fingerprint stays the same. A failed check is reported and does not cause findings to be resolved.

| Weakness ID | Severity | Automatic fix available |
| --- | --- | --- |
| `app.debug_enabled` | Critical in production, high otherwise | Turn off debug mode |
| `app.url_not_https` | High in production | No |
| `app.key_missing` | Critical | No |
| `session.insecure_cookie` | High | Enable secure and HTTP-only cookies when the URL uses HTTPS |
| `installer.still_enabled` | High | No |
| `extension.security_advisory` | Advisory severity | No |
| `extension.auto_updates_off_for_advisory` | Medium | Allow security releases automatically |

Automatic changes require an operator's active approval for one scanned finding, its evidence and the registered remediation class. An unchanged finding can be fixed on a later scan; changed evidence supersedes the approval and requires review and re-approval. Approvals created before scan identity and evidence binding remain recorded but cannot authorise a fix: scan and approve again. When several open findings share an ID, the approval action requires the finding fingerprint; only one approval per weakness ID can be active at a time. Approve again if the fix class changes. A fix requires a successfully completed scan that re-detected the finding; failed or incomplete scans cannot authorise changes. Revoke it to stop subsequent changes; revocation does not undo prior changes. Each attempted change is recorded, including its outcome and before/after values. Environment changes use a shared cache lock, a file lock, a backup and an atomic replacement. A failed configuration cache refresh is reported as a failed fix (exit 2) and retried on the next run. Symbolic links are refused, and CRLF files, duplicate target keys and target keys inside multiline quoted values need manual review. Target definitions using export, quotes or indentation are refused, as are spaces before the equals sign. Allowing security releases may permit major versions: review the update policy and the package's update infrastructure first.

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
- Once every expected node has acknowledged, the run becomes `applied` and the finding `remediated`. A missing node remains expected across retries until it acknowledges or an operator explicitly retires it with `php artisan capell:security:refresh-node --retire=<node-id>`. Retirement is logged and persists in the shared registry. When `CAPELL_SECURITY_NODE_REFRESH_GRACE_MINUTES` pass with nodes still waiting, the run fails with a message naming those nodes and the finding stays open: scans do not resolve it, and the next approved run sends the change to the current fleet and unresolved nodes again.

A single-node installation behaves exactly as described above and writes nothing to the node registry. Node identity comes from `CAPELL_NODE_ID`, falling back to the host name; give each node a stable, unique value. If two live hosts register the same ID, the registry records a collision and Doctor names it; fleet settlement stops until an operator resolves the identity conflict and repairs the registry. Registry entries and the creation sentinel must be retained in the shared cache; missing or malformed membership holds pending runs open.

## Companion packages

A package can tag implementations of `SecurityWeaknessCheck` and `SecurityWeaknessRemediation` with their respective `TAG` constants. Registrations default to the implementation class as owner; direct registrations may supply an owning provider or package class. The same owner may replace its registration, while a different owner is rejected. Checks use the supplied inventory, return stable fingerprints and their own registered weakness ID, and must not mutate the installation. Include the decision context in evidence without secrets; incomplete or truncated evidence cannot be approved. Remediations must be idempotent and record enough information to audit and reverse changes. A package may tag a `SecurityAdvisorySource` to supply advisories already matched to installed packages, or bind `SecurityWeaknessAnalyser` to provide additional advice. Core ships no advisory source and a null analyser. Do not put secrets in inventory, finding evidence or remediation outcomes.

Analyser findings are advice only and are never automatically remediated.
