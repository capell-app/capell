<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Models\Page;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class ImportSiteSpecCommand extends Command
{
    protected $signature = 'capell:site-spec-import
        {spec : Absolute or application-relative path to a Capell SiteSpec JSON file}
        {--audit-private : List the existing imported pages without changing publication state}
        {--repair-private : Repair a legacy private import after reviewing its publication decisions}
        {--keep-published=* : Original spec slugs whose later publication decisions must be preserved}';

    protected $description = 'Import a deterministic Capell site from a SiteSpec JSON file.';

    public function handle(): int
    {
        $path = $this->argument('spec');

        if (! is_string($path) || $path === '') {
            $this->components->error((string) __('capell::message.site_spec_import_path_required'));

            return CommandAlias::FAILURE;
        }

        try {
            /** @var list<string> $keepPublished */
            $keepPublished = $this->option('keep-published');
            $repair = (bool) $this->option('repair-private');
            $audit = (bool) $this->option('audit-private');
            throw_if(($audit && $repair) || ($keepPublished !== [] && ! $repair), RuntimeException::class, 'Use --keep-published with --repair-private; run --audit-private separately.');
            $site = BuildSiteFromSpecFileAction::run($path, $repair, $keepPublished, $audit);
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

        if ($audit) {
            $this->table(['ID', 'Page', 'Slug', 'Visible from'], $site->pages()->with('translations')->get()->map(
                fn (Page $page): array => [$page->id, $page->name, $page->translations->firstWhere('language_id', $site->language_id)?->slug, $page->visible_from?->toDateString() ?? 'NULL (review required)'],
            )->all());

            return CommandAlias::SUCCESS;
        }

        $this->components->info((string) __('capell::message.site_spec_import_complete', [
            'name' => $site->name,
            'id' => $site->getKey(),
            'pages' => $site->pages()->count(),
        ]));

        return CommandAlias::SUCCESS;
    }
}
