<?php

declare(strict_types=1);

namespace Capell\Admin\Data\AdminPanelIntegration;

use Spatie\LaravelData\Data;

final class AdminPanelSetupOptionsData extends Data
{
    public function __construct(
        public readonly ?string $panelPath = null,
        /** @var array<int, array{in: string, for: string}> */
        public readonly array $discoverConfigurators = [],
        public readonly bool $autoDiscoverConfigurators = true,
        public readonly bool $addColors = true,
        public readonly bool $addWidgets = true,
        public readonly bool $addNavigation = true,
        public readonly bool $createBackup = true,
        public readonly bool $preview = false,
    ) {}
}
