<?php

use App\Enums\PromptPhotoRequirement;
use App\Enums\PromptRoundOrigin;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->string('photo_requirement')
                ->default(PromptPhotoRequirement::None->value)
                ->after('primary_user_id');
        });

        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->string('origin')
                ->default(PromptRoundOrigin::Scheduled->value)
                ->after('kind');
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->after('relationship_prompt_schedule_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index(['relationship_id', 'origin', 'status'], 'prompt_round_origin_status_index');
            $table->index(['relationship_id', 'origin', 'scheduled_for'], 'prompt_round_origin_date_index');
        });

        Schema::create('relationship_extracurricular_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_library_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['relationship_id', 'prompt_library_id'], 'relationship_extracurricular_library_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_extracurricular_libraries');

        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->dropIndex('prompt_round_origin_status_index');
            $table->dropIndex('prompt_round_origin_date_index');
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn('origin');
        });

        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->dropColumn('photo_requirement');
        });
    }
};
