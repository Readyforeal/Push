<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shared_moments', function (Blueprint $table) {
            $table->foreignId('relationship_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('shared_moments')->whereNull('relationship_id')->delete();

        Schema::table('shared_moments', function (Blueprint $table) {
            $table->foreignId('relationship_id')->nullable(false)->change();
        });
    }
};
