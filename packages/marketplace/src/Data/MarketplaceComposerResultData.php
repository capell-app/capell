<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

use Capell\Marketplace\Actions\RedactMarketplaceDiagnosticContextAction;

final readonly class MarketplaceComposerResultData
{
    private const string REDACTED = '[redacted]';

    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput,
        public bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /**
     * Composer output can carry the credentials the runner wrote into its
     * throwaway home, and a replayed application hook is free to echo its whole
     * environment when it fails. Every Composer subprocess this package starts
     * therefore returns its output through here, so no call site has to
     * remember which of them handles secrets.
     */
    public function redacted(): self
    {
        $redacted = RedactMarketplaceDiagnosticContextAction::run([
            'output' => $this->output,
            'error_output' => $this->errorOutput,
        ]);

        return new self(
            exitCode: $this->exitCode,
            output: is_string($redacted['output'] ?? null) ? $redacted['output'] : self::REDACTED,
            errorOutput: is_string($redacted['error_output'] ?? null) ? $redacted['error_output'] : self::REDACTED,
            timedOut: $this->timedOut,
        );
    }
}
