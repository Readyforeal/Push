<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('prompt_library_user_contexts');
        Schema::dropIfExists('prompt_library_ai_settings');

        Schema::table('prompt_libraries', function (Blueprint $table) {
            $table->dropColumn('ai_instructions');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_libraries', function (Blueprint $table) {
            $table->text('ai_instructions')->nullable()->after('description');
        });

        Schema::create('prompt_library_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_library_id')->constrained()->cascadeOnDelete();
            $table->string('generation_mode')->default('hybrid');
            $table->text('instructions')->nullable();
            $table->text('shared_context')->nullable();
            $table->timestamps();
            $table->unique(['relationship_id', 'prompt_library_id'], 'prompt_library_ai_setting_unique');
        });

        Schema::create('prompt_library_user_contexts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_library_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('context')->nullable();
            $table->text('prompt_guidance')->nullable();
            $table->timestamps();
            $table->unique(['relationship_id', 'prompt_library_id', 'user_id'], 'prompt_library_user_context_unique');
        });
    }
};
