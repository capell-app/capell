<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildPublishingReadinessReportAction;
use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Capell\Admin\Filament\Pages\Reports\PublishingReadinessReport;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('admin', 'reports');

it('reports ready pages without findings when required translations and urls exist', function (): void {
    [$site, $blueprint, $english, $welsh] = publishingReadinessSiteContext();

    $page = Page::factory()
        ->site($site)
        ->blueprint($blueprint)
        ->withTranslations([$english, $welsh])
        ->createOne(['name' => 'Ready launch page']);

    publishingReadinessUrl($page, $site, $english, '/ready-launch-page');
    publishingReadinessUrl($page, $site, $welsh, '/cy/ready-launch-page');

    $snapshot = BuildPublishingReadinessReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');

    expect($snapshot->key)->toBe('core.publishing_readiness')
        ->and($snapshot->findings)->toBe([])
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_pages_checked')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_ready_pages')])->toBe(1)
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_blocked_pages')])->toBe(0);
});

it('reports missing required translations and urls as blockers', function (): void {
    [$site, $blueprint, $english, $welsh] = publishingReadinessSiteContext();

    $page = Page::factory()
        ->site($site)
        ->blueprint($blueprint)
        ->withTranslations($english)
        ->createOne(['name' => 'Incomplete launch page']);

    publishingReadinessUrl($page, $site, $english, '/incomplete-launch-page');

    $snapshot = BuildPublishingReadinessReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');
    $titles = collect($snapshot->findings)->pluck('title')->all();

    expect($metrics[__('capell-admin::reports.publishing_readiness_metric_ready_pages')])->toBe(0)
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_blocked_pages')])->toBe(1)
        ->and(collect($snapshot->findings)->pluck('severity')->unique()->all())->toBe([ReportFindingSeverity::Critical])
        ->and($titles)->toContain(
            __('capell-admin::reports.publishing_readiness_missing_translation_title', ['language' => $welsh->name]),
            __('capell-admin::reports.publishing_readiness_missing_url_title', ['language' => $welsh->name]),
        );
});

it('reports scheduled pages as warnings without marking them blocked', function (): void {
    [$site, $blueprint, $english] = publishingReadinessSiteContext(requiredLanguages: false);

    $page = Page::factory()
        ->site($site)
        ->blueprint($blueprint)
        ->withTranslations($english)
        ->createOne([
            'name' => 'Scheduled launch page',
            'visible_from' => now()->addDays(2),
        ]);

    publishingReadinessUrl($page, $site, $english, '/scheduled-launch-page');

    $snapshot = BuildPublishingReadinessReportAction::run();
    $metrics = collect($snapshot->metrics)->pluck('value', 'label');

    expect($metrics[__('capell-admin::reports.publishing_readiness_metric_ready_pages')])->toBe(0)
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_blocked_pages')])->toBe(0)
        ->and($metrics[__('capell-admin::reports.publishing_readiness_metric_scheduled_pages')])->toBe(1)
        ->and($snapshot->findings[0]->severity)->toBe(ReportFindingSeverity::Warning)
        ->and($snapshot->findings[0]->title)->toBe(__('capell-admin::reports.publishing_readiness_scheduled_title'));
});

it('reports soft-deleted blueprints without trying to build an edit url', function (): void {
    [$site, $blueprint, $english] = publishingReadinessSiteContext(requiredLanguages: false);

    $page = Page::factory()
        ->site($site)
        ->blueprint($blueprint)
        ->withTranslations($english)
        ->createOne(['name' => 'Orphaned launch page']);

    publishingReadinessUrl($page, $site, $english, '/orphaned-launch-page');

    $blueprint->delete();

    $snapshot = BuildPublishingReadinessReportAction::run();
    $finding = collect($snapshot->findings)
        ->firstWhere('title', __('capell-admin::reports.publishing_readiness_missing_blueprint_title'));

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(ReportFindingSeverity::Critical)
        ->and($finding->recordLabel)->toBe('Orphaned launch page (Launch Site)')
        ->and($finding->actionLabel)->toBe(__('capell-admin::reports.action_edit_page'))
        ->and($finding->url)->toBeNull();
});

it('renders publishing readiness findings on the report page', function (): void {
    test()->actingAsAdmin();

    [$site, $blueprint, $english, $welsh] = publishingReadinessSiteContext();

    Page::factory()
        ->site($site)
        ->blueprint($blueprint)
        ->withTranslations($english)
        ->createOne(['name' => 'Page visible in report']);

    Livewire::test(PublishingReadinessReport::class)
        ->assertSuccessful()
        ->assertSee(__('capell-admin::reports.findings_heading'))
        ->assertSee('Page visible in report')
        ->assertSee(__('capell-admin::reports.publishing_readiness_missing_translation_title', ['language' => $welsh->name]));
});

/**
 * @return array{0:Site,1:Blueprint,2:Language,3:Language}
 */
function publishingReadinessSiteContext(bool $requiredLanguages = true): array
{
    $english = Language::factory()->english()->createOne();
    $welsh = Language::factory()->forCountry('Welsh', 'cy', 'cy', 'gb-wls', order: 2)->createOne();

    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $welsh])
        ->createOne([
            'name' => 'Launch Site',
            'admin' => [
                'require_translations' => $requiredLanguages ? [$english->code, $welsh->code] : [],
            ],
        ]);

    $blueprint = Blueprint::factory()
        ->page()
        ->createOne([
            'name' => 'Launch Page',
            'admin' => [
                'require_translations' => $requiredLanguages ? [$english->code, $welsh->code] : false,
            ],
        ]);

    return [$site, $blueprint, $english, $welsh];
}

function publishingReadinessUrl(Page $page, Site $site, Language $language, string $url, bool $status = true): PageUrl
{
    return PageUrl::factory()
        ->page($page)
        ->site($site)
        ->state([
            'language_id' => $language->id,
            'status' => $status,
            'url' => $url,
        ])
        ->createOne();
}
