<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\LaravelData\Data;

/**
 * The stored record behind a form submission, as a morph type and key. Listeners that keep a
 * link to the stored submission (a contact's source, a conversion's source) resolve it here
 * without naming the form package's classes.
 */
final class FormSubmissionSourceData extends Data
{
    public function __construct(
        public string $type,
        public int|string $id,
    ) {}

    public static function forModel(Model $model): ?self
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? new self($model->getMorphClass(), $key) : null;
    }

    /** The stored record, or null when its class is unknown here or the row no longer exists. */
    public function resolve(): ?Model
    {
        $class = Relation::getMorphedModel($this->type) ?? $this->type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->whereKey($this->id)->first();
    }
}
