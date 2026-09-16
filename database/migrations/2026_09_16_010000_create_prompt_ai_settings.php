<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_libraries', function (Blueprint $table) {
            $table->text('ai_instructions')->nullable()->after('description');
        });

        DB::table('prompt_libraries')->where('kind', 'shared_question')->update([
            'ai_instructions' => 'Create one thoughtful question both partners answer. Make it specific enough to invite a story, feeling, preference, memory, or concrete hope. It should be equally answerable by both people and lead to a meaningful reveal without assuming conflict.',
        ]);
        DB::table('prompt_libraries')->where('kind', 'unique_questions')->update([
            'ai_instructions' => 'Create two different but complementary questions, one tailored to each partner. Each question should stand alone, feel equally thoughtful, and make the paired reveal interesting. Do not write the same question twice or make one partner responsible for fixing the other.',
        ]);
        DB::table('prompt_libraries')->where('kind', 'photo_picker')->update([
            'ai_instructions' => 'Create a playful, achievable theme asking both partners to upload exactly three existing or newly taken photos. Then create one concise instruction for choosing a favorite from the other partner’s three photos. Favor personal meaning and storytelling over photographic skill.',
        ]);
        DB::table('prompt_libraries')->where('kind', 'photo_request')->update([
            'ai_instructions' => 'Create an opening question that lets the requester describe something they genuinely want to see. Then create a concise instruction telling the other partner to respond to that request with exactly three photos. Keep the request practical, consensual, and possible in ordinary daily life.',
        ]);

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
            $table->text('private_guidance')->nullable();
            $table->timestamps();

            $table->unique(['relationship_id', 'prompt_library_id', 'user_id'], 'prompt_library_user_context_unique');
        });

        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->foreignId('prompt_library_id')->nullable()->after('relationship_id')->constrained()->nullOnDelete();
            $table->string('prompt_source')->default('curated')->after('kind');
            $table->string('ai_model')->nullable()->after('prompt_source');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_library_id');
            $table->dropColumn(['prompt_source', 'ai_model']);
        });

        Schema::dropIfExists('prompt_library_user_contexts');
        Schema::dropIfExists('prompt_library_ai_settings');

        Schema::table('prompt_libraries', function (Blueprint $table) {
            $table->dropColumn('ai_instructions');
        });
    }
};
