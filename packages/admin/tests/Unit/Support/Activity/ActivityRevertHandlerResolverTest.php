<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Support\Activity\ActivityRevertHandlerResolver;
use Capell\Admin\Support\Activity\DefaultActivityRevertHandler;
use Capell\Admin\Tests\Fixtures\Autoload\PriorityActivityRevertHandlerForResolver;
use Capell\Admin\Tests\Fixtures\Autoload\SupportedActivityRevertHandlerForResolver;
use Capell\Admin\Tests\Fixtures\Autoload\UnsupportedActivityRevertHandlerForResolver;

it('uses the highest priority tagged handler that supports the selection', function (): void {
    app()->tag([
        SupportedActivityRevertHandlerForResolver::class,
        PriorityActivityRevertHandlerForResolver::class,
    ], ActivityRevertHandler::TAG);

    expect(resolve(ActivityRevertHandlerResolver::class)->resolve(activityRevertSelectionForResolver()))
        ->toBeInstanceOf(PriorityActivityRevertHandlerForResolver::class);
});

it('falls back to the default handler when no tagged handler supports the selection', function (): void {
    app()->tag([UnsupportedActivityRevertHandlerForResolver::class], ActivityRevertHandler::TAG);

    expect(resolve(ActivityRevertHandlerResolver::class)->resolve(activityRevertSelectionForResolver()))
        ->toBeInstanceOf(DefaultActivityRevertHandler::class);
});

function activityRevertSelectionForResolver(): ActivityRevertSelectionData
{
    return new ActivityRevertSelectionData(
        activityId: 1,
        selectedPaths: ['name'],
        beforeValues: ['name' => 'Francais'],
        actorId: null,
        subjectMorphType: null,
        subjectClass: null,
        subjectId: null,
        stableIdentifier: null,
        workspaceId: null,
    );
}
