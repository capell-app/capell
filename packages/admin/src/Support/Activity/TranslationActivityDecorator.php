<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityDecorator;
use Capell\Admin\Data\Activity\ActivityPresentationData;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class TranslationActivityDecorator implements ActivityDecorator
{
    public function supports(Activity $activity): bool
    {
        return $activity->subject instanceof Translation;
    }

    public function decorate(Activity $activity): ActivityPresentationData
    {
        /** @var Translation $translation */
        $translation = $activity->subject;
        $translatable = $translation->translatable;

        return new ActivityPresentationData(
            summary: $this->summary($activity, $translation),
            subjectLabel: $this->subjectLabel($translation, $translatable),
            subjectUrl: resolve(ActivityResourceLinkRegistry::class)->resolve($translation)?->url,
            event: $activity->event,
            oldValues: $this->values($activity, 'old'),
            newValues: $this->values($activity, 'attributes'),
            canRevert: $this->canRevert($activity),
        );
    }

    private function summary(Activity $activity, Translation $translation): string
    {
        $language = $translation->language->name;
        $description = filled($activity->description) ? $activity->description : __('capell-admin::activity.translation_updated');

        return filled($language)
            ? sprintf('%s (%s)', $description, $language)
            : $description;
    }

    private function subjectLabel(Translation $translation, ?Model $translatable): string
    {
        $title = $translatable?->getAttribute('name')
            ?? $translatable?->getAttribute('title')
            ?? $translation->title
            ?? $translation->getKey();

        return __('capell-admin::activity.translation_subject', ['title' => $title]);
    }

    /**
     * @return array<string, mixed>
     */
    private function values(Activity $activity, string $key): array
    {
        $values = $activity->properties?->get($key, []) ?? [];

        return is_array($values) ? $values : [];
    }

    private function canRevert(Activity $activity): bool
    {
        return Str::of((string) $activity->event)->lower()->toString() === 'updated'
            && $this->values($activity, 'old') !== [];
    }
}
