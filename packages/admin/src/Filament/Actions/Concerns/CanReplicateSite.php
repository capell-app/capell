<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Actions\Concerns;

use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\Site\AdditionalSiteLanguages;
use Capell\Admin\Filament\Components\Forms\Site\DefaultPagesCheckboxList;
use Capell\Admin\Filament\Components\Forms\Site\DomainsRepeater;
use Capell\Admin\Filament\Components\Forms\Site\DomainsSchema;
use Capell\Admin\Filament\Components\Forms\Site\LanguageSelect;
use Capell\Admin\Filament\Components\Forms\Site\Wizard\DetailsStep;
use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Contracts\Support\Htmlable;

trait CanReplicateSite
{
    /**
     * @return array<int, Wizard>
     */
    protected function getReplicaFormSchema(string|Htmlable|null $modalSubmitAction): array
    {
        return [
            Wizard::make([
                DetailsStep::make('details')
                    ->columns()
                    ->schema([
                        NameInput::make('name')
                            ->default(fn (string $operation): string => config('app.name')),
                        Grid::make()
                            ->columnSpanFull()
                            ->schema([
                                LanguageSelect::make('language_id'),
                                AdditionalSiteLanguages::make('languages')
                                    ->dehydrated(false),
                                Checkbox::make('copy_pages')
                                    ->label(__('capell-admin::form.copy_pages'))
                                    ->helperText(__('capell-admin::generic.copy_pages_helper'))
                                    ->afterStateUpdated(function (bool $state, Get $get, Set $set): void {
                                        if ($state && $get('setup_pages') !== true) {
                                            $set('setup_pages', true);
                                        }
                                    })
                                    ->reactive()
                                    ->dehydrated(false)
                                    ->default(true),
                                Checkbox::make('setup_pages')
                                    ->label(__('capell-admin::form.setup_pages'))
                                    ->helperText(__('capell-admin::generic.setup_pages_helper'))
                                    ->hiddenJs(<<<'JS'
                                         $get('copy_pages')
                                    JS)
                                    ->dehydrated(false)
                                    ->default(true),
                                Checkbox::make('copy_navigations')
                                    ->label(__('capell-admin::form.copy_navigations'))
                                    ->visibleJs(<<<'JS'
                                         $get('copy_pages') || $get('setup_pages')
                                     JS)
                                    ->dehydrated(false)
                                    ->default(true),
                                DefaultPagesCheckboxList::make('auto_create_pages')
                                    ->dehydrated(false)
                                    ->visibleJs(<<<'JS'
                                         ! $get('copy_pages') && $get('setup_pages')
                                     JS)
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Step::make(__('capell-admin::tab.domains'))
                    ->schema([
                        DomainsRepeater::make()
                            ->model(SiteDomain::class)
                            ->dehydrated(false)
                            ->saveRelationshipsUsing(fn (): bool => false)
                            ->schema(DomainsSchema::make()),
                    ]),
            ])
                ->extraAttributes([
                    'class' => 'minimal-wizard',
                ])
                ->submitAction($modalSubmitAction),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateReplicaRecordData(Site $record, array $data): array
    {
        $data = [
            'name' => $record->name . ' (' . __('capell-admin::generic.copy') . ')',
            'language_id' => $record->language_id,
            'copy_pages' => true,
            'copy_navigations' => true,
        ];

        $data['languages'] = $record->siteDomains
            ->where('language_id', '!=', $record->language_id)
            ->groupBy('language_id')
            ->pluck('language_id')
            ->toArray();

        return $data;
    }

    protected function replicateSiteAction(): mixed
    {
        $result = $this->process(function (Site $record, array $data): Site {
            $this->replica = SiteReplicatedAction::run($record, $this->getRawData());

            return $this->replica;
        });

        try {
            return $result;
        } finally {
            $this->success();
        }
    }
}
