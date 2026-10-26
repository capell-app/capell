<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Core\Models\Layout;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @method static Builder<Layout> run(?int $siteId)
 */
final class BuildPageLayoutOptionsQueryAction
{
    use AsFake;
    use AsObject;

    /** @return Builder<Layout> */
    public function handle(?int $siteId): Builder
    {
        /** @var Builder<Layout> $query */
        $query = LayoutResource::getEloquentQuery();

        return $query->withoutTrashed()
            ->where(fn (Builder $query): Builder => $query->whereNull('site_id')
                ->when($siteId !== null, fn (Builder $query): Builder => $query->orWhere('site_id', $siteId)))
            // An absent JSON key must remain selectable on MySQL, where JSON_CONTAINS returns NULL.
            ->where(fn (Builder $query): Builder => $query->whereNull('admin')
                ->orWhereJsonDoesntContainKey('admin->hidden_from_selection')
                ->orWhereJsonDoesntContain('admin->hidden_from_selection', true));
    }
}
