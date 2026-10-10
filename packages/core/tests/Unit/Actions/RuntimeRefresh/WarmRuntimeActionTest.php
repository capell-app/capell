<?php

declare(strict_types=1);

use Capell\Core\Actions\RuntimeRefresh\WarmRuntimeAction;
use Capell\Core\Contracts\RuntimeRefreshWarmer;

it('runs every registered runtime warmer and aggregates failures', function (): void {
    $completed = [];

    $passing = new class($completed) implements RuntimeRefreshWarmer
    {
        public function __construct(private array &$completed) {}

        #[Override]
        public function label(): string
        {
            return 'Passing warmer';
        }

        #[Override]
        public function warm(): void
        {
            $this->completed[] = 'passing';
        }
    };
    $failing = new class implements RuntimeRefreshWarmer
    {
        #[Override]
        public function label(): string
        {
            return 'Failing warmer';
        }

        #[Override]
        public function warm(): void
        {
            throw new RuntimeException('upstream unavailable');
        }
    };
    $application = app();
    $application->instance('passing', $passing);
    $application->instance('failing', $failing);
    $application->tag(['failing', 'passing'], RuntimeRefreshWarmer::TAG);

    $result = new WarmRuntimeAction($application)->handle();

    expect($result->passed)->toBeFalse()
        ->and($result->message)->toContain('Failing warmer: upstream unavailable')
        ->and($completed)->toBe(['passing']);
});
