<?php

declare(strict_types=1);

namespace Capell\Core\Database\Factories\Concerns;

use Capell\Core\Enums\ContentStructure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin Factory<Model>
 */
trait HasContent
{
    public function content(ContentStructure $structure): static
    {
        return $this->set('content', $structure->isArray()
            ? $this->structuredContent()
            : $this->htmlContent());
    }

    protected function htmlContent(): string
    {
        $paragraphs = $this->faker->paragraphs();

        return collect(is_array($paragraphs) ? $paragraphs : [$paragraphs])
            ->map(fn (string $paragraph): string => sprintf('<p>%s</p>', $paragraph))
            ->implode('');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function structuredContent(): array
    {
        $paragraphs = $this->faker->paragraphs(2);

        return [
            [
                'type' => 'title',
                'data' => [
                    'headingSize' => 'h1',
                ],
            ],
            [
                'type' => 'content',
                'data' => [
                    'content' => collect(is_array($paragraphs) ? $paragraphs : [$paragraphs])
                        ->map(fn (string $paragraph): string => sprintf('<p>%s</p>', $paragraph))
                        ->implode(''),
                ],
            ],
            [
                'type' => 'image',
                'data' => [
                    'src' => $this->faker->imageUrl(),
                    'alt' => $this->faker->sentence(),
                ],
            ],
        ];
    }
}
