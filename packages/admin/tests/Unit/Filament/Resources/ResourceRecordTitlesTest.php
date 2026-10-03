<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\BlockTemplates\BlockTemplateResource;
use Capell\Admin\Filament\Resources\Blueprints\BlueprintResource;
use Capell\Admin\Filament\Resources\Languages\LanguageResource;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Roles\RoleResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Admin\Filament\Resources\Users\UserResource;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/** @param class-string<resource> $resource */
it('uses the record name for named resource titles', function (string $resource, string $attribute): void {
    $model = $resource::getModel();
    $record = new $model;
    assert($record instanceof Model);
    $record->setRawAttributes(['id' => 1, $attribute => 'Harbour studio']);

    expect($resource::getRecordTitle($record))->toBe('Harbour studio');
})->with([
    'block templates' => [BlockTemplateResource::class, 'name'],
    'blueprints' => [BlueprintResource::class, 'name'],
    'languages' => [LanguageResource::class, 'name'],
    'layouts' => [LayoutResource::class, 'name'],
    'media' => [MediaResource::class, 'file_name'],
    'pages' => [PageResource::class, 'name'],
    'roles' => [RoleResource::class, 'name'],
    'sites' => [SiteResource::class, 'name'],
    'themes' => [ThemeResource::class, 'name'],
    'users' => [UserResource::class, 'name'],
]);
