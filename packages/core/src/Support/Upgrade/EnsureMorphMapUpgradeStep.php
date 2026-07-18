<?php

declare(strict_types=1);

namespace Capell\Core\Support\Upgrade;

use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

final class EnsureMorphMapUpgradeStep extends AbstractUpgradeStep
{
    public function id(): string
    {
        return 'core.ensure-morph-map';
    }

    public function label(): string
    {
        return 'Verify all core models are present in the morph map';
    }

    public function run(UpgradeContext $context): bool
    {
        $currentMorphMap = Relation::morphMap();

        $expectedEntries = collect(CapellCore::getModels())
            ->mapWithKeys(fn (string $modelClass, string $name): array => [Str::snake($name) => $modelClass])
            ->all();

        $missing = array_diff_key($expectedEntries, $currentMorphMap);

        if ($missing !== []) {
            Relation::morphMap($missing);
        }

        return true;
    }
}
