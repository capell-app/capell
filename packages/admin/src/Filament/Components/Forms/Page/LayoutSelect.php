<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Page;

use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Admin\Data\RecordStateData;
use Capell\Admin\Filament\Concerns\HasCustomSelectOption;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Layouts\Tables\LayoutsTable;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Data\Database\SqlFragment;
use Capell\Core\Facades\CapellDatabase;
use Capell\Core\Models\Layout;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LayoutSelect extends Select
{
    use HasCustomSelectOption;

    private const string GENERATED_PREVIEW_IMAGE = 'generated_preview_image';

    protected ?Closure $modifyQueryUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.layout'))
            ->required()
            ->allowHtml()
            ->searchable()
            ->relationship(
                name: 'layout',
                titleAttribute: 'name',
                modifyQueryUsing: fn (Builder $query, ?string $search = null) => $this->applyAccessibleLayoutQuery($query)
                    ->select([
                        'layouts.*',
                        LayoutsTable::getUsesCountSelect($query, 'pages_count'),
                    ])
                    ->with(['image'])
                    ->where(
                        // On MySQL, JSON_CONTAINS returns NULL for an absent key, so a
                        // layout whose `admin` JSON exists but lacks hidden_from_selection
                        // would be wrongly excluded (orWhereNull('admin') doesn't rescue a
                        // non-null admin). The key-presence guard includes the absent case.
                        fn (Builder $query) => $query->whereNull('admin')
                            ->orWhereJsonDoesntContainKey('admin->hidden_from_selection')
                            ->orWhereJsonDoesntContain('admin->hidden_from_selection', true),
                    )
                    ->when(
                        $search,
                        function (Builder $query, string $search): void {
                            $this->applySearchOrdering($query, $search);
                        },
                    )
                    ->when(
                        $this->modifyQueryUsing instanceof Closure,
                        fn (Builder $query): mixed => $this->evaluate($this->modifyQueryUsing, ['query' => $query]),
                    ),
            )
            ->rules([
                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if (blank($value)) {
                        return;
                    }

                    $layout = LayoutResource::getEloquentQuery()
                        ->whereKey($value)
                        ->first();

                    if (! $layout instanceof Layout) {
                        $fail(__('capell-admin::message.layout_not_accessible'));

                        return;
                    }

                    $siteId = $get('site_id');

                    if ($layout->site_id !== null && $layout->site_id !== (int) $siteId) {
                        $fail(__('capell-admin::message.layout_not_accessible'));
                    }
                },
            ])
            ->default(fn (): ?int => LayoutResource::getEloquentQuery()->default()->first(['id'])?->id)
            ->getOptionLabelFromRecordUsing(function (Layout $record): string {
                $pagesCountAttribute = $record->getAttributes()['pages_count'] ?? null;
                $hasPagesCount = is_numeric($pagesCountAttribute);
                $pagesCount = $hasPagesCount ? (int) $pagesCountAttribute : 0;
                $hasAuthoritativePagesCount = $hasPagesCount && $this->isUsageCountAuthoritative();

                $data = [
                    'label' => $record->name,
                    'count' => $pagesCount,
                    'states' => array_values(array_filter([
                        ! $record->status ? new RecordStateData(
                            key: 'disabled',
                            label: (string) __('capell-admin::form.disabled'),
                            description: (string) __('capell-admin::table.status'),
                            color: 'danger',
                            icon: Heroicon::OutlinedEyeSlash,
                            priority: 10,
                        ) : null,
                        $hasAuthoritativePagesCount && $pagesCount === 0 ? new RecordStateData(
                            key: 'unused',
                            label: (string) __('capell-admin::table.layout_usage_unused'),
                            description: (string) __('capell-admin::table.layout_usage_unused_tooltip'),
                            color: 'warning',
                            icon: Heroicon::OutlinedExclamationTriangle,
                            priority: 20,
                        ) : null,
                    ])),
                    'relationships' => [
                        new RecordRelationshipCountData(
                            key: 'pages',
                            label: (string) __('capell-admin::table.total_pages'),
                            count: $pagesCount,
                            authoritative: $hasAuthoritativePagesCount,
                        ),
                    ],
                ];

                $imageUrl = $this->layoutPreviewImageUrl($record);

                if ($imageUrl !== null) {
                    $data['image'] = $imageUrl;
                }

                return static::getSelectOption($record, $data);
            });
    }

    public function withEditLink(): static
    {
        return $this->hintAction(
            fn (): Action => Action::make('editLayout')
                ->label(__('capell-admin::button.edit'))
                ->tooltip(__('capell-admin::button.edit_layout'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->visible(fn (?int $state): bool => (bool) $state)
                ->color('gray')
                ->url(fn (?int $state): string => LayoutResource::getUrl('edit', ['record' => $state]), shouldOpenInNewTab: true),
        );
    }

    public function modifyQueryUsing(Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    public function getModifyQueryUsing(): ?Closure
    {
        return $this->modifyQueryUsing;
    }

    private function layoutPreviewImageUrl(Layout $layout): ?string
    {
        if ($layout->image !== null) {
            return $layout->image->getUrl();
        }

        $admin = $layout->admin;

        if (! is_array($admin)) {
            return null;
        }

        if (isset($admin['image']) && is_string($admin['image']) && $admin['image'] !== '') {
            return url('storage/' . $admin['image']);
        }

        $generatedPreviewImage = $admin[self::GENERATED_PREVIEW_IMAGE] ?? null;

        if (is_string($generatedPreviewImage) && $generatedPreviewImage !== '') {
            return Storage::disk('public')->url($generatedPreviewImage);
        }

        return null;
    }

    private function isUsageCountAuthoritative(): bool
    {
        $actor = auth()->user();

        return $actor instanceof Authenticatable && SiteScope::isGlobalActor($actor);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySearchOrdering(Builder $query, string $search): void
    {
        $titleAttribute = $this->getRelationshipTitleAttribute();

        if ($titleAttribute === null) {
            return;
        }

        $column = $this->wrappedSearchColumn($query, $titleAttribute);
        $relevance = CapellDatabase::for($query->getModel())
            ->queryDialect()
            ->textRelevance(SqlFragment::raw($column), $search);

        $relevance->applyOrder($query->getQuery());

        $query->orderBy($titleAttribute);
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function wrappedSearchColumn(Builder $query, string $titleAttribute): string
    {
        if (! preg_match('/^[A-Za-z_]\w*(?:\.[A-Za-z_]\w*)?$/', $titleAttribute)) {
            return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn('name'));
        }

        return $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($titleAttribute));
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function applyAccessibleLayoutQuery(Builder $query): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn($query->getModel()->getKeyName()),
            LayoutResource::getEloquentQuery()->select('id'),
        );
    }
}
