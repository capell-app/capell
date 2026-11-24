<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * How much of a field an AI capability wrote: none (a person wrote it after all),
 * assisted (AI suggested, a person shaped it) or generated (AI wrote it as is).
 */
enum AiDisclosure: string
{
    case None = 'none';
    case Assisted = 'assisted';
    case Generated = 'generated';

    /** Where a page keeps its strongest disclosure, so public rendering needs no query. */
    public const string PAGE_META_KEY = 'ai_disclosure';

    /**
     * The schema.org IPTCDigitalSourceEnumeration member that `digitalSourceType`
     * (a CreativeWork property) takes for a page with this disclosure, or null when no AI wrote it. Generated text is trained
     * algorithmic media; assisted text is a person's work composed with it.
     */
    public function digitalSourceType(): ?string
    {
        return match ($this) {
            self::None => null,
            self::Assisted => 'https://schema.org/CompositeWithTrainedAlgorithmicMediaDigitalSource',
            self::Generated => 'https://schema.org/TrainedAlgorithmicMediaDigitalSource',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Assisted => 1,
            self::Generated => 2,
        };
    }
}
