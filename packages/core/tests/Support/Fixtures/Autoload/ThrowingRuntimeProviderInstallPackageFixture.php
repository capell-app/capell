<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Fixtures\Autoload;

use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;
use RuntimeException;

final class ThrowingRuntimeProviderInstallPackageFixture extends ServiceProvider
{
    public static ?ExtensionStatusEnum $observedStatus = null;

    public static ?bool $observedInstalled = null;

    public static int $calls = 0;

    private static ?string $packageName = null;

    public static function reset(string $packageName): void
    {
        self::$packageName = $packageName;
        self::$observedStatus = null;
        self::$observedInstalled = null;
        self::$calls = 0;
    }

    #[Override]
    public function register(): void
    {
        $packageName = self::$packageName;

        if ($packageName === null) {
            throw new LogicException('The throwing runtime provider fixture was not configured.');
        }

        self::$calls++;
        self::$observedInstalled = CapellCore::isPackageInstalled($packageName);
        self::$observedStatus = CapellExtension::query()
            ->where('composer_name', $packageName)
            ->first()
            ?->status;

        throw new RuntimeException('Runtime provider registration failed.');
    }
}
