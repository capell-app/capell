<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Scaffolding\ScaffoldThemePackageAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Scaffolding\ThemeScaffoldInputData;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class MakeThemeCommand extends Command
{
    use DescribesCommandOptions;

    /** @var array<int, string> */
    protected $aliases = ['capell:make:theme'];

    protected $signature = 'capell:make-theme
        {theme? : Theme key, for example equidynamics}
        {--package= : Composer package name, for example vendor/equidynamics-theme}
        {--name= : Human-readable display name}
        {--path=packages : Directory to create the theme package inside}
        {--extends=default : Parent theme key}
        {--local : Scaffold a project-local theme provider without an installed-package runtime gate}';

    protected $description = 'Scaffold a Capell theme package for a local app or reusable package.';

    public function handle(ScaffoldThemePackageAction $scaffoldThemePackage): int
    {
        $this->writeCommandIntro('scaffold a Capell theme', $this->enabledOptionDetails([
            'package' => 'a custom Composer package name',
            'name' => 'a custom display name',
            'extends' => 'a parent theme key',
            'local' => 'project-local runtime registration',
        ]));

        $themeKey = $this->resolveThemeKey();

        if ($themeKey === null || $themeKey === '') {
            $this->error('Missing required theme argument. Pass a theme key like equidynamics.');

            return CommandAlias::FAILURE;
        }

        if (! $this->isValidSlug($themeKey)) {
            $this->error('The theme argument must use lowercase letters, numbers, and hyphens.');

            return CommandAlias::FAILURE;
        }

        $packageName = $this->resolvePackageName($themeKey);

        if (! $this->isValidComposerPackageName($packageName)) {
            $this->error('The package name must be a valid Composer package name like vendor/example-theme.');

            return CommandAlias::FAILURE;
        }

        $targetDirectory = $this->targetDirectory($packageName);

        if ($targetDirectory === null) {
            $this->error('Missing or unsafe path. Pass --path with a safe package directory inside the current project.');

            return CommandAlias::FAILURE;
        }

        if (file_exists($targetDirectory) && ! is_dir($targetDirectory)) {
            $this->error(sprintf('The target path "%s" already exists and is not a directory.', $targetDirectory));

            return CommandAlias::FAILURE;
        }

        if (is_dir($targetDirectory) && $this->directoryIsNotEmpty($targetDirectory)) {
            $this->error(sprintf('The target directory "%s" already exists and is not empty.', $targetDirectory));

            return CommandAlias::FAILURE;
        }

        $displayName = $this->resolveDisplayName($themeKey);
        $extends = $this->resolveExtends();

        if (! $this->isValidSlug($extends)) {
            $this->error('The parent theme key must use lowercase letters, numbers, and hyphens.');

            return CommandAlias::FAILURE;
        }

        $namespace = $this->namespaceForPackage($packageName);

        ScaffoldThemePackageAction::run(new ThemeScaffoldInputData(
            packageName: $packageName,
            namespace: $namespace,
            slug: Str::of($packageName)->after('/')->replace('_', '-')->slug()->toString(),
            themeKey: $themeKey,
            displayName: $displayName,
            targetPath: $targetDirectory,
            extends: $extends,
            local: $this->option('local') === true,
        ));

        $this->info(sprintf('Created Capell theme: %s', $themeKey));
        $this->line($targetDirectory);

        return CommandAlias::SUCCESS;
    }

    private function resolveThemeKey(): ?string
    {
        $themeKey = $this->argument('theme');

        if (is_string($themeKey) && $themeKey !== '') {
            return $themeKey;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $this->ask('Theme key, for example equidynamics');

        return is_string($answer) ? $answer : null;
    }

    private function resolvePackageName(string $themeKey): string
    {
        $packageName = $this->option('package');

        if (is_string($packageName) && $packageName !== '') {
            return $packageName;
        }

        return 'app/' . $themeKey . '-theme';
    }

    private function resolveDisplayName(string $themeKey): string
    {
        $displayName = $this->option('name');

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        return Str::of($themeKey)->replace('-', ' ')->title()->toString();
    }

    private function resolveExtends(): string
    {
        $extends = $this->option('extends');

        return is_string($extends) && $extends !== '' ? $extends : 'default';
    }

    private function targetDirectory(string $packageName): ?string
    {
        $basePath = $this->option('path');

        if (! is_string($basePath)) {
            return null;
        }

        if ($basePath === '' || str_contains($basePath, '..') || str_contains($basePath, "\0")) {
            return null;
        }

        if (! str_starts_with($basePath, DIRECTORY_SEPARATOR)) {
            $basePath = getcwd() . DIRECTORY_SEPARATOR . $basePath;
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . Str::after($packageName, '/');
    }

    private function directoryIsNotEmpty(string $directory): bool
    {
        $items = scandir($directory);

        return $items !== false && array_values(array_diff($items, ['.', '..'])) !== [];
    }

    private function isValidSlug(string $value): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*[a-z0-9]$/', $value) === 1;
    }

    private function isValidComposerPackageName(string $packageName): bool
    {
        if (str_contains($packageName, '..') || str_contains($packageName, '\\')) {
            return false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $packageName) === 1;
    }

    private function namespaceForPackage(string $packageName): string
    {
        return collect(explode('/', $packageName))
            ->map(static fn (string $part): string => Str::studly(str_replace(['-', '_', '.'], ' ', $part)))
            ->implode('\\');
    }
}
