<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Enums;

use BackedEnum;
use Capell\Admin\Contracts\EnumPresentationContributor;
use Capell\Admin\Data\EnumPresentationData;
use UnitEnum;

final class EnumPresentationRegistry
{
    public function present(UnitEnum $enum): ?EnumPresentationData
    {
        foreach (app()->tagged(EnumPresentationContributor::TAG) as $contributor) {
            if (! $contributor instanceof EnumPresentationContributor) {
                continue;
            }

            $presentation = $contributor->present($enum);
            if ($presentation !== null) {
                return $presentation;
            }
        }

        return null;
    }

    public function label(UnitEnum $enum): string
    {
        $presentation = $this->present($enum);

        return $presentation instanceof EnumPresentationData
            ? $presentation->label
            : str($enum instanceof BackedEnum ? (string) $enum->value : $enum->name)->headline()->toString();
    }

    /**
     * @param  class-string<UnitEnum>  $enumClass
     * @return array<int|string, string>
     */
    public function options(string $enumClass): array
    {
        return collect($enumClass::cases())
            ->mapWithKeys(fn (UnitEnum $enum): array => [
                $enum instanceof BackedEnum ? $enum->value : $enum->name => $this->label($enum),
            ])
            ->all();
    }
}
