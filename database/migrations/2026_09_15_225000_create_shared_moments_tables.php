<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_moments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('intensity');
            $table->text('body')->nullable();
            $table->timestamps();

            $table->index(['relationship_id', 'created_at']);
        });

        Schema::create('shared_moment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shared_moment_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->unique(['shared_moment_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_moment_photos');
        Schema::dropIfExists('shared_moments');
    }
};
