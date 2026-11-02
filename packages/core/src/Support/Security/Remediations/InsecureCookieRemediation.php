<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security\Remediations;

use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use Capell\Core\Data\Security\SecurityRemediationOutcomeData;
use Capell\Core\Data\Security\SecurityWeaknessFindingData;
use Override;

final class InsecureCookieRemediation implements SecurityWeaknessRemediation
{
    public function __construct(private readonly EnvironmentRemediation $environment) {}

    #[Override]
    public function weaknessId(): string
    {
        return 'session.insecure_cookie';
    }

    #[Override]
    public function describe(): string
    {
        return __('capell-core::security.remediation.cookie');
    }

    #[Override]
    public function isReversible(): bool
    {
        return true;
    }

    #[Override]
    public function apply(SecurityWeaknessFindingData $finding): SecurityRemediationOutcomeData
    {
        $url = config('app.url');
        if (! is_string($url) || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return SecurityRemediationOutcomeData::skipped(__('capell-core::security.remediation.https_required'));
        }

        return $this->environment->apply(['SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true']);
    }
}
