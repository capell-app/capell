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

    /** @return list<string> Composer names, de-duplicated. */
    public function handle(): array
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

            if (! $this->isSecurityAdvisory($advisory)) {
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
    private function isSecurityAdvisory(array $advisory): bool
    {
        // An entry that lives in the advisories list and says nothing about its
        // type is treated as a security advisory. That is the safe direction:
        // the alternative is silently ignoring a warning the marketplace went to
        // the trouble of sending.
        $type = $advisory['type'] ?? $advisory['notice_type'] ?? $advisory['kind'] ?? null;

        if (! is_string($type) || $type === '') {
            return true;
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
