<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Properties\VerifyAgentSchemaAction;
use Illuminate\Console\Command;

final class AgentSchemaVerifyCommand extends Command
{
    protected $signature = 'capell:agent-schema:verify {--site= : Limit completeness checks to one site} {--json : Output JSON}';

    protected $description = 'Verify the public agent schema, ownership and contract completeness';

    public function handle(): int
    {
        $site = $this->option('site');
        if ($site !== null && (! is_string($site) || ! ctype_digit($site) || (int) $site < 1)) {
            $this->error('The site option must be a positive integer.');

            return self::INVALID;
        }

        $report = VerifyAgentSchemaAction::run($site !== null ? (int) $site : null);
        if ($this->option('json')) {
            $this->line(json_encode(['capell-agent-schema' => 1, ...$report->toArray()], JSON_THROW_ON_ERROR));
        } else {
            $this->line('capell-agent-schema: 1');
            $this->table(['Check', 'Subject', 'Problem'], $report->failures);
            $this->line(sprintf('Checked %d published pages; %d failures.', $report->pagesChecked, count($report->failures)));
        }

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
