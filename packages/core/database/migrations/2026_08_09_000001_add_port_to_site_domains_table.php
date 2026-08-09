<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_domains') || Schema::hasColumn('site_domains', 'port')) {
            return;
        }

        Schema::table('site_domains', static function (Blueprint $table): void {
            $table->unsignedInteger('port')->nullable()->after('scheme');
            $table->index(['domain', 'scheme', 'port', 'status'], 'site_domains_origin_status_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_domains') || ! Schema::hasColumn('site_domains', 'port')) {
            return;
        }

        throw_if(DB::table('site_domains')->whereNotNull('port')->exists(), RuntimeException::class, 'Cannot remove site domain ports while port-bearing records exist. Clear or migrate them before rollback.');

        Schema::table('site_domains', static function (Blueprint $table): void {
            if (Schema::hasIndex('site_domains', 'site_domains_origin_status_index')) {
                $table->dropIndex('site_domains_origin_status_index');
            }

            $table->dropColumn('port');
        });
    }
};
