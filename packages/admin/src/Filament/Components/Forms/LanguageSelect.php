<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Actions\SetupSiteLanguageAction;
use Capell\Admin\Filament\Resources\Languages\Schemas\LanguageForm;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Services\RelationshipJoiner;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Override;

class LanguageSelect extends Select
{
    protected string $optionKey = 'id';

    protected string $optionLabel = 'name';

    private ?Closure $modifyRelationQueryUsing = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.language'))
            ->native();
    }

    public function optionKey(string $key): static
    {
        $this->optionKey = $key;

        return $this;
    }

    public function optionLabel(string $label): static
    {
        $this->optionLabel = $label;

        return $this;
    }

    public function withOptions(): static
    {
        return $this->options(
            fn (self $component): Collection => Language::query()
                ->orderByDesc('default')
                ->orderBy($component->optionLabel)
                ->pluck($component->optionLabel, $component->optionKey),
        )
            ->default(function (self $component): mixed {
                $default = array_key_first($component->getOptions());
                if ($component->isMultiple()) {
                    return [$default];
                }

                return $default;
            });
    }

    public function withRelationship(): static
    {
        return $this->relationship(
            name: 'language',
            titleAttribute: $this->optionLabel,
            modifyQueryUsing: function (self $component, Builder $query): Builder {
                $query->orderByDesc('default')
                    ->orderBy('name');

                if ($component->getModifyRelationQueryUsing() instanceof Closure) {
                    $component->evaluate($component->getModifyRelationQueryUsing(), [
                        'query' => $query,
                        'search' => null,
                    ]);
                }

                return $query;
            },
        )
            ->preload()
            ->default(function (self $component): mixed {
                if ($component->hasRelationship()) {
                    $relationship = $component->getRelationship();

                    if (! $relationship instanceof Relation) {
                        return null;
                    }

                    $relationshipQuery = resolve(RelationshipJoiner::class)->prepareQueryForNoConstraints($relationship);

                    return $relationshipQuery->value($component->optionKey);
                }

                return array_key_first($component->getOptions());
            });
    }

    public function getModifyRelationQueryUsing(): ?Closure
    {
        return $this->modifyRelationQueryUsing;
    }

    public function modifyRelationQueryUsing(?Closure $callback): static
    {
        $this->modifyRelationQueryUsing = $callback;

        return $this;
    }

    public function withCreateForm(): self
    {
        return $this->createOptionForm(fn (Schema $schema): Schema => LanguageForm::configure($schema))
            ->createOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(__('capell-admin::generic.language'))
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.created_successfully',
                            ['name' => $this->modalHeadingText($action)],
                        ),
                    )
                    ->after(function (Action $action, array $data, Language $record): void {
                        $shouldSetup = isset($data['setup']) && $data['setup'] === true;
                        $setupSites = $data['setup_sites'] ?? [];

                        if ($shouldSetup && is_array($setupSites) && $setupSites !== []) {
                            /** @var Builder<Site> $sitesQuery */
                            $sitesQuery = SiteScope::applyForCurrentActor(Site::query(), 'id');

                            $sitesQuery
                                ->whereIn('id', $setupSites)
                                ->each(function (Site $site) use ($record): void {
                                    SetupSiteLanguageAction::run($site, $record);
                                });
                        }

                        $action->success();
                    }),
            );
    }

    public function withEditForm(): self
    {
        return $this->fillEditOptionActionFormUsing(static function (self $component): array {
            $record = $component->getSelectedRecord();

            return $record?->attributesToArray() ?? [];
        })
            ->editOptionForm(fn (Schema $schema): Schema => LanguageForm::configure($schema))
            ->editOptionAction(
                fn (Action $action): Action => $action
                    ->modalHeading(__('capell-admin::generic.language'))
                    ->modalWidth(Width::ScreenMedium)
                    ->successNotificationTitle(
                        fn (Action $action): string => __(
                            'capell-admin::notification.updated_successfully',
                            ['name' => $this->modalHeadingText($action)],
                        ),
                    )
                    ->after(function (Action $action): void {
                        $action->success();
                    }),
            );
    }

    private function modalHeadingText(Action $action): string
    {
        $heading = $action->getModalHeading();

        return $heading instanceof Htmlable ? $heading->toHtml() : $heading;
    }
}
