<?php

declare(strict_types=1);

namespace Capell\Core\Data\ContentGraph;

use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class ContentGraphNodeData extends Data
{
    /**
     * @param  class-string<Model>  $modelType
     */
    public function __construct(
        public readonly string $modelType,
        public readonly int $modelId,
        public readonly ?string $label = null,
        public readonly ?string $nodeType = null,
        public readonly ?int $siteId = null,
        public readonly ?int $languageId = null,
        public readonly bool $exists = true,
    ) {}

    /**
     * @param  class-string<Model>  $modelType
     */
    public static function fromModelIdentity(string $modelType, int $modelId): self
    {
        return new self(
            modelType: $modelType,
            modelId: $modelId,
        );
    }

    public static function fromModel(Model $model, ?string $label = null, ?string $nodeType = null): self
    {
        return new self(
            modelType: $model::class,
            modelId: (int) $model->getKey(),
            label: $label,
            nodeType: $nodeType,
            siteId: self::integerAttribute($model, 'site_id'),
            languageId: self::integerAttribute($model, 'language_id'),
        );
    }

    private static function integerAttribute(Model $model, string $attribute): ?int
    {
        $value = $model->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : null;
    }
}
