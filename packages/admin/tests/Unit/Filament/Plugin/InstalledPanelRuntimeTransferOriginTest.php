<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\DomainCapellPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\ForeignPanelProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\PackageSecurityDenyMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\SecondCapellPanelProvider;
use Capell\Tests\Fixtures\Models\User;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
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

    /** A Filament panel Capell does not extend keeps Filament's own download on the global route. */
    public function test_an_export_started_in_a_panel_capell_does_not_extend_downloads_through_filament(): void
    {
        [$user, $url] = $this->exportFrom('portal');

        $this->actingAs($user)->get($url)
            ->assertOk()
            ->assertDownload('runtime-export.csv');
    }

    /** A panel served on its own domain is sent an absolute URL on that domain. */
    public function test_an_export_started_in_a_panel_on_another_domain_is_sent_to_that_domain(): void
    {
        [$user, $url] = $this->exportFrom('desk');

        $download = (string) $this->actingAs($user)->get($url)->headers->get('Location');

        $this->assertSame(DomainCapellPanelProvider::DOMAIN, parse_url($download, PHP_URL_HOST));
        $this->assertStringStartsWith('/desk/capell/exports/', (string) parse_url($download, PHP_URL_PATH));
        $this->get($download)->assertOk()->assertDownload('runtime-export.csv');
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(AdminPanelProvider::class, $providers, true);
        array_splice($providers, $position === false ? count($providers) : $position + 1, 0, [SecondCapellPanelProvider::class, DomainCapellPanelProvider::class, ForeignPanelProvider::class]);

        return $providers;
    }

    /**
     * The owner of an export started while the given panel was serving, the signed download URL Filament sends them, and the export.
     *
     * @return array{0: User, 1: string, 2: Export}
     */
    private function exportFrom(string $panel): array
    {
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
        $export = $this->startedIn($panel, static function () use ($user): Export {
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
        $import = $this->startedIn($panel, static function () use ($user): Import {
            $import = new Import;
            $import->forceFill(['file_name' => 'runtime.csv', 'file_path' => 'runtime.csv', 'importer' => Importer::class, 'total_rows' => 1, 'user_id' => $user->getKey()])->save();

            return $import;
        });

        return [$user, $import];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function startedIn(string $panel, callable $create): mixed
    {
        $serving = Filament::getCurrentPanel();
        Filament::setCurrentPanel(Filament::getPanel($panel));
        try {
            return $create();
        } finally {
            Filament::setCurrentPanel($serving);
        }
    }
}
