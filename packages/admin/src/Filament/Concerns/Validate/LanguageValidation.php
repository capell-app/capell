<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\SiteAccess;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements ValidatesDelete
 */
trait LanguageValidation
{
    /**
     * @param  Language  $record
     */
    public function validateDelete(Model $record): bool
    {
        // Default
        if ($record->sites()->exists() || $record->sitesLanguage()->exists()) {
            $siteCount = $this->languageSiteCount($record);

            Notification::make('language_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.language_not_deletable',
                    ['name' => e($record->name)],
                ))
                ->body(trans_choice(
                    'capell-admin::message.language_not_deletable_info',
                    $siteCount,
                    ['count' => $siteCount],
                ))
                ->actions([
                    Action::make('sites')
                        ->label(__('capell-admin::button.view_sites'))
                        ->button()
                        ->url(SiteResource::getUrl(
                            'index',
                            ['filters[filter][language_id]' => $record->id],
                        )),
                ])
                ->send();

            return false;
        }

        return true;
    }

    /**
     * Sites in the actor's scope that use the language through a domain or as their own language,
     * each counted once however many domains it has in that language.
     */
    private function languageSiteCount(Language $language): int
    {
        return SiteAccess::current()
            ->query(Site::class)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('siteDomains', fn (Builder $domains): Builder => $domains->where('language_id', $language->getKey()))
                ->orWhere('language_id', $language->getKey()))
            ->count();
    }
}
