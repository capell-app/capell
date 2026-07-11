<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Theme;

use Capell\Core\Actions\GetResourceAssetsAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;

class AssetsRepeater extends Repeater
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.assets'))
            ->defaultItems(0)
            ->itemLabel(fn (array $state): string => basename($state['file'] ?? ''))
            ->afterStateHydrated(function (Repeater $component, ?array $state): void {
                $component->state(
                    collect($state)
                        ->map(function (string|array $file): array {
                            if (is_array($file)) {
                                $file = $file['file'];
                            }

                            return ['file' => $file];
                        })
                        ->all(),
                );
            })
            ->mutateDehydratedStateUsing(fn (array $state): array => collect($state)->pluck('file')->toArray())
            ->simple(
                TextInput::make(name: 'file')
                    ->label(__('capell-admin::form.file'))
                    ->helperText(__('capell-admin::generic.theme_asset_file_info'))
                    ->datalist(GetResourceAssetsAction::run()),
            );
    }

    public static function getDefaultName(): ?string
    {
        return 'assets';
    }
}
