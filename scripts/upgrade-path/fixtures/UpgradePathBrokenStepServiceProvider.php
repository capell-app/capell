<?php

declare(strict_types=1);

namespace App\Providers;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * Seeded fault for scripts/upgrade-path.sh: an upgrade step that always fails. The upgrade-path
 * check copies this into the scratch application to prove it goes red when a step breaks.
 */
final class UpgradePathBrokenStepServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind('upgrade-path.broken-step', static fn (): UpgradeStepContract => new class implements UpgradeStepContract
        {
            #[Override]
            public function id(): string
            {
                return 'upgrade-path.seeded-broken-step';
            }

            #[Override]
            public function label(): string
            {
                return 'Seeded broken upgrade step';
            }

            #[Override]
            public function package(): string
            {
                return 'capell-app/core';
            }

            #[Override]
            public function priority(): int
            {
                return 0;
            }

            #[Override]
            public function dependsOn(): array
            {
                return [];
            }

            #[Override]
            public function shouldRun(UpgradeContext $context): bool
            {
                return true;
            }

            #[Override]
            public function run(UpgradeContext $context): bool
            {
                throw new RuntimeException('Seeded upgrade-path failure.');
            }

            #[Override]
            public function rollback(UpgradeContext $context): bool
            {
                return true;
            }
        });

        $this->app->tag(['upgrade-path.broken-step'], 'capell.upgrade-steps');
    }
}
