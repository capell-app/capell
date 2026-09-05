<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Models\PropertySet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdatePropertySetAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(PropertySet $propertySet, array $data): PropertySet
    {
        if ($propertySet->owner_package !== null) {
            throw ValidationException::withMessages(['property_set' => __('capell-core::properties.validation.property_set_owned')]);
        }

        /** @var array{key?: string, name?: string} $validated */
        $validated = validator($data, [
            'key' => ['sometimes', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,190}\z/'],
            'name' => ['sometimes', 'string', 'max:191'],
        ])->validate();

        if (isset($validated['key']) && PropertySet::query()
            ->where('key', $validated['key'])
            ->where('id', '!=', $propertySet->id)
            ->exists()) {
            throw ValidationException::withMessages(['key' => __('capell-core::properties.validation.property_set_key_taken')]);
        }

        return DB::transaction(function () use ($propertySet, $validated): PropertySet {
            $propertySet->fill($validated);
            $propertySet->saveOrFail();

            return $propertySet->refresh();
        });
    }
}
