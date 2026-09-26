# Operational reporting

Core supplies a vendor-neutral contract for reporting operational failures. A
`SignalData` holds a signal name, failure category, severity, diagnostic message,
operator summary, correlation identifier, optional run identifier and redacted
context. `DispatchSignalAction` applies configuration and cooldown before sending
the signal through a `Reporter`. The built-in reporter writes structured JSON to
a Laravel log channel.

This contract does not automatically report existing failures. App, deployment
and companion-package adoption are separate changes. The opt-in `operator`
transport adds durable incident state, logs, aggregate health and operator email
with owner/backup routing. The consuming application still owns signal producers,
operator identities, scheduler wiring, command receipts and production settings.

## Create and dispatch a signal

```php
use Capell\Core\Actions\Reporting\DispatchSignalAction;
use Capell\Core\Data\Reporting\SignalData;
use Capell\Core\Enums\Reporting\FailureCategory;
use Capell\Core\Enums\Reporting\Severity;

$signal = new SignalData(
    name: 'import.source_unavailable',
    category: FailureCategory::Dependency,
    severity: Severity::Error,
    message: 'The source did not respond.',
    operatorSummary: 'Check source availability before retrying this import.',
    correlationId: 'trace-7f3a',
    runId: 'run-19b2',
    context: ['attempt' => 2, 'retryable' => true],
);

$result = app(DispatchSignalAction::class)->handle($signal);
$human = $signal->toHuman();
$json = $signal->toJson();
```

Names use lower-case letters, digits and `.`, `_` or `-` separators, starting with
a letter, up to 128 characters. Use a stable operational name, not an exception
message. Correlation/run identifiers are opaque identifiers of 1–128 characters
using letters, digits, `.`, `_`, `:` and `-`; the first character is alphanumeric.
Reuse the correlation identifier across related work. Never use email addresses,
customer identifiers or credentials as identifiers. Invalid identities are
rejected when constructing the data object; delivery failures do not throw from
`handle()`.

Failure categories have stable wire values:
`configuration`, `validation`, `authentication`, `authorization`, `dependency`,
`timeout`, `rate_limit`, `persistence`, `capacity` and `runtime`. Severity uses the
PSR log levels `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`
and `emergency`. Unknown categories/severities cannot enter the typed contract.
Choose a category by failure cause; use the signal name to distinguish operations.

Human output is one line containing severity, signal/category, correlation/run,
message and operator summary. `toArray()`, `toJson()` and `json_encode($signal)`
share the same safe fields. The default reporter logs that JSON string at the
signal's severity; the host's log formatter may add its normal envelope.

## Configuration and precedence

Defaults load from `packages/core/config/capell-reporting.php`. A host may publish
and override the file as `config/capell-reporting.php` through Core's config
publication. Configuration is read on each dispatch; it contains service names,
not service instances or closures, and is suitable for Laravel's config cache.

| Setting                                            | Default   | Meaning                                                                                 |
| -------------------------------------------------- | --------- | --------------------------------------------------------------------------------------- |
| `capell-reporting.enabled`                         | `true`    | Global off switch; `CAPELL_REPORTING_ENABLED` may set it.                               |
| `capell-reporting.defaults.enabled`                | `true`    | Default policy for signals.                                                             |
| `capell-reporting.defaults.transport`              | `log`     | Transport name.                                                                         |
| `capell-reporting.defaults.cooldown_seconds`       | `300`     | Integer from 0 to 86400; zero disables suppression.                                     |
| `capell-reporting.categories`                      | `[]`      | Policies keyed by a failure category's wire value.                                      |
| `capell-reporting.signals`                         | `[]`      | Policies keyed by the complete signal name, including dots.                             |
| `capell-reporting.cache_store`                     | `null`    | Laravel's default store, or `CAPELL_REPORTING_CACHE_STORE`.                             |
| `capell-reporting.log_channel`                     | `null`    | Laravel's default channel, or `CAPELL_REPORTING_LOG_CHANNEL`.                           |
| `capell-reporting.reporters`                       | `[]`      | Opt-in transport names mapped to container bindings or classes implementing `Reporter`. |
| `capell-reporting.defaults.channels`               | `['log']` | Channels selected by the `operator` transport: `log`, `health`, `email`.                |
| `capell-reporting.defaults.owner`                  | `null`    | Primary operator alias.                                                                 |
| `capell-reporting.defaults.backup`                 | `null`    | Backup operator alias, or no backup escalation.                                         |
| `capell-reporting.defaults.escalate_after_seconds` | `900`     | Unacknowledged incident age before backup escalation; integer 1–86400.                  |
| `capell-reporting.operators`                       | `[]`      | Private alias-to-email mapping; addresses never enter signal payloads or receipts.      |
| `capell-reporting.email.enabled`                   | `false`   | Independent email opt-in switch.                                                        |
| `capell-reporting.email.mailer`                    | `null`    | Laravel mailer name; null selects the host default.                                     |
| `capell-reporting.email.max_attempts`              | `5`       | Global email attempt quota, integer 1–1000.                                             |
| `capell-reporting.email.window_seconds`            | `3600`    | Shared quota window, integer 1–86400.                                                   |
| `capell-reporting.health.enabled`                  | `false`   | Enable the aggregate health endpoint.                                                   |

For each field, precedence is **built-in defaults → configured defaults → category
policy → exact signal policy**. Category and signal policies support `enabled`,
`transport`, `cooldown_seconds`, `channels`, `owner`, `backup` and
`escalate_after_seconds`; absent fields inherit. Channel lists replace inherited
lists. Routing fields apply only to the `operator` transport. The top-level
`enabled=false` always disables reporting, including when other configuration is
invalid. An exact signal policy may re-enable a category-disabled signal only
while the global switch is on. Boolean policy values must be actual booleans.
An exact `enabled=false` is honoured before validating inherited policy fields.
A category disable likewise ignores malformed defaults when the exact policy
does not override `enabled`; unrelated configuration cannot turn either disable
into fallback logging.

For example:

```php
'categories' => [
    'dependency' => ['cooldown_seconds' => 60],
],
'signals' => [
    'import.source_unavailable' => ['cooldown_seconds' => 10],
    'import.optional_source_missing' => ['enabled' => false],
],
```

Unknown signal names inherit the defaults and category policy. An unknown or
missing transport, malformed applicable configuration, cache failure, or thrown
transport exception falls back to logging. Explicitly disabled signals neither
log nor claim cooldown. Unrelated category/signal entries are not selected.

## Transports and results

An adapter implements `Capell\Core\Contracts\Reporting\Reporter::report()` and
receives only an immutable, already-redacted `SignalData`. Register its dependencies
in the owning package's appropriate provider bucket, then explicitly map a name in
`reporters` to its class or container binding and select that name in a policy.
Core includes no notification vendor dependency. The `log` and `operator` names
are reserved. The built-in operator channel uses the host Laravel mail service.

Adapters return normally after accepting delivery and throw on failure. The
dispatcher catches all transport exceptions without logging their messages or
traces, since those can contain credentials. Delivery is synchronous; an adapter
must bound its own network timeouts. A timeout category does not impose a process
or network deadline.

| `DispatchStatus` | Meaning                                                                                         |
| ---------------- | ----------------------------------------------------------------------------------------------- |
| `Reported`       | All selected deliveries were accepted.                                                          |
| `Suppressed`     | No delivery: duplicate/in-flight claim, cooldown, acknowledged/resolved incident, or recursion. |
| `Disabled`       | Configuration disabled this signal.                                                             |
| `Fallback`       | Logging accepted the signal after a configuration, cache or transport failure.                  |
| `Partial`        | Operator routing accepted some selected channels but others and fallback logging failed.        |
| `Failed`         | No selected channel or fallback logging accepted delivery.                                      |

`DispatchResultData` contains the status, transport and a safe reason code when
fallback occurs: `configuration_unavailable`, `deduplication_unavailable`, `transport_unavailable` or `routing_unavailable`.
The last code covers unavailable incident persistence or a lost delivery claim.
Single-reporter fallback returns `log_unavailable` when logging also fails. Operator
channel failures retain `transport_unavailable` and their per-channel receipts.
The configured log channel is tried first where available, then the default
channel. No exception detail is included in the result. The host remains
responsible for its log handlers, shared logging context, filtering and retention.

Logger construction failures, including configured drivers, taps and stack
members, follow the same fallback path. Reporting prevents Laravel's emergency
logger from writing their raw exceptions. Custom driver callbacks resolve nested
channels through the same protected manager, including through the container
passed to drivers, factories and taps. These bindings belong to a private clone;
the host container and its ordinary logger remain unchanged during delivery.
Previously resolved factories, taps and their dependencies, including aliases and
stack members, are rebuilt inside that clone so they receive its protected
manager. Register callbacks and their dependencies with reconstructable container
bindings; an instance-only dependency that cannot be rebuilt is treated as
unavailable and follows safe fallback. Channel configuration is resolved only
when used: malformed taps disable that channel while a healthy fallback remains
available, and unrelated channels cannot prevent delivery.
The private container is guarded during both channel construction and delivery,
so a driver or handler cannot use it to start another dispatch recursively.

Dispatch attempted synchronously from a reporter or log listener returns
`Suppressed` with reason `recursive_dispatch`, including during configuration
fallback and with zero cooldown. The guard spans dispatcher instances within the
same application and execution fibre, and is released after every delivery
attempt. Independent application containers and fibres remain isolated.

The immediate dispatch result is not a durable command receipt. Operator routing
also persists incident delivery outcomes, described below. In command adapters,
persist the command's receipt and preserve its original non-zero exit status
independently of reporting. A successful report does not make a failed
operation successful.

## Cooldown and failure recovery

For the legacy single-reporter path, the fingerprint hashes the signal name, category, severity, correlation ID and
run ID. Diagnostic text and context do not affect it, so changing attempt counts
cannot evade suppression. Different runs, correlations and severities remain
independent; increasing severity can therefore produce a new report immediately.
Cooldown begins when delivery is claimed and expires at its configured boundary.

The dispatcher uses an atomic, owner-aware cache lock with a TTL. Successful
delivery, including fallback delivery, leaves the claim until expiry. If every
delivery fails, it releases only its own claim, so an older attempt cannot remove
a replacement acquired after its TTL expired. If release fails, the claim expires
naturally. This is duplicate suppression, not exactly-once delivery: a transport
may accept a message and then throw, and a long delivery can outlive its cooldown.

For suppression across workers, use a shared cache store that supports Laravel's
atomic locks. The array store only coordinates within one process. Its expired
reporting locks are pruned before the next claim because ordinary cache flushes
do not remove lock entries. Reporting retains at most 1000 array-store claims;
at capacity, new fingerprints fall back to logging with
`deduplication_unavailable`, while existing cooldowns and other features' locks
remain intact. Cache failure
or an unsupported store falls back to logging without reliable suppression;
missing/malformed reporting configuration also logs without a cooldown claim.
This prioritises diagnostic delivery during failure. Cooldown is a per-fingerprint
rate limit, not a global transport quota or durable incident store.

## Redaction and retention

Redaction runs during signal construction, before any reporter or formatter can
read the payload. It covers nested secret/PII keys, quoted credential assignments
(including escaped quotes and unterminated values), complete Cookie/Set-Cookie
and Authorization/Proxy-Authorization headers including folded continuation
lines, session credentials,
URLs, bearer/basic credentials, common token patterns, email addresses, IP
addresses, telephone patterns and filesystem paths. Objects/resources and
non-finite numbers are replaced without invoking conversion methods. Context is
bounded to 100 entries across the tree and seven array levels; deeper data is
replaced. Text input is capped at 8192 bytes before processing and output at 2048
characters per value; malformed UTF-8 is normalised.

Unicode escapes, nested JSON strings and URL/form encoding (including `+` spacing) are inspected through at
most eight decoding steps. When decoding reveals sensitive data, the encoded field
is withheld in full, as is data that exceeds the decoding bound. Encoded context keys receive
the same sensitive-key checks as plain keys. Harmless encoded text retains its
original representation.
For a sensitive object or array embedded in text, redaction withholds the
remainder of that text. This also protects incomplete JSON whose closing boundary
cannot be established safely.

Use concise operational messages and explicit context fields. Pattern redaction
cannot identify every person's name or an unlabelled secret in arbitrary prose;
never pass raw request bodies, environment dumps or exception messages/traces.
Sensitive fields should be named explicitly, such as `password`, `email` or
`name`, so the whole value is removed. Do not encode sensitive data in signal
names or identifiers. Redaction cannot be disabled through configuration.

Plain logging creates no incident records. Operator routing uses
`capell_reporting_incidents`; install the registered Core migration before enabling
it. Log retention belongs to the host log channel. Incident records contain only
the redacted signal, opaque operator aliases, timestamps and delivery status codes.
Email addresses remain private configuration. The email quota cache stores only an
attempt counter and window expiry; it contains no signal or recipient payload.
The host schedules bounded retention of resolved incidents as described below.

## Operator routing and incident lifecycle

Select the built-in transport and each channel explicitly. This example is host
configuration; replace the sample addresses with the approved operator and backup:

```php
'defaults' => [
    'enabled' => true,
    'transport' => 'operator',
    'channels' => ['log', 'health', 'email'],
    'owner' => 'primary',
    'backup' => 'backup',
    'cooldown_seconds' => 300,
    'escalate_after_seconds' => 900,
],
'operators' => [
    'primary' => 'operator@example.test',
    'backup' => 'backup@example.test',
],
'email' => [
    'enabled' => true,
    'mailer' => 'operations',
    'max_attempts' => 5,
    'window_seconds' => 3600,
],
'health' => ['enabled' => true],
```

Only `log`, `health` and `email` are accepted in `channels`. Unknown names or an
empty list fall back safely to logs before creating an incident or sending email.
Unknown signals inherit category/default policy, just as the single-reporter path
does. Use `defaults.enabled=false` with exact signal opt-ins to allowlist producers.
The global switch and exact disabled policies prevent incident creation and delivery.

The first accepted signal creates an `open` incident keyed by the existing
fingerprint. SQL transactions reserve delivery before any outbound work. Concurrent
attempts share a 60-second claim; a late response cannot overwrite a newer claim's
receipt. The host must configure mail connection/read timeouts below this claim
window. A process crash leaves the incident and claim available for diagnosis;
pending channels become retryable after both the claim and cooldown expire.

Accepted channels are delivered once per incident stage, even if the cache resets
or the cooldown expires. Failed channels retry after `cooldown_seconds`, without
repeating accepted channels. Zero removes the retry cooldown; the in-flight claim
and email quota still apply. A changed run, correlation or severity creates a new
incident. A resolved fingerprint remains suppressed until retention removes it;
producers must use a new run/correlation for a new occurrence.

Unacknowledged incidents with email selected and a configured backup become
`escalated` when their age reaches the effective `escalate_after_seconds` value.
The transition has its own delivery stage and is allowed during the initial owner
cooldown. Email goes to the backup for this stage. No backup alias means no automatic
backup stage; a missing/invalid address records an unavailable email and leaves
logs and health usable. Configuration is read for each attempt, including scheduled
processing. Already accepted receipts are not resent to changed recipients.

Use the public Actions from authenticated operational code:

```php
use Capell\Core\Actions\Reporting\GetReportingIncidentAction;
use Capell\Core\Actions\Reporting\ProcessReportingIncidentsAction;
use Capell\Core\Actions\Reporting\PruneReportingIncidentsAction;
use Capell\Core\Actions\Reporting\UpdateReportingIncidentAction;
use Capell\Core\Enums\Reporting\IncidentStatus;

$id = $signal->fingerprint();
$incident = GetReportingIncidentAction::run($id);
$acknowledged = UpdateReportingIncidentAction::run($id, IncidentStatus::Acknowledged, 'primary');
$resolved = UpdateReportingIncidentAction::run($id, IncidentStatus::Resolved, 'primary');

$counts = ProcessReportingIncidentsAction::run(limit: 100);
$removed = PruneReportingIncidentsAction::run(retentionDays: 30, limit: 1000);
```

Acknowledgement is restricted to the incident's assigned owner or backup alias and
records its time and alias. The host must authenticate the operator and derive the
alias; never accept it directly from an unauthorised request. Only open/escalated
incidents can be acknowledged. Acknowledgement stops future delivery and escalation
but does not resolve health. Resolution is explicit and cannot reopen the same
incident. An already-started email may still finish after acknowledgement; its
receipt cannot undo the acknowledgement.

`GetReportingIncidentAction` returns a typed snapshot or null for an unknown ID.
It is an internal operational interface; never expose its signal payload on an
anonymous endpoint. `ProcessReportingIncidentsAction` retries pending channels and
checks escalation using current policy, returning dispatch-status counts. Schedule
it at a suitable interval. Batches rotate inspected rows, including disabled or
invalid records, so a bounded batch can advance. Configure the batch size and
cadence for the incident volume; Core does not install a scheduler automatically.
`PruneReportingIncidentsAction` deletes only resolved records at or beyond the
retention boundary. Open, acknowledged and escalated incidents are retained.

## Email quota and failure behaviour

Email uses the host Laravel mail factory and a configured mailer; no vendor client
is included. Messages are plain text with the existing redacted human/JSON signal.
Only the selected operator's configured address is added. Host mailer callbacks,
redirects and transport credentials remain the host's responsibility.

All email attempts, including failures and cancelled message events, consume one
shared quota across signals, severities, owners and backup escalations. The window
starts with the first reserved attempt and resets at its exact expiry. Use a shared
cache store supporting atomic locks in multi-worker deployments. Array cache is
process-local, and clearing the quota store starts a new window. Invalid quota
configuration, a busy lock or unavailable cache prevents email; logs and durable
health state remain available. Explicitly disabled email does not consume quota.

Incident `deliveries` contains `delivered`, `unavailable`, `disabled` or
`rate_limited` per channel. Escalation uses `escalation_`-prefixed channel keys.
Fallback logging has its own `fallback_log` receipt (`escalation_fallback_log`
for the backup stage), even when `log` is not selected. Successful fallback logs
are not repeated during later email retries; failed fallback attempts remain
retryable. The email receipt stays pending independently until delivery succeeds.
`Fallback` can therefore mean that logs succeeded while email remains pending;
inspect the incident or aggregate health rather than assuming every channel sent.
`Partial` means some selected channels accepted delivery while another channel and
fallback logging failed. Transport exception messages and traces never enter
receipts, logs or health output. Missing tables/database access prevent outbound
email and return safe log fallback. Mail acceptance is not proof of inbox delivery.
A transport that accepts a message and then throws, or a crash before saving its
receipt, can cause a later retry to repeat it; SMTP does not provide exactly-once
delivery.

## Aggregate health endpoint

`GET /_capell/reporting/health` is named `capell.reporting.health`. It returns 404
unless reporting and `health.enabled` are both enabled. The response is explicitly
non-cacheable and contains only `status` plus aggregate unresolved, acknowledged,
escalated and delivery-failure counts. It includes no messages, identifiers,
context, addresses, routing policy, exception details or authoring data.

An empty/resolved health set returns 200 with `status=ok`; unresolved health-channel
incidents return 503 with `status=degraded`. Acknowledgement alone still returns 503. Unavailable incident storage returns 503 with only `status=unavailable`, never
a synthetic healthy result. The endpoint describes recorded incidents; it is not
an independent database, queue, mail or process liveness probe. Retain the existing
health checks for those dependencies.

## App adapter follow-up

1. Publish/run the Core migration and map the approved operator and backup aliases
   to private addresses. Configure the mailer with bounded timeouts, a shared atomic
   cache store, email quota, escalation delay and retention appropriate to operations.
   Opt into the three channels through host configuration.
2. Map existing operations-health, queue, install/deploy and command failures into
   stable `SignalData` names/categories and existing run/correlation identifiers.
   Do not pass raw exceptions or environment/request dumps. Preserve each command's
   original exit status and write its durable command receipt independently.
3. Schedule `ProcessReportingIncidentsAction` for retries/escalation and
   `PruneReportingIncidentsAction` for resolved retention. Surface command failures;
   do not turn database/scheduler failures into a successful receipt.
4. Add authenticated acknowledgement/resolution controls, deriving the operator
   alias from the authenticated identity and authorising the incident operation.
   Connect the aggregate endpoint to the existing operations-health consumers and
   bypass public page/edge caching for this route.
5. Verify the configured host mailer, scheduler, endpoint and command receipt/exit
   paths in the consuming environment. Core's tests establish local behaviour;
   they do not establish production email delivery or adapter adoption.

See [Site Health](site-health.md) for existing diagnostic checks and
[the extension surface catalogue](../packages/extension-surface-catalog.md) for
the contract's compatibility status. The category/severity values are stable;
the initial dispatch and adapter surfaces remain experimental during adoption.
