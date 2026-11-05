<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyRuntimeProvider;
use Capell\Core\Models\Page;
use Closure;
use Filament\Auth\Pages\Login;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Auth\Pages\Register;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

/** Each panel route that serves guests is justified, and nothing else skips the panel's authentication. */
final class InstalledPanelRuntimePublicListTest extends AdminTestCase
{
    private const string BODY = 'public panel route served';

    /** Sign-in, registration and password reset serve visitors who cannot have signed in yet. */
    #[TestWith([Login::class])]
    #[TestWith([Register::class])]
    #[TestWith([RequestPasswordReset::class])]
    #[TestWith([ResetPassword::class])]
    public function test_filament_sign_in_pages_registered_by_the_panel_serve_guests(string $page): void
    {
        $this->panelRoutes(static fn () => Route::get('/admin/runtime-public-entry', $page)->name('runtime-public-entry'));

        $ownership = PanelOwnership::of(Route::getRoutes()->getByName('filament.admin.runtime-public-entry') ?? throw new RuntimeException('Missing route.'));
        $this->assertTrue($ownership?->ownedBy('admin'));
        $this->assertTrue($ownership->guests);
    }

    /** The panel's own sign-in and password reset pages serve guests without package authentication. */
    public function test_the_panels_sign_in_pages_serve_guests_without_package_authentication(): void
    {
        $this->get(route('filament.admin.auth.login'))->assertOk()->assertDontSee(PackageSecurityDenyMiddleware::BODY);
        $this->get(route('filament.admin.auth.password-reset.request'))->assertOk()->assertDontSee(PackageSecurityDenyMiddleware::BODY);
    }

    /** A route's name does not make it a sign-in page: only Filament's sign-in pages serve guests. */
    public function test_a_route_named_like_a_sign_in_page_keeps_the_panels_authentication(): void
    {
        $this->panelRoutes(static fn () => Route::get('/admin/runtime-named-entry', static fn (): string => self::BODY)->name('runtime.auth.login'));

        $this->get('/admin/runtime-named-entry')->assertRedirect('/admin/login');
    }

    /** Email verification serves signed-in users, so it keeps the panel's authentication and package security. */
    #[TestWith(['auth.email-verification.prompt'])]
    #[TestWith(['auth.email-verification.verify'])]
    public function test_email_verification_routes_keep_package_authentication(string $suffix): void
    {
        $this->panelRoutes(static fn () => Route::get('/admin/runtime-verification', static fn (): string => self::BODY)->middleware(Authenticate::class)->name($suffix));
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

    /** Register routes in the admin panel's route group, as Filament registers the panel's own routes. */
    private function panelRoutes(Closure $routes): void
    {
        Route::middleware(Filament::getPanel('admin')->getMiddleware())->name('filament.admin.')->group($routes);
        Route::getRoutes()->refreshNameLookups();
    }
}
