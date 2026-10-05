<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\FailingBootstrapRuntimeProvider;
use Capell\Core\Support\Diagnostics\Checks\InstalledRuntimeCheck;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Override;
use ReflectionMethod;

final class InstalledRuntimeFailureScopeTest extends AdminTestCase
{
    protected function tearDown(): void
    {
        FailingBootstrapRuntimeProvider::$failure = 'none';
        parent::tearDown();
    }

    public function test_hook_failure_at_cold_boot_preserves_unrelated_entry_points(): void
    {
        $this->assertFailureIsContained();
        $this->get('/runtime-failed-package')->assertStatus(503);
    }

    public function test_panel_failure_at_cold_boot_preserves_unrelated_entry_points(): void
    {
        $this->assertFailureIsContained();
        $this->get('/runtime-failed-package')->assertOk();
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        FailingBootstrapRuntimeProvider::$failure = str_contains($this->name(), 'hook_failure') ? 'hook' : 'panel';

        return [...parent::getPackageProviders($app), FailingBootstrapRuntimeProvider::class];
    }

    private function assertFailureIsContained(): void
    {
        Route::get('/runtime-public-bootstrap', static fn (): string => 'public');
        $builder = new ApplicationBuilder($this->app);
        $callback = new ReflectionMethod($builder, 'buildRoutingCallback')->invoke($builder, null, null, null, '/up', 'api', null);
        $callback();
        $this->get('/runtime-public-bootstrap')->assertOk();
        $this->get('/up')->assertOk();
        $this->assertSame(0, Artisan::call('list'));
        $this->get('/admin/login')->assertStatus(503);
        $diagnostic = resolve(InstalledRuntimeCheck::class)->check();
        $this->assertFalse($diagnostic->passed);
        $this->assertNotEmpty($diagnostic->evidence['failures']);
        $this->assertStringContainsString('Reload Octane', (string) $diagnostic->remediation);
    }
}
