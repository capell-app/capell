<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Enums\PageUrlTypeEnum;
use Filament\Forms\Components\Radio;

class UrlTypeRadio
{
    public static function make(string $name): Radio
    {
        return Radio::make($name)
            ->label(__('capell-admin::form.type'))
            ->columnSpanFull()
            ->default('default')
            ->afterStateHydrated(function (Radio $component, ?string $state): void {
                if ($state === null) {
                    $component->state('default');
                }
            })
            ->dehydrateStateUsing(fn (string $state): ?string => $state === 'default' ? null : $state)
            ->options(PageUrlTypeEnum::options())
            ->descriptions([
                'alias' => __('capell-admin::generic.page_url_alias_info'),
                'redirect' => __('capell-admin::generic.page_url_redirect_info'),
            ]);
    }
}
