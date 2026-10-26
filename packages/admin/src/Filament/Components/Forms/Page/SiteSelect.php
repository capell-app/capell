<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Actions\Pages\ReconcilePageSiteTranslationsAction;
use Capell\Admin\Actions\Pages\ResolvePageCreationSiteAction;
use Capell\Admin\Data\Pages\PageSiteTranslationsData;
use Capell\Admin\Filament\Components\Forms\SiteSelect as BaseSiteSelect;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Override;

class SiteSelect extends BaseSiteSelect
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->required()
            ->helperText(function (Get $get): string {
                $transition = $this->translationsForSite($get);

                return $transition instanceof PageSiteTranslationsData && $transition->unsupportedLanguageIds !== []
                    ? __('capell-admin::generic.page_site_info') . ' ' . __('capell-admin::generic.page_site_unsupported_languages', [
                        'languages' => implode(', ', $transition->unsupportedLanguageNames),
                    ])
                    : __('capell-admin::generic.page_site_info');
            })
            ->reactive()
            ->default(fn (): ?int => ResolvePageCreationSiteAction::run()?->id)
            ->hiddenOn(['edit', 'editOption'])
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    $transition = $this->translationsForSite($get);

                    if ($transition instanceof PageSiteTranslationsData && $transition->unsupportedLanguageIds !== []) {
                        $fail(__('capell-admin::message.page_site_unsupported_languages'));
                    }
                },
            ])
            ->hintAction(
                Action::make('useSiteLanguages')
                    ->label(__('capell-admin::button.use_site_languages'))
                    ->visible(function (Get $get): bool {
                        $transition = $this->translationsForSite($get);

                        return $transition instanceof PageSiteTranslationsData && $transition->unsupportedLanguageIds !== [];
                    })
                    ->requiresConfirmation()
                    ->modalDescription(__('capell-admin::generic.page_site_remove_languages_confirmation'))
                    ->action(function (Get $get, Set $set): void {
                        $transition = $this->translationsForSite($get, removeUnsupported: true);

                        if ($transition instanceof PageSiteTranslationsData) {
                            $repeater = $this->getRootContainer()->getComponentByStatePath(
                                $this->resolveRelativeStatePath('translations'),
                                withHidden: true,
                                withAbsoluteStatePath: true,
                            );
                            $previousTranslations = $get('translations');
                            $previousKeys = is_array($previousTranslations) ? array_keys($previousTranslations) : [];
                            $activeKey = $repeater instanceof TranslationsRepeater
                                ? ($previousKeys[$repeater->getActiveTab() - 1] ?? null)
                                : null;

                            $set('translations', $transition->translations);

                            if ($repeater instanceof TranslationsRepeater) {
                                $position = array_search($activeKey, array_keys($transition->translations), true);
                                $tabId = $position === false ? 1 : $position + 1;
                                $livewire = $repeater->getLivewire();
                                data_set($livewire, $repeater->getActiveTabStatePath(), $tabId);
                                $livewire->dispatch('refresh-tabs', tabId: $tabId, statePath: $repeater->getStatePath());
                            }

                            $this->reconcileSelections($get, $set);
                        }
                    }),
            )
            ->afterStateUpdated(function (Get $get, Set $set): void {
                $transition = $this->translationsForSite($get);

                if (! $transition instanceof PageSiteTranslationsData) {
                    return;
                }

                $set('translations', $transition->translations);
                $this->reconcileSelections($get, $set);
            });
    }

    #[Override]
    public static function getDefaultName(): ?string
    {
        return 'site_id';
    }

    private function translationsForSite(Get $get, bool $removeUnsupported = false): ?PageSiteTranslationsData
    {
        if (! in_array($this->getContainer()->getOperation(), ['create', 'createOption', 'replicate'], true)) {
            return null;
        }

        $siteId = $get('site_id');

        if (blank($siteId)) {
            return null;
        }

        $translations = $get('translations');
        $name = $get('name');

        return ReconcilePageSiteTranslationsAction::run(
            siteId: (int) $siteId,
            translations: is_array($translations) ? $translations : [],
            name: is_string($name) ? $name : '',
            removeUnsupported: $removeUnsupported,
        );
    }

    private function reconcileSelections(Get $get, Set $set): void
    {
        $cleared = [];

        foreach (['parent_id', 'layout_id'] as $field) {
            $value = $get($field);
            $select = $this->getRootContainer()->getComponentByStatePath(
                $this->resolveRelativeStatePath($field),
                withHidden: true,
                withAbsoluteStatePath: true,
            );

            if (filled($value) && ($select instanceof ParentSelect || $select instanceof LayoutSelect)
                && ! $select->isOptionEligible((int) $value)) {
                $set($field, null);
                if ($field === 'parent_id') {
                    $cleared[] = __('capell-admin::form.parent_page');
                } else {
                    $cleared[] = __('capell-admin::form.layout');
                }
            }
        }

        if ($cleared !== []) {
            Notification::make('page_site_choices_cleared')
                ->warning()
                ->title(__('capell-admin::message.page_site_choices_cleared'))
                ->body(__('capell-admin::generic.page_site_choices_cleared', ['fields' => implode(', ', $cleared)]))
                ->send();
        }
    }
}
