<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Blueprint;

use Capell\Admin\Enums\BlueprintCreationModeEnum;
use Capell\Admin\Enums\ConfiguratorTypeEnum;
use Capell\Admin\Filament\Actions\CreateAction;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Filament\Support\Enums\Width;
use Override;

class CreateBlueprintAction extends CreateAction
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::generic.create_type'))
            ->slideOver()
            ->modalWidth(Width::ScreenLarge)
            ->mutateDataUsing(fn (array $data): array => $this->mutateFormData($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[Override]
    protected function mutateFormData(array $data): array
    {
        $creationMode = $this->resolveCreationMode($data['creation_mode'] ?? null);

        unset($data['creation_mode'], $data['edit_key']);

        if ($creationMode !== BlueprintCreationModeEnum::Basic) {
            $defaultBlueprintConfigurator = $this->defaultBlueprintConfiguratorFor(data_get($data, 'type'));

            data_set(
                $data,
                'admin.type_configurator',
                data_get($data, 'admin.type_configurator', $defaultBlueprintConfigurator),
            );

            return $data;
        }

        $data['status'] = (bool) ($data['status'] ?? true);
        $data['default'] = false;
        $data['admin'] = $this->basicAdminData($data['admin'] ?? []);
        $data['meta'] = [];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $admin
     * @return array<string, mixed>
     */
    private function basicAdminData(array $admin): array
    {
        return collect($admin)
            ->only(['icon', 'notes'])
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->all();
    }

    private function resolveCreationMode(mixed $value): ?BlueprintCreationModeEnum
    {
        if ($value instanceof BlueprintCreationModeEnum) {
            return $value;
        }

        return is_string($value) ? BlueprintCreationModeEnum::tryFrom($value) : null;
    }

    private function defaultBlueprintConfiguratorFor(mixed $type): string
    {
        $type = is_string($type) ? $type : null;
        $componentName = CapellCore::getPageTypes()
            ->first(fn (PageTypeData $pageType): bool => $pageType->name === $type)
            ?->getComponentName();

        $candidates = collect([
            $componentName,
            $type,
            is_string($componentName) ? str($componentName)->studly()->toString() : null,
            is_string($type) ? str($type)->studly()->toString() : null,
            is_string($type) ? str($type)->lower()->toString() : null,
            'Default',
        ])->filter()->unique();

        foreach ($candidates as $candidate) {
            if (AdminSurfaceLookup::hasConfigurator(ConfiguratorTypeEnum::Blueprint, $candidate)) {
                return $candidate;
            }
        }

        return 'Default';
    }
}
