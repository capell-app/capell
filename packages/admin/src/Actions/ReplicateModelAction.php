<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * @template T of Model
 *
 * @method static T run(T $record, array<string, mixed> $data = [])
 */
class ReplicateModelAction
{
    use AsObject;

    /**
     * @param  T  $record
     * @param  array<string, mixed>  $data
     * @return T
     */
    public function handle(Model $record, array $data = []): Model
    {
        $className = $record::class;

        /** @var T $model */
        $model = $className::findOrFail($record->getKey());

        $attributes = $model->getAttributes();

        if (isset($attributes['default'])) {
            $data['default'] = 0;
        }

        $model->fill($data);

        if (method_exists($className, 'setupNewModel')) {
            $className::setupNewModel($model);
        }

        /** @var T $replica */
        $replica = method_exists($model, 'duplicate') ? $model->duplicate() : $model->replicate();

        if ($model->timestamps) {
            $replica->setAttribute('created_at', now());
            $replica->setAttribute('updated_at', now());
        }

        $replica->save();

        return $replica;
    }
}
