<?php

declare(strict_types=1);

use Capell\Core\Support\SiteDomains\SiteDomainAddressing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string UNIQUE_INDEX = 'site_domains_active_routing_identity_unique';

    private const string LEGACY_INDEX = 'site_domains_unique';

    public function up(): void
    {
        if (! Schema::hasTable('site_domains')) {
            return;
        }

        $identities = $this->activeIdentities();
        $duplicate = collect($identities)->duplicates()->first();

        if (is_string($duplicate)) {
            $ids = collect($identities)
                ->filter(static fn (string $identity): bool => $identity === $duplicate)
                ->keys()
                ->implode(', ');

            throw new RuntimeException(sprintf(
                'Cannot enforce active site domain uniqueness while records [%s] have the same normalized routing identity.',
                $ids,
            ));
        }

        if (! Schema::hasColumn('site_domains', 'routing_identity')) {
            Schema::table('site_domains', static function (Blueprint $table): void {
                $table->string('routing_identity', 64)->nullable()->after('path');
            });
        }

        foreach ($identities as $id => $identity) {
            DB::table('site_domains')->where('id', $id)->update(['routing_identity' => $identity]);
        }

        if (! Schema::hasIndex('site_domains', self::UNIQUE_INDEX)) {
            Schema::table('site_domains', static function (Blueprint $table): void {
                $table->unique('routing_identity', self::UNIQUE_INDEX);
            });
        }

        if (Schema::hasIndex('site_domains', self::LEGACY_INDEX)) {
            Schema::table('site_domains', static function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_domains') || ! Schema::hasColumn('site_domains', 'routing_identity')) {
            return;
        }

        if (! Schema::hasIndex('site_domains', self::LEGACY_INDEX)) {
            Schema::table('site_domains', static function (Blueprint $table): void {
                $table->unique(['scheme', 'domain', 'path', 'deleted_at'], self::LEGACY_INDEX);
            });
        }

        Schema::table('site_domains', static function (Blueprint $table): void {
            if (Schema::hasIndex('site_domains', self::UNIQUE_INDEX)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }

            $table->dropColumn('routing_identity');
        });
    }

    /** @return array<int, string> */
    private function activeIdentities(): array
    {
        return DB::table('site_domains')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'scheme', 'domain', 'port', 'path'])
            ->mapWithKeys(static fn (object $row): array => [
                (int) $row->id => SiteDomainAddressing::routingIdentity(
                    is_string($row->scheme) ? $row->scheme : null,
                    is_string($row->domain) ? $row->domain : null,
                    is_numeric($row->port) ? (int) $row->port : null,
                    is_string($row->path) ? $row->path : null,
                ),
            ])
            ->all();
    }
};
