<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Models\SiteDomain;
use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

final class AuditSiteDomainOriginsCommand extends Command
{
    protected $signature = 'capell:site-domains-audit {--json : Output a machine-readable report}';

    protected $description = 'Audit normalized site-domain origins, including disabled rows';

    public function handle(): int
    {
        if (! Schema::hasTable('site_domains')) {
            if ($this->option('json')) {
                $this->line(json_encode(['conflicts' => []], JSON_THROW_ON_ERROR));
            } else {
                $this->info('No site-domain table exists; no normalized origin conflicts found.');
            }

            return self::SUCCESS;
        }

        $groups = SiteDomain::query()
            ->withTrashed()
            ->whereNull('deleted_at')
            ->get(['id', 'site_id', 'domain', 'scheme', 'port', 'path', 'status'])
            ->groupBy(fn (SiteDomain $domain): string => SiteDomainAddressing::routingIdentity(
                $domain->scheme,
                $domain->domain,
                $domain->port,
                $domain->path,
            ))
            ->filter(static fn (Collection $domains): bool => $domains->count() > 1);

        $conflicts = $groups->map(static fn (Collection $domains, string $identity): array => [
            'routing_identity' => $identity,
            'rows' => $domains->map(static fn (SiteDomain $domain): array => [
                'id' => $domain->id,
                'site_id' => $domain->site_id,
                'status' => $domain->status ? 'enabled' : 'disabled',
                'url' => sprintf(
                    '%s://%s%s%s',
                    $domain->scheme ?? '*',
                    $domain->domain ?? '*',
                    $domain->port === null ? '' : ':' . $domain->port,
                    $domain->path ?? '/',
                ),
            ])->values()->all(),
            'remediation' => 'Reconcile the conflicting rows through supported site/domain operations; do not delete the database or relax the unique index.',
        ])->values()->all();

        if ($this->option('json')) {
            $this->line(json_encode(['conflicts' => $conflicts], JSON_THROW_ON_ERROR));
        } elseif ($conflicts === []) {
            $this->info('No normalized site-domain origin conflicts found.');
        } else {
            foreach ($conflicts as $conflict) {
                $this->error(sprintf('Conflict %s: rows %s', $conflict['routing_identity'], implode(', ', array_column($conflict['rows'], 'id'))));
                $this->line($conflict['remediation']);
            }
        }

        return $conflicts === [] ? self::SUCCESS : self::FAILURE;
    }
}
