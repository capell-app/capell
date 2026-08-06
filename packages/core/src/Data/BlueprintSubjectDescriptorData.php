<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Actions\CreateDefaultPageBlueprintAction;
use Capell\Core\Actions\CreateDefaultSiteBlueprintAction;
use Capell\Core\Actions\CreateDefaultThemeBlueprintAction;
use Capell\Core\Enums\BlueprintGroupEnum;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Models\Contracts\Blueprintable;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Illuminate\Database\Eloquent\Model;
use Spatie\LaravelData\Data;

final class BlueprintSubjectDescriptorData extends Data
{
    /**
     * @param  class-string<Model&Blueprintable>  $modelClass
     * @param  list<BlueprintGroupEnum>  $groups
     * @param  class-string|null  $defaultSchemaSeeder
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $modelClass,
        public readonly string $ownerPackage,
        public readonly array $groups = [],
        public readonly ?string $defaultSchemaSeeder = null,
    ) {}

    public static function fromEnum(BlueprintSubjectEnum $typeEnum): self
    {
        return new self(
            key: $typeEnum->getKey(),
            label: $typeEnum->getLabel(),
            modelClass: match ($typeEnum) {
                BlueprintSubjectEnum::Page => Page::class,
                BlueprintSubjectEnum::Site => Site::class,
                BlueprintSubjectEnum::Theme => Theme::class,
            },
            ownerPackage: 'capell-app/core',
            defaultSchemaSeeder: match ($typeEnum) {
                BlueprintSubjectEnum::Page => CreateDefaultPageBlueprintAction::class,
                BlueprintSubjectEnum::Site => CreateDefaultSiteBlueprintAction::class,
                BlueprintSubjectEnum::Theme => CreateDefaultThemeBlueprintAction::class,
            },
        );
    }

    public function toEnum(): ?BlueprintSubjectEnum
    {
        return BlueprintSubjectEnum::tryFrom($this->key);
    }
}
