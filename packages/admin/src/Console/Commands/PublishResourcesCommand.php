<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Enums\AdminSurfaceContributionType;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\AdminRuntimeActivator;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Capell\Core\Console\Commands\Concerns\PromptsWithOptionFallback;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\select;

use ReflectionClass;

class PublishResourcesCommand extends Command
{
    use DescribesCommandOptions;
    use PromptsWithOptionFallback;

    protected $signature = 'capell:admin-publish-resources'
        . ' {--F|force : Overwrite existing resource files if they already exist}'
        . ' {--type= : Only publish resources of the given type}'
        . ' {--resource= : Only publish the specified resource (by label or class name)}';

    protected $description = 'Publish capell resources to the local project (use --force to overwrite existing files, --type to filter by type)';

    /** @var list<string> */
    private array $publishedFiles = [];

    /** @var list<string> */
    private array $skippedFiles = [];

    public function handle(): int
    {
        resolve(AdminRuntimeActivator::class)->activate();

        $this->writeCommandIntro('publish Capell admin resources', $this->enabledOptionDetails([
            'force' => 'overwrite enabled',
            'type' => 'a selected resource type',
            'resource' => 'a selected resource',
        ]));

        if (! $this->isQuiet()) {
            $this->line('Publishing resource files...');
        }

        $resourceGroups = $this->getResources();
        $typeOption = $this->option('type');
        $resourceOption = $this->option('resource');
        $type = is_string($typeOption) ? $typeOption : null;
        $resource = is_string($resourceOption) ? $resourceOption : null;

        // If no type provided and interactive, prompt for type
        if ($type === null && $resourceGroups !== [] && $this->input->isInteractive()) {
            $types = array_keys($resourceGroups);
            sort($types, SORT_NATURAL | SORT_FLAG_CASE);
            $typesWithAll = array_merge(['All'], $types);
            $typeChoice = select('Select resource type to publish', $typesWithAll, default: 'All');
            $type = is_string($typeChoice) && $typeChoice !== 'All' ? $typeChoice : null;
        }

        // If type is set, filter resourceGroups
        if ($type !== null) {
            $resourceGroups = collect($resourceGroups)
                ->filter(fn (array $resources, string|int $key): bool => strtolower($key) === strtolower($type))
                ->all();
        }

        // If type is set but no resource and interactive, prompt for resource
        if ($type !== null && $resource === null && $resourceGroups !== [] && $this->input->isInteractive()) {
            $resources = collect($resourceGroups)->first();
            if (is_array($resources) && count($resources) > 1) {
                $labels = array_keys($resources);
                sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
                $labelsWithAll = array_merge(['All'], $labels);
                $resourceChoice = select('Select resource to publish', $labelsWithAll, default: 'All');
                $resource = is_string($resourceChoice) && $resourceChoice !== 'All' ? $resourceChoice : null;
            } elseif (is_array($resources) && count($resources) === 1) {
                $resourceKey = array_key_first($resources);
                $resource = is_string($resourceKey) ? $resourceKey : null;
            }
        }

        if ($resource !== null) {
            return $this->handleResourceOption($resourceGroups, $resource);
        }

        if ($type !== null) {
            return $this->handleTypeOption($resourceGroups, $type);
        }

        return $this->publishAllResources($resourceGroups);
    }

    /**
     * @return array<string, array<string, class-string>>
     */
    private function getResources(): array
    {
        $resources = [];

        foreach (CapellAdmin::getAdminSurfaceContributions(AdminSurfaceContributionType::Resource) as $contribution) {
            if (! $contribution instanceof AdminSurfaceContributionData) {
                continue;
            }

            if ($contribution->group === null) {
                continue;
            }

            if (! class_exists($contribution->class)) {
                continue;
            }

            $resources[$contribution->group][$contribution->name] = $contribution->class;
        }

        return $resources;
    }

    /**
     * @param  array<string, array<string, class-string>>  $resourceGroups
     */
    private function handleResourceOption(array $resourceGroups, string $resource): int
    {
        $allResources = collect($resourceGroups)->flatMap(fn (array $resources): array => $resources);

        $matched = $allResources->filter(fn (string $class, string $label): bool => strtolower($label) === strtolower($resource)
            || strtolower($class) === strtolower($resource)
            || strtolower(class_basename($class)) === strtolower($resource));
        if ($matched->isEmpty()) {
            $this->error('No resource found matching: ' . $resource);
            $this->logCompletion();

            return Command::FAILURE;
        }

        foreach ($matched as $class) {
            $publishedPath = $this->publishSingleResource($class);
            if ($publishedPath !== null && $publishedPath !== '') {
                $this->publishedFiles[] = $publishedPath;
            }
        }

        $this->logCompletion();

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, array<string, class-string>>  $resourceGroups
     */
    private function handleTypeOption(array $resourceGroups, string $type): int
    {
        $filteredGroups = collect($resourceGroups)
            ->filter(fn (array $resources, string|int $key): bool => strtolower($key) === strtolower($type))
            ->all();

        if ($filteredGroups === []) {
            $this->warn('No resources found for type: ' . $type);
            $this->logCompletion();

            return Command::SUCCESS;
        }

        foreach ($filteredGroups as $resources) {
            $this->publishResourceGroup($resources);
        }

        $this->logCompletion();

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, array<string, class-string>>  $resourceGroups
     */
    private function publishAllResources(array $resourceGroups): int
    {
        foreach ($resourceGroups as $resources) {
            $this->publishResourceGroup($resources);
        }

        $this->logCompletion();

        return Command::SUCCESS;
    }

    private function getResourceDestinationPath(?string $resourceName = null): string
    {
        $basePath = app_path('Filament' . DIRECTORY_SEPARATOR . 'Resources');
        if ($resourceName === null || $resourceName === '') {
            return $basePath;
        }

        $resourceName = str_replace('\\', DIRECTORY_SEPARATOR, $resourceName);

        return $basePath . DIRECTORY_SEPARATOR . $resourceName;
    }

    /**
     * @param  array<string, class-string>  $resources
     */
    private function publishResourceGroup(array $resources): void
    {
        foreach ($resources as $resourceClass) {
            $publishedPath = $this->publishSingleResource($resourceClass);
            if ($publishedPath !== null && $publishedPath !== '') {
                $this->publishedFiles[] = $publishedPath;
            }
        }
    }

    private function publishSingleResource(string $resourceClass): ?string
    {
        if (! class_exists($resourceClass)) {
            $this->error(sprintf('Resource class %s does not exist.', $resourceClass));

            return null;
        }

        $reflector = new ReflectionClass($resourceClass);
        $sourceFilePath = $reflector->getFileName();

        if (! is_string($sourceFilePath)) {
            $this->error(sprintf('Resource class %s does not have a source file.', $resourceClass));

            return null;
        }

        $destinationFilePath = $this->getDestinationFilePath($resourceClass);
        if (File::exists($destinationFilePath) && ! $this->option('force')) {
            $this->skippedFiles[] = $destinationFilePath;

            return null;
        }

        $fileContent = file_get_contents($sourceFilePath);

        if ($fileContent === false) {
            $this->error(sprintf('Unable to read resource class %s from "%s".', $resourceClass, $sourceFilePath));

            return null;
        }

        $updatedContent = $this->updateNamespace($resourceClass, $fileContent);
        $this->writeToFile($destinationFilePath, $updatedContent);

        return $destinationFilePath;
    }

    private function getDestinationFilePath(string $resourceClass): string
    {
        $relativeDir = Str::after($resourceClass, 'Filament\\Resources\\');
        $basename = Str::afterLast($resourceClass, '\\');
        $relativeDir = Str::beforeLast($relativeDir, '\\' . $basename);
        $relativeDirPath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeDir);
        $fileName = class_basename($resourceClass) . '.php';
        $destinationFilePath = $this->getResourceDestinationPath($relativeDirPath . DIRECTORY_SEPARATOR . $fileName);
        File::ensureDirectoryExists(dirname($destinationFilePath));

        return $destinationFilePath;
    }

    private function updateNamespace(string $resourceClass, string $fileContent): string
    {
        $namespace = Str::beforeLast($resourceClass, '\\');
        $relativeNamespace = Str::after($namespace, 'Filament\\Resources\\');
        $newNamespace = 'App\\Filament\\Resources';
        if ($relativeNamespace !== '') {
            $newNamespace .= '\\' . $relativeNamespace;
        }

        $newNamespace = str_replace('\\', '\\', $newNamespace);

        return preg_replace('/^namespace\s+[^;]+;/m', 'namespace ' . $newNamespace . ';', $fileContent) ?? $fileContent;
    }

    private function writeToFile(string $destinationFilePath, string $updatedContent): void
    {
        if (File::put($destinationFilePath, $updatedContent) === false) {
            $this->error(sprintf('Failed to publish resource to "%s". Check folder permissions or create it manually.', $destinationFilePath));
        }
    }

    private function logCompletion(): void
    {
        if ($this->isQuiet()) {
            return;
        }

        $destinationPath = $this->getResourceDestinationPath();
        $this->newLine();
        $this->line('Resource publish under: ' . $destinationPath);
        $publishedCount = count($this->publishedFiles);
        $skippedCount = count($this->skippedFiles);
        if ($publishedCount !== 0) {
            $this->info("\n\033[32m✔ Published {$publishedCount} file" . ($publishedCount === 1 ? '' : 's') . "\033[0m");
            $this->outputFiles($this->publishedFiles, 'published');
        } else {
            $this->warn('No new resource files were published.');
        }

        if ($skippedCount !== 0) {
            $this->newLine();
            $this->warn("\n\033[33m⚠ Skipped {$skippedCount} file" . ($skippedCount === 1 ? '' : 's') . " (already existed):\033[0m");
            $this->outputFiles($this->skippedFiles, 'skipped');
            $this->warn('Use --force to overwrite skipped files.');
        }

        $this->newLine();
        $this->line(str_repeat('=', 48));
        $this->info("Summary: \033[32m{$publishedCount} published\033[0m, \033[33m{$skippedCount} skipped\033[0m");
        $this->line(str_repeat('=', 48));
    }

    /**
     * @param  list<string>  $filePaths
     */
    private function outputFiles(array $filePaths, string $mode = 'published'): void
    {
        $color = $mode === 'published' ? '32' : '33';
        foreach ($filePaths as $path) {
            $fileName = basename((string) $path, '.php');
            $this->line("\033[1;{$color}m• {$fileName}\033[0m [{$path}]");
        }
    }

    private function isQuiet(): bool
    {
        if ($this->option('quiet') !== null && $this->option('quiet') !== false) {
            return true;
        }

        return $this->getOutput()->isQuiet();
    }
}
