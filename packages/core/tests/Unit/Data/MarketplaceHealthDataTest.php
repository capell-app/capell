<?php

declare(strict_types=1);

use Capell\Core\Actions\Marketplace\RecordExtensionHealthAlertsAction;
use Capell\Core\Data\Marketplace\ExtensionHealthAlertData;
use Capell\Core\Data\Marketplace\ExtensionHealthReportData;
use Capell\Core\Data\Workflow\WorkflowAttentionItemData;
use Capell\Core\Enums\ExtensionHealthAlertCategory;
use Capell\Core\Enums\ExtensionHealthAlertSeverity;
use Capell\Core\Models\ExtensionHealthAlert;

it('normalizes workflow attention items for dashboard contributors', function (): void {
    $item = WorkflowAttentionItemData::fromArray([
        'packageName' => 'capell-app/workflow',
        'label' => 'Drafts waiting for review',
        'severity' => 'warning',
        'owner' => 'editorial',
        'nextActionLabel' => 'Review drafts',
        'routeName' => 'filament.admin.resources.pages.index',
        'url' => 123,
        'permission' => 'pages.review',
        'staleAt' => '2026-05-30T12:00:00+00:00',
        'count' => '7',
    ]);

    expect($item->staleAt?->toIso8601String())->toBe('2026-05-30T12:00:00+00:00')
        ->and($item->count)->toBe(7)
        ->and($item->toArray())->toMatchArray([
            'packageName' => 'capell-app/workflow',
            'label' => 'Drafts waiting for review',
            'severity' => 'warning',
            'owner' => 'editorial',
            'nextActionLabel' => 'Review drafts',
            'routeName' => 'filament.admin.resources.pages.index',
            'permission' => 'pages.review',
            'count' => 7,
        ])
        ->and($item->toArray())->not->toHaveKey('url');
});

it('normalizes marketplace extension health reports from remote payloads', function (): void {
    $report = ExtensionHealthReportData::fromPayload([
        'site_id' => 1001,
        'install_id' => '',
        'app_url' => 'https://example.test',
        'capell_version' => '0.0.x-dev',
        'laravel_version' => '12.x',
        'php_version' => PHP_VERSION,
        'environment' => 'production',
        'generated_at' => '2026-05-30T12:00:00+00:00',
        'extensions' => [
            ['slug' => 'blog'],
            'invalid',
        ],
        'packages' => [
            ['name' => 'capell-app/blog'],
        ],
        'alerts' => [
            ['alert_id' => 'alert-1'],
            null,
        ],
        'licence_state' => ['status' => 'active'],
        'metadata' => 'ignored',
    ]);

    expect($report->siteId)->toBe('1001')
        ->and($report->installId)->toBeNull()
        ->and($report->extensions)->toBe([['slug' => 'blog']])
        ->and($report->packages)->toBe([['name' => 'capell-app/blog']])
        ->and($report->alerts)->toBe([['alert_id' => 'alert-1']])
        ->and($report->metadata)->toBe([])
        ->and($report->toArray())->not->toHaveKey('install_id');
});

it('records extension health alerts idempotently and skips unsigned empty alerts', function (): void {
    $alert = ExtensionHealthAlertData::fromApiResponse([
        'alert_id' => 'runtime-disabled',
        'extension_slug' => 'blog',
        'composer_name' => 'capell-app/blog',
        'site_id' => 'site-1',
        'install_id' => 'install-1',
        'severity' => 'critical',
        'category' => 'security',
        'title' => 'Runtime disabled',
        'message' => 'The extension was disabled by marketplace policy.',
        'required_action' => 'Update package',
        'runtime_disabled' => true,
        'protected_actions_blocked' => true,
        'issued_at' => '2026-05-30T12:00:00+00:00',
        'expires_at' => '2026-06-30T12:00:00+00:00',
        'signature' => 'signed-payload',
    ]);

    $blankAlert = ExtensionHealthAlertData::fromApiResponse([
        'title' => 'Ignored',
        'severity' => 'nonsense',
        'category' => 'unknown',
    ]);

    RecordExtensionHealthAlertsAction::run('marketplace', [$blankAlert, $alert]);
    RecordExtensionHealthAlertsAction::run('marketplace', [
        ExtensionHealthAlertData::fromApiResponse([
            ...$alert->payload,
            'alert_id' => 'runtime-disabled',
            'title' => 'Runtime still disabled',
        ]),
    ]);

    $storedAlert = ExtensionHealthAlert::query()->sole();

    expect($storedAlert->alert_id)->toBe('runtime-disabled')
        ->and($storedAlert->source)->toBe('marketplace')
        ->and($storedAlert->severity)->toBe(ExtensionHealthAlertSeverity::Critical)
        ->and($storedAlert->category)->toBe(ExtensionHealthAlertCategory::Security)
        ->and($storedAlert->title)->toBe('Runtime still disabled')
        ->and($storedAlert->runtime_disabled)->toBeTrue()
        ->and($storedAlert->protected_actions_blocked)->toBeTrue()
        ->and(data_get($storedAlert->payload, 'signature'))->toBe('signed-payload');
});
