<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\Themes\ResolveThemeLibraryAction;
use Capell\Admin\Data\Themes\ThemeLibraryCardData;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

final class ValidateThemesCommand extends Command
{
    protected $signature = 'capell:themes:validate {themeKey? : Limit validation to a single theme key}';

    protected $description = 'Validate registered and installed Capell theme definitions.';

    public function handle(): int
    {
        $themeKey = $this->argument('themeKey');
        $library = ResolveThemeLibraryAction::run();

        $cards = collect([
            ...$library['installed'],
            ...$library['available'],
        ])->when(is_string($themeKey) && trim($themeKey) !== '', fn (Collection $cards) => $cards->filter(fn (ThemeLibraryCardData $card): bool => $card->themeKey === $themeKey))->values();

        if ($cards->isEmpty()) {
            $this->warn('No theme definitions matched.');

            return self::FAILURE;
        }

        $hasErrors = false;

        /** @var ThemeLibraryCardData $card */
        foreach ($cards as $card) {
            $diagnostics = $card->diagnostics;
            $status = $diagnostics->isValid() ? 'OK' : 'ERROR';
            $hasErrors = $hasErrors || ! $diagnostics->isValid();

            $this->line(sprintf('[%s] %s (%s)', $status, $card->title, $card->themeKey));

            foreach ($diagnostics->errors as $error) {
                $this->error('  - ' . $error);
            }

            foreach ($diagnostics->warnings as $warning) {
                $this->warn('  - ' . $warning);
            }
        }

        return $hasErrors ? self::FAILURE : self::SUCCESS;
    }
}
