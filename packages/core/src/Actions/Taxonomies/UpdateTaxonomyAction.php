<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Taxonomies;

use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Taxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class UpdateTaxonomyAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(Taxonomy $taxonomy, array $data): Taxonomy
    {
        /** @var array{key?: string, name?: string, hierarchical?: bool, property_set_id?: int|null, position?: int} $validated */
        $validated = validator($data, [
            'key' => ['sometimes', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,190}\z/'],
            'name' => ['sometimes', 'string', 'max:191'],
            'hierarchical' => ['sometimes', 'boolean'],
            'property_set_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ])->validate();

        if (isset($validated['key']) && Taxonomy::query()
            ->where('site_id', $taxonomy->site_id)
            ->where('key', $validated['key'])
            ->where('id', '!=', $taxonomy->id)
            ->exists()) {
            throw ValidationException::withMessages(['key' => __('capell-core::properties.validation.taxonomy_key_taken')]);
        }

        if (array_key_exists('property_set_id', $validated)
            && $validated['property_set_id'] !== null
            && ! PropertySet::query()->whereKey($validated['property_set_id'])->exists()) {
            throw ValidationException::withMessages(['property_set_id' => __('capell-core::properties.validation.property_set_out_of_scope')]);
        }

        return DB::transaction(function () use ($taxonomy, $validated): Taxonomy {
            $taxonomy->fill($validated);
            $taxonomy->saveOrFail();

            return $taxonomy->refresh();
        });
    }
}
