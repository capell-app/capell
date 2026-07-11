<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Data\AssetData;
use Capell\Core\Facades\CapellCore;
use Filament\Forms\Components\Select;
use Override;

class AssetTypeSelect extends Select
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(
            fn (self $component): string => $component->isMultiple()
                ? __('capell-admin::form.asset_types')
                : __('capell-admin::form.asset_type'),
        )
            ->helperText(__('capell-admin::generic.asset_type_info'))
            ->options(
                fn (): array => CapellCore::getAssets()->mapWithKeys(
                    fn (AssetData $asset): array => [$asset->getKey() => $asset->getLabel()],
                )
                    ->all(),
            );
    }
}
