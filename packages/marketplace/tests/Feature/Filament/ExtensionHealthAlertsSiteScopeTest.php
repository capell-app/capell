<?php

declare(strict_types=1);

use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Models\ExtensionHealthAlert;
use Capell\Core\Models\Site;
use Capell\Marketplace\Filament\Widgets\ExtensionHealthAlertsFilamentWidget;
use Capell\Tests\Fixtures\Models\User;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class);

it('shows only global and assigned site alerts while preserving global actor access', function (): void {
    $alpha = Site::factory()->create();
    $beta = Site::factory()->create();
    $alerts = [];
    foreach ([null, $alpha->id, $beta->id] as $siteId) {
        $alerts[] = ExtensionHealthAlert::query()->create([
            'alert_id' => fake()->uuid(), 'source' => 'test', 'extension_slug' => 'blog',
            'affected_site_id' => $siteId, 'severity' => ExtensionHealthAlertSeverity::Critical,
            'category' => ExtensionHealthAlertCategory::Security, 'title' => 'Critical alert',
            'message' => 'Site specific alert', 'signature' => 'test', 'issued_at' => now(),
        ]);
    }

    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$alpha->id]);

    test()->actingAs($actor);
    expect(collect(ExtensionHealthAlertsFilamentWidget::criticalAlertsForExtension('blog', null))->pluck('id')->all())->toEqualCanonicalizing([$alerts[0]->id, $alerts[1]->id]);
    $actor = User::factory()->create();
    $actor->assignedSiteIds = collect([$beta->id]);

    test()->actingAs($actor);
    expect(collect(ExtensionHealthAlertsFilamentWidget::criticalAlertsForExtension('blog', null))->pluck('id')->all())->toEqualCanonicalizing([$alerts[0]->id, $alerts[2]->id]);
    test()->actingAs(User::factory()->create());
    expect(collect(ExtensionHealthAlertsFilamentWidget::criticalAlertsForExtension('blog', null))->pluck('id')->all())->toBe([$alerts[0]->id]);
    test()->actingAsAdmin();
    expect(ExtensionHealthAlertsFilamentWidget::criticalAlertsForExtension('blog', null))->toHaveCount(3);
});
