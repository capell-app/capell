<?php

declare(strict_types=1);

use Capell\Admin\Actions\Cache\QueueFrontendBuildAction;
use Capell\Admin\Actions\Cache\QueueStaticSiteGenerationAction;
use Capell\Admin\Contracts\Cache\StaticSiteGenerationDispatcher;
use Capell\Admin\Enums\FrontendBuildQueueResultEnum;
use Capell\Admin\Jobs\RunFrontendBuildJob;
use Capell\Core\Exceptions\QueueConnectionNotReadyException;
use Capell\Core\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config([
        'queue.default' => 'background',
        'queue.connections.background' => ['driver' => 'array'],
    ]);
    Cache::forget(RunFrontendBuildJob::STATUS_KEY);
});

it('queues one frontend build while one is already pending', function (): void {
    Queue::fake();

    expect(QueueFrontendBuildAction::run())->toBe(FrontendBuildQueueResultEnum::Queued)
        ->and(QueueFrontendBuildAction::run())->toBe(FrontendBuildQueueResultEnum::AlreadyRunning)
        ->and(data_get(Cache::get(RunFrontendBuildJob::STATUS_KEY), 'status'))->toBe('queued');

    Queue::assertPushed(RunFrontendBuildJob::class, 1);
});

it('reports lock contention separately from an in-progress build', function (): void {
    Queue::fake();

    // Hold the dispatch lock so the action cannot acquire it within its wait window.
    $blockingLock = Cache::lock(RunFrontendBuildJob::STATUS_KEY . '.dispatch', 30);

    expect($blockingLock->get())->toBeTrue();

    try {
        expect(QueueFrontendBuildAction::run())->toBe(FrontendBuildQueueResultEnum::Contended);
    } finally {
        $blockingLock->release();
    }

    // Contention must not leave a phantom "queued" status behind.
    expect(Cache::get(RunFrontendBuildJob::STATUS_KEY))->toBeNull();
    Queue::assertNotPushed(RunFrontendBuildJob::class);
});

it('persists a generic failed frontend build status', function (): void {
    (new RunFrontendBuildJob)->failed(new RuntimeException('secret process output'));

    expect(Cache::get(RunFrontendBuildJob::STATUS_KEY))->toMatchArray([
        'status' => 'failed',
        'message' => 'Frontend asset build failed. Review the queue worker log for details.',
    ])->not->toContain('secret process output');
});

it('blocks frontend builds when the queue runs synchronously', function (): void {
    config([
        'queue.default' => 'sync',
        'queue.connections.sync' => ['driver' => 'sync'],
    ]);

    expect(fn () => QueueFrontendBuildAction::run())
        ->toThrow(
            QueueConnectionNotReadyException::class,
            'Queue connection "sync" uses the sync driver. Configure an asynchronous queue and start a worker before continuing.',
        );

    expect(Cache::get(RunFrontendBuildJob::STATUS_KEY))->toBeNull();
    Queue::assertNothingPushed();
});

it('delegates static site generation through the installed cache extension', function (): void {
    $site = Site::factory()->make(['id' => 42]);
    $dispatcher = Mockery::mock(StaticSiteGenerationDispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->with($site);
    app()->instance(StaticSiteGenerationDispatcher::class, $dispatcher);

    QueueStaticSiteGenerationAction::run($site);
});
