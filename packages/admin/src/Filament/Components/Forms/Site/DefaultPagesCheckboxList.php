<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Core\Data\DefaultPageData;
use Capell\Core\Facades\CapellCore;
use Filament\Forms\Components\CheckboxList;
use Filament\Support\Enums\GridDirection;
use Override;

class DefaultPagesCheckboxList extends CheckboxList
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $defaultPages = CapellCore::getDefaultPages();

        $this->label(__('capell-admin::form.default_pages'))
            ->default($defaultPages->keys()->all())
            ->columns()
            ->gridDirection(GridDirection::Row)
            ->options(
                $defaultPages->mapWithKeys(function (DefaultPageData $pageData, string $key): array {
                    $label = $pageData->label ?? str($key)->title()->toString();

                    return [$key => $label];
                })
                    ->all(),
            )
            ->bulkToggleable();
    }
}
