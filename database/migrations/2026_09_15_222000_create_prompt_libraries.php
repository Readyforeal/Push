<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('kind');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['relationship_id', 'active']);
        });

        $now = now();
        $libraryIds = [];

        foreach ([
            'shared_question' => ['Shared connection', 'Questions you both answer and reveal together.'],
            'unique_questions' => ['Individual reflection', 'Different questions for each partner, revealed together.'],
            'photo_picker' => ['Photo memories', 'You each share three photos and choose a favorite from each other.'],
            'photo_request' => ['Photo requests', 'Ask your partner for something you would love to see, then pick your favorite.'],
        ] as $kind => [$name, $description]) {
            $libraryIds[$kind] = DB::table('prompt_libraries')->insertGetId([
                'relationship_id' => null,
                'name' => $name,
                'slug' => 'built-in-'.Str::slug($kind),
                'description' => $description,
                'kind' => $kind,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->foreignId('prompt_library_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('relationship_id')->nullable()->after('prompt_library_id')->constrained()->cascadeOnDelete();
            $table->json('topics')->nullable()->after('secondary_prompt');
            $table->index(['prompt_library_id', 'relationship_id', 'active'], 'prompt_template_library_scope_index');
        });

        foreach ($libraryIds as $kind => $libraryId) {
            DB::table('prompt_templates')->where('kind', $kind)->update(['prompt_library_id' => $libraryId]);
        }

        Schema::table('relationship_prompt_schedules', function (Blueprint $table) {
            $table->foreignId('prompt_library_id')->nullable()->after('relationship_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('prompt_template_id')->nullable()->change();
        });

        DB::table('relationship_prompt_schedules')
            ->whereNotNull('prompt_template_id')
            ->orderBy('id')
            ->eachById(function (object $schedule): void {
                $libraryId = DB::table('prompt_templates')
                    ->where('id', $schedule->prompt_template_id)
                    ->value('prompt_library_id');

                DB::table('relationship_prompt_schedules')
                    ->where('id', $schedule->id)
                    ->update(['prompt_library_id' => $libraryId]);
            });
    }

    public function down(): void
    {
        DB::table('relationship_prompt_schedules')->whereNull('prompt_template_id')->delete();

        Schema::table('relationship_prompt_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_library_id');
            $table->unsignedBigInteger('prompt_template_id')->nullable(false)->change();
        });

        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->dropIndex('prompt_template_library_scope_index');
            $table->dropColumn('topics');
            $table->dropConstrainedForeignId('relationship_id');
            $table->dropConstrainedForeignId('prompt_library_id');
        });

        Schema::dropIfExists('prompt_libraries');
    }
};
