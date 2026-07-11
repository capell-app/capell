<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Backup\PageExporter;
use Capell\Admin\Filament\Resources\Pages\Actions\ExportPageAction;
use Capell\Admin\Filament\Resources\Sites\Actions\ExportSiteAction;
use Capell\Admin\Support\Backup\NullPageExporter;
use Capell\Admin\Support\Backup\PageExportOptions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

it('builds page export options from action data with safe defaults', function (): void {
    $action = new class('export') extends ExportPageAction
    {
        /**
         * @param  array<string, bool|string|null>  $data
         * @return array<string, bool|string|null>
         */
        public function exposeBuildOptions(array $data): array
        {
            return $this->buildOptions($data);
        }
    };

    expect($action->exposeBuildOptions([
        'include_translations' => false,
        'include_media' => false,
        'include_shared_relations' => false,
        'include_all_contexts' => true,
        'note' => 'Release snapshot',
    ]))->toBe([
        'include_translations' => false,
        'include_media' => false,
        'include_shared_relations' => false,
        'include_all_contexts' => true,
        'note' => 'Release snapshot',
    ])
        ->and($action->exposeBuildOptions([]))->toBe([
            'include_translations' => true,
            'include_media' => true,
            'include_shared_relations' => true,
            'include_all_contexts' => false,
            'note' => null,
        ]);
});

it('shows page and site export actions only when a real exporter is registered', function (): void {
    app()->instance(PageExporter::class, new NullPageExporter);

    expect(ExportPageAction::make()->authorize(true)->isVisible())->toBeFalse()
        ->and(ExportSiteAction::make()->authorize(true)->isVisible())->toBeFalse();

    app()->instance(PageExporter::class, new class implements PageExporter
    {
        public function exportPages(array $pageIds, array $options): string
        {
            return '/tmp/page-export.zip';
        }

        public function exportSites(array $siteIds, array $options): string
        {
            return '/tmp/site-export.zip';
        }
    });

    expect(ExportPageAction::make()->authorize(true)->isVisible())->toBeTrue()
        ->and(ExportSiteAction::make()->authorize(true)->isVisible())->toBeTrue();
});

it('configures page and site export option forms', function (): void {
    app()->instance(PageExporter::class, new NullPageExporter);

    $pageSchema = ExportPageAction::make()->getSchema(Schema::make())?->getComponents();
    $siteSchema = ExportSiteAction::make()->getSchema(Schema::make())?->getComponents();
    assert(is_array($pageSchema));
    assert(is_array($siteSchema));
    assert(count($pageSchema) >= 5);
    assert(count($siteSchema) >= 4);

    expect($pageSchema)->toHaveCount(5)
        ->and($pageSchema[0])->toBeInstanceOf(Checkbox::class)
        ->and(filamentObjectName($pageSchema[0]))->toBe('include_translations')
        ->and(filamentObjectName($pageSchema[1]))->toBe('include_media')
        ->and(filamentObjectName($pageSchema[2]))->toBe('include_shared_relations')
        ->and(filamentObjectName($pageSchema[3]))->toBe('include_all_contexts')
        ->and($pageSchema[4])->toBeInstanceOf(Textarea::class)
        ->and(filamentObjectName($pageSchema[4]))->toBe('note')
        ->and($siteSchema)->toHaveCount(4)
        ->and(filamentObjectName($siteSchema[0]))->toBe('include_translations')
        ->and(filamentObjectName($siteSchema[1]))->toBe('include_media')
        ->and(filamentObjectName($siteSchema[2]))->toBe('include_shared_relations')
        ->and($siteSchema[3])->toBeInstanceOf(Textarea::class)
        ->and(filamentObjectName($siteSchema[3]))->toBe('note');
});

it('can preserve the legacy site bulk export option payload shape', function (): void {
    expect(PageExportOptions::resolve([
        'include_translations' => true,
        'include_media' => false,
        'include_shared_relations' => false,
        'note' => 'Sites',
    ], omitAllContexts: true))->toBe([
        'include_translations' => true,
        'include_media' => false,
        'include_shared_relations' => false,
        'note' => 'Sites',
    ]);
});
