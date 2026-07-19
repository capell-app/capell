<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class ImportSiteSpecCommand extends Command
{
    protected $signature = 'capell:site-spec-import
        {spec : Absolute or application-relative path to a Capell SiteSpec JSON file}';

    protected $description = 'Import a deterministic Capell site from a SiteSpec JSON file.';

    public function handle(): int
    {
        $path = $this->argument('spec');

        if (! is_string($path) || $path === '') {
            $this->components->error((string) __('capell::message.site_spec_import_path_required'));

            return CommandAlias::FAILURE;
        }

        try {
            $site = BuildSiteFromSpecFileAction::run($path);
        } catch (ValidationException $validationException) {
            $this->components->error((string) __('capell::message.site_spec_import_validation_failed'));

            foreach ($validationException->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->components->bulletList([sprintf('%s: %s', $field, $message)]);
                }
            }

            return CommandAlias::FAILURE;
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->components->info((string) __('capell::message.site_spec_import_complete', [
            'name' => $site->name,
            'id' => $site->getKey(),
            'pages' => $site->pages()->count(),
        ]));

        return CommandAlias::SUCCESS;
    }
}
