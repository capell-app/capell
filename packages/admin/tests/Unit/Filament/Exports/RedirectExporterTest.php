<?php

declare(strict_types=1);

use Capell\Admin\Filament\Exports\RedirectExporter;
use Capell\Core\Enums\RedirectStatusCodeEnum;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\PageUrl;
use Filament\Actions\Exports\Models\Export;

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('exports redirects with user friendly status and manual flags', function (): void {
    $redirect = PageUrl::factory()
        ->manualRedirect()
        ->create([
            'status' => false,
            'status_code' => RedirectStatusCodeEnum::Temporary,
            'url' => '/old-path',
            'target_url' => '/new-path',
            'hit_count' => 42,
            'notes' => 'Moved during launch',
        ])
        ->load(['site', 'language']);

    $columnNames = collect(RedirectExporter::getColumns())
        ->map->getName()
        ->all();

    $exporter = new RedirectExporter(
        new Export,
        array_combine($columnNames, $columnNames),
        [],
    );

    $row = array_combine($columnNames, $exporter($redirect));

    expect($row)
        ->toMatchArray([
            'url' => '/old-path',
            'target_url' => '/new-path',
            'status_code' => 302,
            'status' => 'disabled',
            'is_manual' => 'yes',
            'site.name' => $redirect->site->name,
            'language.name' => $redirect->language->name,
            'hit_count' => 42,
            'notes' => 'Moved during launch',
        ]);
});

it('scopes redirect exports to redirect URLs and reports completed rows', function (): void {
    $redirect = PageUrl::factory()->manualRedirect()->create(['url' => '/redirect-me']);
    PageUrl::factory()->type(UrlTypeEnum::Alias)->create(['url' => '/alias-only']);

    $export = new Export(['successful_rows' => 1234]);

    expect(RedirectExporter::modifyQuery(PageUrl::query())->pluck('id')->all())
        ->toBe([$redirect->getKey()])
        ->and(RedirectExporter::getCompletedNotificationBody($export))
        ->toBe('Redirect export complete.')
        ->and(RedirectExporter::getModel())
        ->toBe(PageUrl::class);
});
