<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Enums;

/**
 * The editorial workflow states a page moves through. The legacy publish
 * columns remain the source of truth for public visibility; this enum captures
 * the *intent* (who is reviewing, who approved) that the inferred publish state
 * cannot express.
 */
enum PageWorkflowStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';

    /**
     * The states reachable from this one. Encodes the state machine the
     * aggregate enforces.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::InReview, self::Scheduled, self::Published, self::Archived],
            self::InReview => [self::Approved, self::ChangesRequested, self::Draft],
            self::ChangesRequested => [self::InReview, self::Draft],
            self::Approved => [self::Scheduled, self::Published, self::ChangesRequested],
            self::Scheduled => [self::Published, self::Unpublished, self::Draft, self::Archived],
            self::Published => [self::Unpublished, self::Scheduled, self::Archived],
            self::Unpublished => [self::Published, self::Scheduled, self::Draft, self::Archived],
            self::Archived => [self::Draft],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }
}
