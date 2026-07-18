<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\NewUserData;
use Capell\Core\Jobs\RunCapellInstallJob;
use Capell\Core\Tests\Feature\Commands\Fixtures\FakeRunInstallAction;
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

it('sets failed status in cache when an exception is thrown', function (): void {
    $installId = 'test-failed-uuid';

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

    expect(Cache::get(sprintf('capell.install.%s.status', $installId)))->toBe('failed');
});
