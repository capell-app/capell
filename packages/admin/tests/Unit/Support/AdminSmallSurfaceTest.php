<?php

declare(strict_types=1);

use Capell\Admin\Actions\CheckForUpdatesAction;
use Capell\Admin\Actions\Dashboard\BuildDefaultSiteStatsAction;
use Capell\Admin\Actions\Extensions\BuildExtensionOperationsSummaryAction;
use Capell\Admin\Actions\Upgrade\SendUpgradeNotificationEmailAction;
use Capell\Admin\Console\Commands\SendUpgradeSummaryNotificationCommand;
use Capell\Admin\Data\Dashboard\SiteStatsData;
use Capell\Admin\Data\Extensions\ExtensionOperationsSummaryData;
use Capell\Admin\Data\Themes\ThemeInstallIntentCardData;
use Capell\Admin\Data\Upgrade\UpgradeSummaryData;
use Capell\Admin\Filament\Components\Forms\NameInput;
use Capell\Admin\Filament\Components\Forms\ThemeSelect;
use Capell\Admin\Filament\Resources\Sites\Schemas\DefaultSiteForm;
use Capell\Admin\Filament\Widgets\Extensions\ExtensionStatsOverviewFilamentWidget;
use Capell\Admin\Models\FailedJob;
use Capell\Admin\Notifications\UpgradeSummaryNotification;
use Capell\Admin\Support\Dashboard\DefaultSiteStatsDataProvider;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Artisan;

it('runs update checks before sending upgrade summary notification emails', function (): void {
    $checkUpdates = bindFakeAction(CheckForUpdatesAction::class);
    $sendEmail = bindFakeAction(SendUpgradeNotificationEmailAction::class, 3);

    $exitCode = Artisan::call(SendUpgradeSummaryNotificationCommand::class);

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('Sent 3 upgrade summary notification email(s).')
        ->and($checkUpdates->called)->toBeTrue()
        ->and($sendEmail->called)->toBeTrue();
});

it('delegates default site stats data building to the action layer', function (): void {
    $expected = new SiteStatsData(
        workQueueCount: 1,
        publishedCount: 8,
        sparklinePublished: [1, 2, 3],
    );
    $spy = bindFakeAction(BuildDefaultSiteStatsAction::class, $expected);

    expect((new DefaultSiteStatsDataProvider)->build('this_month'))->toBe($expected)
        ->and($spy->args)->toBe(['this_month']);
});

it('uses the configured queue failed job table name', function (): void {
    config(['queue.failed.table' => 'custom_failed_jobs']);

    expect((new FailedJob)->getTable())->toBe('custom_failed_jobs');
});

it('forces livewire asset injection while serving admin pages', function (): void {
    $provider = file_get_contents(dirname(__DIR__, 3) . '/src/Providers/AdminServiceProvider.php');

    expect($provider)->toContain('Filament::serving')
        ->and($provider)->toContain('Livewire::forceAssetInjection();')
        ->and($provider)->toContain('event(new ServingAdmin);');
});

it('builds the default site form schema from upstream form components', function (): void {
    $components = DefaultSiteForm::configure(Schema::make()->operation('create'))->getComponents();

    expect($components)->toHaveCount(4)
        ->and($components[0])->toBeInstanceOf(NameInput::class)
        ->and(filamentObjectName($components[0]))->toBe('name')
        ->and($components[1])->toBeInstanceOf(TextInput::class)
        ->and(filamentObjectName($components[1]))->toBe('url')
        ->and($components[2])->toBeInstanceOf(Section::class);

    /** @var Section $languagesSection */
    $languagesSection = $components[2];

    expect($languagesSection->getHeading())->toBe(__('capell-admin::form.languages'))
        ->and($components[3])->toBeInstanceOf(ThemeSelect::class)
        ->and(filamentObjectName($components[3]))->toBe('theme_id');
});

it('normalises marketplace theme install intent cards for the admin catalogue', function (): void {
    $card = ThemeInstallIntentCardData::fromIntent((object) [
        'extension_name' => ' Campaign Studio ',
        'composer_command' => ' composer require vendor/campaign-studio ',
        'metadata' => [
            'description' => ' Launch-ready editorial theme ',
            'image_url' => ' https://cdn.example.test/theme.jpg ',
        ],
    ]);

    $fallback = ThemeInstallIntentCardData::fromIntent((object) [
        'extension_name' => ' ',
        'composer_command' => null,
        'metadata' => [
            'description' => ' ',
            'image_url' => 123,
        ],
    ]);

    expect($card->title)->toBe('Campaign Studio')
        ->and($card->description)->toBe('Launch-ready editorial theme')
        ->and($card->composerCommand)->toBe('composer require vendor/campaign-studio')
        ->and($card->imageUrl)->toBe('https://cdn.example.test/theme.jpg')
        ->and($fallback->title)->toBe('')
        ->and($fallback->description)->toBe((string) __('capell-admin::table.theme_no_description'))
        ->and($fallback->composerCommand)->toBe('')
        ->and($fallback->imageUrl)->toBeNull();
});

it('builds the upgrade summary notification mail from the advisory summary', function (): void {
    config(['app.url' => 'https://admin.example.test']);

    $notification = new UpgradeSummaryNotification(new UpgradeSummaryData(
        securityCount: 2,
        bugfixCount: 1,
        featureCount: 1,
        majorCount: 0,
        updateCount: 2,
        totalCount: 4,
        maxVersionsBehind: 3,
        navigationBadge: '3 behind',
        navigationBadgeColor: 'danger',
        notices: [],
    ));

    $mail = $notification->toMail(new stdClass);

    expect($notification->via(new stdClass))->toBe(['mail'])
        ->and($mail->subject)->toBe((string) __('capell-admin::notification.upgrade_summary_subject'))
        ->and($mail->greeting)->toBe((string) __('capell-admin::notification.upgrade_summary_greeting'))
        ->and(implode("\n", $mail->introLines))->toContain('3')
        ->and(implode("\n", $mail->introLines))->toContain('2')
        ->and($mail->actionText)->toBe((string) __('capell-admin::notification.upgrade_summary_cta'))
        ->and($mail->actionUrl)->not->toBe('')
        ->and($mail->outroLines)->toContain((string) __('capell-admin::notification.upgrade_summary_footer'));
});

it('renders extension operation stats from the summary action', function (): void {
    $summary = new ExtensionOperationsSummaryData(
        needsAttentionCount: 2,
        blockedCount: 1,
        updatesCount: 3,
        unhealthyCount: 2,
        installedCount: 8,
        uninstalledCount: 5,
        availableCount: 13,
        packages: [],
        alerts: [],
    );

    $spy = bindFakeAction(BuildExtensionOperationsSummaryAction::class, $summary);
    $method = new ReflectionMethod(ExtensionStatsOverviewFilamentWidget::class, 'getStats');
    $stats = $method->invoke(new ExtensionStatsOverviewFilamentWidget);

    expect($spy->called)->toBeTrue()
        ->and($stats)->toHaveCount(5)
        ->and($stats[0]->getLabel())->toBe(__('capell-admin::generic.extension_operations_tab_installed'))
        ->and($stats[0]->getValue())->toBe('8')
        ->and($stats[0]->getColor())->toBe('primary')
        ->and($stats[1]->getValue())->toBe('5')
        ->and($stats[1]->getColor())->toBe('gray')
        ->and($stats[2]->getValue())->toBe('2')
        ->and($stats[2]->getColor())->toBe('warning')
        ->and($stats[3]->getValue())->toBe('3')
        ->and($stats[3]->getColor())->toBe('warning')
        ->and($stats[4]->getValue())->toBe('1')
        ->and($stats[4]->getColor())->toBe('danger');

    bindFakeAction(BuildExtensionOperationsSummaryAction::class, new ExtensionOperationsSummaryData(
        needsAttentionCount: 0,
        blockedCount: 0,
        updatesCount: 0,
        unhealthyCount: 0,
        installedCount: 0,
        uninstalledCount: 0,
        availableCount: 0,
        packages: [],
        alerts: [],
    ));

    $emptyStats = $method->invoke(new ExtensionStatsOverviewFilamentWidget);

    expect($emptyStats[2]->getColor())->toBe('success')
        ->and($emptyStats[3]->getColor())->toBe('success')
        ->and($emptyStats[4]->getColor())->toBe('success');
});
