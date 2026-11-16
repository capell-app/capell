<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Feature\Privacy\Fixtures;

use Capell\Core\Contracts\Privacy\PreparesPrivacyErasure;
use Capell\Core\Contracts\Privacy\PrivacySubjectEraser;
use Capell\Core\Contracts\Privacy\ReviewsPrivacyErasure;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Override;

final class ReviewingPrivacyEraser implements PreparesPrivacyErasure, PrivacySubjectEraser, ReviewsPrivacyErasure
{
    /**
     * @param  list<mixed>  $candidates
     * @param  list<mixed>  $outstanding
     */
    public function __construct(
        private readonly string $key,
        private readonly array $candidates = [],
        private readonly array $outstanding = [],
        private readonly bool $honoursRetainedScopes = false,
        private readonly bool $supports = true,
    ) {}

    #[Override]
    public function contributor(): string
    {
        return $this->key;
    }

    #[Override]
    public function supports(PrivacySubject $subject): bool
    {
        return $this->supports;
    }

    #[Override]
    public function erase(PrivacySubject $subject, PrivacyErasureMode $mode): PrivacyErasureReceiptData
    {
        return new PrivacyErasureReceiptData($this->key, $mode, 0);
    }

    #[Override]
    public function retainsSuppressionMarker(): bool
    {
        return false;
    }

    #[Override]
    public function reviewCandidates(PrivacySubject $subject): array
    {
        return $this->candidates;
    }

    #[Override]
    public function honoursRetainedScopes(): bool
    {
        return $this->honoursRetainedScopes;
    }

    #[Override]
    public function prepareErasure(PrivacySubject $subject): array
    {
        return $this->outstanding;
    }
}
