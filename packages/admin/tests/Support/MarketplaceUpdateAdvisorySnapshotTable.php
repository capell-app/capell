<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MarketplaceUpdateAdvisorySnapshotTable
{
    public static function ensure(bool $truncate = false): void
    {
        if (Schema::hasTable('marketplace_update_advisory_snapshots')) {
            if ($truncate) {
                DB::table('marketplace_update_advisory_snapshots')->truncate();
            }

            return;
        }

        Schema::create('marketplace_update_advisory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->timestamp('checked_at');
            $table->string('capell_version')->nullable();
            $table->json('updates')->nullable();
            $table->json('advisories')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }
}
