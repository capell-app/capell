<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Data\Configurators\ConfiguratorContextData;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Support\Configurators\ConfiguratorResolver;
use Capell\Core\Models\Page;
use Filament\Forms\Components\Hidden;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Auth\Authenticatable;

class CreatePageSchema
{
    /**
     * @return array<int, mixed>
     */
    public static function make(Schema $schema, ?ConfiguratorContextData $context = null): array
    {
        if (! in_array($schema->getOperation(), ['create', 'createOption', 'replicate'], true)) {
            return [];
        }

        return [
            Section::make()
                ->columns()
                ->columnSpanFull()
                ->heading(__('capell-admin::form.page_setup'))
                ->description(__('capell-admin::generic.page_setup_description'))
                ->schema([
                    NameInput::make('name')
                        ->withTitleUpdater(),
                    Hidden::make('blueprint_id')
                        ->default(static::resolveTypeId($context))
                        ->dehydrated(),
                    Hidden::make('system_pages')
                        ->label(__('capell-admin::form.hide_system_pages'))
                        ->dehydrated(false)
                        ->default(true)
                        ->visible(function (): bool {
                            $user = auth()->user();

                            if (! $user instanceof Authenticatable) {
                                return false;
                            }

                            return $user->hasRole(Utils::getSuperAdminName());
                        }),
                    SiteSelect::make(),
                    static::getParentPageSelect($schema),
                ])
                ->contained(in_array($schema->getOperation(), ['create', 'edit'], true)),
        ];
    }

    protected static function resolveTypeId(?ConfiguratorContextData $context = null): int|string|null
    {
        $resolver = resolve(ConfiguratorResolver::class);

        if (filled($context?->typeKey)) {
            return $resolver->resolveTypeByKey(
                $context->typeKey,
                ConfiguratorTypeEnum::Page,
                $context->resourceName,
            )->getKey();
        }

        return Page::getDefaultType($context?->resourceName)?->getKey();
    }

    protected static function getParentPageSelect(Schema $schema): ParentSelect
    {
        /** @var EditRecord|CreateRecord|ListRecords $livewire */
        $livewire = $schema->getLivewire();

        return ParentSelect::make('parent_id')
            ->label(__('capell-admin::form.parent_page'))
            ->helperText(__('capell-admin::generic.parent_page_info'))
            ->setupRelation('parent', $schema)
            ->when(
                true,
                function (ParentSelect $component) use ($livewire): ParentSelect {
                    $resource = $livewire->getResource();
                    $pageGroup = is_a($resource, PageResource::class, true)
                        ? $resource::getResourceName()
                        : $resource;

                    if ($pageGroup !== null) {
                        $component->pageGroup($pageGroup);
                    }

                    return $component;
                },
            )
            ->reactive();
    }
}
