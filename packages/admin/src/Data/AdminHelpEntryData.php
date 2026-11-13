<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class AdminHelpEntryData extends Data
{
    /**
     * @param  list<AdminHelpAnswerData>  $answers  Short task answers for this screen, in display order.
     */
    public function __construct(
        public string $title,
        public string $summary,
        public string $nextAction,
        public ?string $docsUrl = null,
        public array $answers = [],
    ) {}

    public function withAnswers(AdminHelpAnswerData ...$answers): self
    {
        return new self(
            $this->title,
            $this->summary,
            $this->nextAction,
            $this->docsUrl,
            [...$this->answers, ...array_values($answers)],
        );
    }
}
