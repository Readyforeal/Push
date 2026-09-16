<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_prompt_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('delivery_time');
            $table->unsignedInteger('position')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['relationship_id', 'day_of_week', 'active']);
        });

        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->dropUnique(['relationship_id', 'scheduled_for']);
            $table->foreignId('relationship_prompt_schedule_id')
                ->nullable()
                ->after('prompt_template_id')
                ->constrained()
                ->nullOnDelete();
            $table->unique(
                ['relationship_id', 'relationship_prompt_schedule_id', 'scheduled_for'],
                'prompt_round_schedule_date_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->dropUnique('prompt_round_schedule_date_unique');
            $table->dropConstrainedForeignId('relationship_prompt_schedule_id');
            $table->unique(['relationship_id', 'scheduled_for']);
        });

        Schema::dropIfExists('relationship_prompt_schedules');
    }
};
