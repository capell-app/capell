<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SecurityPage;
use Capell\Admin\Filament\Widgets\Dashboard\SecurityAttentionFilamentWidget;
use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationBatchResultData;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityScanResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

use function Pest\Laravel\get;

use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function securityUser(bool $manage = false): void
{
    Permission::findOrCreate('View:SecurityPage', 'web');
    Permission::findOrCreate('Manage:SecurityPage', 'web');
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('View:SecurityPage');
    if ($manage) {
        test()->authenticatedUser()->givePermissionTo('Manage:SecurityPage');
    }
}

/** @param array<string, mixed> $attributes */
function securityFinding(array $attributes = []): SecurityWeaknessFinding
{
    return SecurityWeaknessFinding::query()->create(array_replace([
        'weakness_id' => 'test.weakness', 'fingerprint' => hash('sha256', uniqid('', true)),
        'source' => 'builtin', 'severity' => 'high', 'status' => 'open', 'title' => 'Test weakness',
        'summary' => 'Review this finding', 'remediable' => true, 'first_detected_at' => now(), 'last_detected_at' => now(),
    ], $attributes));
}

function securityRemediation(): void
{
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new class implements SecurityWeaknessRemediation
    {
        #[Override]
        public function weaknessId(): string
        {
            return 'test.weakness';
        }

        #[Override]
        public function describe(): string
        {
            return 'Turn off the unsafe option.';
        }

        #[Override]
        public function isReversible(): bool
        {
            return true;
        }

        #[Override]
        public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData
        {
            return SecurityRemediationOutcomeData::skipped('Not used in this test');
        }
    });
    app()->instance(SecurityWeaknessRegistry::class, $registry);
}

it('requires view permission and renders an empty read-only page', function (): void {
    test()->actingAsUser();
    get(SecurityPage::getUrl())->assertForbidden();
    securityUser();
    Livewire::test(SecurityPage::class)->assertOk()->assertSee(__('capell-admin::security.read_only'))
        ->assertSee(__('capell-admin::security.no_approvals'))->assertSee(__('capell-admin::security.no_history'));
});

it('orders findings by severity and shows approval state and analyser advice', function (): void {
    securityUser(true);
    securityRemediation();
    $low = securityFinding(['severity' => 'low', 'title' => 'Low finding', 'weakness_id' => 'test.other']);
    $high = securityFinding(['severity' => 'high', 'title' => 'High finding']);
    $advice = securityFinding(['severity' => 'critical', 'source' => 'analyser', 'title' => 'Advice finding']);
    SecurityRemediationApproval::query()->create(['weakness_id' => 'test.weakness', 'approved_by' => 42, 'approved_at' => now()]);
    Livewire::test(SecurityPage::class)->assertOk()->assertSeeInOrder(['Advice finding', 'High finding', 'Low finding'])
        ->assertSee(__('capell-admin::security.advice_only'))->assertSee(__('capell-admin::security.not_available'))
        ->assertSee('Approved by user 42')->assertTableActionHidden('approve', $advice)
        ->assertTableActionVisible('fixNow', $high)->assertTableActionHidden('approve', $low);
});

it('shows the fix description and records and revokes approval', function (): void {
    securityUser(true);
    securityRemediation();
    $finding = securityFinding();
    Livewire::test(SecurityPage::class)->mountTableAction('approve', $finding)->assertMountedActionModalSee('Turn off the unsafe option.')
        ->assertMountedActionModalSee(__('capell-admin::security.approval_consequence'))->callMountedTableAction();
    expect(SecurityRemediationApproval::query()->where('weakness_id', 'test.weakness')->firstOrFail()->isActive())->toBeTrue();
    Livewire::test(SecurityPage::class)->callTableAction('revoke', $finding);
    expect(SecurityRemediationApproval::query()->where('weakness_id', 'test.weakness')->firstOrFail()->isActive())->toBeFalse();
});

it('applies only the selected approved weakness', function (): void {
    securityUser(true);
    securityRemediation();
    $finding = securityFinding();
    SecurityRemediationApproval::query()->create(['weakness_id' => 'test.weakness', 'approved_by' => 1, 'approved_at' => now()]);
    ApplyApprovedSecurityRemediationsAction::mock()->shouldReceive('handle')->once()
        ->with('user:' . test()->authenticatedUser()->getAuthIdentifier(), false, 'test.weakness')
        ->andReturn(new SecurityRemediationBatchResultData(false, 1, 1, 0));
    Livewire::test(SecurityPage::class)->callTableAction('fixNow', $finding)
        ->assertNotified(__('capell-admin::security.fix_result', ['applied' => 1, 'failed' => 0, 'skipped' => 0]));
});

it('dismisses with a note and reopens the finding', function (): void {
    securityUser(true);
    $finding = securityFinding();
    Livewire::test(SecurityPage::class)->callTableAction('dismiss', $finding, data: ['note' => 'Risk accepted']);
    expect($finding->refresh()->dismissal_note)->toBe('Risk accepted')->and($finding->refresh()->status->value)->toBe('dismissed');
    Livewire::test(SecurityPage::class)->filterTable('status', 'dismissed')->callTableAction('reopen', $finding);
    expect($finding->refresh()->status->value)->toBe('open')->and($finding->refresh()->dismissal_note)->toBeNull();
});

it('runs a scan and reports the result', function (): void {
    securityUser(true);
    RunSecurityWeaknessScanAction::mock()->shouldReceive('handle')->once()->with(true)
        ->andReturn(new SecurityScanResultData(CarbonImmutable::now(), 1, 0, null, 2, 1, 3, 0, null));
    Livewire::test(SecurityPage::class)->callAction('scan')
        ->assertNotified(__('capell-admin::security.scan_result', ['new' => 2, 'reopened' => 1, 'resolved' => 3]));
});

it('does not offer mutations to a view-only user', function (): void {
    securityUser();
    securityRemediation();
    $finding = securityFinding();
    Livewire::test(SecurityPage::class)->assertSee(__('capell-admin::security.read_only'))
        ->assertActionDisabled('scan')->assertActionDisabled('fixApproved')
        ->assertTableActionDisabled('approve', $finding)->assertTableActionDisabled('dismiss', $finding);
    expect(SecurityRemediationApproval::query()->count())->toBe(0);
});

it('shows dashboard attention only for authorised users with open high or critical findings', function (): void {
    securityUser();
    expect(SecurityAttentionFilamentWidget::canView())->toBeFalse();
    $finding = securityFinding(['severity' => 'high']);
    expect(SecurityAttentionFilamentWidget::canView())->toBeTrue();
    Livewire::test(SecurityAttentionFilamentWidget::class)->assertSee(__('capell-admin::security.attention_count', ['count' => 1]));
    $finding->update(['status' => 'resolved']);
    expect(SecurityAttentionFilamentWidget::canView())->toBeFalse();
    $finding->update(['status' => 'open', 'severity' => 'low']);
    expect(SecurityAttentionFilamentWidget::canView())->toBeFalse();
    test()->authenticatedUser()->revokePermissionTo('View:SecurityPage');
    $finding->update(['severity' => 'critical']);
    expect(SecurityAttentionFilamentWidget::canView())->toBeFalse();
});

it('filters findings and shows their evidence and recommended action', function (): void {
    securityUser();
    $active = securityFinding(['title' => 'Active weakness', 'evidence' => ['setting' => 'enabled'], 'recommended_action' => 'Disable the setting']);
    securityFinding(['title' => 'Resolved weakness', 'status' => 'resolved', 'weakness_id' => 'test.resolved']);
    Livewire::test(SecurityPage::class)->assertSee('Active weakness')->assertDontSee('Resolved weakness')
        ->mountTableAction('evidence', $active)->assertMountedActionModalSee('setting')->assertMountedActionModalSee('enabled')->assertMountedActionModalSee('Disable the setting');
    Livewire::test(SecurityPage::class)->filterTable('status', 'resolved')->assertSee('Resolved weakness')->assertDontSee('Active weakness');
});

it('shows revoked approvals and the recorded remediation history', function (): void {
    securityUser();
    SecurityRemediationApproval::query()->create(['weakness_id' => 'test.removed', 'approved_by' => 7, 'approved_at' => now(), 'revoked_at' => now(), 'note' => 'Reviewed']);
    SecurityRemediationRun::query()->create(['weakness_id' => 'test.removed', 'remediation_class' => 'Example', 'actor' => 'user:7', 'outcome' => 'applied', 'message' => 'Changed setting', 'before' => ['setting' => 'on'], 'after' => ['setting' => 'off'], 'started_at' => now()]);
    Livewire::test(SecurityPage::class)->assertSee('test.removed')->assertSee(__('capell-admin::security.no_longer_available'))
        ->assertSee(__('capell-admin::security.revoked'))->assertSee('Reviewed')->assertSee('Changed setting')
        ->assertSee('user:7')->assertSee('on')->assertSee('off');
});
