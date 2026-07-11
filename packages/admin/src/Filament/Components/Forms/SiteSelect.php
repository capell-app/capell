<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Admin\Support\SiteScope;
use Capell\Core\Models\Site;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;

class SiteSelect extends Select
{
    protected string $optionKey = 'id';

    protected string $optionLabel = 'name';

    private ?Closure $modifyQueryUsing = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.site'))
            ->allowHtml()
            ->preload()
            ->searchable()
            ->rules([
                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (blank($value)) {
                        return;
                    }

                    $siteIds = collect(Arr::wrap($value))
                        ->filter(fn (mixed $siteId): bool => is_numeric($siteId))
                        ->map(fn (mixed $siteId): int => (int) $siteId)
                        ->values();

                    $sites = Site::query()
                        ->whereKey($siteIds)
                        ->get();

                    if ($siteIds->count() !== $sites->count()) {
                        $fail(__('capell-admin::message.site_not_accessible'));

                        return;
                    }

                    $actor = auth()->user();
                    $hasInaccessibleSite = $sites->contains(
                        fn (Site $site): bool => ! SiteScope::actorCanUseSite($actor, $site),
                    );

                    if ($hasInaccessibleSite) {
                        $fail(__('capell-admin::message.site_not_accessible'));
                    }
                },
            ])
            ->options(
                fn (self $component): SupportCollection => $this->getSites($this->modifyQueryUsing ?? null)
                    ->pluck($component->optionLabel, $component->optionKey),
            )
            ->default(function (Select $component): mixed {
                $default = array_key_first($component->getOptions());
                if ($component->isMultiple()) {
                    return [$default];
                }

                return $default;
            });
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

    public function withEditLink(): static
    {
        return $this->hintAction(
            Action::make('edit')
                ->label(__('capell-admin::button.edit'))
                ->tooltip(__('capell-admin::button.edit_site'))
                ->icon(Heroicon::PencilSquare)
                ->visible(fn (?int $state, string $operation): bool => $operation === 'edit' && $state !== null && $state !== 0)
                ->color('gray')
                ->url(
                    fn (?string $state): string => AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $state]),
                    shouldOpenInNewTab: true,
                ),
        );
    }

    public function modifyQueryUsing(Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @return Collection<int, Site>
     */
    private function getSites(?Closure $modifyQueryUsing = null): Collection
    {
        /** @var class-string<Site> $model */
        $model = Site::class;

        $query = SiteScope::applyForCurrentActor($model::query(), 'id');
        if ($modifyQueryUsing instanceof Closure) {
            $query = $this->evaluate($modifyQueryUsing, ['query' => $query]);
        }

        return $query->get();
    }
}
