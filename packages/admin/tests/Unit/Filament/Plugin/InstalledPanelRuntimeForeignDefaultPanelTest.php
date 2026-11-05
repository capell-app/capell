<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Enums\RouteOwnershipEnum;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ForeignDefaultPanelProvider;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Override;

/**
 * When the host's default Filament panel is one Capell does not extend, Filament's global routes
 * belong to the Capell panel instead of being refused.
 */
final class InstalledPanelRuntimeForeignDefaultPanelTest extends AdminTestCase
{
    public function test_global_admin_routes_belong_to_the_capell_panel(): void
    {
        $this->assertSame('portal', Filament::getDefaultPanel()->getId());
        $refused = collect(Route::getRoutes()->getRoutes())
            ->filter(static fn (RoutingRoute $route): bool => PanelOwnership::of($route)?->kind === RouteOwnershipEnum::Refused)
            ->map(static fn (RoutingRoute $route): string => (string) $route->getName())
            ->values()
            ->all();
        $this->assertSame([], $refused);

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
        $user = $this->createUser();
        $export = new Export;
        // Started while the admin panel serves the request, as Filament's export action is.
        $serving = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $export->forceFill(['file_disk' => 'local', 'exporter' => 'exporter', 'total_rows' => 0, 'user_id' => $user->getKey()])->save();
        Filament::setCurrentPanel($serving);
        $url = route('filament.exports.download', ['export' => $export]);

        $download = (string) $this->get($url)->assertRedirectContains('/admin/')->headers->get('Location');
        $this->get($download)->assertRedirect('/admin/login');
        $this->actingAs($user)->get($download)->assertForbidden();
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? 0 : $position, 0, [ForeignDefaultPanelProvider::class]);

        return $providers;
    }
}
