<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relationship_prompt_tag_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('relationship_id')->constrained()->cascadeOnDelete();
            $table->string('tag');
            $table->unsignedTinyInteger('minimum_temperature')->default(1);
            $table->timestamps();

            $table->unique(['relationship_id', 'tag']);
        });

        DB::table('users')->where('background_mode', 'auto')->update([
            'background_mode' => 'none',
            'background_photo_id' => null,
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->string('background_mode')->default('none')->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relationship_prompt_tag_rules');

        Schema::table('users', function (Blueprint $table) {
            $table->string('background_mode')->default('auto')->change();
        });
    }
};
