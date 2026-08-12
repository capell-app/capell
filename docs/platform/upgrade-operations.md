---
path: /platform/upgrade-operations
title: Durable Upgrade Operations
primary_cta:
    label: See Capell upgrade operations
    url: /demo
secondary_cta:
    label: Read the upgrade runbook
    url: /docs/operations/upgrading
---

# Durable Upgrade Operations

![Capell Durable Upgrade Operations screenshot](../images/generated/admin/theme-library-admin-flow.png)

## Buyer Overview

Capell upgrades are designed for the way production Laravel sites actually run: sometimes from a deployment script, sometimes from a queue worker, sometimes from an admin screen, and sometimes manually over SSH when a server is locked down.

The visible benefit is simple: every upgrade attempt has a status, a timeline, and a recovery path. Site owners can see whether an upgrade is queued, running, finished, failed, or needs a manual command. Developers still get the same stable `php artisan capell:upgrade` command they can put into deployment pipelines.

This matters for agencies and product teams because CMS upgrades usually fail in the messy middle: a queue worker is not running, a migration lock cannot be written, a package command was removed, or someone needs to know whether a dry run already happened. Capell records those moments instead of burying them in a web request or a lost terminal buffer.

## Commercial Positioning

Headline:

```text
Upgrade Laravel CMS sites with a real operations trail.
```

Supporting copy:

```text
Capell keeps one upgrade command for developers and adds durable admin visibility for teams. Preview changes, queue safe updates, fall back to exact manual commands when the server cannot run background work, and review the timeline afterwards.
```

Useful feature bullets:

- **Queue-first admin upgrades.** Admin actions create durable operations and queue them only when the server is ready.
- **Manual fallback without guesswork.** If the queue driver is `sync`, locks are unavailable, or a required table is missing, Capell shows the exact artisan command to run on the server.
- **Readable status for non-developers.** The Upgrades page shows current/last status, readiness checks, and recent events.
- **Developer-safe command contract.** `php artisan capell:upgrade` stays the stable entry point for CLI, deployment, and manual recovery.
- **Package-aware by design.** Tagged upgrade steps are preferred, while legacy manifest upgrade commands still run with warnings so package authors can migrate cleanly.

Primary CTA target: `/demo`

Secondary CTA target: `/docs/operations/upgrading`

## What Visitors Should Understand

Capell is not trying to run Composer from a browser. Composer package updates still belong in the normal deployment workflow. The feature here is the operational layer around Capell's upgrade pipeline:

1. Preview or run an upgrade from the admin.
2. Capell checks whether the server can safely run a background job.
3. If ready, Capell creates a queued operation.
4. If not ready, Capell creates a manual-required operation and shows the exact command.
5. The operation timeline records readiness, queue, migration, step, legacy package command, version ledger, cache clear, success, and failure events.

That gives non-technical stakeholders confidence that upgrades are not a black box, while developers keep control over the actual server-side execution.

## For Developers And Operators

The rest of this page is implementation detail for teams evaluating how Capell behaves during deployment, queue execution, manual recovery, and package lifecycle upgrades.

## Developer Detail

The implementation is split across three storage responsibilities:

| Table                       | Responsibility                                                                                                                                                                                             |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `capell_upgrade_runs`       | Mutable operation state: `queued`, `running`, `succeeded`, `failed`, `manual_required`, user, options, current stage, readiness warnings/errors, failure reason, manual commands, and safe output excerpt. |
| `capell_upgrade_run_events` | Append-only timeline events with level, stage, message, redacted context, and safe output excerpt.                                                                                                         |
| `capell_upgrade_log`        | Existing step/version ledger for `UpgradeStepContract` results and Composer version snapshots.                                                                                                             |

The command contract remains:

```bash
php artisan capell:upgrade --force --no-clear-cache --dry-run
php artisan capell:upgrade --force --no-clear-cache
```

Admin execution uses `QueueCapellUpgradeAction`, which returns structured queue result data instead of a boolean. It includes the run id, run status, queue/manual-required status, manual commands, and readiness report.

`RunCapellUpgradeJob` accepts an upgrade run id. It atomically claims queued runs, records timeline events through the database reporter, calls `RunCapellUpgradeAction`, and marks terminal success or failure from both `handle()` and `failed()`.

`RunCapellUpgradeAction` remains the pipeline owner. It still handles the database-backed coordination lock, version audit, migrations, tagged upgrade steps, legacy per-package commands, version snapshots, and cache clearing. Installations upgrading from a version before the lock table existed use the configured cache lock for that one migration boundary. The reporter boundary lets the same pipeline write console output, durable database events, or both.

Readiness checks cover:

- upgrade operation table availability;
- queue driver;
- database queue table when the database driver is active;
- upgrade coordination lock availability;
- migration lock path writability;
- database connectivity;
- legacy package upgrade command availability.

For package authors, the preferred path is a tagged `UpgradeStepContract`. Manifest `commands.upgrade` remains backward compatible, but Capell records warnings/events to make migration visible.

## Retention

Upgrade runs and events are operational audit records. Capell does not prune them automatically in v1 because teams have different compliance and hosting needs. Recommended policy:

- Keep successful dry-run events for at least 30 days.
- Keep successful production run events for at least 180 days.
- Keep failed and manual-required run events until an operator has exported or reviewed them.
- Redacted output excerpts are safe for admin review, but do not use the operation tables as long-term log storage.

## Related Docs

- [Upgrade runbook](../operations/upgrading.md)
- [Authoring upgrade steps](../../packages/core/docs/authoring-upgrade-steps.md)
- [Operations](../operations/index.md)
