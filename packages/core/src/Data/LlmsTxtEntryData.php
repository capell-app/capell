<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Spatie\LaravelData\Data;

final class LlmsTxtEntryData extends Data
{
    public function __construct(
        public string $title,
        public string $url,
        public ?string $description = null,
    ) {}

    public function toMarkdownLine(): string
    {
        $line = sprintf('- [%s](%s)', $this->title, $this->url);

        if ($this->description !== null && $this->description !== '') {
            $line .= ': ' . $this->description;
        }

        return $line;
    }
}
