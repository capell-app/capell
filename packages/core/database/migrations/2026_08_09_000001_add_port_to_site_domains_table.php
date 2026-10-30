<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! TableName::existsAndIsOwned('site_domains', '2026_05_10_190832_06_create_site_domains_table') || Schema::hasColumn(TableName::resolve('site_domains'), 'port')) {
            return;
        }

        Schema::table(TableName::resolve('site_domains'), static function (Blueprint $table): void {
            $table->unsignedInteger('port')->nullable()->after('scheme');
            $table->index(['domain', 'scheme', 'port', 'status'], TableName::namedIndex('site_domains', 'site_domains_origin_status_index'));
        });
    }

    public function down(): void
    {
        if (! TableName::existsAndIsOwned('site_domains', '2026_05_10_190832_06_create_site_domains_table') || ! Schema::hasColumn(TableName::resolve('site_domains'), 'port')) {
            return;
        }

        throw_if(DB::table(TableName::resolve('site_domains'))->whereNotNull('port')->exists(), RuntimeException::class, 'Cannot remove site domain ports while port-bearing records exist. Clear or migrate them before rollback.');

        Schema::table(TableName::resolve('site_domains'), static function (Blueprint $table): void {
            if (Schema::hasIndex(TableName::resolve('site_domains'), TableName::namedIndex('site_domains', 'site_domains_origin_status_index'))) {
                $table->dropIndex(TableName::namedIndex('site_domains', 'site_domains_origin_status_index'));
            }

            $table->dropColumn('port');
        });
    }
};
