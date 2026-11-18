<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\ExportSiteSpecAction;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecJson;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Console\Output\OutputInterface;

final class ExportSiteSpecCommand extends Command
{
    protected $signature = 'capell:site-spec-export
        {site : The ID of the site to export}
        {--output= : Write the SiteSpec to this file instead of printing it}
        {--public : Mark the spec public and acknowledged, so applying it to another copy of the site updates live pages}';

    protected $description = 'Write a site out as a SiteSpec JSON file that is identical every time the site is unchanged, for keeping in Git and applying to another environment.';

    public function handle(): int
    {
        $siteId = (string) $this->argument('site');
        $site = Site::query()->find($siteId);

        if (! $site instanceof Site) {
            $this->components->error((string) __('capell-core::message.site_spec_export_site_not_found', ['id' => $siteId]));

            return CommandAlias::FAILURE;
        }

        try {
            $export = ExportSiteSpecAction::run($site, (bool) $this->option('public'));
        } catch (ValidationException $validationException) {
            $this->components->error((string) __('capell-core::message.site_spec_export_invalid'));

            foreach ($validationException->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->components->bulletList([sprintf('%s: %s', $field, $message)]);
                }
            }

            return CommandAlias::FAILURE;
        }

        $json = SiteSpecJson::encode($export->spec);
        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            // Standard output carries only the JSON, so it can be redirected to a file; notes go to standard error.
            $this->output->write($json, false, OutputInterface::OUTPUT_RAW);

            foreach ($this->omissions($export->omittedPages) as $warning) {
                $this->output->getErrorStyle()->warning($warning);
            }

            return CommandAlias::SUCCESS;
        }

        if (! $this->writable($output) || file_put_contents($output, $json) === false) {
            $this->components->error((string) __('capell-core::message.site_spec_export_not_written', ['path' => $output]));

            return CommandAlias::FAILURE;
        }

        foreach ($this->omissions($export->omittedPages) as $warning) {
            $this->components->warn($warning);
        }

        $this->components->info(trans_choice('capell-core::message.site_spec_export_written', count($export->spec->pages), [
            'name' => $site->name,
            'id' => $site->getKey(),
            'pages' => count($export->spec->pages),
            'path' => $output,
        ]));

        return CommandAlias::SUCCESS;
    }

    /**
     * @param  array<string, list<string>>  $omitted
     * @return list<string>
     */
    private function omissions(array $omitted): array
    {
        $warnings = [];

        foreach ($omitted as $reason => $pages) {
            $warnings[] = (string) __('capell-core::message.site_spec_export_omitted_' . $reason, ['pages' => implode(', ', $pages)]);
        }

        return $warnings;
    }

    private function writable(string $path): bool
    {
        $directory = dirname($path);

        return is_dir($directory) && (is_file($path) ? is_writable($path) : is_writable($directory));
    }
}
