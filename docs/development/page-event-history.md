# Page Event History and Rollback

Capell Core records page revisions as stored events while pages remain ordinary Eloquent models. The event stream provides history and recovery; it is not the primary write model for page editing.

## What Core Records Automatically

`RecordPageRevision` listens for `PageSaved`. After each page save it captures the page through `PageStateSerializer` and appends `PageRevisionRecorded` to the page aggregate.

The captured state includes the page and its owned content relationships. `PageProjector` maintains the queryable `page_revisions` index from those events. Revision replay does not restore historical page fields or translations. The same projector also handles explicit workflow events and can update `visible_from` or `visible_until` while replaying them, so replay still needs an operational window.

`Page` is the only model registered by Core:

```php
$eventSourcedRegistry->register(
    Page::class,
    PageAggregate::class,
    PageStateSerializer::class,
);
```

Do not describe every Capell model as event sourced. Packages can register another model only when they own an aggregate, a deterministic state serializer, and the operational consequences of replay and rollback.

## Workflow Event Boundary

`PageAggregate` defines events and transition rules for review, approval, change requests, scheduling, publication, unpublishing, and archiving. Core registers the corresponding projector and reactor so packages can use that infrastructure.

The base page editing path does not call those workflow transitions automatically. In an unextended install, the production bridge records `PageRevisionRecorded` after `PageSaved`. A package that uses a workflow transition must invoke the aggregate explicitly and test the resulting read model and side effects.

Publishing Studio owns the full editorial workflow: isolated drafts, comparison, assignments, field comments, approvals, scheduling, release checks, and editorial recovery UI. It builds on Core history and rollback rather than moving those engine contracts into the package.

## Previewing and Applying Rollback

Use the Actions instead of calling `RollbackService` from UI code:

```php
use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\Actions\BuildRollbackPreviewAction;
use Capell\Core\Models\Page;

function rollbackPage(Page $page, int $targetVersion): void
{
    $preview = BuildRollbackPreviewAction::run($page, $targetVersion);

    if (! $preview->isBlocked()) {
        ApplyRollbackAction::run($page, $targetVersion);
    }
}
```

The preview reconstructs the target state, compares it with current content, and runs every validator registered for the model. Core registers URL uniqueness and page referential-integrity validators.

Apply performs the validation again inside the recovery path, restores the page and owned relationships, and appends `PageRolledBack`. Existing events remain unchanged. `activeContentVersion()` reports the restored origin while `currentVersion()` continues to report the newer stream head, which allows a later roll-forward.

Rollback restores content state, not unrelated operational data. For example, page URL visit counts remain live rather than returning to their historical value.

## Adding a Rollback Validator

Packages should validate relationships they own without changing `RollbackService`:

```php
use Capell\Core\EventSourcing\Rollback\RollbackValidatorRegistry;
use Capell\Core\Models\Page;

resolve(RollbackValidatorRegistry::class)->register(
    Page::class,
    PackagePageRollbackValidator::class,
);
```

The validator implements `RollbackValidator` and returns `RollbackIssueData` records. A blocking issue prevents apply; a warning remains visible in the preview.

Keep validators deterministic and read-only. They inspect the proposed historical state against current database reality; they must not repair data as a side effect.

## Replay and Operations

Capell registers projectors and reactors explicitly and disables application-path auto-discovery. Stored events are ordered by `aggregate_version` for aggregate replay and rollback reads.

Before replaying projections in an application:

1. Back up the database, including `stored_events`.
2. Stop concurrent content operations that depend on the affected projection.
3. Confirm the replay targets `PageProjector`; do not treat replay as content restoration, and account for workflow events updating page visibility columns.
4. Rebuild the projection with `php artisan event-sourcing:replay 'Capell\Core\EventSourcing\Projectors\PageProjector'`.
5. Compare page revision and workflow read-model counts, then run the page event-sourcing integration tests.

Use rollback Actions when the goal is to restore page content. Use projector replay to rebuild projections from an intact event stream, with the understanding that projected workflow events also synchronize page visibility.

## Tests

Run the focused Core coverage:

```bash
vendor/bin/pest packages/core/tests/Integration/EventSourcing packages/core/tests/Unit/EventSourcing --configuration=phpunit.xml
```

The integration tests cover automatic revision recording, serializer round trips, rollback preview/apply, URL conflicts, preservation of live analytics, and active-content version behavior. Aggregate invariant tests cover the workflow transitions exposed for integrations.
