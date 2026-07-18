<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Facades\CapellCore;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

final class MorphMapCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.morph-map.complete';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Critical;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $current = Relation::morphMap();
        $expected = collect(CapellCore::getModels())->mapWithKeys(fn (string $class, string $name): array => [Str::snake($name) => $class])->all();
        $missing = array_filter($expected, fn (string $class, string $alias): bool => ! array_key_exists($alias, $current), ARRAY_FILTER_USE_BOTH);

        return $missing !== []
            ? new DoctorCheckResultData('Morph map is complete', false, sprintf('Morph map missing aliases: %s.', implode(', ', array_keys($missing))), 'Check your Capell service providers and cached config.')
            : new DoctorCheckResultData('Morph map is complete', true, 'Core morph map is complete.');
    }
}
