<?php

use App\Enums\SecretMissionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secret_mission_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['relationship_id', 'beneficiary_user_id', 'active'], 'secret_mission_prompt_owner_index');
        });

        Schema::create('secret_missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('secret_mission_prompt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assignee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('beneficiary_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('status')->default(SecretMissionStatus::Active->value);
            $table->timestamp('accepted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['relationship_id', 'assignee_user_id', 'status'], 'secret_mission_assignee_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_missions');
        Schema::dropIfExists('secret_mission_prompts');
    }
};
