<?php

declare(strict_types=1);

namespace Capell\Core\Data\Ai;

use Capell\Core\Enums\AiDisclosure;
use Illuminate\Database\Eloquent\Model;

/**
 * One AI write to one field of a page (or another pageable), as reported by the
 * capability that made it. Provider and model are whatever the capability used;
 * Core stores them for the audit trail and never sends them to the public site.
 */
final readonly class AiProvenanceRecordData
{
    public function __construct(
        public Model $pageable,
        public string $field,
        public AiDisclosure $disclosure,
        public ?int $languageId = null,
        public ?string $capability = null,
        public ?string $provider = null,
        public ?string $model = null,
        public ?int $generationHistoryId = null,
        public ?int $actorId = null,
    ) {}
}
