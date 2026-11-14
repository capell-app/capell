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
}
