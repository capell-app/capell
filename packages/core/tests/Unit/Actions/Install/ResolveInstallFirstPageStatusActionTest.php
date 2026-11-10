<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\Install\ResolveInstallFirstPageStatusAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\Publishing\PublishSentinel;
use Capell\Core\Support\TableName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

it('reports a missing first page while the pages table does not exist', function (): void {
    Schema::dropIfExists(TableName::resolve('pages'));

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('missing');
});

it('reports a missing first page when no page has been created', function (): void {
    Page::query()->delete();

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('missing');
});

it('reports an editable first page when Admin can open the page', function (): void {
    $page = Page::factory()->home()->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn('https://example.test/admin/pages/' . $page->getKey() . '/edit');

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('editable');
});

it('reports an unverified first page when the page exists but has no edit URL', function (): void {
    Page::factory()->home()->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn(null);

    expect(ResolveInstallFirstPageStatusAction::run())->toBe('present_unverified');
});

function installedSiteWithDomain(string $host): SiteDomain
{
    return SiteDomain::factory()->state(['domain' => $host, 'path' => null])->create();
}

it('ignores a homepage that belongs to another site', function (): void {
    $other = installedSiteWithDomain('other.example.test');
    $installed = installedSiteWithDomain('installed.example.test');
    Page::factory()->home()->site($other->site_id)->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->never();

    expect(ResolveInstallFirstPageStatusAction::run('https://installed.example.test'))->toBe('missing')
        ->and($installed->site_id)->not->toBe($other->site_id);
});

it('reports the homepage of the site that matches the install address', function (): void {
    $other = installedSiteWithDomain('other.example.test');
    $installed = installedSiteWithDomain('installed.example.test');
    Page::factory()->home()->site($other->site_id)->create();
    Page::factory()->home()->site($installed->site_id)->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn('https://installed.example.test/admin/pages/1/edit');

    expect(ResolveInstallFirstPageStatusAction::run('https://installed.example.test'))->toBe('editable');
});

it('reports a draft homepage as not live', function (): void {
    $domain = installedSiteWithDomain('installed.example.test');
    Page::factory()->home()->site($domain->site_id)->create(['visible_from' => PublishSentinel::draftValue(CarbonImmutable::now())]);
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->never();

    expect(ResolveInstallFirstPageStatusAction::run('https://installed.example.test'))->toBe('draft');
});

it('reports a site with only inner pages as missing its homepage', function (): void {
    $domain = installedSiteWithDomain('installed.example.test');
    Page::factory()->site($domain->site_id)->create();

    expect(ResolveInstallFirstPageStatusAction::run('https://installed.example.test'))->toBe('missing');
});

it('cannot verify the first page when the install address matches no site', function (): void {
    $domain = installedSiteWithDomain('installed.example.test');
    Page::factory()->home()->site($domain->site_id)->create();

    expect(ResolveInstallFirstPageStatusAction::run('https://elsewhere.example.test'))->toBe('unavailable');
});

it('finds the installed site when several sites share the install host under different paths', function (): void {
    $root = SiteDomain::factory()->state(['domain' => 'shared.example.test', 'path' => null])->create();
    $mounted = SiteDomain::factory()->state(['domain' => 'shared.example.test', 'path' => '/services'])->create();
    Page::factory()->home()->site($mounted->site_id)->create();
    GetEditPageResourceUrlAction::mock()->shouldReceive('handle')->once()->andReturn('https://shared.example.test/admin/pages/1/edit');

    expect(ResolveInstallFirstPageStatusAction::run('https://shared.example.test'))->toBe('missing')
        ->and($root->site_id)->not->toBe($mounted->site_id)
        ->and(ResolveInstallFirstPageStatusAction::run('https://shared.example.test/services'))->toBe('editable');
});
