<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Core\Models\Contracts\Defaultable;
use Capell\Core\Support\CapellCoreHelper;
use Filament\Forms\Components\Toggle;

class DefaultToggle extends Toggle
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(
            fn (string $model): string => __('capell-admin::form.default_toggle_label', [
                'label' => $this->getModelLabel($model),
            ]),
        )
            ->inline()
            ->default(fn (string $model, self $component): bool => ! $component->recordDefaultExists($model))
            ->visible(
                fn (?Defaultable $record, string $model, self $component): bool => ($record instanceof Defaultable && $record->isDefault())
                    || ! $component->recordDefaultExists($model),
            );
    }

    private function recordDefaultExists(string $model): bool
    {
        return CapellCoreHelper::modelDefaultExists($model);
    }

    private function getModelLabel(string $model): string
    {
        return str(class_basename($model))->headline()->toString();
    }
}
