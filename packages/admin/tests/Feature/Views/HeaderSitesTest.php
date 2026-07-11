<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

uses(CreatesAdminUser::class)
    ->group('admin');

it('renders the active site switcher link in the admin topbar', function (): void {
    $site = Site::factory()->createOne(['name' => 'Editorial Site']);
    $request = Request::create('/admin/pages', Symfony\Component\HttpFoundation\Request::METHOD_GET, ['foo' => 'bar']);
    $request->setLaravelSession(resolve(Session::class));
    $request->session()->put('capell.current_site_id', $site->getKey());
    app()->instance('request', $request);

    $html = view('capell-admin::components.header.sites', [
        'sites' => $site->newCollection([$site]),
    ])->render();

    expect($html)
        ->toContain('Editorial Site')
        ->toContain('foo=bar&amp;site=' . $site->getKey())
        ->toContain(__('capell-admin::generic.use_site_in_admin'));
});
