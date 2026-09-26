<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\GetComponentViewPathAction;
use Capell\Core\Facades\CapellCore;
use Exception;
use Illuminate\Console\Command;
use Throwable;

class PublishComponentsCommand extends Command
{
    protected $signature = 'capell:publish-components';

    protected $description = 'Publish capell components to the local project';

    public function handle(): int
    {
        $this->comment('Publishing component files...');
        $totals = ['published' => 0, 'skipped' => 0, 'failed' => 0];

        foreach (CapellCore::getCoreComponents() as $groupType => $components) {
            if (! is_string($groupType)) {
                continue;
            }

            if (! is_array($components)) {
                continue;
            }

            foreach ($this->publishComponents($groupType, $this->stringComponents($components)) as $outcome => $count) {
                $totals[$outcome] += $count;
            }
        }

        $this->newLine();
        $this->line(__('capell::message.component_publication_summary', $totals));

        if ($totals['failed'] > 0) {
            $this->error(__('capell::message.component_publication_failed'));

            return Command::FAILURE;
        }

        $this->info('Finished publishing components.');

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, string>  $components
     * @return array{published: int, skipped: int, failed: int}
     */
    private function publishComponents(string $groupType, array $components): array
    {
        $totals = ['published' => 0, 'skipped' => 0, 'failed' => 0];
        $this->newLine();
        $this->comment(sprintf('Publishing %s components...', $groupType));

        foreach ($components as $componentLabel => $component) {
            try {
                $published = $this->publishComponent($component);
                $totals[$published ? 'published' : 'skipped']++;

                $this->line($component);
            } catch (Throwable $exception) {
                $totals['failed']++;
                $this->error(sprintf('%s: %s', $componentLabel, $exception->getMessage()));
            }
        }

        return $totals;
    }

    private function publishComponent(string $component): bool
    {
        $viewFile = GetComponentViewPathAction::run($component);

        if (str_starts_with($viewFile, resource_path() . DIRECTORY_SEPARATOR)) {
            return false;
        }

        $destPath = $this->getDestinationFilePath($viewFile);

        $content = file_get_contents($viewFile);

        throw_if($content === false, Exception::class, sprintf('Failed to read component file "%s".', $viewFile));

        $this->writeToFile($destPath, $content);

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $components
     * @return array<string, string>
     */
    private function stringComponents(array $components): array
    {
        $stringComponents = [];

        foreach ($components as $label => $component) {
            if (! is_string($label)) {
                continue;
            }

            if (! is_string($component)) {
                continue;
            }

            $stringComponents[$label] = $component;
        }

        return $stringComponents;
    }

    private function getDestinationFilePath(string $viewFile): string
    {
        $filePath = str($viewFile)->after('resources/views/')->toString();

        $namespace = $this->getNamespaceForFile($viewFile);

        $destPath = resource_path(sprintf('views/vendor/%s/%s', $namespace, $filePath));

        if (! is_dir(dirname($destPath))) {
            throw_unless(mkdir(dirname($destPath), 0755, true), Exception::class, __('capell::message.component_directory_failed', ['path' => dirname($destPath)]));
        }

        return $destPath;
    }

    private function getNamespaceForFile(string $viewFile): string
    {
        foreach (CapellCore::getPackages() as $package) {
            if ($package->path !== null && $package->path !== '' && str_starts_with($viewFile, $package->path)) {
                return $package->name;
            }
        }

        throw new Exception(sprintf('Could not determine namespace for component file "%s".', $viewFile));
    }

    private function writeToFile(string $destPath, string $content): void
    {
        throw_if(file_put_contents($destPath, $content) !== strlen($content), Exception::class, sprintf('Failed to publish component to "%s". Check folder permissions or create it manually.', $destPath));
    }
}
