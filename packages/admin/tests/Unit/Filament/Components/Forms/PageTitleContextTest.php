<?php

declare(strict_types=1);

use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Components\Forms\Page\TitleWithSlugInput;
use Capell\Admin\Filament\Components\Forms\SlugInput;
use Capell\Admin\Tests\Fixtures\Filament\PageContext\ActionPageContextResource;
use Capell\Admin\Tests\Fixtures\Filament\PageContext\CustomPageContextLivewire;
use Capell\Admin\Tests\Fixtures\Filament\PageContext\UnrelatedContextLivewire;
use Capell\Admin\Tests\Fixtures\Filament\PageContext\UnrelatedContextResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;

it('uses the page resource context for the page title field', function (bool $customPageResource, string $operation, ?string $actionResource, string $expectedPath): void {
    test()->actingAsAdmin();
    $language = Language::factory()->english()->createOne();
    $site = Site::factory()->language($language)->withTranslations([$language])->createOne();
    $livewire = $customPageResource ? new CustomPageContextLivewire : new UnrelatedContextLivewire;
    $livewire->data([
        'site_id' => $site->getKey(),
        'translations' => ['english' => ['language_id' => $language->getKey(), 'title' => 'Title', 'meta' => ['slug' => 'title']]],
    ]);
    $schema = Schema::make($livewire)->statePath('data')->operation($operation);
    $context = $actionResource === null ? null : ConfiguratorContextData::forCreate(ConfiguratorTypeEnum::Page, null, 'action', $actionResource);
    $schema->components([Repeater::make('translations')->schema([TitleWithSlugInput::makeForContext($schema, $context)])]);
    $repeater = $schema->getComponents()[0];
    assert($repeater instanceof Repeater);
    $grid = $repeater->getItems()['english']->getComponents()[0];
    assert($grid instanceof Grid);
    $group = $grid->getChildSchema()?->getComponents()[0];
    assert($group instanceof FusedGroup);
    $slug = $group->getChildSchema()?->getComponents()[1];
    assert($slug instanceof SlugInput);

    expect($slug->getBasePath())->toBe($expectedPath);
})->with([
    'custom page resource' => [true, 'create', null, '/custom/'],
    'unrelated resource' => [false, 'create', null, '/'],
    'embedded default page' => [true, 'createOption', null, '/'],
    'embedded distinct page resource' => [true, 'createOption', ActionPageContextResource::class, '/action/'],
    'embedded unrelated resource class' => [true, 'createOption', UnrelatedContextResource::class, '/'],
]);
