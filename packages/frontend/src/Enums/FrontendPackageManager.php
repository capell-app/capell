<?php

declare(strict_types=1);

namespace Capell\Frontend\Enums;

enum FrontendPackageManager: string
{
    case Npm = 'npm';
    case Pnpm = 'pnpm';
    case Yarn = 'yarn';
    case Bun = 'bun';

    /** @return array<int, string> */
    public function installCommand(FrontendPackageDependencyType $type, array $packages): array
    {
        $prefix = match ([$this, $type]) {
            [self::Npm, FrontendPackageDependencyType::Runtime] => ['npm', 'install'],
            [self::Npm, FrontendPackageDependencyType::Development] => ['npm', 'install', '--save-dev'],
            [self::Pnpm, FrontendPackageDependencyType::Runtime] => ['pnpm', 'add'],
            [self::Pnpm, FrontendPackageDependencyType::Development] => ['pnpm', 'add', '--save-dev'],
            [self::Yarn, FrontendPackageDependencyType::Runtime] => ['yarn', 'add'],
            [self::Yarn, FrontendPackageDependencyType::Development] => ['yarn', 'add', '--dev'],
            [self::Bun, FrontendPackageDependencyType::Runtime] => ['bun', 'add'],
            [self::Bun, FrontendPackageDependencyType::Development] => ['bun', 'add', '--dev'],
        };

        return [...$prefix, ...$packages];
    }
}
