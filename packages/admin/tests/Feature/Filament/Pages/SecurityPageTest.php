<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\SecurityPage;
use Capell\Admin\Filament\Widgets\Dashboard\SecurityAttentionFilamentWidget;
use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\ApproveSecurityRemediationAction;
use Capell\Core\Actions\Security\DismissSecurityWeaknessFindingAction;
use Capell\Core\Actions\Security\ReopenSecurityWeaknessFindingAction;
use Capell\Core\Actions\Security\RevokeSecurityRemediationApprovalAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationBatchResultData;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityScanResultData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityScanRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\SecurityEvidence;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
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
    $scan = SecurityScanRun::query()->firstOrCreate(['id' => 'security-page-scan'], ['started_at' => now(), 'finished_at' => now(), 'findings_count' => 1, 'source' => 'system:console']);
    $attributes = array_replace([
        'weakness_id' => 'test.weakness', 'fingerprint' => hash('sha256', uniqid('', true)),
        'source' => 'builtin', 'severity' => 'high', 'status' => 'open', 'title' => 'Test weakness',
        'summary' => 'Review this finding', 'remediable' => true, 'first_detected_at' => now(), 'last_detected_at' => now(),
        'last_detected_scan_id' => $scan->id, 'evidence' => ['observations' => [['setting' => 'app.debug', 'class' => 'true']]],
    ], $attributes);
    $attributes['evidence_hash'] ??= SecurityEvidence::hash($attributes['evidence']);

    return SecurityWeaknessFinding::query()->create($attributes);
}

/** @param array<string, mixed> $attributes */
function securityApproval(array $attributes = []): SecurityRemediationApproval
{
    $finding = SecurityWeaknessFinding::query()->where('weakness_id', $attributes['weakness_id'] ?? 'test.weakness')->first() ?? securityFinding();

    return SecurityRemediationApproval::query()->create(array_replace([
        'weakness_id' => 'test.weakness', 'approved_by' => 1, 'approved_at' => now(),
        'approved_for_scan_id' => $finding->last_detected_scan_id, 'approved_fingerprint' => $finding->fingerprint, 'evidence_hash' => $finding->evidence_hash,
    ], $attributes));
}

function securityRemediation(bool $reversible = true): void
{
    $registry = new SecurityWeaknessRegistry;
    $registry->registerRemediation(new readonly class($reversible) implements SecurityWeaknessRemediation
    {
        public function __construct(private bool $reversible) {}

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
            return $this->reversible;
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
    securityApproval(['approved_by' => 42]);
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
        ->assertMountedActionModalSee(__('capell-admin::security.approval_scope', ['weakness' => 'test.weakness']))->callMountedTableAction();
    expect(SecurityRemediationApproval::query()->where('weakness_id', 'test.weakness')->firstOrFail()->isActive())->toBeTrue();
    Livewire::test(SecurityPage::class)->callTableAction('revoke', $finding);
    expect(SecurityRemediationApproval::query()->where('weakness_id', 'test.weakness')->firstOrFail()->isActive())->toBeFalse();
});

it('applies only the selected approved weakness', function (): void {
    securityUser(true);
    securityRemediation();
    $finding = securityFinding();
    securityFinding();
    securityApproval(['approved_by' => 1]);
    $actor = 'user:' . test()->authenticatedUser()->getAuthIdentifier();
    RunSecurityWeaknessScanAction::mock()->shouldReceive('handle')->once()->with(false, $actor)
        ->andReturn(new SecurityScanResultData(CarbonImmutable::now(), 1, 0, null, 0, 0, 0, 2, null, ['check: broken.check: boom'], 'scan-confirmed'));
    ApplyApprovedSecurityRemediationsAction::mock()->shouldReceive('handle')->once()
        ->with($actor, 'scan-confirmed', false, 'test.weakness', Mockery::type(CarbonImmutable::class), ['broken.check'])
        ->andReturn(new SecurityRemediationBatchResultData(false, 1, 1, 0));
    Livewire::test(SecurityPage::class)->mountTableAction('fixNow', $finding)
        ->assertMountedActionModalSee(trans_choice('capell-admin::security.fix_now_scope', 2, ['count' => 2, 'weakness' => 'test.weakness']))
        ->callMountedTableAction()
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

it('explains approval scope, reversibility and the current schedule before approval', function (bool $scheduled, bool $fixScheduled, bool $reversible): void {
    securityUser(true);
    securityRemediation($reversible);
    config()->set('capell.security.scan.scheduled', $scheduled);
    config()->set('capell.security.scan.fix_approved', $fixScheduled);

    $finding = securityFinding();
    $enabled = $scheduled && $fixScheduled;

    Livewire::test(SecurityPage::class)->mountTableAction('approve', $finding)
        ->assertMountedActionModalSee(__('capell-admin::security.approval_scope', ['weakness' => 'test.weakness']))
        ->assertMountedActionModalSee($reversible ? __('capell-admin::security.reversible') : __('capell-admin::security.not_reversible'))
        ->assertMountedActionModalSee($enabled ? __('capell-admin::security.approval_schedule_enabled') : __('capell-admin::security.approval_schedule_disabled'))
        ->assertMountedActionModalDontSee($enabled ? __('capell-admin::security.approval_schedule_disabled') : __('capell-admin::security.approval_schedule_enabled'));
})->with([[true, true, true], [true, false, false], [false, true, true], [false, false, false]]);

it('explains fix now scope, reversibility and the current schedule before applying it', function (bool $scheduled, bool $fixScheduled, bool $reversible): void {
    securityUser(true);
    securityRemediation($reversible);
    config()->set('capell.security.scan.scheduled', $scheduled);
    config()->set('capell.security.scan.fix_approved', $fixScheduled);

    $finding = securityFinding();
    securityFinding();
    securityApproval(['approved_by' => 1]);
    $enabled = $scheduled && $fixScheduled;

    Livewire::test(SecurityPage::class)->mountTableAction('fixNow', $finding)
        ->assertMountedActionModalSee(trans_choice('capell-admin::security.fix_now_scope', 2, ['count' => 2, 'weakness' => 'test.weakness']))
        ->assertMountedActionModalSee(__('capell-admin::security.approval_scope', ['weakness' => 'test.weakness']))
        ->assertMountedActionModalSee($reversible ? __('capell-admin::security.reversible') : __('capell-admin::security.not_reversible'))
        ->assertMountedActionModalSee($enabled ? __('capell-admin::security.approval_schedule_enabled') : __('capell-admin::security.approval_schedule_disabled'))
        ->assertMountedActionModalDontSee($enabled ? __('capell-admin::security.approval_schedule_disabled') : __('capell-admin::security.approval_schedule_enabled'));
})->with([[true, true, true], [true, false, false], [false, true, true], [false, false, false]]);

it('requires view permission even with manage permission and redirects unauthenticated visitors', function (): void {
    get(SecurityPage::getUrl())->assertRedirect();
    Permission::findOrCreate('Manage:SecurityPage', 'web');
    test()->actingAsUser();
    test()->authenticatedUser()->givePermissionTo('Manage:SecurityPage');
    get(SecurityPage::getUrl())->assertForbidden();
});

it('does not execute mutations without manage permission, including after an action is mounted', function (string $action, bool $table, bool $revokeAfterMount): void {
    securityUser($revokeAfterMount);
    securityRemediation();
    $finding = securityFinding(['status' => $action === 'reopen' ? 'dismissed' : 'open']);
    if (in_array($action, ['revoke', 'revokeApproval', 'fixNow'], true)) {
        securityApproval(['approved_by' => 1]);
    }

    foreach ([RunSecurityWeaknessScanAction::class, ApproveSecurityRemediationAction::class, RevokeSecurityRemediationApprovalAction::class, ApplyApprovedSecurityRemediationsAction::class, DismissSecurityWeaknessFindingAction::class, ReopenSecurityWeaknessFindingAction::class] as $coreAction) {
        $coreAction::mock()->shouldNotReceive('handle');
    }

    $findingsBefore = SecurityWeaknessFinding::query()->get()->toArray();
    $approvalsBefore = SecurityRemediationApproval::query()->get()->toArray();
    $runsBefore = SecurityRemediationRun::query()->get()->toArray();
    $component = Livewire::test(SecurityPage::class);
    if ($action === 'reopen') {
        $component->filterTable('status', 'dismissed');
    }

    $arguments = $action === 'revokeApproval' ? ['weakness' => 'test.weakness'] : [];
    if ($revokeAfterMount) {
        $hasModal = ! in_array($action, ['scan', 'reopen'], true);
        if ($hasModal) {
            $testAction = $table ? TestAction::make($action)->table($finding) : $action;
            $component->mountAction($testAction, arguments: $arguments)->assertActionMounted($testAction);
            if ($action === 'dismiss') {
                $component->setActionData(['note' => 'Attempted']);
            }
        }

        test()->authenticatedUser()->revokePermissionTo('Manage:SecurityPage');
        if ($hasModal) {
            $component->callMountedAction();
        } elseif ($table) {
            $component->callTableAction($action, $finding);
        } else {
            $component->callAction($action);
        }
    } elseif ($table) {
        $component->assertTableActionDisabled($action, $finding)
            ->callTableAction($action, $finding, data: $action === 'dismiss' ? ['note' => 'Attempted'] : []);
    } else {
        $component->assertActionDisabled($action)->callAction($action, arguments: $arguments);
    }

    expect(SecurityWeaknessFinding::query()->get()->toArray())->toBe($findingsBefore)
        ->and(SecurityRemediationApproval::query()->get()->toArray())->toBe($approvalsBefore)
        ->and(SecurityRemediationRun::query()->get()->toArray())->toBe($runsBefore);
})->with(function (): array {
    $cases = [];
    foreach (['scan' => false, 'approve' => true, 'revoke' => true, 'revokeApproval' => false, 'fixNow' => true, 'fixApproved' => false, 'dismiss' => true, 'reopen' => true] as $action => $table) {
        foreach ([false, true] as $revoked) {
            $cases[] = [$action, $table, $revoked];
        }
    }

    return $cases;
});

it('limits history to the latest 50 runs until all history is requested', function (): void {
    securityUser();
    foreach (range(1, 60) as $number) {
        SecurityRemediationRun::query()->create([
            'weakness_id' => 'history.' . $number, 'remediation_class' => 'Example', 'actor' => 'user:7',
            'outcome' => 'applied', 'message' => 'Run #' . $number . '#', 'started_at' => now()->subMinutes(60 - $number),
        ]);
    }

    $component = Livewire::test(SecurityPage::class)->assertSee(__('capell-admin::security.history_limit'));
    foreach (range(1, 60) as $number) {
        if ($number <= 10) {
            $component->assertDontSee('Run #' . $number . '#');
        } else {
            $component->assertSee('Run #' . $number . '#');
        }
    }

    $component->set('showAllHistory', true)->assertDontSee(__('capell-admin::security.history_limit'));
    foreach (range(1, 60) as $number) {
        $component->assertSee('Run #' . $number . '#');
    }
});

it('distinguishes an empty database from findings excluded by filters', function (): void {
    securityUser();
    Livewire::test(SecurityPage::class)->assertSee(__('capell-admin::security.no_findings'))
        ->assertDontSee(__('capell-admin::security.no_matching_findings'));
    securityFinding(['status' => 'resolved']);
    Livewire::test(SecurityPage::class)->assertSee(__('capell-admin::security.no_matching_findings'))
        ->assertDontSee(__('capell-admin::security.no_findings'));
});

it('escapes finding and history content including the evidence modal', function (): void {
    securityUser();
    $finding = securityFinding([
        'title' => '<script>title</script>', 'summary' => '<script>summary</script>',
        'evidence' => ['value' => '<script>evidence</script>'], 'dismissal_note' => '<script>note</script>',
    ]);
    SecurityRemediationRun::query()->create([
        'weakness_id' => 'test.weakness', 'remediation_class' => 'Example', 'actor' => 'user:7',
        'outcome' => 'applied', 'message' => '<script>history</script>', 'started_at' => now(),
    ]);
    $component = Livewire::test(SecurityPage::class)
        ->assertSee('&lt;script&gt;title&lt;/script&gt;', false)
        ->assertSee('&lt;script&gt;history&lt;/script&gt;', false)
        ->assertDontSee('<script>title</script>', false)->assertDontSee('<script>history</script>', false)
        ->mountTableAction('evidence', $finding)
        ->assertMountedActionModalSee('summary')->assertMountedActionModalSee('evidence')->assertMountedActionModalSee('note');
    foreach (['summary', 'evidence', 'note'] as $value) {
        $component->assertMountedActionModalSee('&lt;script&gt;' . $value, false)
            ->assertMountedActionModalDontSee('<script>' . $value, false);
    }
});
