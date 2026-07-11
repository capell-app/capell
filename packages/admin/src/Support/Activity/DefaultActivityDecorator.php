<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityDecorator;
use Capell\Admin\Data\Activity\ActivityPresentationData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

final class DefaultActivityDecorator implements ActivityDecorator
{
    public function supports(Activity $activity): bool
    {
        return true;
    }

    public function decorate(Activity $activity): ActivityPresentationData
    {
        return new ActivityPresentationData(
            summary: $this->summary($activity),
            subjectLabel: $this->subjectLabel($activity),
            subjectUrl: $this->subjectUrl($activity),
            event: $activity->event,
            oldValues: $this->values($activity, 'old'),
            newValues: $this->values($activity, 'attributes'),
            canRevert: $this->canRevert($activity),
        );
    }

    private function summary(Activity $activity): string
    {
        if (filled($activity->description)) {
            return $activity->description;
        }

        return __('capell-admin::generic.unknown');
    }

    private function subjectLabel(Activity $activity): string
    {
        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return __('capell-admin::activity.subject_missing');
        }

        $title = $subject->getAttribute('name')
            ?? $subject->getAttribute('title')
            ?? $subject->getKey();

        return sprintf('%s #%s', class_basename($subject), $title);
    }

    private function subjectUrl(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        return resolve(ActivityResourceLinkRegistry::class)->resolve($subject)?->url;
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
            && $activity->subject instanceof Model
            && $this->values($activity, 'old') !== [];
    }
}
