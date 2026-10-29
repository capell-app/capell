<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeRunInstallAction;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

it('is queued when dispatched', function (): void {
    Queue::fake();

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData('Test', 'test@example.com', 'password'),
    );

    $job = new RunCapellInstallJob($inputData, 'test-uuid');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('test-uuid')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([30, 60]);

    dispatch($job);

    Queue::assertPushed(RunCapellInstallJob::class);
});

it('handles a queued payload created before table changes existed', function (): void {
    $installId = 'legacy-install-payload';
    $input = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData('Test', 'legacy@example.com', 'password'),
    );
    $payload = new RunCapellInstallJob($input, $installId)->__serialize();
    unset($payload["\0" . RunCapellInstallJob::class . "\0tableChanges"]);
    $job = new ReflectionClass(RunCapellInstallJob::class)->newInstanceWithoutConstructor();
    $job->__unserialize($payload);
    Cache::put('capell.install.lock', ['installId' => $installId]);
    app()->instance(RunInstallAction::class, new FakeRunInstallAction);

    $job->handle();
    expect(Cache::get('capell.install.' . $installId . '.status'))->toBe('complete');
});

it('sets complete status in cache after successful run', function (): void {
    $installId = 'test-complete-uuid';
    Cache::put('capell.install.lock', ['installId' => $installId]);

    // Bind a no-op fake that doesn't do anything
    app()->instance(RunInstallAction::class, new FakeRunInstallAction);

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData('Test', 'complete@example.com', 'password'),
    );

    new RunCapellInstallJob($inputData, $installId)->handle();

    expect(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('complete')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('does not run when another install owns the lock', function (): void {
    $installId = 'test-superseded-uuid';
    $activeInstallId = 'test-active-uuid';

    Cache::put('capell.install.lock', ['installId' => $activeInstallId]);

    $fakeAction = new class
    {
        public bool $called = false;

        public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
        {
            $this->called = true;
        }
    };
    app()->instance(RunInstallAction::class, $fakeAction);

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData('Test', 'superseded@example.com', 'password'),
    );

    new RunCapellInstallJob($inputData, $installId)->handle();

    expect($fakeAction->called)->toBeFalse()
        ->and(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('cancelled');
});

it('sets queued status in cache while a failed attempt awaits retry', function (): void {
    $installId = 'test-failed-uuid';
    Cache::put('capell.install.lock', ['installId' => $installId]);

    // RunInstallAction is final — use an anonymous fake instead of Mockery
    $throwingFake = new class
    {
        public function handle(InstallInputData $inputData, ProgressReporter $reporter): never
        {
            throw new RuntimeException('Install failed');
        }
    };
    app()->instance(RunInstallAction::class, $throwingFake);

    $inputData = new InstallInputData(
        siteUrl: 'https://example.com',
        packages: [],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        newUser: new NewUserData('Test', 'fail@example.com', 'password'),
    );

    expect(fn () => new RunCapellInstallJob($inputData, $installId)->handle())
        ->toThrow(RuntimeException::class);

    expect(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('queued');
});

it('retries a transient failure and completes under the original ownership', function (): void {
    $installId = 'retry-install';
    Cache::put('capell.install.lock', ['installId' => $installId]);
    $action = new class
    {
        public int $calls = 0;

        public function handle(InstallInputData $input, ProgressReporter $reporter): void
        {
            throw_if(++$this->calls === 1, RuntimeException::class, 'Transient failure');
        }
    };
    app()->instance(RunInstallAction::class, $action);
    $job = new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), $installId);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(Cache::get('capell.install.' . $installId . '.status'))->toBe('queued');

    $job->handle();
    expect($action->calls)->toBe(2)
        ->and(Cache::get('capell.install.' . $installId . '.status'))->toBe('complete')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('preserves replacement ownership and cancellation when an old attempt fails', function (): void {
    Cache::put('capell.install.lock', ['installId' => 'old-install']);
    app()->instance(RunInstallAction::class, new class
    {
        public function handle(InstallInputData $input, ProgressReporter $reporter): never
        {
            Cache::put('capell.install.lock', ['installId' => 'replacement']);
            Cache::put('capell.install.old-install.status', 'cancelled');
            throw new RuntimeException('Late failure');
        }
    });
    $job = new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), 'old-install');
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    expect(Cache::get('capell.install.lock'))->toBe(['installId' => 'replacement'])
        ->and(Cache::get('capell.install.old-install.status'))->toBe('cancelled');
});

it('releases only its own lock after exhausting retries', function (): void {
    Cache::put('capell.install.lock', ['installId' => 'exhausted']);
    $job = new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), 'exhausted');
    $job->failed(new RuntimeException('Exhausted'));
    expect(Cache::get('capell.install.exhausted.status'))->toBe('failed')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('never executes an explicitly cancelled queued run', function (): void {
    Cache::put('capell.install.lock', ['installId' => 'cancelled']);
    Cache::put('capell.install.cancelled.status', 'cancelled');
    RunInstallAction::mock()->shouldNotReceive('handle');
    new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), 'cancelled')->handle();
    expect(Cache::get('capell.install.cancelled.status'))->toBe('cancelled');
});

it('marks the final executing attempt failed and keeps later callbacks truthful', function (): void {
    $id = 'final-attempt';
    Cache::put('capell.install.lock', ['installId' => $id]);
    RunInstallAction::mock()->shouldReceive('handle')->once()->andThrow(new RuntimeException('Final failure'));
    $queuedJob = Mockery::mock(Job::class);
    $queuedJob->shouldReceive('attempts')->andReturn(3);
    $job = new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), $id);
    $job->setJob($queuedJob);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(new RuntimeException('Worker callback'));
    expect(Cache::get('capell.install.' . $id . '.status'))->toBe('failed')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('fences a queued completion against replacement under the ownership boundary', function (): void {
    $id = 'paused-queued-completion';
    $store = new class extends ArrayStore
    {
        public ?Closure $onRead = null;

        #[Override]
        public function get($key): mixed
        {
            $observed = parent::get($key);
            if ($this->onRead instanceof Closure) {
                ($this->onRead)($key);
            }

            return $observed;
        }
    };
    Cache::extend('ownership-probe', fn (): Repository => new Repository($store));
    config(['cache.default' => 'ownership-probe', 'cache.stores.ownership-probe' => ['driver' => 'ownership-probe']]);
    Cache::put('capell.install.lock', ['installId' => $id]);
    $probe = false;
    $interleaved = false;
    RunInstallAction::mock()->shouldReceive('handle')->once()->andReturnUsing(function () use (&$probe): void {
        $probe = true;
    });
    $store->onRead = function (string $key) use ($store, &$probe, &$interleaved): void {
        if ($probe && $key === 'capell.install.lock') {
            $probe = false;
            $contender = Cache::lock('capell.install.ownership', 30);
            if ($contender->get()) {
                $contender->release();
                $interleaved = true;
                $store->put('capell.install.lock', ['installId' => 'replacement'], 7200);
            }
        }

    };

    new RunCapellInstallJob(new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false), $id)->handle();

    expect($interleaved)->toBeFalse()
        ->and(Cache::get('capell.install.' . $id . '.status'))->toBe('complete')
        ->and(Cache::get('capell.install.lock'))->toBeNull();
});

it('uses the confirmed table names in a long-running worker and restores its configuration', function (): void {
    $id = 'table-config-install';
    Cache::put('capell.install.lock', ['installId' => $id]);
    config()->set('capell.database.table_prefix', 'stale_');
    $fake = new class
    {
        public mixed $observed = null;

        public function handle(InstallInputData $input, ProgressReporter $reporter): void
        {
            $this->observed = config('capell.database.table_prefix');
        }
    };
    app()->instance(RunInstallAction::class, $fake);
    $input = new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false);
    new RunCapellInstallJob($input, $id, ['capell.database.table_prefix' => 'cms_'])->handle();
    expect($fake->observed)->toBe('cms_')->and(config('capell.database.table_prefix'))->toBe('stale_');
});

it('restores an absent table alias after running a queued install', function (): void {
    $id = 'table-alias-install';
    Cache::put('capell.install.lock', ['installId' => $id]);
    config()->set('capell.database.table_aliases', []);
    $fake = new class
    {
        public mixed $observed = null;

        public function handle(InstallInputData $input, ProgressReporter $reporter): void
        {
            $this->observed = config('capell.database.table_aliases.pages');
        }
    };
    app()->instance(RunInstallAction::class, $fake);
    $input = new InstallInputData(siteUrl: 'https://example.com', packages: [], languages: ['en'], demoContent: false, cachesToClear: [], generateSitemap: false, generateStaticSite: false);
    new RunCapellInstallJob($input, $id, ['capell.database.table_aliases.pages' => 'cms_pages'])->handle();
    expect($fake->observed)->toBe('cms_pages')->and(config('capell.database.table_aliases'))->toBe([]);
});
