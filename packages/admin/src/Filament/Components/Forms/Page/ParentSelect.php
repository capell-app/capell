<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Actions\Pages\BuildParentLanguageEligibilityAction;
use Capell\Admin\Contracts\Extenders\PageTableExtender;
use Capell\Admin\Data\Pages\ParentLanguageEligibilityData;
use Capell\Admin\Filament\Components\Forms\PageSelect;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Page;
use Capell\Core\Support\Permissions\SiteAccess;
use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Override;

class ParentSelect extends PageSelect
{
    private ?Schema $relationshipSchema = null;

    private bool $parentLanguageHintsConfigured = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHintEditAction()
            ->helperText(fn (Get $get): string => $this->languageHelp($get))
            ->modifySelectOptionsQueryUsing(fn (Builder $query): Builder => $this->modifyParentQuery($query))
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (blank($value)) {
                        return;
                    }

                    $parent = SiteAccess::current()->query(Page::class)
                        ->whereKey($value)
                        ->first();

                    if (! $parent instanceof Page) {
                        $fail(__('capell-admin::message.parent_page_not_accessible'));

                        return;
                    }

                    $siteId = $get('site_id');
                    if (blank($siteId)) {
                        $record = $this->getRecord();
                        $siteId = $record instanceof Pageable ? $record->site_id : null;
                    }

                    if ($siteId !== null && $parent->site_id !== (int) $siteId) {
                        $fail(__('capell-admin::message.parent_page_not_accessible'));

                        return;
                    }

                    $translations = $get('translations');
                    $eligibility = BuildParentLanguageEligibilityAction::run((int) $value, is_numeric($siteId) ? (int) $siteId : null, is_array($translations) ? $translations : []);
                    if (! $eligibility->compatible) {
                        $fail($eligibility->missingLanguages === []
                            ? __('capell-admin::parent_languages.unsupported_language')
                            : __('capell-admin::parent_languages.missing', ['languages' => implode(', ', $eligibility->missingLanguages)]));
                    }
                },
            ])
            ->hidden(function (Get $get): bool {
                $typeId = $get('blueprint_id');
                if ($typeId === null || $typeId === 0) {
                    return false;
                }

                $type = Blueprint::query()->pageType()->find($typeId);

                if ($type === null) {
                    return true;
                }

                return ($type->admin['without_parent_page'] ?? false) === true;
            });
    }

    #[Override]
    public function withHintEditAction(): static
    {
        if ($this->parentLanguageHintsConfigured) {
            return $this;
        }

        $this->parentLanguageHintsConfigured = true;

        return $this->hintActions([
            Action::make('edit-parent-translations')
                ->label(__('capell-admin::parent_languages.edit_parent'))
                ->openUrlInNewTab()
                ->url(fn (Get $get): ?string => $this->languageEligibility($get)?->editUrl)
                ->visible(fn (Get $get): bool => $this->languageEligibility($get)?->editUrl !== null),
            Action::make('recheck-parent-languages')
                ->label(__('capell-admin::parent_languages.recheck'))
                ->visible(fn (Get $get): bool => filled($get($this->getName())))
                ->action(fn (): null => null),
        ]);
    }

    #[Override]
    public function pageGroup(string|Closure $pageGroup): static
    {
        // The generic Pages workspace permits hierarchy across content groups.
        $this->pageGroup = is_string($pageGroup) && in_array($pageGroup, ['page', 'default'], true)
            ? null
            : $pageGroup;

        return $this;
    }

    public function setupRelation(string $relation, Schema $schema): self
    {
        $this->relationshipSchema = $schema;

        return $this;
    }

    public function qualifiedForeignKeyName(string $key): self
    {
        return $this;
    }

    private function languageEligibility(Get $get): ?ParentLanguageEligibilityData
    {
        $parentId = $get($this->getName());
        if (! is_numeric($parentId)) {
            return null;
        }

        $siteId = $get('site_id') ?? ($this->getRecord() instanceof Page ? $this->getRecord()->site_id : null);
        $translations = $get('translations');

        return BuildParentLanguageEligibilityAction::run(
            (int) $parentId,
            is_numeric($siteId) ? (int) $siteId : null,
            is_array($translations) ? $translations : [],
        );
    }

    private function languageHelp(Get $get): string
    {
        $base = (string) __('capell-admin::generic.parent_page_info');
        $eligibility = $this->languageEligibility($get);
        if (! $eligibility instanceof ParentLanguageEligibilityData) {
            return $base;
        }

        if (! $eligibility->accessible) {
            return $base . ' ' . __('capell-admin::parent_languages.inaccessible');
        }

        if ($eligibility->compatible) {
            return $base . ' ' . __('capell-admin::parent_languages.compatible');
        }

        if ($eligibility->missingLanguages === []) {
            return $base . ' ' . __('capell-admin::parent_languages.unsupported_language');
        }

        return $base . ' ' . __('capell-admin::parent_languages.missing', ['languages' => implode(', ', $eligibility->missingLanguages)])
            . ' ' . ($eligibility->editUrl === null ? __('capell-admin::parent_languages.ask_editor') : __('capell-admin::parent_languages.handoff'));
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    private function modifyParentQuery(Builder $query): Builder
    {
        $base = $query;

        foreach (app()->tagged(PageTableExtender::TAG) as $extender) {
            if ($extender instanceof PageTableExtender) {
                $base = $extender->modifyQuery($base);
            }
        }

        /** @var Builder<Page> $base */
        $record = $this->getRecord();

        if ($record instanceof Page) {
            $descendantIds = $record->descendants?->pluck('id')->toArray();
            $operation = $this->relationshipSchema?->getOperation();

            $base = $base->when(
                $operation !== 'replicate',
                fn (Builder $q): Builder => $q->where('pages.id', '!=', $record->id),
            )
                ->when(
                    $descendantIds !== null && count($descendantIds) > 0,
                    fn (Builder $q): Builder => $q->whereNotIn('pages.id', $descendantIds),
                );
        }

        return $base;
    }
}
