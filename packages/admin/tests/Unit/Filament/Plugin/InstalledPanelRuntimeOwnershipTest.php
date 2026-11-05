<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Enums\RouteOwnershipEnum;
use Capell\Admin\Http\Controllers\AdminAvatarController;
use Capell\Admin\Support\AdminPanelEntrypoint;
use Capell\Admin\Support\AdminRouteProvenance;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\RuntimeCounterComponent;
use Capell\Tests\Fixtures\Models\User;
use Closure;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;

/**
 * Every route that runs Filament, Livewire or Capell admin code was registered by a panel and runs that
 * panel's whole stack, unless its code is public admin code. Public admin code is justified here.
 */
final class InstalledPanelRuntimeOwnershipTest extends AdminTestCase
{
    public function test_every_admin_code_route_is_registered_by_a_panel_or_public(): void
    {
        $refused = $this->refusedRoutes();

        $this->assertSame([], $refused, "Routes neither registered by a panel nor public or the host's own: " . implode(', ', $refused));
    }

    /**
     * Filament's global export download is served by the panel the export was started in, which runs
     * that panel's authentication.
     */
    public function test_the_global_export_download_runs_the_originating_panel_authentication(): void
    {
        [$user, $url] = $this->ownExport();

        $download = $this->get($url)->assertRedirectContains('/admin/')->headers->get('Location');
        $this->get((string) $download)->assertRedirect('/admin/login');
        // The export is the user's own, so only the panel's admission refuses it.
        $this->actingAs($user)->get((string) $download)->assertForbidden();
    }

    /** A cached Livewire closure is a public host route, so Livewire's cached component assets stay public. */
    public function test_a_cached_livewire_closure_stays_public(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => str_ends_with('/' . $route->uri(), '/css/{component}.css'));
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $route->prepareForSerialization();
        $route->setRouter(resolve(Router::class))->setContainer(app());
        $this->assertIsString($route->getAction('uses'));

        $this->get('/' . str_replace('{component}', 'runtime-missing-component', $route->uri()))->assertNotFound();
    }

    /** Capell admin code registered outside the panel path for the admin panel runs that panel's authentication. */
    public function test_admin_code_registered_for_the_admin_panel_outside_its_path_runs_its_authentication(): void
    {
        AdminRouteProvenance::panel(AdminPanelEntrypoint::PANEL, static function (): void {
            Route::get('/runtime-admin-code/{initials}.svg', AdminAvatarController::class)->middleware('web');
        });

        $this->get('/runtime-admin-code/AB.svg')->assertRedirect('/admin/login');
        $this->actingAsUser()->get('/runtime-admin-code/AB.svg')->assertForbidden();
        $this->actingAsAdmin()->get('/runtime-admin-code/AB.svg')->assertOk();
    }

    /** Routes registered on the admin entrypoint take its path and run the admin panel's authentication. */
    public function test_routes_registered_on_the_admin_entrypoint_take_its_path_and_panel(): void
    {
        AdminRouteProvenance::entrypoint(static function (): void {
            Route::get('runtime-entrypoint/{initials}.svg', AdminAvatarController::class);
        });

        $this->get('/admin/runtime-entrypoint/AB.svg')->assertRedirect('/admin/login');
        $this->actingAsUser()->get('/admin/runtime-entrypoint/AB.svg')->assertForbidden();
        $this->actingAsAdmin()->get('/admin/runtime-entrypoint/AB.svg')->assertOk();
        $this->get('/runtime-entrypoint/AB.svg')->assertNotFound();
    }

    /** Entrypoint routes that serve guests skip the panel's sign-in but stay the admin panel's. */
    public function test_entrypoint_routes_serving_guests_skip_the_panel_sign_in(): void
    {
        AdminRouteProvenance::entrypoint(static function (): void {
            Route::get('runtime-entrypoint-guest/{initials}.svg', AdminAvatarController::class);
        }, guests: true);

        $this->get('/admin/runtime-entrypoint-guest/AB.svg')->assertOk();
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => $route->uri() === 'admin/runtime-entrypoint-guest/{initials}.svg');
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertTrue(PanelOwnership::of($route)?->ownedBy(AdminPanelEntrypoint::PANEL));
        $this->assertTrue(PanelOwnership::of($route)->guests);
    }

    /** Admin code registered for a panel that is not installed is refused, never assigned to another panel. */
    public function test_admin_code_registered_for_a_panel_that_is_not_installed_is_refused(): void
    {
        AdminRouteProvenance::panel('retired', static function (): void {
            Route::get('/admin/runtime-retired-panel/{initials}.svg', AdminAvatarController::class)->middleware('web');
        });

        $this->get('/admin/runtime-retired-panel/AB.svg')->assertStatus(503);
        $this->actingAsAdmin()->get('/admin/runtime-retired-panel/AB.svg')->assertStatus(503);
    }

    /** Admin code registered by no panel outside every panel path is refused, and its ownership records why. */
    public function test_an_unmarked_admin_controller_outside_the_panel_path_is_refused(): void
    {
        Route::get('/runtime-unmarked-admin/{initials}.svg', AdminAvatarController::class)->middleware('web')->name('runtime.unmarked-admin');

        $this->assertSame(['runtime.unmarked-admin'], $this->refusedRoutes());
        $this->get('/runtime-unmarked-admin/AB.svg')->assertStatus(503);
        $this->actingAsAdmin()->get('/runtime-unmarked-admin/AB.svg')->assertStatus(503);
    }

    /** A closure over admin code registered without the helper is refused too. */
    public function test_an_unmarked_admin_closure_outside_the_panel_path_is_refused(): void
    {
        Route::get('/runtime-unmarked-closure/{initials}.svg', Closure::fromCallable(resolve(AdminAvatarController::class)))->middleware('web');

        $this->get('/runtime-unmarked-closure/AB.svg')->assertStatus(503);
    }

    /** Livewire's script is served to every visitor, signed in or not, because public pages load it too. */
    public function test_livewire_assets_are_public(): void
    {
        $script = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => $route->getActionName() === FrontendAssets::class . '@returnJavaScriptAsFile');
        $this->assertInstanceOf(RoutingRoute::class, $script);

        $this->get('/' . $script->uri())->assertOk();
    }

    /** Livewire's component script and style endpoints serve every visitor, as public components use them. */
    public function test_livewire_component_assets_are_public(): void
    {
        foreach (['/css/{component}.css', '/css/{component}.global.css', '/js/{component}.js'] as $suffix) {
            $route = collect(Route::getRoutes()->getRoutes())
                ->first(static fn (RoutingRoute $route): bool => str_ends_with('/' . $route->uri(), $suffix));
            $this->assertInstanceOf(RoutingRoute::class, $route, $suffix);

            // Livewire itself answers for an unknown component, rather than the panel sending the visitor to sign in.
            $this->get('/' . str_replace('{component}', 'runtime-missing-component', $route->uri()))->assertNotFound();
        }
    }

    /** A component under test updates through Livewire's own testing endpoint, which no panel owns. */
    public function test_the_livewire_testing_endpoint_is_public(): void
    {
        Livewire::component('runtime.public-counter', RuntimeCounterComponent::class);

        Livewire::test('runtime.public-counter')->call('increment')->assertSee('count: 1');
    }

    /** Livewire's upload and preview endpoints are public because their URL signature is the credential. */
    public function test_livewire_file_endpoints_are_public_and_require_their_signature(): void
    {
        $this->post(route('livewire.upload-file'))->assertUnauthorized();
        $this->get(route('livewire.preview-file', ['filename' => 'missing.png']))->assertUnauthorized();
    }

    /** Livewire's update endpoint serves public components; panel components replay their panel's middleware. */
    public function test_the_livewire_update_endpoint_is_public(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => $route->named('*livewire.update'));
        $this->assertInstanceOf(RoutingRoute::class, $route);

        // Livewire itself answers a guest's empty update, rather than the panel sending them to sign in.
        $this->postJson('/' . $route->uri(), ['components' => []], ['X-Livewire' => 'true'])
            ->assertNotFound();
    }

    /** Leaving an impersonation serves the impersonated user, who need not have panel access. */
    public function test_leaving_an_impersonation_does_not_require_panel_access(): void
    {
        $this->actingAsUser()->get(route('filament-impersonate.leave'))->assertRedirect('/');
    }

    /** The internal preview URL is public because its unguessable cache token is the credential. */
    public function test_the_internal_preview_requires_its_token(): void
    {
        $this->get(route('filament-peek.preview', ['token' => 'missing']))->assertNotFound();
    }

    /** The icon picker only serves admin forms, so it is owned by the default panel. */
    public function test_the_icon_picker_runs_the_default_panel_authentication(): void
    {
        $this->get(route('guava-icon-picker.index'))->assertRedirect('/admin/login');
    }

    /** @return array{0: User, 1: string} */
    private function ownExport(): array
    {
        if (! Schema::hasTable('exports')) {
            // Filament's exports table, as its published migration creates it.
            Schema::create('exports', static function (Blueprint $table): void {
                $table->id();
                $table->timestamp('completed_at')->nullable();
                $table->string('file_disk');
                $table->string('file_name')->nullable();
                $table->string('exporter');
                $table->unsignedInteger('processed_rows')->default(0);
                $table->unsignedInteger('total_rows');
                $table->unsignedInteger('successful_rows')->default(0);
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }

        $user = $this->createUser();
        $export = new Export;
        // Started while an admin panel route serves the request, as Filament's export action is.
        $request = request();
        $resolver = $request->getRouteResolver();
        $adminRoute = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => PanelOwnership::of($route)?->ownedBy('admin') === true);
        $this->assertInstanceOf(RoutingRoute::class, $adminRoute);
        $request->setRouteResolver(static fn (): RoutingRoute => $adminRoute);
        try {
            $export->forceFill(['file_disk' => 'local', 'exporter' => 'exporter', 'total_rows' => 0, 'user_id' => $user->getKey()])->save();
        } finally {
            $request->setRouteResolver($resolver ?? static fn (): null => null);
        }

        return [$user, URL::signedRoute('filament.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false)];
    }

    /**
     * Routes the router would refuse wherever they are requested: carrying no ownership, or refused by it.
     *
     * @return list<string>
     */
    private function refusedRoutes(): array
    {
        return array_values(collect(Route::getRoutes()->getRoutes())
            ->reject(static fn (RoutingRoute $route): bool => in_array(PanelOwnership::of($route)?->kind, [RouteOwnershipEnum::Panel, RouteOwnershipEnum::Public, RouteOwnershipEnum::Host], true))
            ->map(static fn (RoutingRoute $route): string => $route->getName() ?? implode('|', $route->methods()) . ' ' . $route->uri())
            ->all());
    }
}
