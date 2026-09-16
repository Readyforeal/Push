<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('background_image_disk')->nullable()->after('background_photo_id');
        });

        DB::table('users')
            ->whereNotNull('background_image_path')
            ->update(['background_image_disk' => 'local']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('background_image_disk');
        });
    }
};
