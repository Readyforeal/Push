<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_round_tasks', function (Blueprint $table): void {
            $table->timestamp('reminded_at')->nullable()->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_round_tasks', function (Blueprint $table): void {
            $table->dropColumn('reminded_at');
        });
    }
};
