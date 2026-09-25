# Operational reporting

Core supplies a vendor-neutral contract for reporting operational failures. A
`SignalData` holds a signal name, failure category, severity, diagnostic message,
operator summary, correlation identifier, optional run identifier and redacted
context. `DispatchSignalAction` applies configuration and cooldown before sending
the signal through a `Reporter`. The built-in reporter writes structured JSON to
a Laravel log channel.

This contract does not automatically report existing failures. App, deployment
and companion-package adoption are separate changes. Notification transports,
owner/backup routing, paging thresholds, acknowledgement and escalation state,
durable command receipts and their retention remain the consuming application's
responsibility. Production notification settings require an owner-approved rollout.

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

| Setting                                      | Default | Meaning                                                                                 |
| -------------------------------------------- | ------- | --------------------------------------------------------------------------------------- |
| `capell-reporting.enabled`                   | `true`  | Global off switch; `CAPELL_REPORTING_ENABLED` may set it.                               |
| `capell-reporting.defaults.enabled`          | `true`  | Default policy for signals.                                                             |
| `capell-reporting.defaults.transport`        | `log`   | Transport name.                                                                         |
| `capell-reporting.defaults.cooldown_seconds` | `300`   | Integer from 0 to 86400; zero disables suppression.                                     |
| `capell-reporting.categories`                | `[]`    | Policies keyed by a failure category's wire value.                                      |
| `capell-reporting.signals`                   | `[]`    | Policies keyed by the complete signal name, including dots.                             |
| `capell-reporting.cache_store`               | `null`  | Laravel's default store, or `CAPELL_REPORTING_CACHE_STORE`.                             |
| `capell-reporting.log_channel`               | `null`  | Laravel's default channel, or `CAPELL_REPORTING_LOG_CHANNEL`.                           |
| `capell-reporting.reporters`                 | `[]`    | Opt-in transport names mapped to container bindings or classes implementing `Reporter`. |

For each field, precedence is **built-in defaults → configured defaults → category
policy → exact signal policy**. Category and signal policies support `enabled`,
`transport` and `cooldown_seconds`; absent fields inherit. The top-level
`enabled=false` always disables reporting, including when other configuration is
invalid. An exact signal policy may re-enable a category-disabled signal only
while the global switch is on. Boolean policy values must be actual booleans.

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
Core includes no notification vendor dependency. The `log` name is reserved.

Adapters return normally after accepting delivery and throw on failure. The
dispatcher catches all transport exceptions without logging their messages or
traces, since those can contain credentials. Delivery is synchronous; an adapter
must bound its own network timeouts. A timeout category does not impose a process
or network deadline.

| `DispatchStatus` | Meaning                                                                              |
| ---------------- | ------------------------------------------------------------------------------------ |
| `Reported`       | The selected reporter returned normally.                                             |
| `Suppressed`     | Another dispatch owns the cooldown claim, or delivery attempted recursive reporting. |
| `Disabled`       | Configuration disabled this signal.                                                  |
| `Fallback`       | Logging accepted the signal after a configuration, cache or transport failure.       |
| `Failed`         | Logging also failed; no delivery is confirmed.                                       |

`DispatchResultData` contains the status, transport and a safe reason code when
fallback occurs: `configuration_unavailable`, `deduplication_unavailable` or
`transport_unavailable`. If logging also fails, the reason is `log_unavailable`.
The configured log channel is tried first where available, then the default
channel. No exception detail is included in the result. The host remains
responsible for its log handlers, shared logging context, filtering and retention.

Logger construction failures, including configured drivers, taps and stack
members, follow the same fallback path. Reporting prevents Laravel's emergency
logger from writing their raw exceptions. Custom driver callbacks resolve nested
channels through the same protected manager, including through the container
passed to drivers, factories and taps. These bindings belong to a private clone;
the host container and its ordinary logger remain unchanged during delivery.
The private container is guarded during both channel construction and delivery,
so a driver or handler cannot use it to start another dispatch recursively.

Dispatch attempted synchronously from a reporter or log listener returns
`Suppressed` with reason `recursive_dispatch`, including during configuration
fallback and with zero cooldown. The guard spans dispatcher instances within the
same application and execution fibre, and is released after every delivery
attempt. Independent application containers and fibres remain isolated.

These results are not durable receipts or incident acknowledgement. In command
adapters, persist the command's receipt and preserve its original non-zero exit
status independently of reporting. A successful report does not make a failed
operation successful.

## Cooldown and failure recovery

The fingerprint hashes the signal name, category, severity, correlation ID and
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

Unicode escapes, nested JSON strings and URL encoding are inspected through at
most eight decoding steps. When decoding reveals sensitive data, the encoded field
is withheld in full, as is data that exceeds the decoding bound. Encoded context keys receive
the same sensitive-key checks as plain keys. Harmless encoded text retains its
original representation.

Use concise operational messages and explicit context fields. Pattern redaction
cannot identify every person's name or an unlabelled secret in arbitrary prose;
never pass raw request bodies, environment dumps or exception messages/traces.
Sensitive fields should be named explicitly, such as `password`, `email` or
`name`, so the whole value is removed. Do not encode sensitive data in signal
names or identifiers. Redaction cannot be disabled through configuration.

Core creates no reporting tables, files or retention scheduler. Log retention is
controlled by the host's selected log channel; cache claims retain only a hashed
fingerprint and lock ownership until cooldown expiry. Later adopters must set
their own receipt, incident and notification retention policies.

See [Site Health](site-health.md) for existing diagnostic checks and
[the extension surface catalogue](../packages/extension-surface-catalog.md) for
the contract's compatibility status. The category/severity values are stable;
the initial dispatch and adapter surfaces remain experimental during adoption.
