<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Taxonomies;

use Capell\Core\Models\PropertySet;
use Capell\Core\Models\Site;
use Capell\Core\Models\Taxonomy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class CreateTaxonomyAction
{
    use AsFake;
    use AsObject;

    /** @param array<string, mixed> $data */
    public function handle(Site $site, array $data): Taxonomy
    {
        $validated = $this->validate($data);

        return DB::transaction(function () use ($site, $validated): Taxonomy {
            if (Taxonomy::query()->where('site_id', $site->id)->where('key', $validated['key'])->exists()) {
                throw ValidationException::withMessages([
                    'key' => __('capell-core::properties.validation.taxonomy_key_taken'),
                ]);
            }

            return Taxonomy::query()->create(['site_id' => $site->id, ...$validated]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{key: string, name: string, hierarchical: bool, property_set_id: int|null, position: int}
     */
    private function validate(array $data): array
    {
        /** @var array{key: string, name: string, hierarchical?: bool, property_set_id?: int|null, position?: int} $validated */
        $validated = validator($data, [
            'key' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,190}\z/'],
            'name' => ['required', 'string', 'max:191'],
            'hierarchical' => ['sometimes', 'boolean'],
            'property_set_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ])->validate();

        $propertySetId = $validated['property_set_id'] ?? null;
        if ($propertySetId !== null && ! PropertySet::query()->whereKey($propertySetId)->exists()) {
            throw ValidationException::withMessages([
                'property_set_id' => __('capell-core::properties.validation.property_set_out_of_scope'),
            ]);
        }

        return [
            'key' => $validated['key'],
            'name' => $validated['name'],
            'hierarchical' => (bool) ($validated['hierarchical'] ?? false),
            'property_set_id' => $propertySetId,
            'position' => (int) ($validated['position'] ?? 0),
        ];
    }
}
