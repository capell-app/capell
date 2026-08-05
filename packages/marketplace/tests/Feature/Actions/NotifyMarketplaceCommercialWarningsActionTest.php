<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\NotifyMarketplaceCommercialWarningsAction;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

uses(CreatesAdminUser::class);

beforeEach(function (): void {
    Schema::create('notifications', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
});

it('stores one deduplicated admin notification for commercial access needing attention', function (): void {
    CarbonImmutable::setTestNow('2026-08-05 12:00:00');
    Cache::flush();
    test()->actingAsAdmin();

    $commercial = [
        'purchases' => [[
            'id' => 'purchase-expiring',
            'name' => 'SEO Suite',
            'status' => 'active',
            'protected_updates' => true,
            'access_ends_at' => '2026-08-20T00:00:00Z',
        ]],
    ];

    NotifyMarketplaceCommercialWarningsAction::run($commercial);
    NotifyMarketplaceCommercialWarningsAction::run($commercial);

    $notifications = test()->authenticatedUser()->notifications;

    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()?->getAttribute('data'))->toMatchArray([
            'title' => 'SEO Suite access expires soon',
        ]);
});

it('does not notify for healthy commercial access', function (): void {
    test()->actingAsAdmin();

    NotifyMarketplaceCommercialWarningsAction::run([
        'purchases' => [[
            'name' => 'SEO Suite',
            'status' => 'active',
            'protected_updates' => true,
            'access_ends_at' => '2027-08-20T00:00:00Z',
        ]],
    ]);

    expect(test()->authenticatedUser()->notifications)->toBeEmpty();
});
