<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Scaffolding\ScaffoldPackageAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Data\Scaffolding\PackageScaffoldInputData;
use Capell\Core\Enums\PackageScaffoldProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class MakeExtensionCommand extends Command
{
    use DescribesCommandOptions;

    /** @var array<int, string> */
    protected $aliases = ['capell:make:extension'];

    protected $signature = 'capell:make-extension
        {package? : Composer package name, for example vendor/example}
        {--name= : Human-readable display name}
        {--profile= : Scaffold profile: minimal or full}
        {--premium : Scaffold the manifest as a premium extension}
        {--path= : Directory to create the package inside}';

    protected $description = 'Scaffold a Capell package with a manifest v3 contract.';

    public function handle(ScaffoldPackageAction $scaffoldPackage): int
    {
        $details = $this->enabledOptionDetails([
            'name' => 'a custom display name',
            'profile' => 'a scaffold profile',
            'premium' => 'premium manifest defaults',
        ]);

        if ($this->optionWasProvided('path')) {
            $details[] = 'a custom package path';
        }

        $this->writeCommandIntro('scaffold a Capell package', $details);

        $packageName = $this->resolvePackageName();

        if ($packageName === null || $packageName === '') {
            $this->error('Missing required package argument. Pass a Composer package name like vendor/example.');

            return CommandAlias::FAILURE;
        }

        if (! $this->isValidComposerPackageName($packageName)) {
            $this->error('The package argument must be a valid Composer package name like vendor/example.');

            return CommandAlias::FAILURE;
        }

        if ($this->isReservedPlatformPackage($packageName)) {
            $this->error('The capell-app and capell vendor namespaces are reserved for Capell platform packages.');

            return CommandAlias::FAILURE;
        }

        $profile = $this->resolveProfile();

        if (! $profile instanceof PackageScaffoldProfile) {
            $message = $this->optionWasProvided('profile')
                ? 'Invalid profile. Pass --profile=minimal or --profile=full.'
                : 'Missing required profile. Pass --profile=minimal or --profile=full.';

            $this->error($message);

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

        $namespace = $this->namespaceForPackage($packageName);
        $displayName = $this->resolveDisplayName($packageName);
        $slug = Str::of($packageName)->after('/')->replace('_', '-')->slug()->toString();
        $tier = $this->option('premium') === true ? 'premium' : 'free';

        ScaffoldPackageAction::run(new PackageScaffoldInputData(
            packageName: $packageName,
            namespace: $namespace,
            slug: $slug,
            displayName: $displayName,
            tier: $tier,
            targetPath: $targetDirectory,
            profile: $profile,
        ));

        $this->info(sprintf('Created Capell package: %s', $packageName));
        $this->line($targetDirectory);

        return CommandAlias::SUCCESS;
    }

    private function resolvePackageName(): ?string
    {
        $packageName = $this->argument('package');

        if (is_string($packageName) && $packageName !== '') {
            return $packageName;
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $this->ask('Composer package name, for example vendor/example');

        return is_string($answer) ? $answer : null;
    }

    private function resolveProfile(): ?PackageScaffoldProfile
    {
        $profile = $this->option('profile');

        if (is_string($profile) && $profile !== '') {
            return PackageScaffoldProfile::tryFrom($profile);
        }

        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $this->choice('Scaffold profile', PackageScaffoldProfile::values(), PackageScaffoldProfile::Minimal->value);

        return is_string($answer) ? PackageScaffoldProfile::tryFrom($answer) : null;
    }

    private function resolveDisplayName(string $packageName): string
    {
        $displayName = $this->option('name');

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        $default = Str::of($packageName)->after('/')->replace(['-', '_'], ' ')->title()->toString();

        if (! $this->input->isInteractive()) {
            return $default;
        }

        $answer = $this->ask('Display name', $default);

        return is_string($answer) && $answer !== '' ? $answer : $default;
    }

    private function isValidComposerPackageName(string $packageName): bool
    {
        if (str_contains($packageName, '..') || str_contains($packageName, '\\')) {
            return false;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $packageName) === 1;
    }

    private function isReservedPlatformPackage(string $packageName): bool
    {
        return str_starts_with($packageName, 'capell-app/')
            || str_starts_with($packageName, 'capell/');
    }

    private function targetDirectory(string $packageName): ?string
    {
        $basePath = $this->option('path');

        if ((! is_string($basePath) || $basePath === '') && $this->input->isInteractive()) {
            $basePath = $this->ask('Target directory');
        }

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

    private function namespaceForPackage(string $packageName): string
    {
        return collect(explode('/', $packageName))
            ->map(static fn (string $part): string => Str::studly(str_replace(['-', '_', '.'], ' ', $part)))
            ->implode('\\');
    }
}
