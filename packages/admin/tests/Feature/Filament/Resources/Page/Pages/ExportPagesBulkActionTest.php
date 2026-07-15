<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Filament\Resources\Pages\Actions\ExportPagesBulkAction;
use Capell\Admin\Filament\Resources\Pages\Pages\ListPages;
use Capell\Admin\Policies\PagePolicy;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();

    $relativeExportDirectory = 'framework/testing/backup-exports-' . uniqid();
    $exportDirectory = storage_path('app/' . $relativeExportDirectory);
    config()->set('backup.paths.exports', $relativeExportDirectory);

    test()->exportDirectory = $exportDirectory;

    app()->instance(PageExporter::class, new readonly class($exportDirectory) implements PageExporter
    {
        public function __construct(private string $exportDirectory) {}

        public function exportPages(array $pageIds, array $options): string
        {
            File::ensureDirectoryExists($this->exportDirectory);

            $path = $this->exportDirectory . '/capell-cms-pages-' . uniqid() . '.zip';
            File::put($path, 'fake export');

            return $path;
        }

        public function exportSites(array $siteIds, array $options): string
        {
            return $this->exportPages($siteIds, $options);
        }
    });
});

afterEach(function (): void {
    $exportDirectory = test()->exportDirectory ?? null;

    if (is_string($exportDirectory) && File::isDirectory($exportDirectory)) {
        File::deleteDirectory($exportDirectory);
    }
});

it('export pages bulk action form renders with defaults', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->mountAction(TestAction::make(ExportPagesBulkAction::class)->table()->bulk())
        ->assertSchemaStateSet([
            'include_translations' => true,
            'include_media' => true,
            'include_shared_relations' => true,
            'include_drafts' => false,
        ])
        ->assertActionDataSet([
            'include_translations' => true,
            'include_media' => true,
            'include_shared_relations' => true,
            'include_drafts' => false,
        ]);
});

it('export pages bulk action requires the page export permission', function (): void {
    Permission::findOrCreate('page.export');
    Gate::policy(Page::class, PagePolicy::class);

    test()->actingAsUser();

    expect(ExportPagesBulkAction::make()->model(Page::class)->isAuthorized())->toBeFalse();
});

it('export pages bulk action completes without errors using default form values', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(ExportPagesBulkAction::class)->table()->bulk(),
        )
        ->assertHasNoActionErrors()
        ->assertNotified();
});

it('export pages bulk action completes with shared relations disabled', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(ExportPagesBulkAction::class)->table()->bulk(),
            data: [
                'include_translations' => true,
                'include_media' => false,
                'include_shared_relations' => false,
                'include_drafts' => false,
            ],
        )
        ->assertHasNoActionErrors()
        ->assertNotified();
});

it('export pages bulk action writes a zip archive to the configured export path', function (): void {
    $pages = Page::factory()->count(2)->create();

    Livewire::test(ListPages::class)
        ->assertSuccessful()
        ->selectTableRecords($pages)
        ->callAction(
            TestAction::make(ExportPagesBulkAction::class)->table()->bulk(),
        )
        ->assertHasNoActionErrors();

    expect(test()->exportDirectory)->toBeDirectory();

    $files = File::files(test()->exportDirectory);

    expect($files)->toHaveCount(1)
        ->and($files[0]->getFilename())->toStartWith('capell-cms-pages-')
        ->and($files[0]->getFilename())->toEndWith('.zip');
});
