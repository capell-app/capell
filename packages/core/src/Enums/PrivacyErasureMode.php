<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * How an eraser removes a person's data: delete the records, or keep the records
 * (for example, an order needed for accounting) with every personal field
 * replaced.
 */
enum PrivacyErasureMode: string
{
    case Delete = 'delete';
    case Anonymize = 'anonymize';
}
