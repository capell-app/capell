<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Models\PropertySet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreatePropertySetAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(array $data): PropertySet
    {
        /** @var array{key: string, name: string} $validated */
        $validated = validator($data, [
            'key' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,190}\z/'],
            'name' => ['required', 'string', 'max:191'],
        ])->validate();

        return DB::transaction(function () use ($validated): PropertySet {
            if (PropertySet::query()->where('key', $validated['key'])->exists()) {
                throw ValidationException::withMessages(['key' => __('capell-core::properties.validation.property_set_key_taken')]);
            }

            return PropertySet::query()->create($validated);
        });
    }
}
