<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\PanelOwnership;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\DomainCapellPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ForeignPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\OtherDomainCapellPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SecondCapellPanelProvider;
use Capell\Core\Support\TableName;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Override;

/**
 * Filament's global export and import downloads are served by the panel the record was started in,
 * under that panel's whole stack and package security, rather than by one fallback panel for every
 * panel. A panel's own copy of the download serves only the records that panel started.
 */
final class InstalledPanelRuntimeTransferOriginTest extends AdminTestCase
{
    public function test_an_export_started_in_a_panel_downloads_under_that_panel(): void
    {
        [$user, $url] = $this->exportFrom('admin');

        $this->actingAs($user)->followingRedirects()->get($url)
            ->assertOk()
            ->assertDownload('runtime-export.csv');
    }

    public function test_an_export_started_in_another_panel_is_held_to_that_panels_security(): void
    {
        [$user, $url] = $this->exportFrom('studio');

        // The default panel would admit this user; the originating panel's package security refuses them.
        $this->actingAs($user)->followingRedirects()->get($url)
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY);
    }

    public function test_a_guest_is_sent_to_sign_in_to_the_originating_panel(): void
    {
        [, $url] = $this->exportFrom('studio');

        $download = $this->get($url)->assertRedirectContains('/studio/')->headers->get('Location');

        $this->get((string) $download)->assertRedirect('/studio/login');
    }

    /** Another panel's copy of the download does not serve an export it did not start, even to its owner. */
    public function test_another_panels_copy_of_the_export_download_refuses_the_export(): void
    {
        [$user, , $export] = $this->exportFrom('studio');

        $this->actingAs($user)
            ->get(URL::signedRoute('filament.admin.capell.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false))
            ->assertNotFound();
    }

    /** Another panel's copy of the import failures download does not serve an import it did not start. */
    public function test_another_panels_copy_of_the_import_failures_download_refuses_the_import(): void
    {
        [$user, $import] = $this->importFrom('studio');

        $this->actingAs($user)
            ->get(URL::signedRoute('filament.admin.capell.imports.failed-rows.download', ['authGuard' => 'web', 'import' => $import], absolute: false))
            ->assertNotFound();
        // The originating panel's own copy is held to its package security.
        $this->get(URL::signedRoute('filament.studio.capell.imports.failed-rows.download', ['authGuard' => 'web', 'import' => $import], absolute: false))
            ->assertForbidden()
            ->assertSee(PackageSecurityDenyMiddleware::BODY);
    }

    /** An import's failures download through the panel the import was started in. */
    public function test_an_import_started_in_a_panel_downloads_its_failures_under_that_panel(): void
    {
        [$user, $import] = $this->importFrom('admin');

        $this->actingAs($user)->followingRedirects()
            ->get(URL::signedRoute('filament.imports.failed-rows.download', ['authGuard' => 'web', 'import' => $import], absolute: false))
            ->assertOk();
    }

    /** A Filament panel Capell does not extend has no Capell security stack to serve its downloads, so they are refused. */
    public function test_an_export_started_in_a_panel_capell_does_not_extend_is_refused(): void
    {
        [$user, $url] = $this->exportFrom('portal');

        $this->actingAs($user)->get($url)
            ->assertForbidden()
            ->assertSee(__('capell-admin::message.transfer_origin_unknown'));
    }

    /** An export with no recorded panel, such as one started before origins were recorded, cannot be tied to a panel. */
    public function test_an_export_without_a_recorded_panel_is_refused_with_its_recovery(): void
    {
        [$user, $url] = $this->exportFrom(null);

        $this->actingAs($user)->get($url)
            ->assertForbidden()
            ->assertSee(__('capell-admin::message.transfer_origin_unknown'));
    }

    /** An export started in a panel that no longer exists is refused rather than served by another panel. */
    public function test_an_export_started_in_a_retired_panel_is_refused(): void
    {
        [$user, $url, $export] = $this->exportFrom(null);
        DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'export', 'transfer_key' => (string) $export->getKey(), 'panel' => 'retired', 'host' => 'localhost']);

        $this->actingAs($user)->get($url)->assertForbidden();
    }

    /** With panels on different domains, the download is sent to the host that served the export, as an absolute URL. */
    public function test_an_export_started_on_a_panel_domain_is_sent_back_to_that_host(): void
    {
        [$user, $url, $export] = $this->exportFrom('desk', DomainCapellPanelProvider::DOMAIN);

        $download = (string) $this->actingAs($user)->get($url)->headers->get('Location');

        $this->assertSame(DomainCapellPanelProvider::DOMAIN, parse_url($download, PHP_URL_HOST));
        $this->assertStringStartsWith('/desk/capell/exports/', (string) parse_url($download, PHP_URL_PATH));
        $this->get($download)->assertOk()->assertDownload('runtime-export.csv');
        // The other domain's panel does not serve it.
        $this->get('http://' . OtherDomainCapellPanelProvider::DOMAIN . URL::signedRoute('filament.counter.capell.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false))
            ->assertNotFound();
    }

    /** The originating panel's copy serves the download only on the host and port the export was started on. */
    public function test_the_originating_panels_copy_is_not_served_on_another_host(): void
    {
        $startedOn = request()->getHttpHost();
        [$user, , $export] = $this->exportFrom('admin');
        $path = URL::signedRoute('filament.admin.capell.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false);

        $this->actingAs($user)->get('http://' . $startedOn . $path)->assertOk()->assertDownload('runtime-export.csv');
        $this->actingAs($user)->get('http://elsewhere.example.test' . $path)->assertNotFound();
        $this->actingAs($user)->get('http://' . explode(':', $startedOn)[0] . ':9443' . $path)->assertNotFound();
    }

    /**
     * The origin is the panel whose route served the creating request, not whichever panel Filament last set as
     * current: a record created while a host route serves the request has no panel origin and is refused.
     */
    public function test_an_export_created_while_a_host_route_serves_the_request_is_refused(): void
    {
        [$user, $url] = $this->exportFrom(null, null, currentPanel: 'admin');

        $this->actingAs($user)->get($url)->assertForbidden()->assertSee(__('capell-admin::message.transfer_origin_unknown'));
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [SecondCapellPanelProvider::class, DomainCapellPanelProvider::class, OtherDomainCapellPanelProvider::class, ForeignPanelProvider::class]);

        return $providers;
    }

    /**
     * The owner of an export started while the given panel was serving, the signed download URL Filament sends them, and the export.
     *
     * @return array{0: User, 1: string, 2: Export}
     */
    private function exportFrom(?string $panel, ?string $host = null, ?string $currentPanel = null): array
    {
        if ($currentPanel !== null) {
            Filament::setCurrentPanel(Filament::getPanel($currentPanel));
        }

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

        $user = $this->createUserWithRole('super_admin');
        $export = $this->startedIn($panel, $host, static function () use ($user): Export {
            $export = new Export;
            $export->forceFill(['file_disk' => 'local', 'file_name' => 'runtime-export', 'exporter' => 'exporter', 'total_rows' => 1, 'user_id' => $user->getKey()])->save();

            return $export;
        });

        Storage::fake('local');
        Storage::disk('local')->put($export->getFileDirectory() . '/headers.csv', "name\n");
        Storage::disk('local')->put($export->getFileDirectory() . '/0000000000000001.csv', "runtime\n");

        return [$user, URL::signedRoute('filament.exports.download', ['authGuard' => 'web', 'export' => $export, 'format' => 'csv'], absolute: false), $export];
    }

    /** @return array{0: User, 1: Import} */
    private function importFrom(string $panel): array
    {
        Schema::create('imports', static function (Blueprint $table): void {
            $table->id();
            $table->timestamp('completed_at')->nullable();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('importer');
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('total_rows');
            $table->unsignedInteger('successful_rows')->default(0);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
        Schema::create('failed_import_rows', static function (Blueprint $table): void {
            $table->id();
            $table->json('data');
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->text('validation_error')->nullable();
            $table->timestamps();
        });

        $user = $this->createUserWithRole('super_admin');
        $import = $this->startedIn($panel, null, static function () use ($user): Import {
            $import = new Import;
            $import->forceFill(['file_name' => 'runtime.csv', 'file_path' => 'runtime.csv', 'importer' => Importer::class, 'total_rows' => 1, 'user_id' => $user->getKey()])->save();

            return $import;
        });

        return [$user, $import];
    }

    /**
     * Create a record while a route the given panel registered serves the request, on the given host, as
     * Filament's actions do.
     *
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function startedIn(?string $panel, ?string $host, callable $create): mixed
    {
        $request = request();
        $servingHost = $request->headers->get('host');
        $resolver = $request->getRouteResolver();
        $route = $panel === null ? null : collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => PanelOwnership::of($route)?->ownedBy($panel) === true);
        $request->setRouteResolver(static fn (): ?RoutingRoute => $route);
        if ($host !== null) {
            $request->headers->set('host', $host);
        }

        try {
            return $create();
        } finally {
            $request->setRouteResolver($resolver ?? static fn (): null => null);
            $request->headers->set('host', $servingHost);
        }
    }
}
