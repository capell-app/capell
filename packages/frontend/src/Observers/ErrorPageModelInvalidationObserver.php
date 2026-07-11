<?php

declare(strict_types=1);

namespace Capell\Frontend\Observers;

use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Translation;
use Capell\Frontend\Actions\RegenerateSiteErrorPagesAction;
use Capell\Frontend\Support\Error\ErrorPageRegenerationQueue;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Targeted regeneration of a site's static error pages when an
 * error-page-relevant model changes. Bridges raw `eloquent.*` events to the
 * RegenerateSiteErrorPagesAction, with a tight relevance gate so unrelated
 * saves never trigger regeneration. Never throws out of the observer.
 */
final class ErrorPageModelInvalidationObserver
{
    /**
     * @param  array<int, mixed>  $payload
     */
    public function createdFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->handleChange($model, isUpdate: false);
        }
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function updatedFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->handleChange($model, isUpdate: true);
        }
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    public function deletedFromEvent(string $eventName, array $payload): void
    {
        $model = $this->modelFromPayload($payload);

        if ($model instanceof Model) {
            $this->handleChange($model, isUpdate: false);
        }
    }

    private function handleChange(Model $model, bool $isUpdate): void
    {
        try {
            if ($isUpdate && $this->isTimestampOnlyUpdate($model)) {
                return;
            }

            $siteId = $this->resolveSiteId($model);

            if ($siteId === null) {
                return;
            }

            $this->dispatch($siteId);
        } catch (Throwable) {
            // Never block a model save because of error-page regeneration.
        }
    }

    /**
     * Resolve the affected site id from the changed model, or null when the
     * change is not relevant to the site's static error pages.
     */
    private function resolveSiteId(Model $model): ?int
    {
        if ($model instanceof Site) {
            return $this->integerKey($model->getKey());
        }

        if ($model instanceof SiteDomain) {
            return $this->integerKey($model->site_id);
        }

        if ($model instanceof Page) {
            return $model->isErrorPage() ? $this->integerKey($model->site_id) : null;
        }

        if ($model instanceof Translation) {
            return $this->resolveSiteIdFromTranslation($model);
        }

        if ($model instanceof Media) {
            return $this->resolveSiteIdFromLogoMedia($model);
        }

        return null;
    }

    private function resolveSiteIdFromLogoMedia(Media $media): ?int
    {
        if ($media->model_type !== resolve(Site::class)->getMorphClass()) {
            return null;
        }

        if (! in_array($media->collection_name, [
            MediaCollectionEnum::Logo->value,
            MediaCollectionEnum::LogoInverted->value,
        ], true)) {
            return null;
        }

        return $this->integerKey($media->model_id);
    }

    private function resolveSiteIdFromTranslation(Translation $translation): ?int
    {
        $owner = $translation->translatable;

        if ($owner instanceof Site) {
            return $this->integerKey($owner->getKey());
        }

        if ($owner instanceof Page) {
            return $owner->isErrorPage() ? $this->integerKey($owner->site_id) : null;
        }

        return null;
    }

    private function dispatch(int $siteId): void
    {
        if (app()->runningUnitTests() || app()->runningInConsole()) {
            RegenerateSiteErrorPagesAction::dispatchSync($siteId);

            return;
        }

        if (resolve(ErrorPageRegenerationQueue::class)->markQueued($siteId)) {
            RegenerateSiteErrorPagesAction::dispatchAfterResponse($siteId);
        }
    }

    /**
     * @param  array<int, mixed>  $payload
     */
    private function modelFromPayload(array $payload): ?Model
    {
        $model = $payload[0] ?? null;

        return $model instanceof Model ? $model : null;
    }

    private function integerKey(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function isTimestampOnlyUpdate(Model $model): bool
    {
        $changedAttributes = array_keys($model->getChanges());

        return $changedAttributes !== []
            && array_diff($changedAttributes, [$model->getUpdatedAtColumn()]) === [];
    }
}
