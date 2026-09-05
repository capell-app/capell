<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories;

use Capell\Core\Models\Page;
use Capell\Core\Models\PagePropertyValue;
use Capell\Core\Models\PropertyDefinition;
use Capell\Core\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagePropertyValue>
 */
class PagePropertyValueFactory extends Factory
{
    protected $model = PagePropertyValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Callers building real page/value pairs should set both
            // `site_id` and `page_id` explicitly to the same page's site —
            // the default here only needs to be independently valid.
            'site_id' => Site::factory(),
            'page_id' => Page::factory(),
            'translation_id' => null,
            'property_definition_id' => PropertyDefinition::factory(),
            'position' => 0,
            'value_text' => $this->faker->word(),
            'value_number' => null,
            'value_boolean' => null,
            'value_datetime' => null,
            'currency' => null,
            'unit' => null,
            'term_id' => null,
            'referenced_page_id' => null,
            'media_id' => null,
        ];
    }
}
