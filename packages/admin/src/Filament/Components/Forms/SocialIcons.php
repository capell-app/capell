<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class SocialIcons extends Fieldset
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.social_links'))
            ->columns(1)
            ->schema([
                Repeater::make('social_links')
                    ->hiddenLabel()
                    ->grid(['md' => 2, 'lg' => 3])
                    ->collapsed()
                    ->itemLabel(function (array $state): ?string {
                        if (! array_key_exists('type', $state) || $state['type'] === '') {
                            return null;
                        }

                        return config('capell-admin.social_types')[$state['type']]['name'];
                    })
                    ->schema([
                        CustomSelectGroup::make(
                            name: 'type',
                            options: function (): array {
                                $options = [];
                                $social_blueprints = config('capell-admin.social_types', []);
                                foreach ($social_blueprints as $key => $social_type) {
                                    $options[$key] = $social_type['name'];
                                }

                                return $options;
                            },
                            modifySelectUsing: fn (Select $component): Select => $component
                                ->label(__('capell-admin::form.type'))
                                ->required()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                                    if (in_array($state, [null, '', '0'], true)) {
                                        return;
                                    }

                                    $urlSet = $get('url') !== null && $get('url') !== '';
                                    $iconSet = $get('icon') !== null && $get('icon') !== '';
                                    if ($urlSet || $iconSet) {
                                        return;
                                    }

                                    $social_type = config('capell-admin.social_types')[$state];
                                    $set('url', $social_type['url']);
                                    $set('icon', $social_type['icon'] ?? null);
                                }),
                        ),
                        Group::make()
                            ->visibleJs(<<<'JS'
                                 $get('type')
                             JS)
                            ->schema([
                                TextInput::make('url')
                                    ->label(__('capell-admin::form.url'))
                                    ->url()
                                    ->required()
                                    ->placeholder('https://')
                                    ->hintAction(
                                        Action::make('visit')
                                            ->color('gray')
                                            ->hiddenLabel()
                                            ->icon('heroicon-m-arrow-top-right-on-square')
                                            ->iconButton()
                                            ->size('sm')
                                            ->visible(fn (?string $state): bool => (bool) $state)
                                            ->url(url: fn (string $state): string => $state, shouldOpenInNewTab: true),
                                    ),
                                IconPicker::make('icon')
                                    ->label(__('capell-admin::form.icon')),
                                TextInput::make('title')
                                    ->label(__('capell-admin::form.title')),
                            ]),
                    ]),
            ]);
    }
}
