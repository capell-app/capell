<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\Diagnostics\Checks\AdminRouteOwnershipCheck;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\CustomGuestPage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\CustomGuestPageRuntimeProvider;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Mockery\MockInterface;
use Override;

final class InstalledPanelRuntimeGuestPageTest extends AdminTestCase
{
    public function test_custom_simple_pages_require_sign_in_unless_the_panel_declares_guests(): void
    {
        $this->get('/admin/custom-guest-default')->assertRedirect('/admin/login');
        $this->get('/admin/custom-guest-livewire')->assertRedirect('/admin/login');
        $this->get('/admin/custom-guest-declared')->assertOk()->assertSee(CustomGuestPage::BODY);
        $this->get('/admin/custom-guest-authenticated')->assertRedirect('/admin/login');
    }

    public function test_boot_warns_once_per_finished_route_table_and_doctor_lists_the_pages_and_remedy(): void
    {
        $logger = Log::getFacadeRoot();
        $this->assertInstanceOf(MockInterface::class, $logger);
        $pages = [
            'filament.admin.custom-guest-default -> admin (' . CustomGuestPage::class . ')',
            'filament.admin.custom-guest-livewire -> admin (' . CustomGuestPage::class . ')',
        ];
        $message = __('capell-admin::message.route_pages_require_authentication', ['routes' => implode(', ', $pages)]);
        $logger->shouldHaveReceived('warning')->with($message, ['pages' => $pages])->once();

        $runtime = resolve(InstalledPanelRuntime::class);
        $runtime->extend(Filament::getPanel('admin'));
        $runtime->extend(Filament::getPanel('admin'));
        $logger->shouldHaveReceived('warning')->with($message, ['pages' => $pages])->once();

        $result = resolve(AdminRouteOwnershipCheck::class)->check();
        $this->assertFalse($result->passed);
        $this->assertSame(DoctorCheckSeverity::Warning, $result->severity);
        $this->assertSame($pages, $result->evidence['pages_requiring_guest_declaration']);
        foreach ($pages as $page) {
            $this->assertStringContainsString($page, $result->message);
        }

        $this->assertStringContainsString('AdminRouteProvenance::panel($id, ..., guests: true)', $result->remediation ?? '');

        // A changed table produces one new aggregate warning, then becomes quiet again.
        Route::get('/runtime-new-public-route', static fn (): string => 'public');
        $runtime->extend(Filament::getPanel('admin'));
        $runtime->extend(Filament::getPanel('admin'));
        $logger->shouldHaveReceived('warning')->with($message, ['pages' => $pages])->twice();
    }

    public function test_doctor_compiles_panel_path_matchers_once_for_the_route_table(): void
    {
        $runtime = resolve(InstalledPanelRuntime::class);
        $panel = Mockery::mock(Panel::class)->makePartial();
        $panel->id('doctor-domain')->path('doctor-domain')->domains(['doctor.example.test']);
        $runtime->extend($panel);
        Route::get('/doctor-domain/one', static fn (): string => 'one')->name('doctor.one');
        Route::get('/doctor-domain/two', static fn (): string => 'two')->name('doctor.two');
        Route::get('/outside-doctor-domain', static fn (): string => 'outside')->name('doctor.outside');
        $panel->shouldReceive('getDomains')->once()->andReturn(['doctor.example.test']);

        $refused = $runtime->refusedRoutes();

        $this->assertContains('doctor.one', $refused);
        $this->assertContains('doctor.two', $refused);
        $this->assertNotContains('doctor.outside', $refused);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), CustomGuestPageRuntimeProvider::class];
    }
}
