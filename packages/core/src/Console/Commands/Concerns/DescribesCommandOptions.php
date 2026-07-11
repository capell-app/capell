<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

trait DescribesCommandOptions
{
    /**
     * @param  array<int, string>  $details
     */
    protected function writeCommandIntro(string $action, array $details = []): void
    {
        $message = 'You are about to ' . $action;

        if ($details !== []) {
            $message .= ' with ' . $this->formatCommandIntroDetails($details);
        }

        $this->line($message . '.');
    }

    /**
     * @param  array<int, string>  $details
     */
    protected function formatCommandIntroDetails(array $details): string
    {
        if (count($details) === 1) {
            return $details[0];
        }

        $lastDetail = array_pop($details);

        return implode(', ', $details) . ' and ' . $lastDetail;
    }

    /**
     * @param  array<string, string>  $optionLabels
     * @return array<int, string>
     */
    protected function enabledOptionDetails(array $optionLabels): array
    {
        $details = [];

        foreach ($optionLabels as $optionName => $label) {
            if (! $this->input->hasOption($optionName)) {
                continue;
            }

            if ($this->optionIsEnabled($this->input->getOption($optionName))) {
                $details[] = $label;
            }
        }

        return $details;
    }

    protected function optionWasProvided(string $optionName): bool
    {
        return $this->input->hasParameterOption('--' . $optionName);
    }

    private function optionIsEnabled(mixed $value): bool
    {
        if (in_array($value, [false, null, ''], true)) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
