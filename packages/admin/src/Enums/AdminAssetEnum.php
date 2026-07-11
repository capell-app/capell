<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Capell\Admin\Actions\CreatePageAction;
use Capell\Admin\Actions\MutateDefaultPageDataAction;
use Capell\Admin\Filament\Contracts\FormConfigurator;
use Capell\Admin\Filament\Resources\Pages\Schemas\PageForm;
use Capell\Core\Contracts\Actionable;
use Capell\Core\Enums\AssetEnum;

enum AdminAssetEnum: string
{
    case Page = AssetEnum::Page->value;

    public function getAsset(): AssetEnum
    {
        return AssetEnum::from($this->value);
    }

    /**
     * @return class-string<FormConfigurator>
     */
    public function getFormClass(): string
    {
        return match ($this) {
            self::Page => PageForm::class,
        };
    }

    /**
     * @return class-string<Actionable>
     */
    public function getCreateActionClass(): string
    {
        return match ($this) {
            self::Page => CreatePageAction::class,
        };
    }

    /**
     * @return class-string<Actionable>
     */
    public function getDefaultDataActionClass(): string
    {
        return match ($this) {
            self::Page => MutateDefaultPageDataAction::class,
        };
    }
}
