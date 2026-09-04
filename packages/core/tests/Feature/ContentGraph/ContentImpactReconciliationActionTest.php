<?php

declare(strict_types=1);

use Capell\Core\Actions\ContentGraph\ReconcileContentImpactAction;
use Capell\Core\Models\Layout;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

it('records matching predicted and actual surfaces in the activity log', function (): void {
    $layout = Layout::factory()->createOne();

    $result = ReconcileContentImpactAction::run(
        $layout,
        ['url:https://example.test/landing', 'cache:site-1'],
        ['cache:site-1', 'url:https://example.test/landing'],
    );

    $activity = Activity::query()
        ->where('log_name', 'content-impact')
        ->where('subject_type', $layout->getMorphClass())
        ->where('subject_id', $layout->getKey())
        ->sole();

    expect($result->drifted)->toBeFalse()
        ->and($result->missingSurfaces)->toBe([])
        ->and($result->unexpectedSurfaces)->toBe([])
        ->and($activity->event)->toBe('reconciled')
        ->and($activity->properties?->get('predictedSurfaces'))->toBe([
            'cache:site-1',
            'url:https://example.test/landing',
        ])
        ->and($activity->properties?->get('actualSurfaces'))->toBe([
            'cache:site-1',
            'url:https://example.test/landing',
        ]);
});

it('records and logs drift between predicted and actual surfaces', function (): void {
    $log = Log::spy();
    $layout = Layout::factory()->createOne();

    $result = ReconcileContentImpactAction::run(
        $layout,
        ['url:https://example.test/landing'],
        ['url:https://example.test/home'],
    );

    expect($result->drifted)->toBeTrue()
        ->and($result->missingSurfaces)->toBe(['url:https://example.test/landing'])
        ->and($result->unexpectedSurfaces)->toBe(['url:https://example.test/home']);

    $log->shouldHaveReceived('warning')
        ->once();
});
