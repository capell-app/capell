<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Illuminate\Support\Arr;
use Override;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

final class CapellSiteSpecLanguageData extends Data
{
    /** The attributes a language row can change; `code` identifies the row and is always stated. */
    public const array ATTRIBUTES = ['name', 'locale', 'flag', 'default'];

    /**
     * @param  list<string>|null  $stated  The attributes the spec's author wrote. The rest hold defaults, which
     *                                     never overwrite or get compared with an existing shared row. Null means
     *                                     unknown, so every attribute counts as stated. Hidden, so it is not part
     *                                     of the serialised spec or its hash.
     */
    public function __construct(
        public readonly string $code = 'en',
        public readonly string $name = 'English',
        public readonly string $locale = 'en_GB',
        public readonly string $flag = 'gb',
        public readonly bool $default = true,
        #[Hidden]
        public readonly ?array $stated = null,
    ) {}

    /**
     * A language built from an array states the attributes that array holds, unless the caller recorded
     * them already (a validated spec is normalised with every default filled in, so it cannot be told).
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    #[Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['stated'] ??= array_values(array_intersect(self::ATTRIBUTES, array_keys($properties)));

        return $properties;
    }

    /**
     * The attributes of an existing row this spec is allowed to compare and change.
     *
     * @return array<string, mixed>
     */
    public function statedAttributes(): array
    {
        return array_intersect_key(
            Arr::only($this->toArray(), self::ATTRIBUTES),
            array_flip($this->stated ?? self::ATTRIBUTES),
        );
    }
}
