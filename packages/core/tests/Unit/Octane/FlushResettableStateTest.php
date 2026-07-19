<?php

declare(strict_types=1);

use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Octane\Resettable;
use Capell\Core\Support\CapellCoreManager;
use Capell\Core\Support\Media\ImageUrlPolicy;
use Capell\Core\Support\Security\LockdownStore;
use Illuminate\Foundation\Application;
use Laravel\Octane\Contracts\OperationTerminated;

it('flushes tagged resettable services', function (): void {
    $resettable = new class implements Resettable
    {
        public int $flushes = 0;

        public function flushOctaneState(): void
        {
            $this->flushes++;
        }
    };

    app()->instance('capell.test-resettable', $resettable);
    app()->tag(['capell.test-resettable'], Resettable::TAG);

    new FlushResettableState(app())->handle();

    expect($resettable->flushes)->toBe(1);
});

it('ignores tagged services that do not implement the reset contract', function (): void {
    app()->instance('capell.test-not-resettable', new stdClass);
    app()->tag(['capell.test-not-resettable'], Resettable::TAG);

    new FlushResettableState(app())->handle();

    expect(true)->toBeTrue();
});

it('registers singleton request-caching core services for Octane reset', function (): void {
    $resettableServices = collect(app()->tagged(Resettable::TAG));

    expect($resettableServices->contains(fn (object $service): bool => $service instanceof CapellCoreManager))->toBeTrue()
        ->and($resettableServices->contains(fn (object $service): bool => $service instanceof LockdownStore))->toBeTrue()
        ->and($resettableServices->contains(fn (object $service): bool => $service instanceof ImageUrlPolicy))->toBeFalse();
});

it('flushes resettable services when an Octane operation terminates', function (): void {
    $baseApplication = app();
    $sandbox = clone $baseApplication;
    $resettable = new class implements Resettable
    {
        public int $flushes = 0;

        public function flushOctaneState(): void
        {
            $this->flushes++;
        }
    };

    $sandbox->instance('capell.test-octane-resettable', $resettable);
    $sandbox->tag(['capell.test-octane-resettable'], Resettable::TAG);

    event(new readonly class($baseApplication, $sandbox) implements OperationTerminated
    {
        public function __construct(
            private Application $application,
            private Application $sandbox,
        ) {}

        public function app(): Application
        {
            return $this->application;
        }

        public function sandbox(): Application
        {
            return $this->sandbox;
        }
    });

    expect($resettable->flushes)->toBe(1)
        ->and(collect($baseApplication->tagged(Resettable::TAG))->contains($resettable))->toBeFalse();
});
