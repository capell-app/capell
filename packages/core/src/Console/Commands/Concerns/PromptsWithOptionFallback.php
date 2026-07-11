<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Illuminate\Console\Command;
use RuntimeException;

/**
 * Helpers for commands that use Laravel Prompts and must also work in
 * non-interactive environments (CI, Docker builds, scripted installs).
 *
 * Without these helpers, a call like
 *
 *     text(label: 'URL', required: true)
 *
 * raises `Laravel\Prompts\Exceptions\NonInteractiveValidationException:
 * Required.` — unhelpful, and the user has no way of knowing which CLI
 * flag would have answered the prompt. Wrapping each prompt with
 * `requireInteractiveOrFail()` converts the failure into an actionable
 * `RuntimeException` that names the missing field (and, optionally, the
 * flag that answers it).
 *
 * @mixin Command
 */
trait PromptsWithOptionFallback
{
    /**
     * Abort with a clear RuntimeException when a prompt needs interactive
     * input but the console is non-interactive (e.g. `--no-interaction`,
     * a non-TTY CI runner, a Docker build).
     *
     * Call this immediately before any Laravel Prompts call that has no
     * safe default.
     *
     * @param  string  $requirement  Human-readable name of what's missing (e.g. "Site URL").
     * @param  string  $hint  Optional trailing hint, e.g. "Pass --url=<url>.".
     */
    protected function requireInteractiveOrFail(string $requirement, string $hint = ''): void
    {
        if ($this->input->isInteractive()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s is required in non-interactive mode.%s',
            $requirement,
            $hint !== '' ? ' ' . $hint : '',
        ));
    }
}
