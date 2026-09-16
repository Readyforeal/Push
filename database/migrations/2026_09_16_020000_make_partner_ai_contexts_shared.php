<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_library_user_contexts', function (Blueprint $table) {
            $table->renameColumn('private_guidance', 'context');
            $table->text('prompt_guidance')->nullable()->after('context');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_library_user_contexts', function (Blueprint $table) {
            $table->dropColumn('prompt_guidance');
            $table->renameColumn('context', 'private_guidance');
        });
    }
};
