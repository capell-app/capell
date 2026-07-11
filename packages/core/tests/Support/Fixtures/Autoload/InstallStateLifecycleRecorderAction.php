<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;

final class InstallStateLifecycleRecorderAction implements PackageLifecycleAction
{
    public static ?ExtensionStatusEnum $observedStatus = null;

    public static ?bool $observedInstalled = null;

    public static int $calls = 0;

    public static function reset(): void
    {
        self::$observedStatus = null;
        self::$observedInstalled = null;
        self::$calls = 0;
    }

    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        self::$calls++;
        self::$observedInstalled = CapellCore::isPackageInstalled($package->name);
        self::$observedStatus = CapellExtension::query()
            ->where('composer_name', $package->name)
            ->first()
            ?->status;

        $reporter?->report('explicit lifecycle action ran');
    }
}
