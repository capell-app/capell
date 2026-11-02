<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(TableName::resolve('gadgets'), fn (Blueprint $table) => $table->id());
        Schema::create(TableName::resolve(config('example.tables.gadget_parts', 'gadget_parts')), fn (Blueprint $table) => $table->foreignId('gadget_id')->constrained(TableName::resolve('gadgets')));
    }
};
