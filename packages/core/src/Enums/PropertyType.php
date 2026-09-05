<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

use Capell\Core\Data\Properties\PropertyValueData;

/**
 * The typed value shape a property definition holds. Drives which column(s) on
 * `page_property_values` / `term_property_values` a value round-trips through
 * ({@see PropertyValueData}) and which unit/currency
 * fields are required.
 */
enum PropertyType: string
{
    case Money = 'money';
    case Dimension = 'dimension';
    case Date = 'date';
    case DateTime = 'datetime';
    case Duration = 'duration';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case TermReference = 'term_reference';
    case EntryReference = 'entry_reference';
    case Url = 'url';
    case Media = 'media';

    /**
     * Whether values of this type must carry an explicit ISO-4217 currency code.
     */
    public function requiresCurrency(): bool
    {
        return $this === self::Money;
    }

    /**
     * Whether values of this type must carry a unit resolvable against the
     * definition's `unit_config` whitelist.
     */
    public function requiresUnit(): bool
    {
        return $this === self::Dimension || $this === self::Duration;
    }

    /**
     * Whether this type is stored in the numeric column (`value_number`).
     */
    public function isNumeric(): bool
    {
        return match ($this) {
            self::Money, self::Dimension, self::Duration, self::Number => true,
            default => false,
        };
    }

    /**
     * Whether this type is stored in the datetime column (`value_datetime`).
     */
    public function isTemporal(): bool
    {
        return $this === self::Date || $this === self::DateTime;
    }

    /**
     * Whether this type is stored in the boolean column (`value_boolean`).
     */
    public function isBoolean(): bool
    {
        return $this === self::Boolean;
    }

    /**
     * Whether this type references another row (`term_id`, `referenced_page_id`
     * or `media_id`) rather than a scalar column.
     */
    public function isReference(): bool
    {
        return match ($this) {
            self::TermReference, self::EntryReference, self::Media => true,
            default => false,
        };
    }

    /**
     * Whether this type is stored in the free-text column (`value_text`) —
     * the default for anything not numeric, temporal, boolean, or a reference.
     */
    public function isText(): bool
    {
        return match ($this) {
            self::Text, self::Url => true,
            default => false,
        };
    }
}
