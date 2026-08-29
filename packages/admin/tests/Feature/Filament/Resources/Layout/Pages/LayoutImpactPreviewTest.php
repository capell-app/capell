<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Layouts\Pages\EditLayout;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('layout');

it('shows the saved content impact before a layout is saved', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Shared landing']);

    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);

    Livewire::test(EditLayout::class, [
        'record' => $layout->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::generic.layout_impact_preview'))
        ->assertSee(__('capell-admin::generic.layout_impact_preview_exact'))
        ->assertSee('Shared landing')
        ->assertSee('http://example.test/landing')
        ->assertSee(__('capell-admin::generic.layout_impact_preview_cache'))
        ->assertSee(__('capell-admin::generic.layout_impact_preview_reversibility'));
});
