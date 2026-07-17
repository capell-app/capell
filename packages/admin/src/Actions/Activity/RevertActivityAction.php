<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Activity;

use Capell\Admin\Data\Activity\ActivityChangedFieldData;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Support\Activity\ActivityRevertHandlerResolver;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Spatie\Activitylog\Models\Activity;

/**
 * @method static ActivityRevertResultData run(Activity $activity, ?list<string> $selectedPaths = null)
 */
final class RevertActivityAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<string>|null  $selectedPaths
     */
    public function handle(Activity $activity, ?array $selectedPaths = null): ActivityRevertResultData
    {
        $selectedPaths = $selectedPaths === null ? null : $this->normalizedSelectedPaths($selectedPaths);

        if (! $this->actorCanRevert()) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_unauthorized',
                skippedFields: ['unauthorized' => $selectedPaths ?? $this->oldValuePaths($activity)],
            );
        }

        $activityId = $activity->getKey();

        if ($activityId === null) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['missing_activity' => $selectedPaths ?? []],
            );
        }

        $oldValues = $this->oldValues($activity);

        if ($oldValues === []) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['missing_old_value' => []],
            );
        }

        $selectedPaths ??= array_keys($oldValues);
        $selectionValidation = $this->validateSelection($activity, $selectedPaths);
        $selectedPaths = $selectionValidation['selectedPaths'];
        $preSkippedFields = $selectionValidation['skippedFields'];

        if ($selectedPaths === []) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: $preSkippedFields,
            );
        }

        $selection = ActivityRevertSelectionData::fromActivity(
            activityId: $activityId,
            selectedPaths: $selectedPaths,
            beforeValues: array_intersect_key($oldValues, array_flip($selectedPaths)),
            actorId: auth()->id(),
            subjectMorphType: $activity->subject_type,
            subjectClass: $this->subjectClass($activity),
            subjectId: $activity->subject_id,
            stableIdentifier: $this->stableIdentifier($activity),
            workspaceId: $activity->properties?->get('workspace_id'),
        );

        $handler = resolve(ActivityRevertHandlerResolver::class)->resolve($selection);

        return $this->withPreSkippedFields($handler->revert($selection), $preSkippedFields);
    }

    /**
     * @return array<string, mixed>
     */
    private function oldValues(Activity $activity): array
    {
        $oldValues = $activity->properties?->get('old', []) ?? [];

        return is_array($oldValues) ? $oldValues : [];
    }

    private function actorCanRevert(): bool
    {
        return auth()->user()?->can(CapellPermission::RevertActivityLog->name()) === true;
    }

    /**
     * @param  array<int, mixed>  $selectedPaths
     * @return list<string>
     */
    private function normalizedSelectedPaths(array $selectedPaths): array
    {
        return array_values(array_unique(array_filter(
            $selectedPaths,
            fn (mixed $selectedPath): bool => is_string($selectedPath) && $selectedPath !== '',
        )));
    }

    /**
     * @param  list<string>  $selectedPaths
     * @return array{selectedPaths: list<string>, skippedFields: array<string, list<string>>}
     */
    private function validateSelection(Activity $activity, array $selectedPaths): array
    {
        $changeSet = BuildActivityChangeSetAction::run($activity);
        $fieldsByPath = collect($changeSet->fields)
            ->keyBy(fn (ActivityChangedFieldData $field): string => $field->path);
        $validatedPaths = [];
        $skippedFields = [];

        foreach ($selectedPaths as $selectedPath) {
            $field = $fieldsByPath->get($selectedPath);

            if (! $field instanceof ActivityChangedFieldData) {
                $this->skip($skippedFields, 'missing_old_value', $selectedPath);

                continue;
            }

            if (! $field->reversible) {
                $this->skip($skippedFields, $field->skipReason ?? 'unsupported_event', $selectedPath);

                continue;
            }

            $validatedPaths[] = $selectedPath;
        }

        return [
            'selectedPaths' => $validatedPaths,
            'skippedFields' => $skippedFields,
        ];
    }

    /**
     * @param  array<string, list<string>>  $preSkippedFields
     */
    private function withPreSkippedFields(
        ActivityRevertResultData $result,
        array $preSkippedFields,
    ): ActivityRevertResultData {
        if ($preSkippedFields === []) {
            return $result;
        }

        return new ActivityRevertResultData(
            successful: $result->successful,
            messageKey: $result->messageKey,
            skippedFields: $this->mergeSkippedFields($preSkippedFields, $result->skippedFields),
            workspaceId: $result->workspaceId,
        );
    }

    /**
     * @param  array<string, list<string>>  $firstSkippedFields
     * @param  array<string, list<string>>  $secondSkippedFields
     * @return array<string, list<string>>
     */
    private function mergeSkippedFields(array $firstSkippedFields, array $secondSkippedFields): array
    {
        foreach ($secondSkippedFields as $reason => $fields) {
            foreach ($fields as $field) {
                $this->skip($firstSkippedFields, $reason, $field);
            }
        }

        return $firstSkippedFields;
    }

    /**
     * @param  array<string, list<string>>  $skippedFields
     */
    private function skip(array &$skippedFields, string $reason, string $fieldPath): void
    {
        $skippedFields[$reason] ??= [];
        $skippedFields[$reason][] = $fieldPath;
    }

    /**
     * @return list<string>
     */
    private function oldValuePaths(Activity $activity): array
    {
        return array_keys($this->oldValues($activity));
    }

    private function subjectClass(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject instanceof Model) {
            return $subject::class;
        }

        return is_string($activity->subject_type) ? $activity->subject_type : null;
    }

    private function stableIdentifier(Activity $activity): ?string
    {
        $subject = $activity->subject;

        if ($subject instanceof Model) {
            foreach (['uuid', 'slug'] as $attribute) {
                if (! array_key_exists($attribute, $subject->getAttributes())) {
                    continue;
                }

                $value = $subject->getAttribute($attribute);

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return $activity->subject_id === null ? null : (string) $activity->subject_id;
    }
}
