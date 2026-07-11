<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Roles\Pages\CreateRole;
use Capell\Admin\Filament\Resources\Roles\Pages\EditRole;
use Capell\Admin\Filament\Resources\Roles\Pages\ListRoles;
use Capell\Admin\Filament\Resources\Roles\Pages\ViewRole;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;

it('uses Capell shield role pages', function (): void {
    $pages = RoleResource::getPages();

    expect($pages['index']->getPage())->toBe(ListRoles::class)
        ->and($pages['create']->getPage())->toBe(CreateRole::class)
        ->and($pages['view']->getPage())->toBe(ViewRole::class)
        ->and($pages['edit']->getPage())->toBe(EditRole::class);
});

it('renders shield role form actions above the permission form', function (string $page): void {
    /** @var class-string<CreateRole|EditRole> $page */
    $pageInstance = new $page;
    $component = $pageInstance->getFormContentComponent();
    $childComponents = $component->getDefaultChildComponents();
    assert(is_array($childComponents));

    expect($pageInstance->hasFormWrapper())->toBeFalse()
        ->and($childComponents[0])->toBeInstanceOf(Actions::class)
        ->and($childComponents[1])->toBeInstanceOf(EmbeddedSchema::class);
})->with([
    'create role' => [CreateRole::class],
    'edit role' => [EditRole::class],
]);

it('keeps permission checkbox lists searchable for large role forms', function (): void {
    $component = RoleResource::getCheckboxListFormComponent(
        name: 'custom_permissions_tab',
        options: [
            'View:Page' => 'View Page',
            'Update:Page' => 'Update Page',
        ],
    );

    expect($component)->toBeInstanceOf(CheckboxList::class);

    assert($component instanceof CheckboxList);

    expect($component->isSearchable())->toBeTrue();
});
