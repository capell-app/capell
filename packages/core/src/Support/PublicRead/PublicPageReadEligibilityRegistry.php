<?php

declare(strict_types=1);

namespace Capell\Core\Support\PublicRead;

use Capell\Core\Contracts\PublicRead\PublicPageReadEligibility;
use Capell\Core\Data\PublicRead\PublicPageReadCandidateData;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Throwable;

/**
 * Collects public-read eligibility decisions from packages.
 *
 * A page is publicly readable only when every contributor allows it. A
 * contributor that throws denies the page, so a projection omits it rather
 * than publishing an address whose access rules could not be evaluated.
 *
 * @experimental
 *
 * @extends AbstractKeyedRegistry<PublicPageReadEligibility>
 */
final class PublicPageReadEligibilityRegistry extends AbstractKeyedRegistry
{
    public function register(PublicPageReadEligibility $eligibility): self
    {
        $this->setItem($eligibility->key(), $eligibility);

        return $this;
    }

    public function hasContributors(): bool
    {
        return $this->allItems() !== [];
    }

    public function allows(PublicPageReadCandidateData $candidate): bool
    {
        if (! $this->hasContributors()) {
            return true;
        }

        if (trim($candidate->url) === '') {
            return false;
        }

        foreach ($this->allItems() as $eligibility) {
            try {
                if (! $eligibility->isPubliclyReadable($candidate)) {
                    return false;
                }
            } catch (Throwable $throwable) {
                report($throwable);

                return false;
            }
        }

        return true;
    }
}
