<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Core\Models\Language;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
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
            Notification::make('language_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.language_not_deletable',
                    ['name' => $record->name],
                ))
                ->body(__(
                    'capell-admin::message.language_not_deletable_info',
                    ['count' => $record->sites()->count()],
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
}
