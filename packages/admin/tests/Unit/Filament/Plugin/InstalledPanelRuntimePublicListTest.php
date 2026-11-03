<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Capell\Core\Models\Page;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Override;
use PHPUnit\Framework\Attributes\TestWith;

/** Each entry on the public panel route list is justified, and nothing else skips the panel's authentication. */
final class InstalledPanelRuntimePublicListTest extends AdminTestCase
{
    private const string BODY = 'public panel route served';

    /** Sign-in, registration and password reset serve visitors who cannot have signed in yet. */
    #[TestWith(['auth.login'])]
    #[TestWith(['auth.register'])]
    #[TestWith(['auth.password-reset.request'])]
    #[TestWith(['auth.password-reset.reset'])]
    public function test_filament_entry_routes_serve_guests_without_package_authentication(string $suffix): void
    {
        Route::get('/admin/runtime-public-entry', static fn (): string => self::BODY)
            ->middleware(Filament::getPanel('admin')->getMiddleware())
            ->name('filament.admin.' . $suffix);

        $this->get('/admin/runtime-public-entry')->assertOk()->assertSee(self::BODY);
    }

    /** Email verification serves signed-in users, so it keeps the panel's authentication and package security. */
    #[TestWith(['auth.email-verification.prompt'])]
    #[TestWith(['auth.email-verification.verify'])]
    public function test_email_verification_routes_keep_package_authentication(string $suffix): void
    {
        Route::get('/admin/runtime-verification', static fn (): string => self::BODY)
            ->middleware([...Filament::getPanel('admin')->getMiddleware(), Authenticate::class])
            ->name('filament.admin.' . $suffix);
        $this->actingAsAdmin();

        $this->get('/admin/runtime-verification')
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY)
            ->assertDontSee(self::BODY);
    }

    /** Preview links are public only because the URL signature is the credential. */
    public function test_declared_public_previews_still_require_their_signature(): void
    {
        foreach (['capell.admin.preview-page', 'capell.admin.theme-preview'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
            $this->assertContains(ValidateSignature::class, resolve(Router::class)->gatherRouteMiddleware($route));
        }

        $page = Page::factory()->createOne();
        $this->get(route('capell.admin.preview-page', ['page' => $page]))->assertForbidden()->assertDontSee(PackageSecurityDenyMiddleware::BODY);
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), PackageSecurityDenyRuntimeProvider::class];
    }
}
