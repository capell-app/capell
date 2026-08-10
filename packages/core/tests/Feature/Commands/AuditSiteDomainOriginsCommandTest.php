<?php

declare(strict_types=1);

use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('fails with every normalized conflict and remediation before mutation', function (): void {
    $site = Site::factory()->createOne();
    $now = now();

    DB::table('site_domains')->insert([
        [
            'site_id' => $site->id,
            'domain' => 'Example.test.',
            'scheme' => 'HTTPS',
            'path' => '/docs/',
            'status' => false,
            'default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'site_id' => $site->id,
            'domain' => 'example.test',
            'scheme' => 'https',
            'path' => 'docs',
            'status' => true,
            'default' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $ids = SiteDomain::query()->orderByDesc('id')->limit(2)->pluck('id')->all();

    expect(Artisan::call('capell:site-domains-audit', ['--json' => true]))->toBe(1);

    $output = Artisan::output();

    expect($output)
        ->toContain((string) $ids[0])
        ->toContain((string) $ids[1])
        ->toContain('Reconcile the conflicting rows');

    expect(SiteDomain::query()->whereKey($ids)->count())->toBe(2);
});
