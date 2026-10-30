<?php

declare(strict_types=1);

use Capell\Core\Support\Database\SchemaDialects\AncillaryUserColumnRepair;
use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        (new AncillaryUserColumnRepair)->repair(DB::connection(), TableName::resolve('languages'));
    }

    public function down(): void
    {
        // These columns may predate this repair and can contain user data.
    }
};
