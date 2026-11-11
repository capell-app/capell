<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Data\Diagnostics\DoctorWarningSummaryData;
use Capell\Core\Data\Install\InstallHandoffData;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * The closing install summary: what was installed, where to open it and what to do next.
 * Every list is capped, so the summary stays within {@see self::MAX_LINES} lines however
 * many packages, warnings or tips an install produced.
 */
final class BuildInstallSummaryLinesAction
{
    use AsFake;
    use AsObject;

    public const int MAX_LINES = 60;

    private const int PACKAGE_LINE_WIDTH = 72;

    private const int MAX_PACKAGE_LINES = 6;

    private const int MAX_WARNINGS = 5;

    private const int MAX_TIPS = 8;

    private const int MAX_TEXT_LENGTH = 160;

    private const string INDENT = '  ';

    /**
     * @return list<string>
     */
    public function handle(InstallHandoffData $handoff, bool $wroteJson = false): array
    {
        $title = $handoff->status === 'completed'
            ? __('capell-core::install.handoff.completed_title')
            : __('capell-core::install.handoff.incomplete_title');

        $lines = [
            '<fg=blue;options=bold>' . $this->escape($title) . '</>',
            '',
            ...$this->presetLines($handoff),
            ...$this->installedLines($handoff),
            $this->row(__('capell-core::install.handoff.summary.site'), $handoff->urls['public'] !== '' ? $handoff->urls['public'] : __('capell-core::install.handoff.summary.unavailable')),
            $this->row(__('capell-core::install.handoff.summary.admin'), $handoff->urls['admin'] ?? __('capell-core::install.handoff.summary.unavailable')),
            $this->row(__('capell-core::install.handoff.summary.home_page'), $handoff->firstPage['status']),
            $this->row(__('capell-core::install.handoff.summary.checks'), __('capell-core::install.handoff.summary.checks_value', [
                'migrations' => $handoff->outcomes['migrations'],
                'setup' => $handoff->outcomes['setup'],
                'doctor' => $handoff->outcomes['doctor'],
            ])),
            ...$this->warningLines($handoff),
            ...$this->tipLines($handoff),
            '',
            $this->escape(__('capell-core::install.handoff.summary.full_log')),
        ];

        if ($wroteJson) {
            $lines[] = '<info>Machine-readable install handoff written.</info>';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function presetLines(InstallHandoffData $handoff): array
    {
        if ($handoff->preset === null) {
            return [];
        }

        return [$this->row(__('capell-core::install.handoff.summary.preset'), $handoff->preset['label'])];
    }

    /**
     * @return list<string>
     */
    private function installedLines(InstallHandoffData $handoff): array
    {
        $label = __('capell-core::install.handoff.summary.installed');

        if ($handoff->selectedPackages === []) {
            return [$this->row($label, __('capell-core::install.handoff.summary.none'))];
        }

        $rows = [];
        $current = '';
        $remaining = count($handoff->selectedPackages);

        foreach (array_values($handoff->selectedPackages) as $index => $package) {
            $piece = $package . ($index < count($handoff->selectedPackages) - 1 ? ',' : '');

            if ($current !== '' && mb_strlen($current) + 1 + mb_strlen($piece) > self::PACKAGE_LINE_WIDTH) {
                $rows[] = $current;
                $current = '';

                if (count($rows) === self::MAX_PACKAGE_LINES) {
                    break;
                }
            }

            $current = $current === '' ? $piece : $current . ' ' . $piece;
            $remaining--;
        }

        if (count($rows) < self::MAX_PACKAGE_LINES && $current !== '') {
            $rows[] = $current;
        }

        $lines = [];
        $padding = self::INDENT . str_repeat(' ', $this->labelWidth() + 2);

        foreach ($rows as $index => $text) {
            $lines[] = $index === 0 ? $this->row($label, $text) : $padding . $this->escape($text);
        }

        if ($remaining > 0) {
            $lines[] = $padding . $this->escape(__('capell-core::install.handoff.summary.more_packages', ['count' => $remaining]));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function warningLines(InstallHandoffData $handoff): array
    {
        $count = $handoff->warningCount ?? count($handoff->warnings);

        if ($count === 0 && $handoff->warnings === []) {
            return [];
        }

        $lines = [$this->row(__('capell-core::install.handoff.summary.warnings'), __('capell-core::install.handoff.summary.warnings_value', [
            'count' => $count,
            'command' => DoctorWarningSummaryData::LIST_COMMAND,
        ]))];

        foreach (array_slice($handoff->warnings, 0, self::MAX_WARNINGS) as $warning) {
            $lines[] = self::INDENT . '• ' . $this->escape(Str::limit(Str::squish($warning), self::MAX_TEXT_LENGTH));
        }

        $hidden = count($handoff->warnings) - self::MAX_WARNINGS;
        if ($hidden > 0) {
            $lines[] = self::INDENT . '• ' . $this->escape(__('capell-core::install.handoff.summary.more_warnings', [
                'count' => $hidden,
                'command' => DoctorWarningSummaryData::LIST_COMMAND,
            ]));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function tipLines(InstallHandoffData $handoff): array
    {
        if ($handoff->tips === []) {
            return [];
        }

        $tips = array_slice($handoff->tips, 0, self::MAX_TIPS);
        $width = max(array_map(static fn (array $tip): int => mb_strlen($tip['label']), $tips));
        $lines = ['', '<options=bold>' . $this->escape(__('capell-core::install.handoff.title')) . '</>'];

        foreach ($tips as $index => $tip) {
            $lines[] = sprintf(
                '  %d. %s  %s',
                $index + 1,
                $this->escape(mb_str_pad(Str::squish($tip['label']), $width)),
                $this->escape(Str::limit(Str::squish($tip['detail']), self::MAX_TEXT_LENGTH)),
            );
        }

        return $lines;
    }

    private function row(string $label, string $value): string
    {
        return self::INDENT . '<options=bold>' . $this->escape(mb_str_pad($label, $this->labelWidth())) . '</>  ' . $this->escape(Str::squish($value));
    }

    private function labelWidth(): int
    {
        return 9;
    }

    private function escape(string $text): string
    {
        return OutputFormatter::escape($text);
    }
}
