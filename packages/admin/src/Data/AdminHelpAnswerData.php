<?php

declare(strict_types=1);

namespace Capell\Admin\Data;

use Spatie\LaravelData\Data;

final class AdminHelpAnswerData extends Data
{
    public function __construct(
        public string $question,
        public string $answer,
    ) {}
}
