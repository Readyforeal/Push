<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temperature_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('value');
            $table->timestamps();

            $table->index(['relationship_id', 'user_id', 'created_at'], 'temperature_check_ins_latest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temperature_check_ins');
    }
};
