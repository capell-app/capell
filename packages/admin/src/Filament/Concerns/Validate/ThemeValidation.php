<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Models\Theme;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements ValidatesDelete
 */
trait ThemeValidation
{
    /**
     * @param  Theme  $record
     */
    public function validateDelete(Model $record): bool
    {
        if ($record->sites()->exists()) {
            Notification::make('theme_not_deletable')
                ->warning()
                ->title(__(
                    'capell-admin::message.theme_not_deletable',
                    ['name' => $record->name],
                ))
                ->body(__(
                    'capell-admin::message.theme_not_deletable_info',
                    ['count' => $record->sites->count()],
                ))
                ->actions([
                    Action::make('sites')
                        ->label(__('capell-admin::button.view_sites'))
                        ->button()
                        ->url(AdminSurfaceLookup::resource(ResourceEnum::Site)::getUrl(
                            'index',
                            ['filters[theme_id][value]' => $record->id],
                        )),
                ])

                ->send();

            return false;
        }

        return true;
    }
}
