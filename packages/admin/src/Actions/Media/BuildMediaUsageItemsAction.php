<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Media;

use Capell\Admin\Support\AdminSurfaceLookup;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Media;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

final class BuildMediaUsageItemsAction
{
    use AsAction;

    /**
     * @return list<array{label: string, title: string, url: string|null}>
     */
    public function handle(?Media $media): array
    {
        if (! $media instanceof Media) {
            return [];
        }

        $items = [];
        $owner = $media->model;

        if ($owner instanceof Model) {
            $items[] = $this->usageItem(
                label: (string) __('capell-admin::media.attached_to'),
                model: $owner,
            );
        }

        $relations = $media->assetRelations()
            ->with('related')
            ->orderBy('order')
            ->limit(12)
            ->get();

        foreach ($relations as $relation) {
            if (! $relation instanceof AssetAttachment) {
                continue;
            }

            $related = $relation->related;

            if (! $related instanceof Model) {
                continue;
            }

            $items[] = $this->usageItem(
                label: (string) __('capell-admin::media.used_on'),
                model: $related,
            );
        }

        return $items;
    }

    /**
     * @return array{label: string, title: string, url: string|null}
     */
    private function usageItem(string $label, Model $model): array
    {
        return [
            'label' => $label,
            'title' => $this->modelTitle($model),
            'url' => $this->resourceUrl($model),
        ];
    }

    private function modelTitle(Model $model): string
    {
        $name = $model->getAttribute('name');

        if (filled($name)) {
            return (string) $name;
        }

        return sprintf('%s #%s', Str::headline(class_basename($model)), $model->getKey());
    }

    private function resourceUrl(Model $model): ?string
    {
        $modelType = class_basename($model);
        $resource = AdminSurfaceLookup::resourceIfRegistered($modelType);

        if ($resource === null && $model instanceof Pageable) {
            $resource = AdminSurfaceLookup::resourceIfRegistered('Page', Str::lower($modelType));
        }

        if ($resource === null || ! $this->canEditResourceRecord($resource, $model)) {
            return null;
        }

        try {
            return $resource::getUrl('edit', ['record' => $model->getKey()]);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * @param  class-string<resource>  $resource
     */
    private function canEditResourceRecord(string $resource, Model $model): bool
    {
        try {
            return $resource::hasPage('edit') && $resource::canEdit($model);
        } catch (Throwable) {
            return false;
        }
    }
}
