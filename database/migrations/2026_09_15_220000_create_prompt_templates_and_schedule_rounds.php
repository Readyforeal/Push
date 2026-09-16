<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('kind');
            $table->text('primary_prompt');
            $table->text('secondary_prompt')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['active', 'position']);
        });

        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->foreignId('prompt_template_id')->nullable()->after('relationship_id')->constrained()->nullOnDelete();
            $table->date('scheduled_for')->nullable()->after('available_at');
            $table->unique(['relationship_id', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->dropUnique(['relationship_id', 'scheduled_for']);
            $table->dropConstrainedForeignId('prompt_template_id');
            $table->dropColumn('scheduled_for');
        });

        Schema::dropIfExists('prompt_templates');
    }
};
