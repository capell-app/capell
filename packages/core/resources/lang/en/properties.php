<?php

declare(strict_types=1);

return [
    'builtin' => [
        'commerce_product' => [
            'name' => 'Commerce product',
            'price' => "The product's current price, in the given currency.",
            'sku' => "The product's stock-keeping unit identifier.",
            'weight' => "The product's shipping weight, in the given unit.",
            'availability' => "The product's stock availability (schema.org availability enumeration).",
        ],
        'events_event' => [
            'name' => 'Event',
            'start_date' => 'The date and time the event starts.',
            'end_date' => 'The date and time the event ends.',
            'venue' => 'The name of the venue hosting the event.',
        ],
        'content_article' => [
            'name' => 'Article',
            'author' => "The name of the article's author.",
            'date_published' => 'The date the article was first published.',
            'word_count' => "The article's approximate word count.",
        ],
    ],

    'validation' => [
        'type_mismatch' => 'The value given for property ":property" does not match its declared type ":type".',
        'currency_required' => 'Property ":property" requires a currency code.',
        'unit_not_allowed' => 'Unit ":unit" is not permitted for property ":property".',
        'localised_translation_required' => 'Property ":property" is localised and requires a translation.',
        'not_attached_to_blueprint' => 'Property ":property" is not attached to this page\'s blueprint.',
        'promoted_property_direct_write' => 'Property ":property" is promoted from field ":field" and cannot be written to directly.',
    ],
];
