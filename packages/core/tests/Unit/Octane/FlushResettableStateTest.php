<?php

declare(strict_types=1);

use Capell\Core\Octane\FlushResettableState;
use Capell\Core\Octane\Resettable;

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
