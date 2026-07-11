<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Actions\Activity\DescribeActivityAction;
use Capell\Admin\Contracts\Activity\ActivityChangeSetBuilder;
use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityChangedResourceData;
use Capell\Admin\Data\Activity\ActivityChangeSetData;
use Capell\Admin\Data\Activity\ActivityResourceLinkData;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

final class DefaultActivityChangeSetBuilder implements ActivityChangeSetBuilder
{
    public function supports(Activity $activity): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 0;
    }

    public function build(Activity $activity): ActivityChangeSetData
    {
        $presentation = DescribeActivityAction::run($activity);
        $oldValues = $this->propertiesArray($activity, 'old');
        $newValues = $this->propertiesArray($activity, 'attributes');
        $fieldPaths = array_values(array_unique(array_merge(array_keys($oldValues), array_keys($newValues))));

        return new ActivityChangeSetData(
            summary: $presentation->summary,
            resource: $this->resource($activity, count($fieldPaths), $presentation->subjectLabel, $presentation->subjectUrl),
            fields: array_map(
                function (string $fieldPath) use ($activity, $oldValues, $newValues): ActivityChangedFieldData {
                    $skipReason = $this->skipReason($activity, $fieldPath, $oldValues);

                    return new ActivityChangedFieldData(
                        path: $fieldPath,
                        beforeValue: $oldValues[$fieldPath] ?? null,
                        afterValue: $newValues[$fieldPath] ?? null,
                        status: $this->fieldStatus($fieldPath, $oldValues, $newValues),
                        reversible: $skipReason === null,
                        skipReason: $skipReason,
                        label: str($fieldPath)->replace('_', ' ')->headline()->toString(),
                    );
                },
                $fieldPaths,
            ),
            actorLabel: $activity->causer instanceof Model
                ? (string) ($activity->causer->getAttribute('name') ?? $activity->causer->getKey())
                : (string) __('capell-admin::dashboard.activity_system'),
            event: $activity->event,
            occurredAt: $activity->created_at,
            workspaceId: $activity->properties?->get('workspace_id'),
            emptyMessage: $fieldPaths === [] ? 'capell-admin::activity.no_field_changes' : null,
        );
    }

    private function resource(Activity $activity, int $changedFieldCount, string $label, ?string $url): ?ActivityChangedResourceData
    {
        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        $link = resolve(ActivityResourceLinkRegistry::class)->resolve($subject);
        $resourceRecord = $link instanceof ActivityResourceLinkData ? $link->record : $subject;

        return new ActivityChangedResourceData(
            morphType: $activity->subject_type,
            modelClass: $resourceRecord::class,
            stableIdentifier: $this->stableIdentifier($resourceRecord),
            label: $label,
            url: $url,
            area: (string) __('capell-admin::generic.unknown'),
            package: null,
            changedFieldCount: $changedFieldCount,
        );
    }

    private function stableIdentifier(Model $subject): ?string
    {
        foreach (['uuid', 'slug'] as $attribute) {
            if (! array_key_exists($attribute, $subject->getAttributes())) {
                continue;
            }

            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $subject->getKey() === null ? null : (string) $subject->getKey();
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function fieldStatus(string $fieldPath, array $oldValues, array $newValues): string
    {
        if (! array_key_exists($fieldPath, $oldValues)) {
            return 'created';
        }

        if (! array_key_exists($fieldPath, $newValues)) {
            return 'deleted';
        }

        return 'updated';
    }

    /**
     * @param  array<string, mixed>  $oldValues
     */
    private function skipReason(Activity $activity, string $fieldPath, array $oldValues): ?string
    {
        if ($activity->event !== 'updated') {
            return 'unsupported_event';
        }

        if ($activity->properties?->get('workspace_id') !== null) {
            return 'workspace_context';
        }

        if (! array_key_exists($fieldPath, $oldValues)) {
            return 'missing_old_value';
        }

        if (str_contains($fieldPath, '.')) {
            return 'nested_path';
        }

        $subject = $activity->subject;

        if ($subject instanceof Model && ! array_key_exists($fieldPath, array_flip($subject->getFillable()))) {
            return 'not_fillable';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function propertiesArray(Activity $activity, string $key): array
    {
        $values = $activity->properties?->get($key, []) ?? [];

        return is_array($values) ? $values : [];
    }
}
