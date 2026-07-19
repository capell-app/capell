<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\WarmRuntimeAction;
use Capell\Core\Contracts\RuntimeRefreshWarmer;
use Illuminate\Foundation\Application;

it('runs every registered runtime warmer and aggregates failures', function (): void {
    $completed = [];

    $passing = new class($completed) implements RuntimeRefreshWarmer
    {
        public function __construct(private array &$completed) {}

        public function label(): string
        {
            return 'Passing warmer';
        }

        public function warm(): void
        {
            $this->completed[] = 'passing';
        }
    };
    $failing = new class implements RuntimeRefreshWarmer
    {
        public function label(): string
        {
            return 'Failing warmer';
        }

        public function warm(): void
        {
            throw new RuntimeException('upstream unavailable');
        }
    };
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('tagged')
        ->once()
        ->with(RuntimeRefreshWarmer::TAG)
        ->andReturn([$failing, $passing]);

    $result = (new WarmRuntimeAction($application))->handle();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('Failing warmer: upstream unavailable')
        ->and($completed)->toBe(['passing']);
});
