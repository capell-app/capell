<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Properties;

use Capell\Core\Data\Properties\PropertyConstraintsData;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\PropertySet;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Replaces a property definition's declarative validation rules.
 *
 * Input uses the stored (snake_case) shape of {@see PropertyConstraintsData}.
 * Rules that cannot apply to the definition's type, contradictory ranges and
 * patterns that do not compile are rejected with field-level messages, so a
 * definition can never be saved with a rule set that silently does nothing or
 * rejects everything. Package-owned sets are managed by their package and are
 * refused, matching {@see UpdatePropertySetAction}.
 *
 * @method static PropertyDefinition run(PropertyDefinition $definition, array<string, mixed> $input)
 */
final class UpdatePropertyDefinitionConstraintsAction
{
    use AsFake;
    use AsObject;

    /** Inputs that expose catastrophic backtracking: long runs of common character kinds that then fail to match. */
    private const array PATTERN_PROBES = [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!',
        'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA!',
        '0000000000000000000000000000000000000000!',
        'a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0!',
        '                                        !',
        '----------------------------------------!',
        '........................................' . "\x00",
    ];

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(PropertyDefinition $definition, array $input): PropertyDefinition
    {
        $propertySet = $definition->propertySet;

        if ($propertySet instanceof PropertySet && $propertySet->owner_package !== null) {
            throw ValidationException::withMessages([
                'constraints' => __('capell-core::properties.constraints.input.owned_set'),
            ]);
        }

        $input = array_map(static fn (mixed $value): mixed => $value === '' ? null : $value, $input);

        /** @var array<string, mixed> $validated */
        $validated = validator($input, [
            'min' => ['nullable', 'numeric'],
            'max' => ['nullable', 'numeric'],
            'min_length' => ['nullable', 'integer', 'min:0'],
            'max_length' => ['nullable', 'integer', 'min:1'],
            'pattern' => ['nullable', 'string', 'max:500', $this->compilablePattern()],
            'unique_per_site' => ['nullable', 'boolean'],
            'allowed_blueprints' => ['nullable', 'array'],
            'allowed_blueprints.*' => ['string', 'max:191'],
            'allowed_taxonomies' => ['nullable', 'array'],
            'allowed_taxonomies.*' => ['string', 'max:191'],
            'allowed_mime_types' => ['nullable', 'array'],
            'allowed_mime_types.*' => ['string', 'regex:~\A[a-z]+/(\*|[a-z0-9][a-z0-9.+-]*)\z~'],
            'min_width' => ['nullable', 'integer', 'min:1'],
            'max_width' => ['nullable', 'integer', 'min:1'],
            'min_height' => ['nullable', 'integer', 'min:1'],
            'max_height' => ['nullable', 'integer', 'min:1'],
        ], $this->messages(), $this->attributes())->validate();

        $constraints = PropertyConstraintsData::fromStored([
            ...$validated,
            'min' => $this->number($validated['min'] ?? null),
            'max' => $this->number($validated['max'] ?? null),
            'min_length' => $this->integer($validated['min_length'] ?? null),
            'max_length' => $this->integer($validated['max_length'] ?? null),
            'unique_per_site' => filter_var($validated['unique_per_site'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'min_width' => $this->integer($validated['min_width'] ?? null),
            'max_width' => $this->integer($validated['max_width'] ?? null),
            'min_height' => $this->integer($validated['min_height'] ?? null),
            'max_height' => $this->integer($validated['max_height'] ?? null),
        ]);

        $this->assertRangesAreOrdered($constraints);

        $inapplicable = $constraints->inapplicableRulesFor($definition->type);

        if ($inapplicable !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $inapplicable,
                __('capell-core::properties.constraints.input.not_applicable'),
            ));
        }

        if ($constraints->uniquePerSite && $definition->constraints?->uniquePerSite !== true && $this->hasSharedValues($definition)) {
            throw ValidationException::withMessages([
                'unique_per_site' => __('capell-core::properties.constraints.input.unique_has_duplicates'),
            ]);
        }

        return DB::transaction(function () use ($definition, $constraints): PropertyDefinition {
            $definition->constraints = $constraints->isEmpty() ? null : $constraints;
            $definition->saveOrFail();

            return $definition->refresh();
        });
    }

    private function assertRangesAreOrdered(PropertyConstraintsData $constraints): void
    {
        $errors = [];

        foreach ([
            'max' => [$constraints->min, $constraints->max],
            'max_length' => [$constraints->minLength, $constraints->maxLength],
            'max_width' => [$constraints->minWidth, $constraints->maxWidth],
            'max_height' => [$constraints->minHeight, $constraints->maxHeight],
        ] as $key => [$lower, $upper]) {
            if ($lower !== null && $upper !== null && $upper < $lower) {
                $errors[$key] = __('capell-core::properties.constraints.input.max_below_min');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Whether two live pages on one site already hold the same value, so the
     * rule cannot be switched on until an editor resolves them.
     */
    private function hasSharedValues(PropertyDefinition $definition): bool
    {
        $column = BuildPropertyValidationRulesAction::uniqueColumn($definition->type);

        if ($column === null) {
            return false;
        }

        return PagePropertyValue::query()
            ->where('property_definition_id', $definition->id)
            ->whereNotNull($column)
            ->whereHas('page')
            ->select(['site_id', $column])
            ->groupBy(['site_id', $column])
            ->havingRaw('COUNT(DISTINCT page_id) > 1')
            ->exists();
    }

    private function compilablePattern(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || @preg_match(BuildPropertyValidationRulesAction::compilePattern($value), '') === false) {
                $fail(__('capell-core::properties.constraints.input.pattern_invalid'));

                return;
            }

            // Refuse a pattern that cannot finish within the backtracking
            // budget on short, ordinary-looking input (nested repeats such as
            // (a+)+): it would slow every check and fail closed on real values.
            foreach (self::PATTERN_PROBES as $probe) {
                if (BuildPropertyValidationRulesAction::matchesPattern($value, $probe) === null) {
                    $fail(__('capell-core::properties.constraints.input.pattern_too_slow'));

                    return;
                }
            }
        };
    }

    private function number(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'allowed_mime_types.*.regex' => (string) __('capell-core::properties.constraints.input.mime_type_invalid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'min' => (string) __('capell-core::properties.constraints.fields.min'),
            'max' => (string) __('capell-core::properties.constraints.fields.max'),
            'min_length' => (string) __('capell-core::properties.constraints.fields.min_length'),
            'max_length' => (string) __('capell-core::properties.constraints.fields.max_length'),
            'pattern' => (string) __('capell-core::properties.constraints.fields.pattern'),
            'unique_per_site' => (string) __('capell-core::properties.constraints.fields.unique_per_site'),
            'allowed_blueprints' => (string) __('capell-core::properties.constraints.fields.allowed_blueprints'),
            'allowed_taxonomies' => (string) __('capell-core::properties.constraints.fields.allowed_taxonomies'),
            'allowed_mime_types' => (string) __('capell-core::properties.constraints.fields.allowed_mime_types'),
            'min_width' => (string) __('capell-core::properties.constraints.fields.min_width'),
            'max_width' => (string) __('capell-core::properties.constraints.fields.max_width'),
            'min_height' => (string) __('capell-core::properties.constraints.fields.min_height'),
            'max_height' => (string) __('capell-core::properties.constraints.fields.max_height'),
        ];
    }
}
