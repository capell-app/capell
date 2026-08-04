<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Enums\VisitorLanguageDetection;
use Capell\Frontend\Settings\FrontendSettings;

interface FrontendSettingsReaderInterface
{
    public function settings(): FrontendSettings;

    public function minifyHtml(): bool;

    public function visitorLanguageDetection(): VisitorLanguageDetection;
}
