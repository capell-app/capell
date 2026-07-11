<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Core\Models\Language;
use Filament\Forms\Components\CheckboxList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\GridDirection;
use Override;

class AdditionalSiteLanguages extends CheckboxList
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.additional_languages'))
            ->options(
                fn (Get $get): array => Language::query()
                    ->ordered()
                    ->pluck('name', 'id')
                    ->all(),
            )
            ->columns()
            ->gridDirection(GridDirection::Row)
            ->disableOptionWhen(fn (Get $get, int $value): bool => (int) $get('language_id') === $value);
    }
}
