<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Enums\UpdateNoticeType;
use Capell\Marketplace\Models\UpdateAdvisorySnapshot;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Which installed packages the marketplace has flagged as needing a security
 * release.
 *
 * The heartbeat has been storing advisories since the beginning and
 * UpdateNoticeType has never had a non-test reader, so this is the first thing
 * that turns "we were told" into "we did something". Only the newest snapshot is
 * consulted: an advisory that has been withdrawn should stop driving automatic
 * updates the moment the next heartbeat says so.
 *
 * The payload comes from a service this site does not control, so both the
 * envelope shape and the key naming are read defensively rather than trusted.
 */
final class ResolveMarketplaceSecurityAdvisoriesAction
{
    use AsFake;
    use AsObject;

    /**
     * Packages the marketplace has flagged, for **queueing an unattended
     * update**. An explicit `security` type is required.
     *
     * The permissive reading below is wrong in this direction. The `security`
     * policy is chosen by the operator who wants the least automatic change, and
     * `ExtensionAutoUpdatePolicyEnum::Security` lets a security release be a
     * major. So a server that omitted `type` — on a channel that demonstrably
     * also carries `update` and `bug` notices, over a payload with no schema
     * guarantee — would silently turn "only security fixes" into "every release
     * the marketplace mentioned, majors included", overnight, with nobody
     * watching.
     *
     * @return list<string> Composer names, de-duplicated.
     */
    public function handle(): array
    {
        return $this->composerNames(requireExplicitSecurityType: true);
    }

    /**
     * Packages the marketplace has flagged, for **telling a human**.
     *
     * Here the permissive reading is right: an untyped entry that the
     * marketplace went to the trouble of sending is worth surfacing, and the
     * cost of a warning that turns out not to be a security advisory is that
     * somebody reads one line and moves on. Nothing is installed by this.
     *
     * @return list<string> Composer names, de-duplicated.
     */
    public function surfaceable(): array
    {
        return $this->composerNames(requireExplicitSecurityType: false);
    }

    /** @return list<string> */
    private function composerNames(bool $requireExplicitSecurityType): array
    {
        $snapshot = UpdateAdvisorySnapshot::latestSnapshot();

        if (! $snapshot instanceof UpdateAdvisorySnapshot) {
            return [];
        }

        $composerNames = [];

        foreach ($snapshot->advisories ?? [] as $advisory) {
            if (! is_array($advisory)) {
                continue;
            }

            if (! $this->isSecurityAdvisory($advisory, $requireExplicitSecurityType)) {
                continue;
            }

            $composerName = $this->composerName($advisory);

            if ($composerName !== null) {
                $composerNames[$composerName] = true;
            }
        }

        return array_keys($composerNames);
    }

    /** @param array<string, mixed> $advisory */
    private function isSecurityAdvisory(array $advisory, bool $requireExplicitSecurityType): bool
    {
        $type = $advisory['type'] ?? $advisory['notice_type'] ?? $advisory['kind'] ?? null;

        if (! is_string($type) || $type === '') {
            return ! $requireExplicitSecurityType;
        }

        return UpdateNoticeType::tryFrom($type) === UpdateNoticeType::Security;
    }

    /** @param array<string, mixed> $advisory */
    private function composerName(array $advisory): ?string
    {
        $composerName = $advisory['composer_name'] ?? $advisory['package'] ?? $advisory['name'] ?? null;

        return is_string($composerName) && trim($composerName) !== '' ? trim($composerName) : null;
    }
}
