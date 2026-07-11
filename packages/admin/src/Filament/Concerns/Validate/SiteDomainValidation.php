<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\SiteDomain;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

trait SiteDomainValidation
{
    /**
     * @param  array<string, string|null>  $urlParts
     */
    public static function validateExists(array $urlParts, ?SiteDomain $record = null): bool
    {
        /** @var class-string<SiteDomain> $model */
        $model = SiteDomain::class;
        $scheme = $urlParts['scheme'] ?? null;
        $host = $urlParts['host'] ?? null;
        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        // Check unique multiple columns
        $query = $model::query()
            ->when(
                $scheme !== null,
                fn (Builder $query): Builder => $query
                    ->where(fn (Builder $schemeQuery): Builder => $schemeQuery
                        ->whereNull('scheme')
                        ->orWhere('scheme', $scheme)),
            )
            ->when(
                $host === null,
                fn (Builder $query): Builder => $query->whereNull('domain'),
                fn (Builder $query): Builder => $query
                    ->where(fn (Builder $domainQuery): Builder => $domainQuery
                        ->where('domain', $host)
                        ->when(
                            $host === $appUrlHost,
                            fn (Builder $domainQuery): Builder => $domainQuery->orWhereNull('domain'),
                        )),
            )
            ->where('path', $urlParts['path'] ?? null)
            ->withWhereHas('site');

        if ($record instanceof SiteDomain) {
            $query->whereKeyNot($record->id);
        }

        $matchSite = $query->first();

        if ($matchSite !== null) {
            Notification::make('site_domain_unique')
                ->warning()
                ->title(__('capell-admin::message.site_domain_not_unique', ['name' => $matchSite->site->name]))
                ->body($matchSite->full_url)
                ->actions([
                    Action::make('editSite')
                        ->label(__('capell-admin::button.edit_site'))
                        ->button()
                        ->icon('heroicon-o-pencil-square')
                        ->url(AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl('edit', ['record' => $matchSite->site_id])),
                ])
                ->send();

            return false;
        }

        return true;
    }
}
