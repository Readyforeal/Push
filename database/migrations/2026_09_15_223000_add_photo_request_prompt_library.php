<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('prompt_libraries')
            ->where('slug', 'built-in-photo-picker')
            ->update([
                'description' => 'You each share three photos and choose a favorite from each other.',
                'updated_at' => $now,
            ]);

        $libraryId = DB::table('prompt_libraries')
            ->where('slug', 'built-in-photo-request')
            ->value('id');

        if (! $libraryId) {
            $libraryId = DB::table('prompt_libraries')->insertGetId([
                'relationship_id' => null,
                'name' => 'Photo requests',
                'slug' => 'built-in-photo-request',
                'description' => 'Ask your partner for something you would love to see, then pick your favorite.',
                'kind' => 'photo_request',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

    }

    public function down(): void
    {
        DB::table('prompt_libraries')
            ->where('slug', 'built-in-photo-picker')
            ->update([
                'description' => 'Three-photo prompts followed by a favorite pick.',
                'updated_at' => now(),
            ]);

        DB::table('prompt_libraries')
            ->where('slug', 'built-in-photo-request')
            ->delete();
    }
};
