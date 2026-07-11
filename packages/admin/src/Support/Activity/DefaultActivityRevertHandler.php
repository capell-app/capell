<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Activity;

use Capell\Admin\Contracts\Activity\ActivityRevertHandler;
use Capell\Admin\Data\Activity\ActivityRevertResultData;
use Capell\Admin\Data\Activity\ActivityRevertSelectionData;
use Capell\Admin\Enums\CapellPermission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Throwable;

final class DefaultActivityRevertHandler implements ActivityRevertHandler
{
    public function supports(ActivityRevertSelectionData $selection): bool
    {
        return true;
    }

    public function priority(): int
    {
        return 0;
    }

    public function revert(ActivityRevertSelectionData $selection): ActivityRevertResultData
    {
        if (! $this->actorCanRevert()) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_unauthorized',
                skippedFields: ['unauthorized' => $selection->selectedPaths],
            );
        }

        $activity = Activity::query()->find($selection->activityId);

        if (! $activity instanceof Activity) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['missing_activity' => $selection->selectedPaths],
            );
        }

        if ($selection->workspaceId !== null) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.workspace_revert_not_available',
                skippedFields: ['workspace_context' => $selection->selectedPaths],
                workspaceId: $selection->workspaceId,
            );
        }

        if ($activity->event !== 'updated') {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['unsupported_event' => $selection->selectedPaths],
            );
        }

        $subject = $activity->subject;

        if (! $subject instanceof Model) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['missing_subject' => $selection->selectedPaths],
            );
        }

        $oldValues = $this->propertiesArray($activity, 'old');
        $newValues = $this->propertiesArray($activity, 'attributes');
        $fillable = array_flip($subject->getFillable());
        $updates = [];
        $skippedFields = [];

        foreach ($selection->selectedPaths as $fieldPath) {
            if (! array_key_exists($fieldPath, $oldValues)) {
                $this->skip($skippedFields, 'missing_old_value', $fieldPath);

                continue;
            }

            if (str_contains($fieldPath, '.')) {
                $this->skip($skippedFields, 'nested_path', $fieldPath);

                continue;
            }

            if (! array_key_exists($fieldPath, $fillable)) {
                $this->skip($skippedFields, 'not_fillable', $fieldPath);

                continue;
            }

            if (
                array_key_exists($fieldPath, $newValues)
                && ! $this->matchesCurrentValue($subject, $fieldPath, $newValues[$fieldPath])
            ) {
                $this->skip($skippedFields, 'stale_value', $fieldPath);

                continue;
            }

            $updates[$fieldPath] = $oldValues[$fieldPath];
        }

        if ($updates === []) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: $skippedFields,
            );
        }

        try {
            $subject->fill($updates);
        } catch (Throwable) {
            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['cast_invalid' => array_keys($updates)] + $skippedFields,
            );
        }

        try {
            if (! $subject->isDirty()) {
                return ActivityRevertResultData::success(
                    messageKey: 'capell-admin::activity.reverted',
                    skippedFields: $skippedFields,
                );
            }

            if (! $subject->save()) {
                $this->logWriteFailure($selection, $subject, array_keys($updates));

                return ActivityRevertResultData::failed(
                    messageKey: 'capell-admin::activity.revert_failed',
                    skippedFields: ['write_failed' => array_keys($updates)] + $skippedFields,
                );
            }
        } catch (Throwable $throwable) {
            $this->logWriteFailure($selection, $subject, array_keys($updates), $throwable);

            return ActivityRevertResultData::failed(
                messageKey: 'capell-admin::activity.revert_failed',
                skippedFields: ['write_failed' => array_keys($updates)] + $skippedFields,
            );
        }

        return ActivityRevertResultData::success(
            messageKey: 'capell-admin::activity.reverted',
            skippedFields: $skippedFields,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function propertiesArray(Activity $activity, string $key): array
    {
        $values = $activity->properties?->get($key, []) ?? [];

        return is_array($values) ? $values : [];
    }

    private function actorCanRevert(): bool
    {
        return auth()->user()?->can(CapellPermission::RevertActivityLog->name()) === true;
    }

    private function matchesCurrentValue(Model $subject, string $fieldPath, mixed $expectedValue): bool
    {
        return $subject->getAttribute($fieldPath) === $this->castValueForComparison($subject, $fieldPath, $expectedValue);
    }

    private function castValueForComparison(Model $subject, string $fieldPath, mixed $value): mixed
    {
        $comparisonSubject = clone $subject;
        $comparisonSubject->setAttribute($fieldPath, $value);

        return $comparisonSubject->getAttribute($fieldPath);
    }

    /**
     * @param  list<string>  $updatePaths
     */
    private function logWriteFailure(
        ActivityRevertSelectionData $selection,
        Model $subject,
        array $updatePaths,
        ?Throwable $throwable = null,
    ): void {
        Log::warning('Activity log revert write failed.', [
            'activity_id' => $selection->activityId,
            'actor_id' => $selection->actorId,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'selected_paths' => $selection->selectedPaths,
            'update_paths' => $updatePaths,
            'exception' => $throwable,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $skippedFields
     */
    private function skip(array &$skippedFields, string $reason, string $fieldPath): void
    {
        $skippedFields[$reason] ??= [];
        $skippedFields[$reason][] = $fieldPath;
    }
}
