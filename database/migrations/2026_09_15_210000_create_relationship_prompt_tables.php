<?php

use App\Enums\PromptRoundStatus;
use App\Enums\PromptTaskStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationships', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('timezone')->default('UTC');
            $table->timestamps();
        });

        Schema::create('relationship_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->unique(['relationship_id', 'user_id']);
        });

        Schema::create('prompt_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('status')->default(PromptRoundStatus::Active->value);
            $table->timestamp('available_at');
            $table->timestamp('revealed_at')->nullable();
            $table->timestamps();

            $table->index(['relationship_id', 'status']);
        });

        Schema::create('prompt_round_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('depends_on_task_id')->nullable()->constrained('prompt_round_tasks')->nullOnDelete();
            $table->string('kind');
            $table->string('status')->default(PromptTaskStatus::Locked->value);
            $table->text('prompt')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedSmallInteger('position')->default(1);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['prompt_round_id', 'user_id', 'position']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('question_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_round_task_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('answer')->nullable();
            $table->timestamps();
        });

        Schema::create('round_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_round_task_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->unique(['prompt_round_task_id', 'position']);
        });

        Schema::create('photo_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_round_task_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('round_photo_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_selections');
        Schema::dropIfExists('round_photos');
        Schema::dropIfExists('question_responses');
        Schema::dropIfExists('prompt_round_tasks');
        Schema::dropIfExists('prompt_rounds');
        Schema::dropIfExists('relationship_members');
        Schema::dropIfExists('relationships');
    }
};
