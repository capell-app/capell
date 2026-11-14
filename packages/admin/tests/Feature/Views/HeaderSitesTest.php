<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

uses(CreatesAdminUser::class)
    ->group('admin');

function renderSiteSwitcher(Site $current, Site $other): string
{
    $request = Request::create('/admin/pages', Symfony\Component\HttpFoundation\Request::METHOD_GET, ['foo' => 'bar']);
    $request->setLaravelSession(resolve(Session::class));
    $request->session()->put('capell.current_site_id', $current->getKey());
    app()->instance('request', $request);

    return view('capell-admin::components.header.sites', [
        'sites' => $current->newCollection([$current, $other]),
    ])->render();
}

it('offers a switch link for other sites and marks the current site in words', function (): void {
    $current = Site::factory()->createOne(['name' => 'Editorial Site']);
    $other = Site::factory()->createOne(['name' => 'Shop Site']);
    test()->actingAsAdmin();

    $html = renderSiteSwitcher($current, $other);

    expect($html)
        ->toContain('foo=bar&amp;site=' . $other->getKey())
        ->not->toContain('foo=bar&amp;site=' . $current->getKey())
        ->toContain(__('capell-admin::generic.use_site_in_admin', ['site' => 'Shop Site']))
        ->toContain(__('capell-admin::generic.current_site'))
        ->toContain('aria-current="true"')
        ->toContain('aria-label="' . e(__('capell-admin::generic.site_switcher_label', ['site' => 'Editorial Site'])) . '"');
});

it('links to site settings only for a person who can update the site', function (): void {
    $current = Site::factory()->createOne(['name' => 'Editorial Site']);
    $other = Site::factory()->createOne(['name' => 'Shop Site']);

    test()->actingAsAdmin();
    expect(renderSiteSwitcher($current, $other))->toContain(__('capell-admin::generic.edit_site_settings'));

    test()->actingAsRole('panel_user');
    expect(renderSiteSwitcher($current, $other))->not->toContain(__('capell-admin::generic.edit_site_settings'));
});
