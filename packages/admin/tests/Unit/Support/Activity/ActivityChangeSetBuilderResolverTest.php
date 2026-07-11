<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Support\Activity\ActivityChangeSetBuilderResolver;
use Capell\Admin\Support\Activity\DefaultActivityChangeSetBuilder;
use Capell\Admin\Tests\Fixtures\Autoload\PriorityActivityChangeSetBuilderForResolver;
use Capell\Admin\Tests\Fixtures\Autoload\SupportedActivityChangeSetBuilderForResolver;
use Capell\Admin\Tests\Fixtures\Autoload\UnsupportedActivityChangeSetBuilderForResolver;
use Capell\Core\Models\Language;
use Spatie\Activitylog\Models\Activity;

it('uses the highest priority tagged builder that supports the activity', function (): void {
    app()->tag([
        SupportedActivityChangeSetBuilderForResolver::class,
        PriorityActivityChangeSetBuilderForResolver::class,
    ], ActivityChangeSetBuilder::TAG);

    $activity = new Activity;

    expect(resolve(ActivityChangeSetBuilderResolver::class)->resolve($activity))
        ->toBeInstanceOf(PriorityActivityChangeSetBuilderForResolver::class);
});

it('falls back to the default builder when no tagged builder supports the activity', function (): void {
    app()->tag([UnsupportedActivityChangeSetBuilderForResolver::class], ActivityChangeSetBuilder::TAG);

    $activity = new Activity;

    expect(resolve(ActivityChangeSetBuilderResolver::class)->resolve($activity))
        ->toBeInstanceOf(DefaultActivityChangeSetBuilder::class);
});

it('does not mark deleted activity fields as reversible in the default builder', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('deleted')
        ->withProperties(['old' => ['name' => 'French']])
        ->log('deleted language');
    assert($activity instanceof Activity);

    $changeSet = resolve(DefaultActivityChangeSetBuilder::class)->build($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->reversible)->toBeFalse()
        ->and($changeSet->fields[0]->skipReason)->toBe('unsupported_event');
});

it('does not mark workspace stamped default activity fields as reversible', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'workspace_id' => 123,
            'old' => ['name' => 'Francais'],
            'attributes' => ['name' => 'French'],
        ])
        ->log('updated language');
    assert($activity instanceof Activity);

    $changeSet = resolve(DefaultActivityChangeSetBuilder::class)->build($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->reversible)->toBeFalse()
        ->and($changeSet->fields[0]->skipReason)->toBe('workspace_context');
});

it('does not mark non-fillable default activity fields as reversible', function (): void {
    $activity = activity()
        ->performedOn(Language::factory()->createOne(['name' => 'French']))
        ->event('updated')
        ->withProperties([
            'old' => ['id' => 1],
            'attributes' => ['id' => 2],
        ])
        ->log('updated language');
    assert($activity instanceof Activity);

    $changeSet = resolve(DefaultActivityChangeSetBuilder::class)->build($activity);

    expect($changeSet->fields)->toHaveCount(1)
        ->and($changeSet->fields[0]->reversible)->toBeFalse()
        ->and($changeSet->fields[0]->skipReason)->toBe('not_fillable');
});

function activityChangeSetForResolver(Activity $activity, string $summary): ActivityChangeSetData
{
    return new ActivityChangeSetData(
        summary: $summary,
        resource: null,
        fields: [],
        actorLabel: 'System',
        event: $activity->event,
        occurredAt: $activity->created_at,
        workspaceId: null,
        emptyMessage: null,
    );
}
