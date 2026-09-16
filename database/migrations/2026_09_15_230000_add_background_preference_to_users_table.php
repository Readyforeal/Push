<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('background_mode')->default('auto');
            $table->foreignId('background_photo_id')
                ->nullable()
                ->constrained('round_photos')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('background_photo_id');
            $table->dropColumn('background_mode');
        });
    }
};
