<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('relationship_invitations', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('relationship_invitations', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
