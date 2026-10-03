<?php

declare(strict_types=1);

use Capell\Admin\Actions\Reports\BuildAccessibilityReadinessReportAction;
use Capell\Admin\Actions\Reports\BuildPublishingReadinessReportAction;
use Capell\Admin\Data\Dashboard\CapellOverviewStatData;
use Capell\Admin\Data\Reports\ReportSnapshotData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class)
    ->group('admin', 'reports');

/**
 * Three sites, each with one page that has no translation in the site's
 * default language, so every page produces findings that name it.
 *
 * @return array{alpha: Site, beta: Site, gamma: Site}
 */
function reportSiteScopeFixture(): array
{
    $sites = [];

    foreach (['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'] as $key => $label) {
        $site = Site::factory()->create(['name' => sprintf('Scope %s Site', $label)]);
        SiteDomain::factory()->site($site)->create([
            'domain' => sprintf('scope-%s.example.test', $key),
            'language_id' => $site->language_id,
        ]);
        Page::factory()->site($site)->create(['name' => sprintf('Scope %s page', $label)]);

        $sites[$key] = $site;
    }

    return $sites;
}

function actAsReportSiteScopedUser(Site $site): void
{
    $user = User::factory()->createOne();
    $user->assignedSiteIds = collect([(int) $site->getKey()]);

    test()->actingAs($user);
}

function reportSiteScopeMetric(ReportSnapshotData $snapshot, string $translationKey): int|string|null
{
    return collect($snapshot->metrics)->pluck('value', 'label')->get(__($translationKey));
}

function reportSiteScopeOverviewStat(string $key): ?string
{
    $stat = collect(CapellAdmin::getOverviewStats(false))
        ->first(fn (CapellOverviewStatData $stat): bool => $stat->key === $key);

    return $stat instanceof CapellOverviewStatData ? $stat->value : null;
}

it('limits the publishing readiness report to the sites assigned to a site-scoped user', function (): void {
    $sites = reportSiteScopeFixture();
    actAsReportSiteScopedUser($sites['alpha']);

    $snapshot = BuildPublishingReadinessReportAction::run();
    $payload = $snapshot->toJson();

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.publishing_readiness_metric_pages_checked'))->toBe(1)
        ->and(collect($snapshot->findings)->pluck('recordLabel')->filter()->unique()->values()->all())
        ->toBe(['Scope Alpha page (Scope Alpha Site)'])
        ->and($payload)->not->toContain('Scope Beta')
        ->and($payload)->not->toContain('Scope Gamma')
        ->and($payload)->not->toContain('scope-beta.example.test')
        ->and($payload)->not->toContain('scope-gamma.example.test');
});

it('shows every site in the publishing readiness report to a global user', function (): void {
    reportSiteScopeFixture();
    test()->actingAsAdmin();

    $snapshot = BuildPublishingReadinessReportAction::run();

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.publishing_readiness_metric_pages_checked'))->toBe(3)
        ->and(collect($snapshot->findings)->pluck('recordLabel')->filter()->unique()->sort()->values()->all())->toBe([
            'Scope Alpha page (Scope Alpha Site)',
            'Scope Beta page (Scope Beta Site)',
            'Scope Gamma page (Scope Gamma Site)',
        ]);
});

it('returns no publishing readiness data without an authenticated actor', function (): void {
    reportSiteScopeFixture();

    $snapshot = BuildPublishingReadinessReportAction::run();

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.publishing_readiness_metric_pages_checked'))->toBe(0)
        ->and($snapshot->findings)->toBe([]);
});

it('limits the accessibility readiness report to the sites assigned to a site-scoped user', function (): void {
    $sites = reportSiteScopeFixture();
    actAsReportSiteScopedUser($sites['alpha']);

    $snapshot = BuildAccessibilityReadinessReportAction::run();
    $payload = $snapshot->toJson();

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.accessibility_metric_pages'))->toBe(1)
        ->and(collect($snapshot->findings)->pluck('evidence.site_id')->unique()->values()->all())
        ->toBe([(int) $sites['alpha']->getKey()])
        ->and(collect($snapshot->findings)->pluck('recordLabel')->unique()->values()->all())->toBe(['Scope Alpha page'])
        ->and($payload)->not->toContain('Scope Beta')
        ->and($payload)->not->toContain('Scope Gamma');
});

it('does not let a site-scoped user request the accessibility report for an unassigned site', function (): void {
    $sites = reportSiteScopeFixture();
    actAsReportSiteScopedUser($sites['alpha']);

    $snapshot = BuildAccessibilityReadinessReportAction::run($sites['beta']);

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.accessibility_metric_pages'))->toBe(0)
        ->and($snapshot->findings)->toBe([])
        ->and($snapshot->toJson())->not->toContain('Scope Beta');
});

it('shows every site in the accessibility readiness report to a global user', function (): void {
    $sites = reportSiteScopeFixture();
    test()->actingAsAdmin();

    $snapshot = BuildAccessibilityReadinessReportAction::run();

    expect(reportSiteScopeMetric($snapshot, 'capell-admin::reports.accessibility_metric_pages'))->toBe(3)
        ->and(collect($snapshot->findings)->pluck('evidence.site_id')->unique()->sort()->values()->all())
        ->toBe(collect($sites)->map(fn (Site $site): int => (int) $site->getKey())->sort()->values()->all());
});

it('counts only assigned sites and their pages in the dashboard inventory stats for a site-scoped user', function (): void {
    $sites = reportSiteScopeFixture();
    actAsReportSiteScopedUser($sites['alpha']);

    expect(reportSiteScopeOverviewStat('capell_overview.sites'))->toBe('1')
        ->and(reportSiteScopeOverviewStat('capell_overview.pages'))->toBe('1');
});

it('counts every site and page in the dashboard inventory stats for a global user', function (): void {
    reportSiteScopeFixture();
    test()->actingAsAdmin();

    expect(reportSiteScopeOverviewStat('capell_overview.sites'))->toBe('3')
        ->and(reportSiteScopeOverviewStat('capell_overview.pages'))->toBe('3');
});
