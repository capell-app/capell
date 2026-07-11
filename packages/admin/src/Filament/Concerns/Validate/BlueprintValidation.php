<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Concerns\Validate;

use Capell\Admin\Enums\ListenerEnum;
use Capell\Admin\Filament\Contracts\ValidatesDelete;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Admin\Filament\Resources\Sites\SiteResource;
use Capell\Admin\Filament\Resources\Themes\ThemeResource;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\Subscriber\SubscriberManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-implements ValidatesDelete
 */
trait BlueprintValidation
{
    /**
     * @param  Blueprint  $record
     */
    public function validateDelete(Model $record): bool
    {
        $blueprints = [
            'page' => 'pages',
            'site' => 'sites',
            'theme' => 'themes',
        ];

        foreach ($blueprints as $type => $relation) {
            $hasRelated = $record->newQuery()
                ->where('id', $record->getKey())
                ->has($relation)
                ->exists();

            if ($hasRelated === false) {
                continue;
            }

            $resourceClass = match ($type) {
                'page' => PageResource::class,
                'site' => SiteResource::class,
                'theme' => ThemeResource::class,
            };

            $error = __(
                'capell-admin::message.type_not_deletable',
                ['name' => $record->name, 'type' => $type],
            );

            $this->addError('data.type', $error);

            $countKey = $relation . '_count';
            $relatedCount = (int) $record->newQuery()
                ->where('id', $record->getKey())
                ->withCount($relation)
                ->value($countKey);

            Notification::make($type . '_type_not_deletable')
                ->warning()
                ->title($error)
                ->body(__(
                    sprintf('capell-admin::message.%s_type_not_deletable_info', $type),
                    ['count' => $relatedCount],
                ))
                ->actions([
                    Action::make('edit_' . $type)
                        ->label(__('capell-admin::button.edit'))
                        ->button()
                        ->url(resolve($resourceClass)::getUrl('edit', ['record' => $record])),
                ])
                ->send();

            return false;
        }

        return resolve(SubscriberManager::class)
            ->validateWithSubscribers(ListenerEnum::ValidateCustomType, $record);
    }
}
