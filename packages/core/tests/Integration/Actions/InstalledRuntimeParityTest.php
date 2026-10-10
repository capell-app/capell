<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Integration\Actions;

use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Health\HealthCheckRegistry;
use Capell\Core\Testing\Contracts\InstalledRuntimeContract;
use Capell\Core\Tests\CoreTestCase;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\FileViewFinder;
use Override;
use RuntimeException;

final class InstalledRuntimeParityTest extends CoreTestCase
{
    public function test_fresh_boot_matches_installation_and_repeated_refresh_without_deduplication(): void
    {
        InstalledRuntimeContract::assertParity(
            provider: ParityMainProvider::class,
            boot: function (bool $installed): Application {
                ParityMainProvider::$initiallyInstalled = $installed;
                $this->refreshApplication();
                RefreshDatabaseState::$migrated = false;
                $this->refreshDatabase();

                return $this->app ?? throw new RuntimeException('Test application was not created.');
            },
            snapshot: $this->snapshot(...),
            install: static function (): void {
                InstallPackageAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
            },
            refresh: static function (): void {
                RefreshInstalledPackageRuntimeAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
                RefreshInstalledPackageRuntimeAction::run(CapellCore::getPackage(ParityMainProvider::$packageName));
            },
            assertAbsent: function (Application $app): void {
                $this->assertSame([], $app->make(ParityTaggedRegistry::class)->all());
                $this->assertSame([], $app->make(Dispatcher::class)->getRawListeners()['runtime.parity'] ?? []);
                $this->assertFalse(Gate::has('runtime.parity'));
                $this->assertNotInstanceOf(HealthCheck::class, $app->make(HealthCheckRegistry::class)->find('runtime.parity'));
            },
            assertDeclared: function (Application $app): void {
                $snapshot = $this->snapshot($app);
                $this->assertSame([ParityContributor::class, ParityChildProvider::class], $snapshot['contributors']);
                $this->assertSame(1, $snapshot['listeners']);
                $this->assertSame(['runtime.parity'], $snapshot['schedules']);
                $this->assertSame(ParityPolicy::class, $snapshot['policy']);
                $this->assertTrue($snapshot['gate']);
                $this->assertSame(ParityComponent::class, $snapshot['livewire']);
                $this->assertSame(ParityBladeComponent::class, $snapshot['blade']);
                $this->assertNotEmpty($snapshot['views']);
                $this->assertSame('runtime/parity', $snapshot['route']);
                $this->assertSame([ParityContributor::class], $snapshot['declaredHealthChecks']);
            },
        );
        ParityMainProvider::$initiallyInstalled = false;
    }

    #[Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ParityMainProvider::class, ParityChildProvider::class];
    }

    /** @return array<string, mixed> */
    private function snapshot(Application $app): array
    {
        $app->make(Router::class)->getRoutes()->refreshNameLookups();
        $finder = $app->make(Factory::class)->getFinder();
        throw_unless($finder instanceof FileViewFinder, RuntimeException::class, 'Expected a filesystem view finder.');

        return [
            'contributors' => $app->make(ParityTaggedRegistry::class)->all(),
            'declaredHealthChecks' => array_values(array_map(
                static fn (HealthCheck $check): string => $check::class,
                array_filter($app->make(HealthCheckRegistry::class)->checks(), static fn (HealthCheck $check): bool => $check->id() === 'runtime.parity'),
            )),
            'listeners' => count($app->make(Dispatcher::class)->getRawListeners()['runtime.parity'] ?? []),
            'schedules' => array_values(array_map(
                static fn (Event $event): ?string => $event->description,
                array_filter($app->make(Schedule::class)->events(), static fn (Event $event): bool => $event->description === 'runtime.parity'),
            )),
            'policy' => Gate::policies()[ParityModel::class] ?? null,
            'gate' => Gate::has('runtime.parity'),
            'livewire' => $app->make('livewire.finder')->resolveClassComponentClassName('runtime.parity'),
            'blade' => Blade::getClassComponentAliases()['runtime-parity'] ?? null,
            'views' => $finder->getHints()['runtime-parity'] ?? [],
            'route' => $app->make(Router::class)->getRoutes()->getByName('runtime.parity')?->uri(),
        ];
    }
}
