<?php

declare(strict_types=1);

use Capell\Admin\Actions\Extensions\BuildExtensionUpdateReadinessAction;
use Capell\Admin\Data\Extensions\ExtensionUpdateReadinessData;
use Capell\Core\Enums\ExtensionAutoUpdatePolicyEnum;
use Capell\Core\Enums\ExtensionReleaseKindEnum;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Actions\QueueMarketplaceAutoUpdatesAction;
use Capell\Marketplace\Actions\RecordUpdateAdvisorySnapshotAction;
use Capell\Marketplace\Actions\ResolveMarketplaceSecurityAdvisoriesAction;
use Capell\Marketplace\Actions\ResolvePackagesWithPendingMigrationsAction;

function autoUpdateExtension(string $composerName, ExtensionAutoUpdatePolicyEnum $policy): CapellExtension
{
    return CapellExtension::query()->create([
        'composer_name' => $composerName,
        'name' => $composerName,
        'status' => ExtensionStatusEnum::Enabled,
        'auto_update_policy' => $policy,
    ]);
}

/** @param list<ExtensionUpdateReadinessData> $readiness */
function fakeUpdateReadiness(array $readiness): void
{
    app()->instance(BuildExtensionUpdateReadinessAction::class, new readonly class($readiness)
    {
        /** @param list<ExtensionUpdateReadinessData> $readiness */
        public function __construct(private array $readiness) {}

        /** @return list<ExtensionUpdateReadinessData> */
        public function handle(): array
        {
            return $this->readiness;
        }
    });
}

/** @param list<string> $composerNames */
function fakePendingMigrationsFor(array $composerNames): void
{
    app()->instance(ResolvePackagesWithPendingMigrationsAction::class, new readonly class($composerNames)
    {
        /** @param list<string> $composerNames */
        public function __construct(private array $composerNames) {}

        /** @return list<string> */
        public function handle(): array
        {
            return $this->composerNames;
        }
    });
}

function recordSecurityAdvisoryFor(string $composerName): void
{
    RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
        'advisories' => [
            ['type' => 'security', 'composer_name' => $composerName],
        ],
    ]);
}

it('classifies the step between two versions', function (?string $current, ?string $latest, ExtensionReleaseKindEnum $expected): void {
    expect(ExtensionReleaseKindEnum::between($current, $latest))->toBe($expected);
})->with([
    ['1.2.3', '1.2.4', ExtensionReleaseKindEnum::Patch],
    ['1.2.3', '1.3.0', ExtensionReleaseKindEnum::Minor],
    ['1.2.3', '2.0.0', ExtensionReleaseKindEnum::Major],
    ['v1.2.3', 'v1.2.4', ExtensionReleaseKindEnum::Patch],
    ['1.2.3', '1.2.4-beta.1', ExtensionReleaseKindEnum::Patch],
    ['1.2.4', '1.2.3', ExtensionReleaseKindEnum::Unknown],
    ['1.2.3', '1.2.3', ExtensionReleaseKindEnum::Unknown],
    ['dev-main', '1.2.3', ExtensionReleaseKindEnum::Unknown],
    ['1.2.3', null, ExtensionReleaseKindEnum::Unknown],
]);

it('answers whether a policy allows a release', function (
    ExtensionAutoUpdatePolicyEnum $policy,
    ExtensionReleaseKindEnum $releaseKind,
    bool $securityRelease,
    bool $expected,
): void {
    expect($policy->allows($releaseKind, $securityRelease))->toBe($expected);
})->with([
    'none never updates, even for security' => [ExtensionAutoUpdatePolicyEnum::None, ExtensionReleaseKindEnum::Patch, true, false],
    'patch takes a patch' => [ExtensionAutoUpdatePolicyEnum::Patch, ExtensionReleaseKindEnum::Patch, false, true],
    'patch refuses a minor' => [ExtensionAutoUpdatePolicyEnum::Patch, ExtensionReleaseKindEnum::Minor, false, false],
    'patch refuses a major' => [ExtensionAutoUpdatePolicyEnum::Patch, ExtensionReleaseKindEnum::Major, false, false],
    'minor takes a patch' => [ExtensionAutoUpdatePolicyEnum::Minor, ExtensionReleaseKindEnum::Patch, false, true],
    'minor takes a minor' => [ExtensionAutoUpdatePolicyEnum::Minor, ExtensionReleaseKindEnum::Minor, false, true],
    'minor refuses a major' => [ExtensionAutoUpdatePolicyEnum::Minor, ExtensionReleaseKindEnum::Major, false, false],
    'security refuses a plain patch' => [ExtensionAutoUpdatePolicyEnum::Security, ExtensionReleaseKindEnum::Patch, false, false],
    'security takes a flagged patch' => [ExtensionAutoUpdatePolicyEnum::Security, ExtensionReleaseKindEnum::Patch, true, true],
    'security takes a flagged major' => [ExtensionAutoUpdatePolicyEnum::Security, ExtensionReleaseKindEnum::Major, true, true],
    'nothing takes an unclassifiable release' => [ExtensionAutoUpdatePolicyEnum::Minor, ExtensionReleaseKindEnum::Unknown, false, false],
]);

it('selects only extensions whose policy allows the release that is waiting', function (): void {
    autoUpdateExtension('capell-app/patch-only', ExtensionAutoUpdatePolicyEnum::Patch);
    autoUpdateExtension('capell-app/minor-ok', ExtensionAutoUpdatePolicyEnum::Minor);
    autoUpdateExtension('capell-app/never', ExtensionAutoUpdatePolicyEnum::None);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/patch-only', 'minor_ready', '1.0.0', '1.1.0'),
        new ExtensionUpdateReadinessData('capell-app/minor-ok', 'minor_ready', '1.0.0', '1.1.0'),
        new ExtensionUpdateReadinessData('capell-app/never', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())
        ->toBe(['capell-app/minor-ok']);
});

it('leaves an extension the marketplace has blocked alone whatever its policy says', function (): void {
    autoUpdateExtension('capell-app/blocked', ExtensionAutoUpdatePolicyEnum::Minor);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/blocked', 'blocked', '1.0.0', '1.1.0'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())->toBe([]);
});

it('takes a major release only when a security advisory names the package', function (): void {
    autoUpdateExtension('capell-app/vulnerable', ExtensionAutoUpdatePolicyEnum::Security);
    autoUpdateExtension('capell-app/merely-old', ExtensionAutoUpdatePolicyEnum::Security);

    recordSecurityAdvisoryFor('capell-app/vulnerable');

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/vulnerable', 'major_review', '1.0.0', '2.0.0'),
        new ExtensionUpdateReadinessData('capell-app/merely-old', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())
        ->toBe(['capell-app/vulnerable']);
});

it('ignores an advisory that is not a security advisory', function (): void {
    autoUpdateExtension('capell-app/buggy', ExtensionAutoUpdatePolicyEnum::Security);

    RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
        'advisories' => [
            ['type' => 'bug', 'composer_name' => 'capell-app/buggy'],
        ],
    ]);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/buggy', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())->toBe([]);
});

it('only consults the newest advisory snapshot, so a withdrawn advisory stops driving updates', function (): void {
    autoUpdateExtension('capell-app/was-vulnerable', ExtensionAutoUpdatePolicyEnum::Security);

    recordSecurityAdvisoryFor('capell-app/was-vulnerable');
    RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
        'advisories' => [],
        'checked_at' => now()->addMinute()->toIso8601String(),
    ]);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/was-vulnerable', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())->toBe([]);
});

it('queues nothing at all when every extension is left on the default policy', function (): void {
    autoUpdateExtension('capell-app/default-policy', ExtensionAutoUpdatePolicyEnum::None);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/default-policy', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    $result = QueueMarketplaceAutoUpdatesAction::run();

    expect($result->requestedCount)->toBe(0)
        ->and($result->queuedAnything())->toBeFalse();
});

it('will not queue an unattended update from an advisory with no type at all', function (): void {
    autoUpdateExtension('capell-app/untyped-notice', ExtensionAutoUpdatePolicyEnum::Security);

    // The advisory channel demonstrably carries update and bug notices too, and
    // the payload has no schema guarantee. If a missing type counted as
    // security, the policy chosen by the operator who wants the LEAST automatic
    // change would silently become "take every release the marketplace
    // mentioned" — majors included, overnight.
    RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
        'advisories' => [
            ['composer_name' => 'capell-app/untyped-notice'],
        ],
    ]);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/untyped-notice', 'major_review', '1.0.0', '2.0.0'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())->toBe([]);
});

it('still surfaces an advisory with no type, because warning a human is free', function (): void {
    RecordUpdateAdvisorySnapshotAction::run('heartbeat', [
        'advisories' => [
            ['composer_name' => 'capell-app/untyped-notice'],
        ],
    ]);

    $advisories = resolve(ResolveMarketplaceSecurityAdvisoriesAction::class);

    expect($advisories->surfaceable())->toBe(['capell-app/untyped-notice'])
        ->and($advisories->handle())->toBe([]);
});

it('refuses to install a pre-release unattended, even when it classifies as a patch', function (): void {
    autoUpdateExtension('capell-app/beta-channel', ExtensionAutoUpdatePolicyEnum::Patch);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/beta-channel', 'patch_ready', '1.2.3', '1.2.4-beta.1'),
    ]);

    $eligibility = resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibility();

    expect($eligibility['eligible'])->toBe([])
        ->and($eligibility['skipped'])->toHaveKey('capell-app/beta-channel')
        ->and($eligibility['skipped']['capell-app/beta-channel'])->toContain('1.2.4-beta.1');
});

it('still takes the final release on the same policy', function (): void {
    autoUpdateExtension('capell-app/stable-channel', ExtensionAutoUpdatePolicyEnum::Patch);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/stable-channel', 'patch_ready', '1.2.3', '1.2.4'),
    ]);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())
        ->toBe(['capell-app/stable-channel']);
});

it('declines an unattended update while another package has migrations waiting', function (): void {
    autoUpdateExtension('capell-app/candidate', ExtensionAutoUpdatePolicyEnum::Patch);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/candidate', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    fakePendingMigrationsFor(['capell-app/unrelated-neighbour']);

    $eligibility = resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibility();

    // The update would publish and run every pending migration on the site, so
    // a health-check failure would roll the candidate's code back and leave the
    // neighbour ahead of its own — with no failure record against it at all.
    expect($eligibility['eligible'])->toBe([])
        ->and($eligibility['skipped']['capell-app/candidate'] ?? '')
        ->toContain('capell-app/unrelated-neighbour');
});

it('lets a package with only its own migrations waiting update itself', function (): void {
    autoUpdateExtension('capell-app/candidate', ExtensionAutoUpdatePolicyEnum::Patch);

    fakeUpdateReadiness([
        new ExtensionUpdateReadinessData('capell-app/candidate', 'patch_ready', '1.0.0', '1.0.1'),
    ]);

    fakePendingMigrationsFor(['capell-app/candidate']);

    expect(resolve(QueueMarketplaceAutoUpdatesAction::class)->eligibleComposerNames())
        ->toBe(['capell-app/candidate']);
});

it('defaults a newly recorded extension to never updating itself', function (): void {
    $extension = CapellExtension::query()->create([
        'composer_name' => 'capell-app/fresh',
        'name' => 'Fresh',
        'status' => ExtensionStatusEnum::Enabled,
    ]);

    expect($extension->refresh()->auto_update_policy)->toBe(ExtensionAutoUpdatePolicyEnum::None);
});
