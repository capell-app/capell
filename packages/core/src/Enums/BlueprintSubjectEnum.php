<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Support\Blueprints\CoreBlueprintSubjects;
use Capell\Core\Support\BlueprintSubjectRegistry;

/**
 * Key namespace for the blueprint subjects core contributes.
 *
 * This enum exists so core call sites can name Page, Site and Theme without
 * repeating string literals. It deliberately carries no label or model mapping:
 * the subject set is open, and
 * {@see BlueprintSubjectRegistry} is the only place that
 * resolves a key to its label, model and seeder. Adding a `match` back here
 * would re-close the set that CAP-0100.2 opened.
 *
 * @see CoreBlueprintSubjects The descriptors behind these keys.
 */
enum BlueprintSubjectEnum: string
{
    case Page = 'page';

    case Site = 'site';

    case Theme = 'theme';

    /**
     * Stable subject key used by the registry.
     */
    public function getKey(): string
    {
        return $this->value;
    }
}
