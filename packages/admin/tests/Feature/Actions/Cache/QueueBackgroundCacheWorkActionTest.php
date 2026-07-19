<?php

declare(strict_types=1);

use Capell\Admin\Actions\Cache\QueueFrontendBuildAction;
use Capell\Admin\Actions\Cache\QueueStaticSiteGenerationAction;
use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Admin\Jobs\RunFrontendBuildJob;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Cache::forget(RunFrontendBuildJob::STATUS_KEY);
});

it('queues one frontend build while one is already pending', function (): void {
    Queue::fake();

    expect(QueueFrontendBuildAction::run())->toBeTrue()
        ->and(QueueFrontendBuildAction::run())->toBeFalse()
        ->and(data_get(Cache::get(RunFrontendBuildJob::STATUS_KEY), 'status'))->toBe('queued');

    Queue::assertPushed(RunFrontendBuildJob::class, 1);
});

it('persists a generic failed frontend build status', function (): void {
    (new RunFrontendBuildJob)->failed(new RuntimeException('secret process output'));

    expect(Cache::get(RunFrontendBuildJob::STATUS_KEY))->toMatchArray([
        'status' => 'failed',
        'message' => 'Frontend asset build failed. Review the queue worker log for details.',
    ])->not->toContain('secret process output');
});

it('delegates static site generation through the installed cache extension', function (): void {
    $site = Site::factory()->make(['id' => 42]);
    $dispatcher = Mockery::mock(StaticSiteGenerationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->with($site);
    app()->instance(StaticSiteGenerationDispatcher::class, $dispatcher);

    QueueStaticSiteGenerationAction::run($site);
});
