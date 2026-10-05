<?php

declare(strict_types=1);

namespace Capell\Core\Support\Activity;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use JsonException;
use LogicException;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;

final class ActivityLogCompat
{
    // Keep absent-major symbols as strings so dependency analysis works with either installed major.
    private const string ModernLogOptions = 'Spatie\\Activitylog\\Support\\LogOptions';

    /** @return class-string */
    public static function logOptionsClass(): string
    {
        $class = class_exists(self::ModernLogOptions) ? self::ModernLogOptions : 'Spatie\\Activitylog\\LogOptions';

        throw_unless(class_exists($class), LogicException::class, 'Activity log options are unavailable.');

        return $class;
    }

    public static function logsActivityTrait(): string
    {
        return class_exists(self::ModernLogOptions)
            ? 'Spatie\\Activitylog\\Models\\Concerns\\LogsActivity'
            : 'Spatie\\Activitylog\\Traits\\LogsActivity';
    }

    /** @return class-string<Model&ActivityContract> */
    public static function activityModelClass(): string
    {
        $model = config('activitylog.activity_model', Activity::class);

        throw_if(! is_string($model) || ! is_a($model, Model::class, true) || ! is_a($model, ActivityContract::class, true), LogicException::class, 'The configured activity model must be an Eloquent model implementing the activity contract.');

        return $model;
    }

    /** @param list<string> $excludedAttributes */
    public static function options(string $logName, array $excludedAttributes): LogOptions
    {
        return self::withoutEmptyLogs(LogOptions::defaults()
            ->useLogName($logName)
            ->logAll()
            ->logExcept($excludedAttributes)
            ->logOnlyDirty());
    }

    /**
     * @template T of object
     *
     * @param  T  $options
     * @return T
     */
    public static function withoutEmptyLogs(object $options): object
    {
        if (method_exists($options, 'dontLogEmptyChanges')) {
            $options->dontLogEmptyChanges();
        } elseif (method_exists($options, 'dontSubmitEmptyLogs')) {
            $options->dontSubmitEmptyLogs();
        } else {
            throw new LogicException('Activity log options do not support suppressing empty changes.');
        }

        return $options;
    }

    /** @return array<string, mixed> */
    public static function attributeValues(Model $activity, string $key): array
    {
        // Old rows and manually logged properties remain readable after a schema upgrade.
        $changes = array_key_exists('attribute_changes', $activity->getAttributes())
            ? self::arrayValue($activity->getAttribute('attribute_changes'))
            : [];
        $properties = self::properties($activity);
        $values = $changes[$key] ?? $properties[$key] ?? [];

        return is_array($values) ? $values : [];
    }

    /** @return array<string, mixed> */
    public static function properties(Model $activity): array
    {
        return self::arrayValue($activity->getAttribute('properties'));
    }

    /** @return array<string, mixed> */
    private static function arrayValue(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        return is_array($value) ? $value : [];
    }
}
