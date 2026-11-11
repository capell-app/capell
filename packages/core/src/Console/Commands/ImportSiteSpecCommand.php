<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\ApplySiteSpecToSiteAction;
use Capell\Core\Actions\BuildSiteFromSpecFileAction;
use Capell\Core\Actions\PlanSiteSpecAction;
use Capell\Core\Actions\ReadSiteSpecFileAction;
use Capell\Core\Data\SiteSpec\SiteSpecPlanData;
use Capell\Core\Data\SiteSpec\SiteSpecPlanItemData;
use Capell\Core\Enums\SiteSpecPlanAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\SiteSpec\SiteSpecHasher;
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
        {--keep-published=* : Original spec slugs whose later publication decisions must be preserved}
        {--site= : Apply the spec to this existing site (by ID) instead of creating a new one}
        {--plan : Show what applying the spec would create, update and leave alone, without writing anything}';

    protected $description = 'Import a deterministic Capell site from a SiteSpec JSON file.';

    public function handle(): int
    {
        $path = $this->argument('spec');

        if (! is_string($path) || $path === '') {
            $this->components->error((string) __('capell::message.site_spec_import_path_required'));

            return CommandAlias::FAILURE;
        }

        $planOnly = (bool) $this->option('plan');
        $siteOption = $this->option('site');

        if ($planOnly || $siteOption !== null) {
            return $this->planOrApply($path, $planOnly, is_string($siteOption) ? $siteOption : null);
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

    private function planOrApply(string $path, bool $planOnly, ?string $siteOption): int
    {
        if ($this->option('audit-private') || $this->option('repair-private') || $this->option('keep-published') !== []) {
            $this->components->error((string) __('capell::message.site_spec_options_conflict'));

            return CommandAlias::FAILURE;
        }

        try {
            $spec = ReadSiteSpecFileAction::run($path);
            $site = $siteOption === null ? null : Site::query()->find($siteOption);

            if ($siteOption !== null && ! $site instanceof Site) {
                $this->components->error((string) __('capell::message.site_spec_site_not_found', ['id' => $siteOption]));

                return CommandAlias::FAILURE;
            }

            if ($planOnly) {
                // With no site named, plan against the site this spec was last applied to, if any.
                $site ??= Site::query()->where('meta->site_spec_hash', SiteSpecHasher::hash($spec))->first();
                $plan = PlanSiteSpecAction::run($spec, $site);
                $this->renderPlan($plan);
                $this->components->info((string) __('capell::message.site_spec_plan_summary', $this->planCounts($plan)));

                return CommandAlias::SUCCESS;
            }

            throw_unless($site instanceof Site, RuntimeException::class, 'Name the site to apply the spec to with --site.');
            $plan = ApplySiteSpecToSiteAction::run($spec, $site);
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

        $this->renderPlan($plan);
        $this->components->info((string) __('capell::message.site_spec_apply_complete', ['name' => $site->name, 'id' => $site->getKey(), ...$this->planCounts($plan)]));

        return CommandAlias::SUCCESS;
    }

    private function renderPlan(SiteSpecPlanData $plan): void
    {
        $this->table(['Kind', 'Key', 'Action', 'Changes'], array_map(
            static fn (SiteSpecPlanItemData $item): array => [$item->kind, $item->key, $item->action->value, implode(', ', $item->changes) . ($item->note === null ? '' : ' (' . __('capell::message.site_spec_plan_note_' . $item->note) . ')')],
            $plan->items,
        ));

        if ($plan->appliers !== []) {
            $this->components->warn((string) __('capell::message.site_spec_plan_appliers', ['appliers' => implode(', ', $plan->appliers)]));
        }

        if (! $plan->hasChanges()) {
            $this->components->info((string) __('capell::message.site_spec_plan_nothing'));
        }
    }

    /** @return array{created: int, updated: int, skipped: int} */
    private function planCounts(SiteSpecPlanData $plan): array
    {
        return [
            'created' => $plan->count(SiteSpecPlanAction::Create),
            'updated' => $plan->count(SiteSpecPlanAction::Update),
            'skipped' => $plan->count(SiteSpecPlanAction::Skip),
        ];
    }
}
